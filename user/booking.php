<?php
/**
 * FILE: user/booking.php
 * MÔ TẢ: Trang Đặt Sân Bóng Trực Tuyến (Bản nâng cấp Sơ đồ Khung Giờ Trực Quan & Tích hợp Cáp Kèo).
 * CHỨC NĂNG: Chọn sân, bấm chọn trực tiếp các ô giờ trống trên sơ đồ (Interactive Time-Slot Grid),
 *            chọn dịch vụ đi kèm, áp dụng Voucher, tùy chọn đăng kèo tìm đối thủ giao hữu tự động.
 */
require_once '../includes/auth.php';
$u = require_login(); 
if($u['role'] === 'admin') { header('Location: ../admin/dashboard.php'); exit; }

// 1. Tải danh sách sân bóng, dịch vụ đi kèm và các mã Voucher đang hoạt động
$fields = $pdo->query("SELECT * FROM fields WHERE status='active' ORDER BY name")->fetchAll();
$services = $pdo->query("SELECT * FROM services WHERE status='active'")->fetchAll();
$vouchers_active = $pdo->query("SELECT * FROM vouchers WHERE status='active' AND expired_at >= CURDATE() AND used_count < usage_limit ORDER BY discount_value DESC")->fetchAll();
$fid = (int)($_POST['field_id'] ?? $_GET['field_id'] ?? 0);

// 2. Xử lý tạo đơn đặt sân khi người dùng gửi biểu mẫu (POST)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  csrf_check();
  $date = $_POST['date'] ?? ''; 
  $st = $_POST['start'] ?? ''; 
  $en = $_POST['end'] ?? '';
  $note = trim($_POST['note'] ?? '');
  $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
  $req_services = $_POST['services'] ?? [];

  // Kiểm tra tính hợp lệ của ngày và khung giờ đặt
  $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) && preg_match('/^\d{2}:00$/',$st) && preg_match('/^\d{2}:00$/',$en);
  if(!$ok || $en <= $st) flash('err','Khung giờ không hợp lệ (Giờ kết thúc phải lớn hơn giờ bắt đầu).');
  elseif($date < date('Y-m-d') || ($date == date('Y-m-d') && $st <= date('H:i'))) flash('err','Không thể đặt thời gian đã qua.');
  else {
    // Mở Transaction để chống trùng lịch đặt (Race Condition)
    $pdo->beginTransaction();
    $s = $pdo->prepare("SELECT * FROM fields WHERE id=? AND status='active' FOR UPDATE"); 
    $s->execute([$fid]); 
    $f = $s->fetch();

    if(!$f){ $pdo->rollBack(); flash('err','Sân không tồn tại hoặc đang bảo trì.'); }
    else {
      // Kiểm tra xem khung giờ đã có đơn đặt nào trùng lặp hay chưa
      $c = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE field_id=? AND booking_date=? AND status IN('pending','confirmed','completed') AND start_time<? AND end_time>?");
      $c->execute([$fid,$date,$en,$st]);
      if($c->fetchColumn()){ 
        $pdo->rollBack(); 
        flash('err','Khung giờ này đã có người đặt. Vui lòng chọn khung giờ khác.'); 
      } else {
        // Tính tiền thuê sân theo số giờ
        $hours = (int)$en - (int)$st;
        $subtotal = $hours * $f['price_per_hour'];

        // Tính tiền các dịch vụ đi kèm được chọn
        $service_total = 0;
        $svc_to_insert = [];
        if(!empty($req_services) && is_array($req_services)){
          $svc_ids = array_keys($req_services);
          $in  = str_repeat('?,', count($svc_ids) - 1) . '?';
          $stmt_svc = $pdo->prepare("SELECT * FROM services WHERE id IN ($in)");
          $stmt_svc->execute($svc_ids);
          $svc_db = $stmt_svc->fetchAll();

          foreach($svc_db as $s_item){
            $qty = max(0, (int)($req_services[$s_item['id']] ?? 0));
            if($qty > 0){
              $item_cost = $qty * $s_item['price'];
              $service_total += $item_cost;
              $svc_to_insert[] = ['id' => $s_item['id'], 'qty' => $qty, 'price' => $s_item['price']];
            }
          }
        }

        $total = $subtotal + $service_total;
        $vid = null;

        // Kiểm tra và áp dụng mã giảm giá (Voucher) nếu có
        if($voucher_code !== ''){
          $vstmt = $pdo->prepare("SELECT * FROM vouchers WHERE code=? AND status='active' AND expired_at >= CURDATE() FOR UPDATE");
          $vstmt->execute([$voucher_code]);
          $v = $vstmt->fetch();
          if($v && $v['used_count'] < $v['usage_limit'] && $total >= $v['min_order']){
            $vid = $v['id'];
            if($v['discount_type'] === 'percent'){
              $discount = (int)round(($total * $v['discount_value']) / 100);
              if($v['max_discount'] > 0 && $discount > $v['max_discount']) $discount = (int)$v['max_discount'];
            } else {
              $discount = (int)$v['discount_value'];
            }
            $total = max(0, $total - $discount);
            $pdo->prepare("UPDATE vouchers SET used_count = used_count + 1 WHERE id=?")->execute([$vid]);
          }
        }

        // Lưu đơn đặt sân, dịch vụ đi kèm và bản ghi thanh toán
        $pdo->prepare("INSERT INTO bookings(user_id,field_id,booking_date,start_time,end_time,total_price,voucher_id,note) VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$u['id'],$fid,$date,$st,$en,$total,$vid,$note]);
        $bid = $pdo->lastInsertId();

        foreach($svc_to_insert as $s_ins){
          $pdo->prepare("INSERT INTO booking_services(booking_id,service_id,quantity,price) VALUES(?,?,?,?)")
              ->execute([$bid, $s_ins['id'], $s_ins['qty'], $s_ins['price']]);
        }

        $method = in_array($_POST['payment_method'] ?? '', ['bank','momo','vnpay','cash']) ? $_POST['payment_method'] : 'bank';
        $pdo->prepare("INSERT INTO payments(booking_id,method,amount,status) VALUES(?,?,?,'pending')")->execute([$bid,$method,$total]);

        // Nếu người dùng bật chế độ "Đăng lên Sàn Cáp Kèo / Tìm Đối Giao Hữu"
        if (!empty($_POST['enable_matchmaking'])) {
          $team_name = trim($_POST['team_name'] ?? '') ?: ('FC ' . $u['full_name']);
          $match_type = in_array($_POST['match_type'] ?? '', ['tim_doi','tuyen_nguoi']) ? $_POST['match_type'] : 'tim_doi';
          $skill_level = in_array($_POST['skill_level'] ?? '', ['vui_ve','phong_trao','ban_chuyen']) ? $_POST['skill_level'] : 'phong_trao';
          $fee_share = trim($_POST['fee_share'] ?? 'Chia đôi 50/50') ?: 'Chia đôi 50/50';
          $spots = max(1, min(15, (int)($_POST['spots_needed'] ?? 1)));
          $pdo->prepare("INSERT INTO match_requests(booking_id,creator_id,field_id,team_name,match_date,start_time,end_time,skill_level,match_type,spots_needed,fee_share,contact_phone,note,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'open')")
              ->execute([$bid, $u['id'], $fid, $team_name, $date, $st, $en, $skill_level, $match_type, $spots, $fee_share, $u['phone'], $note]);
        }

        notify($u['id'], "Bạn đã tạo đơn đặt {$f['name']} (#$bid) ngày ".date('d/m/Y',strtotime($date))." ($st-$en). Tổng thanh toán: ".money($total));
        $pdo->commit(); 

        // Điều hướng sang trang Lịch đặt (nếu tiền mặt) hoặc Cổng Thanh Toán Demo (nếu trực tuyến)
        if ($method === 'cash') {
          flash('ok', 'Đặt sân thành công! Đơn #' . $bid . ' đã được ghi nhận (Thanh toán tiền mặt tại sân).');
          header("Location: my-bookings.php");
        } else {
          flash('ok', 'Đơn đặt sân #' . $bid . ' đã được khởi tạo! Vui lòng hoàn tất thanh toán trên Cổng Thanh Toán Demo.');
          header("Location: payment-demo.php?booking_id=$bid");
        }
        exit;
      }
    }
  }
}

// 3. Lấy danh sách các khung giờ đã đặt của sân đang chọn để dựng Sơ đồ Khung Giờ Trực Quan
$sel = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'); 
$busy = [];
$busyHoursMap = [];
$cur_field = null;
if($fid){ 
  $s = $pdo->prepare("SELECT start_time,end_time FROM bookings WHERE field_id=? AND booking_date=? AND status IN('pending','confirmed','completed') ORDER BY start_time"); 
  $s->execute([$fid,$sel]); 
  $busy = $s->fetchAll(); 

  foreach($busy as $b_row){
    $b_st = (int)substr($b_row['start_time'], 0, 2);
    $b_en = (int)substr($b_row['end_time'], 0, 2);
    for($hr = $b_st; $hr < $b_en; $hr++){
      $busyHoursMap[$hr] = true;
    }
  }

  foreach($fields as $f_item){
    if($f_item['id'] == $fid){ $cur_field = $f_item; break; }
  }
}

$active = 'book'; 
$title = 'Đặt sân bóng - ' . ($cur_field ? $cur_field['name'] : 'Chọn sân'); 
include '../includes/header.php'; 
?>

<!-- 4. Banner Tiêu Đề Phong Cách Thể Thao -->
<div class="sport-page-head">
  <div>
    <h2>⚡ SƠ ĐỒ ĐẶT SÂN TRỰC TUYẾN</h2>
    <small>Bấm chọn trực tiếp các ô giờ trống trên sơ đồ sân đấu, thêm dịch vụ và nhận vé điện tử QR</small>
  </div>
  <div style="display:flex;gap:10px">
    <a class="btn sm outline" style="border-color:#4ade80;color:#4ade80 !important" href="matchmaking.php">⚔️ Sàn Cáp Kèo</a>
    <button type="button" class="btn bl sm" onclick="toggleAiChat()">🤖 Nhờ AI tìm sân trống</button>
  </div>
</div>

<!-- Bộ chọn Sân & Ngày thi đấu -->
<div class="box" style="margin-bottom:20px;padding:20px;border-left:5px solid #16a34a">
  <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;align-items:end">
    <div>
      <label style="margin:0 0 6px;font-weight:700;color:#0f172a">🏟 Bước 1: Chọn Sân Bóng Thi Đấu</label>
      <select name="field_id" onchange="this.form.submit()" style="padding:12px;font-size:14px;border:2px solid #cbd5e1;border-radius:10px;width:100%;font-weight:600">
        <option value="">-- Chọn sân bóng trong hệ thống --</option>
        <?php foreach($fields as $f): ?>
          <option value="<?=$f['id']?>" <?=$f['id']==$fid?'selected':''?>>
            ⚽ <?=e($f['name'])?> (Sân <?=$f['type']?>v<?=$f['type']?> - <?=e($f['district'])?>) — <?=money($f['price_per_hour'])?>/giờ
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="margin:0 0 6px;font-weight:700;color:#0f172a">📅 Bước 2: Chọn Ngày Ra Sân</label>
      <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($sel)?>" onchange="this.form.submit()" style="padding:11px 12px;font-size:14px;border:2px solid #cbd5e1;border-radius:10px;width:100%;font-weight:600">
    </div>
  </form>
</div>

<!-- 5. Sơ đồ khung giờ trực quan & Biểu mẫu đặt sân -->
<?php if($fid && $cur_field): ?>
  <div style="display:grid;grid-template-columns:1.45fr 1fr;gap:24px;align-items:start">
    
    <div class="box">
      <!-- Sơ đồ mặt sân cỏ trực quan (Tactical Pitch Visual) -->
      <div class="tactical-pitch">
        <div class="tactical-pitch-inner">
          <div>
            <span style="background:#0f172a;color:#4ade80;font-family:var(--font-sport);font-size:11px;font-weight:800;padding:3px 9px;border-radius:4px">SÂN TIÊU CHUẨN <?=$cur_field['type']?>v<?=$cur_field['type']?></span>
            <h3 style="margin:6px 0 2px;font-family:var(--font-sport);font-size:22px;color:#fff"><?=e($cur_field['name'])?></h3>
            <small style="color:#dcfce7;margin:0">📍 <?=e($cur_field['address'])?></small>
          </div>
          <div style="text-align:right">
            <small style="color:#bbf7d0;margin:0">ĐƠN GIÁ THUÊ SÂN</small>
            <div style="font-family:var(--font-sport);font-size:26px;font-weight:800;color:#ffffff"><?=money($cur_field['price_per_hour'])?><span style="font-size:13px">/h</span></div>
          </div>
        </div>
      </div>

      <form method="post" id="bookingForm">
        <?=csrf_field()?>
        <input type="hidden" name="field_id" value="<?=$fid?>">
        <input type="hidden" name="date" value="<?=e($sel)?>">

        <h4 style="margin:0 0 10px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase">
          1. Bấm Chọn Khung Giờ Trên Sơ Đồ (Ngày <?=date('d/m/Y',strtotime($sel))?>)
        </h4>

        <!-- Chú thích màu sắc ô giờ -->
        <div class="slot-legend">
          <span><i class="slot-dot free"></i> Còn trống</span>
          <span><i class="slot-dot selected"></i> Đang chọn</span>
          <span><i class="slot-dot peak"></i> 🔥 Giờ Vàng (17h-21h)</span>
          <span><i class="slot-dot busy"></i> Đã kín / Đã qua</span>
        </div>

        <!-- Lưới chọn khung giờ trực quan (Interactive Time-Slot Grid) -->
        <div class="slot-grid" id="interactiveSlotGrid">
          <?php 
          $nowHour = (int)date('H');
          $isToday = ($sel === date('Y-m-d'));
          for($h=6; $h<=22; $h++): 
            $hNext = $h + 1;
            $label = sprintf('%02d:00 - %02d:00', $h, $hNext);
            $isPast = ($isToday && $h <= $nowHour);
            $isBusy = !empty($busyHoursMap[$h]) || $isPast;
            $isPeak = ($h >= 17 && $h <= 20);
          ?>
            <div class="slot-btn <?=$isBusy?'busy':($isPeak?'peak':'free')?>" 
                 data-hour="<?=$h?>" 
                 data-busy="<?=$isBusy?'1':'0'?>"
                 onclick="handleSlotClick(<?=$h?>, <?=$isBusy?'true':'false'?>)">
              <?php if($isPeak && !$isBusy): ?>
                <span class="slot-peak-badge">🔥 Hot</span>
              <?php endif; ?>
              <span class="slot-time"><?=$label?></span>
              <span class="slot-sub"><?=$isBusy ? ($isPast ? 'Đã qua' : 'Đã đặt') : money($cur_field['price_per_hour'])?></span>
            </div>
          <?php endfor; ?>
        </div>

        <!-- Hai ô Select giờ bắt đầu / kết thúc đồng bộ hai chiều với lưới chọn giờ -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0">
          <div>
            <label style="margin:0 0 4px;font-size:12.5px;font-weight:700">Giờ bắt đầu nhận sân</label>
            <select name="start" id="selStart" onchange="syncGridFromSelect()" style="padding:9px;border-radius:8px;width:100%;font-weight:700">
              <?php for($h=6;$h<=22;$h++): $v=sprintf('%02d:00',$h); ?>
                <option value="<?=$v?>" <?=$h==17?'selected':''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label style="margin:0 0 4px;font-size:12.5px;font-weight:700">Giờ kết thúc trả sân</label>
            <select name="end" id="selEnd" onchange="syncGridFromSelect()" style="padding:9px;border-radius:8px;width:100%;font-weight:700">
              <?php for($h=7;$h<=23;$h++): $v=sprintf('%02d:00',$h); ?>
                <option value="<?=$v?>" <?=$h==19?'selected':''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          2. Dịch vụ tiện ích đi kèm (Tùy chọn)
        </h4>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
          <?php if(!$services): ?>
            <small>Hiện chưa có dịch vụ cộng thêm.</small>
          <?php else: ?>
            <?php foreach($services as $svc): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 16px;border-radius:10px">
                <div>
                  <b style="font-size:14px;color:#1e293b">🥤 <?=e($svc['name'])?></b>
                  <small style="color:#64748b"><?=e($svc['description'])?></small>
                  <small style="color:#16a34a;font-weight:700"><?=money($svc['price'])?> / đơn vị</small>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:12px;color:#64748b">SL:</span>
                  <input type="number" min="0" max="20" name="services[<?=$svc['id']?>]" value="0" class="svc-input" data-price="<?=$svc['price']?>" oninput="recalcPrice()" style="width:68px;padding:6px 10px;border-radius:6px;border:1.5px solid #cbd5e1;text-align:center;margin:0;font-weight:700">
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Tính năng mới: Bật tìm đối giao hữu / cáp kèo tự động -->
        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          3. Sàn Cáp Kèo / Tìm Đối Giao Hữu (Tùy chọn)
        </h4>
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:14px 16px;margin-bottom:20px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;font-weight:700;color:#14532d">
            <input type="checkbox" name="enable_matchmaking" value="1" id="chkMatchmaking" onchange="document.getElementById('matchmakingFields').style.display=this.checked?'grid':'none'" style="width:18px;height:18px;margin:0">
            <span>⚔️ Đăng lịch đặt sân này lên Sàn Cáp Kèo để tìm đối thủ giao hữu / tuyển thêm cầu thủ</span>
          </label>
          <div id="matchmakingFields" style="display:none;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;padding-top:14px;border-top:1px dashed #86efac">
            <div>
              <label style="font-size:12px;font-weight:700;margin:0 0 4px">Tên đội bóng của bạn (FC)</label>
              <input name="team_name" placeholder="VD: FC Anh Em Sài Gòn" value="FC <?=e($u['full_name'])?>" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;width:100%">
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;margin:0 0 4px">Hình thức ghép kèo</label>
              <select name="match_type" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;width:100%">
                <option value="tim_doi">⚔️ Tìm đội cáp kèo đá giao hữu</option>
                <option value="tuyen_nguoi">🏃 Tuyển thêm cầu thủ đá ghép</option>
              </select>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;margin:0 0 4px">Trình độ mong muốn</label>
              <select name="skill_level" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;width:100%">
                <option value="phong_trao">⚽ Phong trào (Vừa sức)</option>
                <option value="vui_ve">😊 Vui vẻ / Văn phòng rèn sức khỏe</option>
                <option value="ban_chuyen">🔥 Bán chuyên / Đối cứng</option>
              </select>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;margin:0 0 4px">Thỏa thuận chia tiền sân</label>
              <input name="fee_share" value="Chia đôi 50/50" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;width:100%">
            </div>
          </div>
        </div>

        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          4. Mã giảm giá (Voucher) & Phương thức Thanh toán
        </h4>
        <div style="margin-bottom:16px">
          <label style="margin:0 0 6px;font-weight:600">Mã giảm giá (Voucher)</label>
          <div style="display:flex;gap:8px">
            <input name="voucher_code" id="voucherInput" placeholder="Nhập mã (VD: SANBONG10, GIAM30K)" style="padding:10px 14px;border-radius:8px;margin:0;flex:1">
            <button type="button" class="btn bl" onclick="applyVoucher()" style="white-space:nowrap">Áp dụng mã</button>
          </div>
          <div id="voucherMsg" style="margin-top:6px;font-size:12.5px"></div>

          <?php if($vouchers_active): ?>
            <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
              <small style="margin:0;color:#64748b">🎁 Chọn nhanh Voucher:</small>
              <?php foreach($vouchers_active as $va): ?>
                <button type="button" onclick="selectVoucherCode('<?=e($va['code'])?>')" style="border:1px dashed #16a34a;background:#f0fdf4;color:#15803d;border-radius:6px;padding:4px 9px;font-size:11.5px;font-weight:700;cursor:pointer">
                  <?=e($va['code'])?> (<?=$va['discount_type']==='percent' ? '-'.$va['discount_value'].'%' : '-'.money($va['discount_value'])?>)
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="margin-bottom:16px">
          <label style="margin:0 0 6px;font-weight:600">Ghi chú cho Ban Quản Lý Sân (Tùy chọn)</label>
          <input name="note" placeholder="Ví dụ: Đội mình cần chuẩn bị sẵn 2 quả bóng Động Lực..." style="padding:10px 14px;border-radius:8px;width:100%;border:1.5px solid #cbd5e1">
        </div>

        <div style="margin-bottom:24px">
          <label style="margin:0 0 8px;font-weight:600">Phương thức thanh toán</label>
          <select name="payment_method" style="padding:12px;border-radius:8px;width:100%;border:1.5px solid #cbd5e1;font-weight:600">
            <option value="bank">🏦 Chuyển khoản Ngân hàng (VietQR Tự động - Cổng Demo)</option>
            <option value="momo">📱 Ví điện tử MoMo (Quét QR / Giả lập Demo)</option>
            <option value="vnpay">💳 Cổng thanh toán VNPay / Thẻ ATM nội địa (Demo)</option>
            <option value="cash">💵 Thanh toán trực tiếp bằng Tiền mặt tại sân</option>
          </select>
        </div>

        <button class="btn" style="width:100%;padding:15px;font-size:15.5px;font-weight:800;text-transform:uppercase;letter-spacing:0.4px" type="submit">
          ⚡ Xác Nhận Đặt Sân & Nhận Vé Điện Tử QR →
        </button>
      </form>
    </div>

    <!-- Cột phải: Phiếu tóm tắt chi phí Realtime -->
    <div class="box" style="position:sticky;top:88px;border-top:4px solid #16a34a">
      <div style="overflow:hidden;border-radius:12px;height:160px;margin-bottom:14px;position:relative">
        <img src="<?=e(img_src($cur_field['image']))?>" alt="<?=e($cur_field['name'])?>" style="width:100%;height:100%;object-fit:cover">
        <span style="position:absolute;bottom:10px;left:10px;background:#0f172a;color:#4ade80;font-family:var(--font-sport);font-weight:700;font-size:12px;padding:3px 10px;border-radius:6px">SÂN <?=$cur_field['type']?> NGƯỜI</span>
      </div>
      <h3 style="margin:0 0 6px;font-family:var(--font-sport);font-size:20px;color:#0f172a"><?=e($cur_field['name'])?></h3>
      <small style="color:#64748b;font-size:13px">📍 <?=e($cur_field['address'])?></small>
      <div style="display:flex;gap:8px;margin:10px 0">
        <span class="tag green">⚽ Cỏ FIFA Quality</span>
        <span class="tag blue">⭐ <?=e($cur_field['rating'])?> (<?=$cur_field['review_count']?>)</span>
      </div>

      <div style="border-top:1px solid #e2e8f0;margin-top:14px;padding-top:14px">
        <h4 style="margin:0 0 10px;font-family:var(--font-sport);font-size:15.5px;color:#0f172a;text-transform:uppercase">🧾 Bảng Tính Chi Phí Trận Đấu</h4>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Khung giờ đã chọn:</span>
          <b id="summarySlotRange" style="color:#16a34a">17:00 - 19:00</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Đơn giá thuê sân:</span>
          <b><?=money($cur_field['price_per_hour'])?> / giờ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Thời lượng đá:</span>
          <b id="summaryHours">2 giờ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Tiền thuê sân:</span>
          <b id="summaryFieldCost">0đ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Dịch vụ đi kèm:</span>
          <b id="summarySvcCost">0đ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px;color:#ef4444">
          <span>Giảm giá Voucher:</span>
          <b id="summaryDiscount">-0đ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:12px;border-top:2px dashed #cbd5e1;font-size:16px;align-items:center">
          <b style="color:#0f172a">TỔNG THANH TOÁN:</b>
          <b id="summaryTotal" style="color:#16a34a;font-family:var(--font-sport);font-size:24px">0đ</b>
        </div>
      </div>

      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px;margin-top:16px">
        <small style="color:#14532d;font-weight:700;margin:0">🎫 Cấp Vé Đặt Sân QR Tức Thì:</small>
        <span style="font-size:12px;color:#166534;display:block;margin-top:4px">
          Sau khi đặt sân, bạn sẽ nhận được <b>Vé Thi Đấu Điện Tử (Match Pass)</b> kèm mã QR Check-in tại sân và biên lai xác nhận.
        </span>
      </div>
    </div>

  </div>

<!-- 6. Script xử lý chọn ô giờ trực quan (Interactive Time-Slot Grid) & Tính tiền Realtime -->
<script>
const PRICE_PER_HOUR = <?=(int)$cur_field['price_per_hour']?>;
const CHECK_VOUCHER_URL = <?=json_encode(project_base() . 'api/check_voucher.php')?>;
let currentVoucher = null;
let clickAnchorHour = null;

function formatVND(n) {
  return new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(n))) + 'đ';
}

// Xử lý khi người dùng bấm vào một ô giờ trên sơ đồ
function handleSlotClick(hour, isBusy) {
  if (isBusy) return;
  const selStart = document.getElementById('selStart');
  const selEnd = document.getElementById('selEnd');

  if (clickAnchorHour === null || hour < clickAnchorHour) {
    // Chọn giờ bắt đầu mới (mặc định 1 tiếng)
    clickAnchorHour = hour;
    selStart.value = String(hour).padStart(2, '0') + ':00';
    selEnd.value = String(hour + 1).padStart(2, '0') + ':00';
  } else if (hour === clickAnchorHour) {
    // Bấm lại vào cùng ô -> giữ 1 tiếng
    selStart.value = String(hour).padStart(2, '0') + ':00';
    selEnd.value = String(hour + 1).padStart(2, '0') + ':00';
    clickAnchorHour = null;
  } else {
    // Kiểm tra xem từ clickAnchorHour đến hour có ô nào bị bận không
    let blocked = false;
    for (let h = clickAnchorHour; h <= hour; h++) {
      const btn = document.querySelector(`.slot-btn[data-hour="${h}"]`);
      if (btn && btn.dataset.busy === '1') { blocked = true; break; }
    }
    if (blocked) {
      clickAnchorHour = hour;
      selStart.value = String(hour).padStart(2, '0') + ':00';
      selEnd.value = String(hour + 1).padStart(2, '0') + ':00';
    } else {
      selStart.value = String(clickAnchorHour).padStart(2, '0') + ':00';
      selEnd.value = String(hour + 1).padStart(2, '0') + ':00';
      clickAnchorHour = null;
    }
  }
  syncGridFromSelect();
}

// Đồng bộ trạng thái tô sáng (selected) của các ô giờ từ 2 thẻ select
function syncGridFromSelect() {
  const st = parseInt(document.getElementById('selStart').value.split(':')[0], 10);
  const en = parseInt(document.getElementById('selEnd').value.split(':')[0], 10);
  document.querySelectorAll('.slot-btn').forEach(btn => {
    const h = parseInt(btn.dataset.hour, 10);
    if (btn.dataset.busy !== '1' && h >= st && h < en) {
      btn.classList.add('selected');
    } else {
      btn.classList.remove('selected');
    }
  });
  recalcPrice();
}

function getSubtotal() {
  const stStr = document.getElementById('selStart').value;
  const enStr = document.getElementById('selEnd').value;
  const st = parseInt(stStr.split(':')[0], 10);
  const en = parseInt(enStr.split(':')[0], 10);
  const hours = Math.max(0, en - st);
  const fieldCost = hours * PRICE_PER_HOUR;

  let svcCost = 0;
  document.querySelectorAll('.svc-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || '0', 10));
    const pr = parseInt(inp.dataset.price || '0', 10);
    svcCost += qty * pr;
  });

  return { stStr, enStr, hours, fieldCost, svcCost, subtotal: fieldCost + svcCost };
}

function recalcPrice() {
  const { stStr, enStr, hours, fieldCost, svcCost, subtotal } = getSubtotal();
  document.getElementById('summarySlotRange').textContent = hours > 0 ? `${stStr} - ${enStr}` : 'Chưa hợp lệ';
  document.getElementById('summaryHours').textContent = hours > 0 ? (hours + ' giờ') : 'Khung giờ chưa hợp lệ';
  document.getElementById('summaryFieldCost').textContent = formatVND(fieldCost);
  document.getElementById('summarySvcCost').textContent = formatVND(svcCost);

  let discount = 0;
  if (currentVoucher && subtotal >= currentVoucher.min_order) {
    if (currentVoucher.discount_type === 'percent') {
      discount = Math.round((subtotal * currentVoucher.discount_value) / 100);
      if (currentVoucher.max_discount > 0 && discount > currentVoucher.max_discount) {
        discount = currentVoucher.max_discount;
      }
    } else {
      discount = currentVoucher.discount_value;
    }
    if (discount > subtotal) discount = subtotal;
  }

  document.getElementById('summaryDiscount').textContent = '-' + formatVND(discount);
  document.getElementById('summaryTotal').textContent = formatVND(subtotal - discount);
}

function selectVoucherCode(code) {
  document.getElementById('voucherInput').value = code;
  applyVoucher();
}

async function applyVoucher() {
  const code = document.getElementById('voucherInput').value.trim();
  const msgEl = document.getElementById('voucherMsg');
  const { subtotal } = getSubtotal();

  if (!code) {
    currentVoucher = null;
    msgEl.innerHTML = '<span style="color:#ef4444">⚠️ Vui lòng nhập mã giảm giá.</span>';
    recalcPrice();
    return;
  }

  try {
    const res = await fetch(`${CHECK_VOUCHER_URL}?code=${encodeURIComponent(code)}&subtotal=${subtotal}`);
    const data = await res.json();
    if (data.ok) {
      currentVoucher = data;
      msgEl.innerHTML = `<span style="color:#16a34a;font-weight:700">✔ ${data.message} (-${formatVND(data.discount_amount)})</span>`;
    } else {
      currentVoucher = null;
      msgEl.innerHTML = `<span style="color:#ef4444">❌ ${data.message}</span>`;
    }
    recalcPrice();
  } catch (e) {
    msgEl.innerHTML = '<span style="color:#ef4444">⚠️ Lỗi kiểm tra mã giảm giá.</span>';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  // Tự động chọn ô giờ trống đầu tiên từ 17h nếu 17h-19h bị bận
  const firstFree = document.querySelector('.slot-btn[data-busy="0"]');
  const slot17 = document.querySelector('.slot-btn[data-hour="17"][data-busy="0"]');
  if (!slot17 && firstFree) {
    const h = parseInt(firstFree.dataset.hour, 10);
    document.getElementById('selStart').value = String(h).padStart(2, '0') + ':00';
    document.getElementById('selEnd').value = String(h + 1).padStart(2, '0') + ':00';
  }
  syncGridFromSelect();
});
</script>
<?php else: ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:52px;margin-bottom:12px">🏟</div>
    <h3 style="color:#0f172a;font-family:var(--font-sport);font-size:22px;margin:0 0 8px">CHỌN SÂN BÓNG ĐỂ MỞ SƠ ĐỒ GIỜ TRỐNG</h3>
    <small style="color:#64748b;font-size:14px">Hệ thống sẽ hiển thị sơ đồ mặt sân cỏ, các ô giờ trống trong ngày và bảng tính chi phí tự động.</small>
  </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
