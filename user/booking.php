<?php
/**
 * FILE: user/booking.php
 * MÔ TẢ: Trang Đặt Sân Bóng Trực Tuyến dành cho khách hàng.
 * CHỨC NĂNG: Chọn sân, kiểm tra khung giờ trống, chọn dịch vụ tiện ích đi kèm, áp dụng mã giảm giá Voucher
 *            (tính tiền Realtime bằng JS) và khởi tạo đơn đặt sân + giao dịch thanh toán trong Transaction.
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

// 3. Lấy danh sách các khung giờ đã đặt của sân đang chọn để hiển thị trực quan
$sel = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'); 
$busy = [];
$cur_field = null;
if($fid){ 
  $s = $pdo->prepare("SELECT start_time,end_time FROM bookings WHERE field_id=? AND booking_date=? AND status IN('pending','confirmed','completed') ORDER BY start_time"); 
  $s->execute([$fid,$sel]); 
  $busy = $s->fetchAll(); 

  foreach($fields as $f_item){
    if($f_item['id'] == $fid){ $cur_field = $f_item; break; }
  }
}

$active = 'book'; 
$title = 'Đặt sân bóng - ' . ($cur_field ? $cur_field['name'] : 'Chọn sân'); 
include '../includes/header.php'; 
?>

<!-- 4. Giao diện chọn sân bóng và ngày thi đấu -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0 0 6px;font-size:24px;font-weight:800;color:#0f172a">⚡ Đặt Sân Bóng Trực Tuyến</h2>
    <small style="font-size:13.5px;color:#64748b">Chọn sân yêu thích, khung giờ thi đấu, áp mã giảm giá và trải nghiệm Thanh toán Demo</small>
  </div>
  <button type="button" class="btn bl sm" onclick="toggleAiChat()">🤖 Nhờ AI tư vấn chọn sân</button>
</div>

<div class="box" style="margin-bottom:20px;padding:20px">
  <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;align-items:end">
    <div>
      <label style="margin:0 0 6px;font-weight:700">⚽ Chọn sân bóng:</label>
      <select name="field_id" onchange="this.form.submit()" style="padding:12px;font-size:14px;border:1.5px solid #cbd5e1;border-radius:10px;width:100%">
        <option value="">-- Danh sách sân bóng --</option>
        <?php foreach($fields as $f): ?>
          <option value="<?=$f['id']?>" <?=$f['id']==$fid?'selected':''?>>
            <?=e($f['name'])?> (Sân <?=$f['type']?> người - <?=e($f['district'])?>) - <?=money($f['price_per_hour'])?>/h
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="margin:0 0 6px;font-weight:700">📅 Ngày thi đấu:</label>
      <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($sel)?>" onchange="this.form.submit()" style="padding:11px 12px;font-size:14px;border:1.5px solid #cbd5e1;border-radius:10px;width:100%">
    </div>
  </form>
</div>

<!-- 5. Biểu mẫu chi tiết đặt sân & Bảng tính tiền tự động bên phải -->
<?php if($fid && $cur_field): ?>
  <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:24px;align-items:start">
    
    <div class="box">
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:20px">
        <b style="color:#0f172a;font-size:13.5px">🕒 Tình trạng khung giờ ngày <?=date('d/m/Y',strtotime($sel))?>:</b>
        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">
          <?php if(!$busy): ?>
            <span class="tag green">🟢 Đang còn trống tất cả các khung giờ (06:00 - 23:00)</span>
          <?php else: ?>
            <?php foreach($busy as $b): ?>
              <span class="tag amber">⛔ <?=substr($b['start_time'],0,5)?> - <?=substr($b['end_time'],0,5)?> (Đã đặt)</span>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" id="bookingForm">
        <?=csrf_field()?>
        <input type="hidden" name="field_id" value="<?=$fid?>">
        <input type="hidden" name="date" value="<?=e($sel)?>">

        <h4 style="margin:0 0 14px;color:#0f172a;font-size:16px;border-bottom:1px solid #f1f5f9;padding-bottom:8px">1. Chọn khung giờ thi đấu</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">
          <div>
            <label style="margin:0 0 6px">Giờ bắt đầu</label>
            <select name="start" id="selStart" onchange="recalcPrice()" style="padding:10px;border-radius:8px;width:100%">
              <?php for($h=6;$h<=22;$h++): $v=sprintf('%02d:00',$h); ?>
                <option value="<?=$v?>" <?=$h==17?'selected':''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label style="margin:0 0 6px">Giờ kết thúc</label>
            <select name="end" id="selEnd" onchange="recalcPrice()" style="padding:10px;border-radius:8px;width:100%">
              <?php for($h=7;$h<=23;$h++): $v=sprintf('%02d:00',$h); ?>
                <option value="<?=$v?>" <?=$h==19?'selected':''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <h4 style="margin:20px 0 12px;color:#0f172a;font-size:16px;border-bottom:1px solid #f1f5f9;padding-bottom:8px">2. Dịch vụ tiện ích đi kèm (Tùy chọn)</h4>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
          <?php if(!$services): ?>
            <small>Hiện chưa có dịch vụ cộng thêm.</small>
          <?php else: ?>
            <?php foreach($services as $svc): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 16px;border-radius:10px">
                <div>
                  <b style="font-size:14px;color:#1e293b"><?=e($svc['name'])?></b>
                  <small style="color:#64748b"><?=e($svc['description'])?></small>
                  <small style="color:#059669;font-weight:700"><?=money($svc['price'])?> / đơn vị</small>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:12px;color:#64748b">SL:</span>
                  <input type="number" min="0" max="20" name="services[<?=$svc['id']?>]" value="0" class="svc-input" data-price="<?=$svc['price']?>" oninput="recalcPrice()" style="width:68px;padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;text-align:center;margin:0">
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <h4 style="margin:20px 0 12px;color:#0f172a;font-size:16px;border-bottom:1px solid #f1f5f9;padding-bottom:8px">3. Mã giảm giá (Voucher) & Thanh toán Demo</h4>
        <div style="margin-bottom:16px">
          <label style="margin:0 0 6px">Mã giảm giá (Voucher)</label>
          <div style="display:flex;gap:8px">
            <input name="voucher_code" id="voucherInput" placeholder="Nhập mã (VD: SANBONG10, GIAM30K)" style="padding:10px 14px;border-radius:8px;margin:0;flex:1">
            <button type="button" class="btn bl" onclick="applyVoucher()" style="white-space:nowrap">Áp dụng mã</button>
          </div>
          <div id="voucherMsg" style="margin-top:6px;font-size:12.5px"></div>

          <?php if($vouchers_active): ?>
            <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
              <small style="margin:0;color:#64748b">🎁 Chọn nhanh:</small>
              <?php foreach($vouchers_active as $va): ?>
                <button type="button" onclick="selectVoucherCode('<?=e($va['code'])?>')" style="border:1px dashed #10b981;background:#ecfdf5;color:#065f46;border-radius:6px;padding:3px 8px;font-size:11.5px;font-weight:700;cursor:pointer">
                  <?=e($va['code'])?> (<?=$va['discount_type']==='percent' ? '-'.$va['discount_value'].'%' : '-'.money($va['discount_value'])?>)
                </button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="margin-bottom:16px">
          <label style="margin:0 0 6px">Ghi chú cho chủ sân (Tùy chọn)</label>
          <input name="note" placeholder="Ví dụ: Đội mình cần chuẩn bị sẵn 2 quả bóng..." style="padding:10px 14px;border-radius:8px">
        </div>

        <div style="margin-bottom:24px">
          <label style="margin:0 0 8px">Phương thức thanh toán</label>
          <select name="payment_method" style="padding:12px;border-radius:8px">
            <option value="bank">🏦 Chuyển khoản Ngân hàng (VietQR Tự động - Cổng Demo)</option>
            <option value="momo">📱 Ví điện tử MoMo (Quét QR / Giả lập Demo)</option>
            <option value="vnpay">💳 Cổng thanh toán VNPay / Thẻ ATM nội địa (Demo)</option>
            <option value="cash">💵 Thanh toán trực tiếp bằng Tiền mặt tại sân</option>
          </select>
        </div>

        <button class="btn" style="width:100%;padding:14px;font-size:15px;font-weight:700" type="submit">
          ⚡ Tiếp tục & Thanh toán đơn đặt sân →
        </button>
      </form>
    </div>

    <div class="box" style="position:sticky;top:88px">
      <div style="overflow:hidden;border-radius:12px;height:160px;margin-bottom:14px">
        <img src="<?=e(img_src($cur_field['image']))?>" alt="<?=e($cur_field['name'])?>" style="width:100%;height:100%;object-fit:cover">
      </div>
      <h3 style="margin:0 0 6px;font-size:18px;color:#0f172a"><?=e($cur_field['name'])?></h3>
      <small style="color:#64748b;font-size:13px">📍 <?=e($cur_field['address'])?></small>
      <div style="display:flex;gap:8px;margin:10px 0">
        <span class="tag green">Sân <?=e($cur_field['type'])?> người</span>
        <span class="tag blue">⭐ <?=e($cur_field['rating'])?></span>
      </div>

      <div style="border-top:1px solid #e2e8f0;margin-top:14px;padding-top:14px">
        <h4 style="margin:0 0 10px;font-size:14.5px;color:#0f172a">🧾 Tóm tắt chi phí dự kiến</h4>
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
        <div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:12px;border-top:1.5px dashed #cbd5e1;font-size:16px">
          <b style="color:#0f172a">Tổng thanh toán:</b>
          <b id="summaryTotal" style="color:#059669;font-size:20px">0đ</b>
        </div>
      </div>

      <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px;margin-top:16px">
        <small style="color:#064e3b;font-weight:700;margin:0">💡 Hỗ trợ Thanh toán Demo:</small>
        <span style="font-size:12px;color:#047857;display:block;margin-top:4px">
          Sau khi bấm đặt sân, hệ thống mở Cổng Thanh Toán Demo (VietQR, MoMo, VNPay) cho phép xác nhận thanh toán tức thì chỉ với 1 cú nhấp chuột.
        </span>
      </div>
    </div>

  </div>

<!-- 6. Script tính toán tổng chi phí Realtime và kiểm tra mã giảm giá qua AJAX -->
<script>
const PRICE_PER_HOUR = <?=(int)$cur_field['price_per_hour']?>;
const CHECK_VOUCHER_URL = <?=json_encode(project_base() . 'api/check_voucher.php')?>;
let currentVoucher = null;

function formatVND(n) {
  return new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(n))) + 'đ';
}

function getSubtotal() {
  const st = parseInt(document.getElementById('selStart').value.split(':')[0], 10);
  const en = parseInt(document.getElementById('selEnd').value.split(':')[0], 10);
  const hours = Math.max(0, en - st);
  const fieldCost = hours * PRICE_PER_HOUR;

  let svcCost = 0;
  document.querySelectorAll('.svc-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || '0', 10));
    const pr = parseInt(inp.dataset.price || '0', 10);
    svcCost += qty * pr;
  });

  return { hours, fieldCost, svcCost, subtotal: fieldCost + svcCost };
}

function recalcPrice() {
  const { hours, fieldCost, svcCost, subtotal } = getSubtotal();
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
      msgEl.innerHTML = `<span style="color:#059669;font-weight:700">✔ ${data.message} (-${formatVND(data.discount_amount)})</span>`;
    } else {
      currentVoucher = null;
      msgEl.innerHTML = `<span style="color:#ef4444">❌ ${data.message}</span>`;
    }
    recalcPrice();
  } catch (e) {
    msgEl.innerHTML = '<span style="color:#ef4444">⚠️ Lỗi kiểm tra mã giảm giá.</span>';
  }
}

document.addEventListener('DOMContentLoaded', recalcPrice);
</script>
<?php else: ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:48px;margin-bottom:12px">⚽</div>
    <h3 style="color:#0f172a;margin:0 0 8px">Vui lòng chọn sân bóng ở phía trên</h3>
    <small style="color:#64748b;font-size:14px">Hệ thống sẽ hiển thị lịch trống, dịch vụ tiện ích và bảng tính chi phí tự động.</small>
  </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
