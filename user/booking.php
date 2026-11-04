<?php
/**
 * ============================================================================
 * FILE: user/booking.php
 * MÔ TẢ: Trang Đặt Sân Bóng Trực Tuyến tích hợp 5 Thuật Toán Lõi (Enterprise Edition):
 *   1. Ghép sân - Tách sân phân cấp (Hierarchical Pitch Mapping: 5 <-> 7 <-> 11).
 *   2. Lưới chọn khung giờ 30 phút (60p / 90p / 120p) & Phát hiện trùng lịch chéo.
 *   3. Giữ chỗ tạm thời 10 phút (HOLD Timeout) & Khóa Pessimistic chống Race Condition.
 *   4. Tính giá linh hoạt theo từng lát cắt thời gian & loại ngày (Dynamic Pricing Engine).
 *   5. Đặt lịch cố định theo chu kỳ hàng tuần (4 / 8 / 12 tuần - Ưu đãi 5%).
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu đăng nhập trước khi đặt sân
$u = require_login();

// ----------------------------------------------------------------------------
// PHẦN 1: TẢI DANH SÁCH SÂN BÓNG, DỊCH VỤ & MÃ GIẢM GIÁ VOUCHER
// ----------------------------------------------------------------------------
$fields = $pdo->query("SELECT * FROM fields WHERE status = 'active' ORDER BY type ASC, id ASC")->fetchAll();
$services = $pdo->query("SELECT * FROM services WHERE status = 'active'")->fetchAll();
$vouchers_active = $pdo->query("
    SELECT * FROM vouchers
    WHERE status = 'active'
      AND expired_at >= CURDATE()
      AND used_count < usage_limit
    ORDER BY discount_value DESC
")->fetchAll();

$fid = (int)($_POST['field_id'] ?? $_GET['field_id'] ?? ($fields[0]['id'] ?? 0));

// ----------------------------------------------------------------------------
// PHẦN 2: XỬ LÝ TẠO ĐƠN ĐẶT SÂN (ĐẶT LẺ HOẶC HỢP ĐỒNG CỐ ĐỊNH HÀNG TUẦN)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $date         = $_POST['date'] ?? '';
    $st           = $_POST['start'] ?? '';
    $en           = $_POST['end'] ?? '';
    $note         = trim($_POST['note'] ?? '');
    $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
    $req_services = $_POST['services'] ?? [];
    $method       = in_array($_POST['payment_method'] ?? '', ['bank', 'momo', 'vnpay', 'cash'])
                    ? $_POST['payment_method']
                    : 'bank';
    $booking_mode = $_POST['booking_mode'] ?? 'single'; // 'single' hoặc 'recurring'

    // 2.1. Kiểm tra định dạng ngày và bước nhảy 30 phút (:00 hoặc :30)
    $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
       && preg_match('/^\d{2}:(00|30)$/', $st)
       && preg_match('/^\d{2}:(00|30)$/', $en);

    $startTs = strtotime("$date $st:00");
    $endTs   = strtotime("$date $en:00");

    if (!$ok || !$startTs || !$endTs || $endTs <= $startTs) {
        flash('err', 'Khung giờ không hợp lệ (Giờ kết thúc phải lớn hơn giờ bắt đầu, đơn vị 30 phút).');
    } elseif (($endTs - $startTs) < 3600) {
        flash('err', 'Thời lượng đặt sân tối thiểu là 60 phút (1 giờ).');
    } elseif ($startTs <= time()) {
        flash('err', 'Không thể đặt khung giờ đã qua.');
    }
    // 2.2. XỬ LÝ NẾU KHÁCH CHỌN "ĐẶT LỊCH CỐ ĐỊNH HÀNG TUẦN" (RECURRING CONTRACT)
    elseif ($booking_mode === 'recurring') {
        $weeks_count = max(2, min(12, (int)($_POST['weeks_count'] ?? 4)));
        $res = create_recurring_booking_contract(
            $u['id'],
            $fid,
            $date,
            $st,
            $en,
            $weeks_count,
            $method,
            $note
        );

        if (!$res['ok']) {
            flash('err', $res['message']);
        } else {
            if ($method === 'cash') {
                flash(
                    'ok',
                    "🎉 Đã đăng ký Hợp đồng lịch cố định #{$res['contract_id']} ({$res['weeks_count']} tuần) thành công! " .
                    "Tổng giá trị hợp đồng (đã giảm 5%): " . money($res['total_price'])
                );
                header('Location: my-bookings.php');
            } else {
                flash(
                    'ok',
                    "🎉 Đã khởi tạo Hợp đồng lịch cố định #{$res['contract_id']} ({$res['weeks_count']} tuần)! " .
                    "Hệ thống đang giữ chỗ 10 phút (HOLD) để bạn xác nhận thanh toán."
                );
                header('Location: payment-demo.php?booking_id=' . $res['first_booking_id']);
            }
            exit;
        }
    }
    // 2.3. XỬ LÝ ĐẶT SÂN LẺ VỚI PESSIMISTIC LOCKING + KIỂM TRA GHÉP/TÁCH SÂN + GIÁ ĐỘNG
    else {
        $pdo->beginTransaction();
        try {
            // Bước A: Khóa Bi Quan (Pessimistic Lock) trên toàn bộ cụm sân liên quan (Cha + Con + Chính nó)
            // Sắp xếp ORDER BY f.id ASC để chống Deadlock tuyệt đối khi nhiều người đặt cùng lúc
            $stmtLock = $pdo->prepare("
                SELECT f.*
                FROM fields f
                INNER JOIN field_conflict_map fcm ON fcm.conflict_field_id = f.id
                WHERE fcm.field_id = ? AND f.status = 'active'
                ORDER BY f.id ASC
                FOR UPDATE
            ");
            $stmtLock->execute([$fid]);
            $lockedCluster = $stmtLock->fetchAll();

            $f = null;
            foreach ($lockedCluster as $lf) {
                if ((int)$lf['id'] === $fid) {
                    $f = $lf;
                    break;
                }
            }

            if (!$f) {
                $pdo->rollBack();
                flash('err', 'Sân bóng không tồn tại hoặc đang bảo trì.');
            } else {
                // Bước B: Kiểm tra trùng lịch trên toàn bộ tập sân Cha / Con (field_conflict_map)
                $avail = check_field_availability($fid, $date, $st, $en);

                if (!$avail['available']) {
                    $pdo->rollBack();
                    $c0 = $avail['conflicts'][0];
                    $relMsg = ($c0['relation_type'] === 'SELF')
                        ? 'Khung giờ này đã có người đặt.'
                        : "Khung giờ này bị khóa ghép/tách sân do {$c0['field_name']} đã có lịch (" .
                          substr($c0['start_time'], 0, 5) . '-' . substr($c0['end_time'], 0, 5) . ").";
                    flash('err', $relMsg);
                } else {
                    // Bước C: Tính tiền thuê sân bằng Dynamic Pricing Engine (theo phân đoạn 30 phút & loại ngày)
                    $pricing  = calculate_dynamic_price($fid, $date, $st, $en);
                    $subtotal = (int)$pricing['total_price'];

                    // Bước D: Tính tiền các dịch vụ tiện ích đi kèm
                    $service_total = 0;
                    $svc_to_insert = [];

                    if (!empty($req_services) && is_array($req_services)) {
                        $svc_ids  = array_keys($req_services);
                        $in       = str_repeat('?,', count($svc_ids) - 1) . '?';
                        $stmt_svc = $pdo->prepare("SELECT * FROM services WHERE id IN ($in)");
                        $stmt_svc->execute($svc_ids);
                        $svc_db = $stmt_svc->fetchAll();

                        foreach ($svc_db as $s_item) {
                            $qty = max(0, (int)($req_services[$s_item['id']] ?? 0));
                            if ($qty > 0) {
                                $item_cost      = $qty * $s_item['price'];
                                $service_total += $item_cost;
                                $svc_to_insert[] = [
                                    'id'    => $s_item['id'],
                                    'qty'   => $qty,
                                    'price' => $s_item['price']
                                ];
                            }
                        }
                    }

                    $total = $subtotal + $service_total;
                    $vid   = null;

                    // Bước E: Kiểm tra và áp dụng mã giảm giá (Voucher)
                    if ($voucher_code !== '') {
                        $vstmt = $pdo->prepare("
                            SELECT * FROM vouchers
                            WHERE code = ? AND status = 'active' AND expired_at >= CURDATE()
                            FOR UPDATE
                        ");
                        $vstmt->execute([$voucher_code]);
                        $v = $vstmt->fetch();

                        if ($v && $v['used_count'] < $v['usage_limit'] && $total >= $v['min_order']) {
                            $vid = $v['id'];
                            if ($v['discount_type'] === 'percent') {
                                $discount = (int)round(($total * $v['discount_value']) / 100);
                                if ($v['max_discount'] > 0 && $discount > $v['max_discount']) {
                                    $discount = (int)$v['max_discount'];
                                }
                            } else {
                                $discount = (int)$v['discount_value'];
                            }
                            $total = max(0, $total - $discount);
                            $pdo->prepare("UPDATE vouchers SET used_count = used_count + 1 WHERE id = ?")
                                ->execute([$vid]);
                        }
                    }

                    // Bước F: Thiết lập thời gian giữ chỗ 10 phút (HOLD Timeout) nếu thanh toán trực tuyến
                    $holdExpiresAt = ($method === 'cash')
                        ? null
                        : date('Y-m-d H:i:s', time() + 600); // 10 phút = 600 giây

                    $stmtIns = $pdo->prepare("
                        INSERT INTO bookings (
                            user_id, field_id, booking_date, start_time, end_time,
                            total_price, voucher_id, note, status, hold_expires_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
                    ");
                    $stmtIns->execute([
                        $u['id'], $fid, $date, $st . ':00', $en . ':00',
                        $total, $vid, $note, $holdExpiresAt
                    ]);
                    $bid = (int)$pdo->lastInsertId();

                    // Lưu danh sách dịch vụ đi kèm
                    foreach ($svc_to_insert as $s_ins) {
                        $pdo->prepare("
                            INSERT INTO booking_services (booking_id, service_id, quantity, price)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$bid, $s_ins['id'], $s_ins['qty'], $s_ins['price']]);
                    }

                    // Lưu bản ghi thanh toán
                    $pdo->prepare("
                        INSERT INTO payments (booking_id, method, amount, status)
                        VALUES (?, ?, ?, 'pending')
                    ")->execute([$bid, $method, $total]);

                    // Bước G: Nếu bật đăng lên Sàn Cáp Kèo / Tìm Đối Giao Hữu
                    if (!empty($_POST['enable_matchmaking'])) {
                        $team_name   = trim($_POST['team_name'] ?? '') ?: ('FC ' . $u['full_name']);
                        $match_type  = in_array($_POST['match_type'] ?? '', ['tim_doi', 'tuyen_nguoi']) ? $_POST['match_type'] : 'tim_doi';
                        $skill_level = in_array($_POST['skill_level'] ?? '', ['vui_ve', 'phong_trao', 'ban_chuyen']) ? $_POST['skill_level'] : 'phong_trao';
                        $fee_share   = trim($_POST['fee_share'] ?? 'Chia đôi 50/50') ?: 'Chia đôi 50/50';
                        $spots       = max(1, min(15, (int)($_POST['spots_needed'] ?? 1)));

                        $pdo->prepare("
                            INSERT INTO match_requests (
                                booking_id, creator_id, field_id, team_name, match_date,
                                start_time, end_time, skill_level, match_type, spots_needed,
                                fee_share, contact_phone, note, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')
                        ")->execute([
                            $bid, $u['id'], $fid, $team_name, $date,
                            $st . ':00', $en . ':00', $skill_level, $match_type, $spots,
                            $fee_share, $u['phone'], $note
                        ]);
                    }

                    notify(
                        $u['id'],
                        "Bạn đã khởi tạo đơn đặt {$f['name']} (#$bid) ngày " .
                        date('d/m/Y', strtotime($date)) . " ($st - $en). Tổng thanh toán: " . money($total)
                    );
                    $pdo->commit();

                    if ($method === 'cash') {
                        flash('ok', 'Đặt sân thành công! Đơn #' . $bid . ' đã được ghi nhận (Thanh toán tiền mặt tại sân).');
                        header('Location: my-bookings.php');
                    } else {
                        flash(
                            'ok',
                            '⏳ Đã giữ chỗ tạm thời (HOLD 10 phút) cho đơn #' . $bid .
                            '! Vui lòng hoàn tất thanh toán trước khi hết hạn giữ sân.'
                        );
                        header("Location: payment-demo.php?booking_id=$bid");
                    }
                    exit;
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('err', 'Lỗi xử lý đặt sân: ' . $e->getMessage());
        }
    }
}

// ----------------------------------------------------------------------------
// PHẦN 3: DỰNG DỮ LIỆU SƠ ĐỒ 30 PHÚT, QUAN HỆ GHÉP/TÁCH SÂN & BẢNG GIÁ ĐỘNG
// ----------------------------------------------------------------------------
$sel       = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
$cur_field = null;
$hierarchy = ['ancestors' => [], 'descendants' => [], 'all_related' => []];
$slots30m  = [];
$dayRules  = [];

if ($fid) {
    foreach ($fields as $f_item) {
        if ((int)$f_item['id'] === $fid) {
            $cur_field = $f_item;
            break;
        }
    }

    if ($cur_field) {
        // 3.1. Lấy thông tin Sân Cha / Sân Con liên thông (Ghép sân - Tách sân)
        $hierarchy = get_field_hierarchy_info($fid);

        // 3.2. Lấy trạng thái bận/trống của từng ô 30 phút (kể cả khóa chéo do Sân Cha / Sân Con)
        $slotData = get_field_busy_slots_30m($fid, $sel);
        $slots30m = $slotData['slots'];

        // 3.3. Lấy cấu hình bảng giá theo khung giờ của sân
        $dow = (int)date('N', strtotime($sel));
        $stH = $pdo->prepare("SELECT 1 FROM special_holidays WHERE holiday_date = ?");
        $stH->execute([$sel]);
        $dType = $stH->fetchColumn() ? 'HOLIDAY' : ($dow >= 6 ? 'WEEKEND' : 'WEEKDAY');

        $stR = $pdo->prepare("
            SELECT * FROM pricing_rules
            WHERE field_id = ? AND day_type = ?
            ORDER BY start_time ASC
        ");
        $stR->execute([$fid, $dType]);
        $dayRules = $stR->fetchAll();
    }
}

$active = 'book';
$title  = 'Đặt sân bóng - ' . ($cur_field ? $cur_field['name'] : 'Chọn sân');
include '../includes/header.php';
?>

<!-- ==========================================================================
     PHẦN 4: BANNER TIÊU ĐỀ & BỘ CHỌN SÂN / NGÀY THI ĐẤU
     ========================================================================== -->
<div class="sport-page-head">
  <div>
    <h2>⚡ SƠ ĐỒ ĐẶT SÂN TRỰC QUAN (CHUẨN 30 PHÚT & GHÉP/TÁCH SÂN)</h2>
    <small>
      Tích hợp khóa chéo Sân 5 ↔ Sân 7 ↔ Sân 11, giữ chỗ HOLD 10 phút,
      tính giá động theo khung giờ và đặt lịch cố định hàng tuần
    </small>
  </div>
  <div style="display:flex;gap:10px">
    <a class="btn sm outline" style="border-color:#4ade80;color:#4ade80 !important" href="matchmaking.php">
      ⚔️ Sàn Cáp Kèo
    </a>
    <button type="button" class="btn bl sm" onclick="toggleAiChat()">
      🤖 Nhờ AI tìm sân trống
    </button>
  </div>
</div>

<!-- Thanh tiến trình 3 bước đặt sân chuyên nghiệp (Booking Stepper) -->
<div class="booking-stepper">
  <div class="step-node active">
    <div class="step-num">1</div>
    <div class="step-info">
      <b>Chọn Sân & Khung Giờ 30p</b>
      <small>Sơ đồ mặt sân 2D & tính giá động</small>
    </div>
  </div>
  <div class="step-node">
    <div class="step-num">2</div>
    <div class="step-info">
      <b>Giữ Chỗ HOLD 10 Phút</b>
      <small>Khóa chéo sân Cha/Con & quét QR</small>
    </div>
  </div>
  <div class="step-node">
    <div class="step-num">3</div>
    <div class="step-info">
      <b>Nhận Vé Điện Tử QR</b>
      <small>Check-in tại sân hoặc Cáp kèo</small>
    </div>
  </div>
</div>

<!-- Thanh chọn Sân bóng & Ngày ra sân -->
<div class="box" style="margin-bottom:20px;padding:20px;border-left:5px solid #16a34a">
  <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;align-items:end">
    <div>
      <label style="margin:0 0 6px;font-weight:700;color:#0f172a">🏟 Bước 1: Chọn Sân Bóng Thi Đấu</label>
      <select
        name="field_id"
        onchange="this.form.submit()"
        style="padding:12px;font-size:14px;border:2px solid #cbd5e1;border-radius:10px;width:100%;font-weight:600"
      >
        <?php foreach ($fields as $f): ?>
          <option value="<?=$f['id']?>" <?=$f['id'] == $fid ? 'selected' : ''?>>
            ⚽ [Sân <?=$f['type']?>v<?=$f['type']?><?=!empty($f['pitch_code']) ? ' - ' . $f['pitch_code'] : ''?>]
            <?=e($f['name'])?> (<?=e($f['district'])?>) — Giá gốc <?=money($f['price_per_hour'])?>/h
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="margin:0 0 6px;font-weight:700;color:#0f172a">📅 Bước 2: Chọn Ngày Ra Sân</label>
      <input
        type="date"
        name="date"
        min="<?=date('Y-m-d')?>"
        value="<?=e($sel)?>"
        onchange="this.form.submit()"
        style="padding:11px 12px;font-size:14px;border:2px solid #cbd5e1;border-radius:10px;width:100%;font-weight:600"
      >
    </div>
  </form>
</div>

<!-- ==========================================================================
     PHẦN 5: SƠ ĐỒ KHUNG GIỜ 30 PHÚT, THÔNG TIN GHÉP SÂN & BIỂU MẪU ĐẶT SÂN
     ========================================================================== -->
<?php if ($fid && $cur_field): ?>
  <div style="display:grid;grid-template-columns:1.45fr 1fr;gap:24px;align-items:start">

    <div class="box">
      <!-- 5.1. Sơ đồ mặt sân cỏ trực quan kèm Sơ đồ Ghép sân - Tách sân -->
      <div class="tactical-pitch">
        <div class="tactical-pitch-inner">
          <div>
            <span style="background:#0f172a;color:#4ade80;font-family:var(--font-sport);font-size:11px;font-weight:800;padding:3px 9px;border-radius:4px">
              SÂN TIÊU CHUẨN <?=$cur_field['type']?>v<?=$cur_field['type']?>
              <?=!empty($cur_field['pitch_code']) ? ' • MÃ SÂN: ' . e($cur_field['pitch_code']) : ''?>
            </span>
            <h3 style="margin:6px 0 2px;font-family:var(--font-sport);font-size:22px;color:#fff">
              <?=e($cur_field['name'])?>
            </h3>
            <small style="color:#dcfce7;margin:0">📍 <?=e($cur_field['address'])?></small>
          </div>
          <div style="text-align:right">
            <small style="color:#bbf7d0;margin:0">GIÁ CƠ SỞ</small>
            <div style="font-family:var(--font-sport);font-size:26px;font-weight:800;color:#ffffff">
              <?=money($cur_field['price_per_hour'])?><span style="font-size:13px">/h</span>
            </div>
          </div>
        </div>
      </div>

      <?php
      // Xây dựng bản đồ ánh xạ pitch_code -> thông tin sân để vẽ Sơ đồ Mặt Sân Cỏ 2D Trực Quan
      $pitchMapByCode = [];
      foreach ($fields as $pf) {
          $code = strtoupper(trim($pf['pitch_code'] ?? ''));
          if ($code !== '') {
              $pitchMapByCode[$code] = $pf;
          }
      }
      $relatedIds = array_map(fn($r) => (int)$r['id'], $hierarchy['all_related'] ?? []);

      // Hàm trợ giúp lấy class trạng thái (is-selected hoặc is-linked-lock) và URL chuyển sân
      $getPitchNodeMeta = function (string $code, string $fallbackLabel) use ($pitchMapByCode, $fid, $relatedIds, $sel) {
          $node = $pitchMapByCode[$code] ?? null;
          if (!$node) {
              return [
                  'id'    => 0,
                  'name'  => $fallbackLabel,
                  'price' => '',
                  'class' => '',
                  'href'  => '#'
              ];
          }
          $nid = (int)$node['id'];
          $cls = '';
          if ($nid === (int)$fid) {
              $cls = 'is-selected';
          } elseif (in_array($nid, $relatedIds, true)) {
              $cls = 'is-linked-lock';
          }
          return [
              'id'    => $nid,
              'name'  => $node['name'],
              'price' => money($node['price_per_hour']) . '/h',
              'class' => $cls,
              'href'  => '?field_id=' . $nid . '&date=' . urlencode($sel)
          ];
      };

      $p11A = $getPitchNodeMeta('11A', 'Đại Sân 11A (Ghép 7A + 7B)');
      $p7A  = $getPitchNodeMeta('7A', 'Sân 7A (Ghép 5A + 5B + 5C)');
      $p7B  = $getPitchNodeMeta('7B', 'Sân 7B Tiêu Chuẩn');
      $p5A  = $getPitchNodeMeta('5A', 'Sân 5A');
      $p5B  = $getPitchNodeMeta('5B', 'Sân 5B');
      $p5C  = $getPitchNodeMeta('5C', 'Sân 5C');
      ?>

      <!-- 5.1B. Sơ đồ Mặt Sân Cỏ 2D Trực Quan (Interactive 2D Hierarchical Pitch Map) -->
      <div class="pitch-2d-container">
        <div class="pitch-2d-header">
          <h4>🏟 SƠ ĐỒ MẶT SÂN CỎ 2D TRỰC QUAN (BẤM VÀO SÂN ĐỂ CHUYỂN NHANH)</h4>
          <div class="pitch-2d-legend">
            <span><i class="pitch-2d-dot" style="background:#22c55e;border:1px solid #fff"></i> Đang chọn</span>
            <span><i class="pitch-2d-dot" style="background:#f59e0b;border:1px solid #fde68a"></i> Tự động khóa chéo (Cha/Con)</span>
            <span><i class="pitch-2d-dot" style="background:#15803d;border:1px solid rgba(255,255,255,0.6)"></i> Sân khác</span>
          </div>
        </div>

        <div class="pitch-2d-stadium">
          <!-- Thanh chọn Đại Sân 11A (Bao trùm toàn bộ cụm 7A + 7B) -->
          <a
            href="<?=e($p11A['href'])?>"
            class="pitch-2d-11-bar pitch-node <?=$p11A['class']?>"
            style="text-decoration:none;color:#fff"
          >
            <div>
              <b style="font-family:var(--font-sport);font-size:14px">🏆 [11A] <?=e($p11A['name'])?></b>
              <small style="display:inline;margin-left:8px;color:#bbf7d0">(Tổ hợp ghép toàn bộ Sân 7A + Sân 7B)</small>
            </div>
            <span style="font-family:var(--font-sport);font-weight:800;font-size:13px;color:#4ade80">
              <?=$p11A['class'] === 'is-selected' ? '⚡ ĐANG CHỌN' : ($p11A['class'] === 'is-linked-lock' ? '🔗 KHÓA CHÉO' : $p11A['price'])?>
            </span>
          </a>

          <!-- Lưới 2 Sân 7 người: Sân 7A (Chứa 5A, 5B, 5C) và Sân 7B -->
          <div class="pitch-2d-grid-7">
            <!-- Khu vực Sân 7A -->
            <div class="pitch-2d-zone-7">
              <a
                href="<?=e($p7A['href'])?>"
                class="pitch-2d-7-head pitch-node <?=$p7A['class']?>"
                style="text-decoration:none;color:#fff"
              >
                <span>⚽ [7A] <?=e($p7A['name'])?></span>
                <span style="color:#86efac">
                  <?=$p7A['class'] === 'is-selected' ? '⚡ ĐANG CHỌN' : ($p7A['class'] === 'is-linked-lock' ? '🔗 KHÓA CHÉO' : $p7A['price'])?>
                </span>
              </a>

              <!-- 3 Sân 5 người nằm trong lòng Sân 7A -->
              <div class="pitch-2d-grid-5">
                <?php foreach ([['5A', $p5A], ['5B', $p5B], ['5C', $p5C]] as [$code5, $node5]): ?>
                  <a
                    href="<?=e($node5['href'])?>"
                    class="pitch-2d-cell-5 pitch-node <?=$node5['class']?>"
                    style="text-decoration:none"
                  >
                    <b>⚽ SÂN <?=$code5?></b>
                    <small><?=e($node5['price'] ?: 'Sân 5v5')?></small>
                    <?php if ($node5['class'] === 'is-selected'): ?>
                      <small style="margin-top:4px;font-weight:800">⚡ ĐANG CHỌN</small>
                    <?php elseif ($node5['class'] === 'is-linked-lock'): ?>
                      <small style="margin-top:4px;color:#fef3c7;font-weight:800">🔗 Khóa chéo</small>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Khu vực Sân 7B -->
            <div class="pitch-2d-zone-7" style="display:flex;flex-direction:column;justify-content:space-between">
              <a
                href="<?=e($p7B['href'])?>"
                class="pitch-2d-7-head pitch-node <?=$p7B['class']?>"
                style="text-decoration:none;color:#fff;height:100%;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:18px 12px;margin:0"
              >
                <span style="font-family:var(--font-sport);font-size:16px;font-weight:800">⚽ [7B] <?=e($p7B['name'])?></span>
                <small style="color:#dcfce7;margin-top:4px">Sân 7v7 độc lập • Thuộc cụm Đại Sân 11A</small>
                <span style="margin-top:8px;background:rgba(0,0,0,0.28);padding:4px 10px;border-radius:6px;color:#4ade80;font-family:var(--font-sport)">
                  <?=$p7B['class'] === 'is-selected' ? '⚡ ĐANG CHỌN' : ($p7B['class'] === 'is-linked-lock' ? '🔗 TỰ KHÓA CHÉO' : $p7B['price'])?>
                </span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- 5.2. Hộp thông tin Quan hệ Ghép sân - Tách sân (Hierarchical Pitch Mapping) -->
      <?php if (!empty($hierarchy['all_related'])): ?>
        <div style="background:#eff6ff;border:1.5px solid #93c5fd;border-radius:12px;padding:12px 14px;margin-bottom:16px">
          <div style="font-weight:800;font-size:13px;color:#1e3a8a;margin-bottom:6px">
            🔗 CƠ CHẾ GHÉP SÂN - TÁCH SÂN TỰ ĐỘNG (HIERARCHICAL PITCH LOCKING):
          </div>
          <div style="font-size:12.5px;color:#1e40af;line-height:1.55">
            <?php if (!empty($hierarchy['ancestors'])): ?>
              <div>
                • <b>Thuộc tổ hợp sân lớn:</b>
                <?php foreach ($hierarchy['ancestors'] as $anc): ?>
                  <a href="?field_id=<?=$anc['id']?>&date=<?=urlencode($sel)?>" style="display:inline-block;background:#dbeafe;color:#1d4ed8;font-weight:700;padding:2px 8px;border-radius:6px;margin-right:4px">
                    🏟 <?=e($anc['name'])?> (Sân <?=$anc['type']?>)
                  </a>
                <?php endforeach; ?>
                <i>(Nếu sân này có lịch, sân lớn sẽ tự động khóa và ngược lại)</i>
              </div>
            <?php endif; ?>

            <?php if (!empty($hierarchy['descendants'])): ?>
              <div style="margin-top:4px">
                • <b>Được ghép từ các sân thành phần:</b>
                <?php foreach ($hierarchy['descendants'] as $desc): ?>
                  <a href="?field_id=<?=$desc['id']?>&date=<?=urlencode($sel)?>" style="display:inline-block;background:#dcfce7;color:#15803d;font-weight:700;padding:2px 8px;border-radius:6px;margin-right:4px">
                    ⚽ <?=e($desc['name'])?> (Sân <?=$desc['type']?>)
                  </a>
                <?php endforeach; ?>
                <i>(Đặt sân này sẽ tự động khóa toàn bộ các sân thành phần bên dưới)</i>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 5.3. Bảng khung giờ Giá Động áp dụng cho ngày đang chọn -->
      <?php if ($dayRules): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin-bottom:18px">
          <?php foreach ($dayRules as $dr): ?>
            <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:10px;padding:8px 12px">
              <small style="margin:0;font-weight:700;color:#475569;font-size:11.5px"><?=e($dr['label'])?></small>
              <div style="font-family:var(--font-sport);font-size:15px;font-weight:800;color:#059669">
                <?=substr($dr['start_time'], 0, 5)?> - <?=substr($dr['end_time'], 0, 5)?>: <?=money($dr['price_per_hour'])?>/h
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" id="bookingForm">
        <?=csrf_field()?>
        <input type="hidden" name="field_id" value="<?=$fid?>">
        <input type="hidden" name="date" value="<?=e($sel)?>">

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
          <h4 style="margin:0;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase">
            1. Chọn Ca Thi Đấu 30 Phút (Ngày <?=date('d/m/Y', strtotime($sel))?>)
          </h4>

          <!-- Nút chọn nhanh thời lượng ca phổ biến: 60 phút, 90 phút, 120 phút -->
          <div style="display:flex;gap:6px;align-items:center">
            <small style="margin:0;font-weight:700;color:#475569">Ca phổ biến:</small>
            <button type="button" class="btn sm outline" onclick="setQuickDuration(60)" style="padding:4px 10px;font-size:12px">⏱ 60 phút</button>
            <button type="button" class="btn sm outline" onclick="setQuickDuration(90)" style="padding:4px 10px;font-size:12px">⏱ 90 phút</button>
            <button type="button" class="btn sm outline" onclick="setQuickDuration(120)" style="padding:4px 10px;font-size:12px">⏱ 120 phút</button>
          </div>
        </div>

        <!-- Chú thích màu sắc ô giờ 30 phút -->
        <div class="slot-legend">
          <span><i class="slot-dot free"></i> Còn trống</span>
          <span><i class="slot-dot selected"></i> Đang chọn</span>
          <span><i class="slot-dot peak"></i> 🔥 Giờ Vàng (17:30-22:30)</span>
          <span><i class="slot-dot busy"></i> Đã đặt / Khóa ghép sân</span>
        </div>

        <!-- 5.4. Lưới chọn khung giờ 30 phút (Interactive 30-Minute Time-Slot Grid) -->
        <div class="slot-grid" id="interactiveSlotGrid" style="grid-template-columns:repeat(auto-fill,minmax(122px,1fr))">
          <?php
          $idxSlot = 0;
          foreach ($slots30m as $sKey => $slot):
              $isBusy   = $slot['is_busy'];
              $isPeak   = ($sKey >= '17:30' && $sKey < '22:00');
              $cType    = $slot['conflict_type'];

              if ($slot['is_past']) {
                  $subText = 'Đã qua';
              } elseif ($cType === 'SELF') {
                  $subText = ($slot['booking_status'] === 'pending') ? '⏳ Đang giữ chỗ' : '🔴 Đã đặt';
              } elseif ($cType === 'ANCESTOR' || $cType === 'DESCENDANT') {
                  $subText = '🔒 Khóa (' . $slot['conflict_name'] . ')';
              } else {
                  $subText = $isPeak ? '🔥 Giờ Vàng' : '🟢 Trống';
              }
          ?>
            <div
              class="slot-btn <?=$isBusy ? 'busy' : ($isPeak ? 'peak' : 'free')?>"
              data-idx="<?=$idxSlot?>"
              data-start="<?=$slot['start']?>"
              data-end="<?=$slot['end']?>"
              data-busy="<?=$isBusy ? '1' : '0'?>"
              onclick="handleSlot30Click(<?=$idxSlot?>, <?=$isBusy ? 'true' : 'false'?>)"
              title="<?=e($slot['label'] . ' - ' . $subText)?>"
            >
              <span class="slot-time" style="font-size:13px"><?=$slot['label']?></span>
              <span class="slot-sub" style="font-size:10.5px"><?=e($subText)?></span>
            </div>
          <?php
              $idxSlot++;
          endforeach;
          ?>
        </div>

        <!-- 5.5. Hai ô Select Giờ Bắt Đầu / Giờ Kết Thúc (Bước nhảy 30 phút) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0">
          <div>
            <label style="margin:0 0 4px;font-size:12.5px;font-weight:700">Giờ bắt đầu nhận sân (30p/ca)</label>
            <select name="start" id="selStart" onchange="syncGridFromSelect30()" style="padding:9px;border-radius:8px;width:100%;font-weight:700">
              <?php
              for ($t = strtotime('06:00'); $t <= strtotime('22:00'); $t += 1800):
                  $v = date('H:i', $t);
              ?>
                <option value="<?=$v?>" <?=$v === '17:30' ? 'selected' : ''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label style="margin:0 0 4px;font-size:12.5px;font-weight:700">Giờ kết thúc trả sân (Tối thiểu 60p)</label>
            <select name="end" id="selEnd" onchange="syncGridFromSelect30()" style="padding:9px;border-radius:8px;width:100%;font-weight:700">
              <?php
              for ($t = strtotime('07:00'); $t <= strtotime('23:00'); $t += 1800):
                  $v = date('H:i', $t);
              ?>
                <option value="<?=$v?>" <?=$v === '19:00' ? 'selected' : ''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <!-- 5.6. Chế độ Đặt sân: Đặt 1 buổi lẻ vs Hợp đồng Đặt Cố Định Hàng Tuần (Recurring Booking) -->
        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          2. Chế độ Đặt Lịch (Vãng lai 1 buổi / Cố định hàng tuần)
        </h4>
        <div style="background:#eff6ff;border:1.5px solid #93c5fd;border-radius:12px;padding:14px 16px;margin-bottom:20px">
          <div style="display:flex;gap:18px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;font-weight:700;color:#0f172a">
              <input type="radio" name="booking_mode" value="single" checked onchange="toggleRecurringMode()" style="width:17px;height:17px;margin:0">
              <span>⚡ Đặt lẻ 1 trận (Ngày <?=date('d/m/Y', strtotime($sel))?>)</span>
            </label>

            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;font-weight:700;color:#1d4ed8">
              <input type="radio" name="booking_mode" value="recurring" id="radRecurring" onchange="toggleRecurringMode()" style="width:17px;height:17px;margin:0">
              <span>🔄 Đặt Hợp đồng Cố định hàng tuần (Ưu đãi giảm 5% + Hỗ trợ Báo nghỉ / Xả sân trước 24h)</span>
            </label>
          </div>

          <div id="recurringOptionsBox" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed #93c5fd">
            <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:12px;align-items:center">
              <div>
                <label style="font-size:12.5px;font-weight:700;margin:0 0 4px;color:#1e3a8a">Chọn thời hạn hợp đồng cố định:</label>
                <select name="weeks_count" id="selWeeksCount" onchange="recalcPrice()" style="padding:9px;border-radius:8px;border:1px solid #93c5fd;width:100%;font-weight:700">
                  <option value="4">🗓️ 1 Tháng (4 tuần liên tiếp - Giảm 5%)</option>
                  <option value="8">🗓️ 2 Tháng (8 tuần liên tiếp - Giảm 5%)</option>
                  <option value="12">🗓️ 3 Tháng (12 tuần liên tiếp - Giảm 5%)</option>
                </select>
              </div>
              <div style="font-size:12.5px;color:#1e40af;line-height:1.5">
                ✔ Hệ thống tự động giữ lịch cố định vào cùng khung giờ mỗi tuần.<br>
                ✔ Khi bận đột xuất, đội bạn có thể bấm <b>"🔓 Báo nghỉ / Xả sân" (Trước 24h)</b> trong mục <i>Vé của tôi</i> để mở lại đúng buổi hôm đó cho khách lẻ mà không mất lịch các tuần sau!
              </div>
            </div>
          </div>
        </div>

        <!-- 5.7. Dịch vụ tiện ích đi kèm -->
        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          3. Dịch vụ tiện ích đi kèm (Tùy chọn)
        </h4>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
          <?php if (!$services): ?>
            <small>Hiện chưa có dịch vụ cộng thêm.</small>
          <?php else: ?>
            <?php foreach ($services as $svc): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;padding:12px 16px;border-radius:10px">
                <div>
                  <b style="font-size:14px;color:#1e293b">🥤 <?=e($svc['name'])?></b>
                  <small style="color:#64748b"><?=e($svc['description'])?></small>
                  <small style="color:#16a34a;font-weight:700"><?=money($svc['price'])?> / đơn vị</small>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:12px;color:#64748b">SL:</span>
                  <input
                    type="number"
                    min="0"
                    max="20"
                    name="services[<?=$svc['id']?>]"
                    value="0"
                    class="svc-input"
                    data-price="<?=$svc['price']?>"
                    oninput="recalcPrice()"
                    style="width:68px;padding:6px 10px;border-radius:6px;border:1.5px solid #cbd5e1;text-align:center;margin:0;font-weight:700"
                  >
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- 5.8. Đăng lên Sàn Cáp Kèo / Tìm Đối Giao Hữu -->
        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          4. Sàn Cáp Kèo / Tìm Đối Giao Hữu (Tùy chọn)
        </h4>
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:14px 16px;margin-bottom:20px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;font-weight:700;color:#14532d">
            <input
              type="checkbox"
              name="enable_matchmaking"
              value="1"
              id="chkMatchmaking"
              onchange="document.getElementById('matchmakingFields').style.display=this.checked?'grid':'none'"
              style="width:18px;height:18px;margin:0"
            >
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

        <!-- 5.9. Mã giảm giá Voucher & Phương thức Thanh toán -->
        <h4 style="margin:22px 0 12px;color:#0f172a;font-family:var(--font-sport);font-size:17px;text-transform:uppercase;border-bottom:1px solid #f1f5f9;padding-bottom:8px">
          5. Mã giảm giá (Voucher) & Phương thức Thanh toán
        </h4>
        <div style="margin-bottom:16px">
          <label style="margin:0 0 6px;font-weight:600">Mã giảm giá (Voucher)</label>
          <div style="display:flex;gap:8px">
            <input name="voucher_code" id="voucherInput" placeholder="Nhập mã (VD: SANBONG10, GIAM30K)" style="padding:10px 14px;border-radius:8px;margin:0;flex:1">
            <button type="button" class="btn bl" onclick="applyVoucher()" style="white-space:nowrap">Áp dụng mã</button>
          </div>
          <div id="voucherMsg" style="margin-top:6px;font-size:12.5px"></div>

          <?php if ($vouchers_active): ?>
            <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
              <small style="margin:0;color:#64748b">🎁 Chọn nhanh Voucher:</small>
              <?php foreach ($vouchers_active as $va): ?>
                <button
                  type="button"
                  onclick="selectVoucherCode('<?=e($va['code'])?>')"
                  style="border:1px dashed #16a34a;background:#f0fdf4;color:#15803d;border-radius:6px;padding:4px 9px;font-size:11.5px;font-weight:700;cursor:pointer"
                >
                  <?=e($va['code'])?> (<?=$va['discount_type'] === 'percent' ? '-' . $va['discount_value'] . '%' : '-' . money($va['discount_value'])?>)
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
            <option value="bank">🏦 Chuyển khoản Ngân hàng (VietQR Tự động - Giữ chỗ HOLD 10 phút)</option>
            <option value="momo">📱 Ví điện tử MoMo (Quét QR - Giữ chỗ HOLD 10 phút)</option>
            <option value="vnpay">💳 Cổng thanh toán VNPay / Thẻ ATM nội địa (Giữ chỗ HOLD 10 phút)</option>
            <option value="cash">💵 Thanh toán trực tiếp bằng Tiền mặt tại sân</option>
          </select>
        </div>

        <button class="btn" style="width:100%;padding:15px;font-size:15.5px;font-weight:800;text-transform:uppercase;letter-spacing:0.4px" type="submit">
          ⚡ Xác Nhận Giữ Chỗ (HOLD) & Nhận Vé Điện Tử QR →
        </button>
      </form>
    </div>

    <!-- ======================================================================
         CỘT PHẢI: BẢNG TÍNH CHI PHÍ LINH HOẠT THEO PHÂN ĐOẠN (DYNAMIC PRICING)
         ====================================================================== -->
    <div class="box" style="position:sticky;top:88px;border-top:4px solid #16a34a">
      <div style="overflow:hidden;border-radius:12px;height:155px;margin-bottom:14px;position:relative">
        <img src="<?=e(img_src($cur_field['image']))?>" alt="<?=e($cur_field['name'])?>" style="width:100%;height:100%;object-fit:cover">
        <span style="position:absolute;bottom:10px;left:10px;background:#0f172a;color:#4ade80;font-family:var(--font-sport);font-weight:700;font-size:12px;padding:3px 10px;border-radius:6px">
          SÂN <?=$cur_field['type']?> NGƯỜI <?=!empty($cur_field['pitch_code']) ? '• ' . e($cur_field['pitch_code']) : ''?>
        </span>
      </div>

      <h3 style="margin:0 0 6px;font-family:var(--font-sport);font-size:20px;color:#0f172a"><?=e($cur_field['name'])?></h3>
      <small style="color:#64748b;font-size:13px">📍 <?=e($cur_field['address'])?></small>

      <div style="border-top:1px solid #e2e8f0;margin-top:14px;padding-top:14px">
        <h4 style="margin:0 0 10px;font-family:var(--font-sport);font-size:15.5px;color:#0f172a;text-transform:uppercase">
          🧾 Bảng Tính Giá Động (Dynamic Pricing)
        </h4>

        <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px">
          <span style="color:#64748b">Loại ngày áp dụng:</span>
          <b id="summaryDayType" style="color:#0f172a">Đang tính...</b>
        </div>

        <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13.5px">
          <span style="color:#64748b">Khung giờ đã chọn:</span>
          <b id="summarySlotRange" style="color:#16a34a">17:30 - 19:00</b>
        </div>

        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Thời lượng đá:</span>
          <b id="summaryHours">1.5 giờ (90 phút)</b>
        </div>

        <!-- Chi tiết từng lát cắt thời gian (Time-Slice Breakdown) -->
        <div id="dynamicPriceSegments" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:10px;font-size:12px">
          Đang tải chi tiết phân đoạn giá...
        </div>

        <!-- Cảnh báo nếu trùng lịch ghép/tách sân -->
        <div id="conflictAlertBox" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:9px 12px;border-radius:8px;font-size:12.5px;font-weight:700;margin-bottom:10px"></div>

        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Tiền thuê sân:</span>
          <b id="summaryFieldCost">0đ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px">
          <span style="color:#64748b">Dịch vụ đi kèm:</span>
          <b id="summarySvcCost">0đ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13.5px;color:#ef4444">
          <span>Giảm giá Voucher / Cố định:</span>
          <b id="summaryDiscount">-0đ</b>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:12px;border-top:2px dashed #cbd5e1;font-size:16px;align-items:center">
          <b style="color:#0f172a">TỔNG THANH TOÁN:</b>
          <b id="summaryTotal" style="color:#16a34a;font-family:var(--font-sport);font-size:24px">0đ</b>
        </div>
      </div>

      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px;margin-top:14px">
        <small style="color:#92400e;font-weight:700;margin:0">⏳ Cơ chế Giữ Chỗ Tạm Thời (HOLD 10 Phút):</small>
        <span style="font-size:12px;color:#b45309;display:block;margin-top:4px">
          Ngay khi bấm Đặt sân, hệ thống sẽ <b>khóa sân (kể cả sân Cha/Con liên quan) trong 10 phút</b> để chống đặt trùng trong lúc bạn quét QR thanh toán.
        </span>
      </div>
    </div>

  </div>

<!-- ==========================================================================
     PHẦN 6: JAVASCRIPT XỬ LÝ LƯỚI 30 PHÚT & GỌI API GIÁ ĐỘNG THỜI GIAN THỰC
     ========================================================================== -->
<script>
const FIELD_ID = <?=(int)$fid?>;
const BOOKING_DATE = <?=json_encode($sel)?>;
const CHECK_PRICE_URL = <?=json_encode(project_base() . 'api/check_price_availability.php')?>;
const CHECK_VOUCHER_URL = <?=json_encode(project_base() . 'api/check_voucher.php')?>;

let currentVoucher = null;
let clickAnchorIdx = null;
let latestDynamicFieldPrice = 0;

function formatVND(n) {
  return new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(n))) + 'đ';
}

// Chuyển chuỗi HH:MM sang tổng số phút từ 00:00
function timeToMinutes(tStr) {
  const [h, m] = tStr.split(':').map(Number);
  return h * 60 + m;
}

// Chuyển tổng số phút sang chuỗi HH:MM
function minutesToTime(mins) {
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

// Nút chọn nhanh thời lượng 60 phút, 90 phút, 120 phút kể từ giờ bắt đầu hiện tại
function setQuickDuration(durationMins) {
  const selStart = document.getElementById('selStart');
  const selEnd = document.getElementById('selEnd');
  const stMins = timeToMinutes(selStart.value);
  const targetEndMins = Math.min(23 * 60, stMins + durationMins);
  selEnd.value = minutesToTime(targetEndMins);
  syncGridFromSelect30();
}

// Bấm chọn trực tiếp trên lưới các ô 30 phút
function handleSlot30Click(idx, isBusy) {
  if (isBusy) return;
  const allBtns = document.querySelectorAll('.slot-btn');
  const clickedBtn = allBtns[idx];
  if (!clickedBtn) return;

  const selStart = document.getElementById('selStart');
  const selEnd = document.getElementById('selEnd');

  if (clickAnchorIdx === null || idx < clickAnchorIdx) {
    // Mặc định khi bấm lần đầu: chọn 2 ô liên tiếp = 60 phút (hoặc 90 phút nếu trống)
    clickAnchorIdx = idx;
    const stTime = clickedBtn.dataset.start;
    const stMins = timeToMinutes(stTime);
    const nextBtn = allBtns[idx + 1];
    const enMins = (nextBtn && nextBtn.dataset.busy === '0') ? (stMins + 60) : (stMins + 60);
    selStart.value = stTime;
    selEnd.value = minutesToTime(Math.min(23 * 60, enMins));
  } else {
    // Người dùng bấm ô thứ 2 để kéo dài khoảng thời gian (90p, 120p...)
    let blocked = false;
    for (let i = clickAnchorIdx; i <= idx; i++) {
      if (allBtns[i] && allBtns[i].dataset.busy === '1') {
        blocked = true;
        break;
      }
    }
    if (blocked) {
      clickAnchorIdx = idx;
      const stTime = clickedBtn.dataset.start;
      selStart.value = stTime;
      selEnd.value = minutesToTime(Math.min(23 * 60, timeToMinutes(stTime) + 60));
    } else {
      const stTime = allBtns[clickAnchorIdx].dataset.start;
      let enTime = clickedBtn.dataset.end;
      // Đảm bảo tối thiểu 60 phút
      if (timeToMinutes(enTime) - timeToMinutes(stTime) < 60) {
        enTime = minutesToTime(Math.min(23 * 60, timeToMinutes(stTime) + 60));
      }
      selStart.value = stTime;
      selEnd.value = enTime;
      clickAnchorIdx = null;
    }
  }
  syncGridFromSelect30();
}

// Tô sáng các ô 30 phút nằm trong khoảng [selStart, selEnd] và gọi API tính giá động
function syncGridFromSelect30() {
  const stMins = timeToMinutes(document.getElementById('selStart').value);
  const enMins = timeToMinutes(document.getElementById('selEnd').value);

  document.querySelectorAll('.slot-btn').forEach(btn => {
    const bStart = timeToMinutes(btn.dataset.start);
    const bEnd = timeToMinutes(btn.dataset.end);
    if (btn.dataset.busy !== '1' && bStart >= stMins && bEnd <= enMins) {
      btn.classList.add('selected');
    } else {
      btn.classList.remove('selected');
    }
  });

  fetchDynamicPricingAndAvailability();
}

function toggleRecurringMode() {
  const isRec = document.getElementById('radRecurring').checked;
  document.getElementById('recurringOptionsBox').style.display = isRec ? 'block' : 'none';
  recalcPrice();
}

// Gọi API tính giá động theo lát cắt 30 phút và kiểm tra xung đột Ghép/Tách sân
async function fetchDynamicPricingAndAvailability() {
  const stStr = document.getElementById('selStart').value;
  const enStr = document.getElementById('selEnd').value;
  const segBox = document.getElementById('dynamicPriceSegments');
  const conflictBox = document.getElementById('conflictAlertBox');

  if (timeToMinutes(enStr) <= timeToMinutes(stStr)) {
    latestDynamicFieldPrice = 0;
    segBox.innerHTML = '<span style="color:#ef4444">⚠️ Giờ kết thúc phải lớn hơn giờ bắt đầu (tối thiểu 60 phút).</span>';
    recalcPrice();
    return;
  }

  try {
    const url = `${CHECK_PRICE_URL}?field_id=${FIELD_ID}&date=${encodeURIComponent(BOOKING_DATE)}&start=${encodeURIComponent(stStr)}&end=${encodeURIComponent(enStr)}`;
    const res = await fetch(url);
    const data = await res.json();

    if (data.ok) {
      const pr = data.pricing;
      latestDynamicFieldPrice = pr.total_price;
      document.getElementById('summaryDayType').textContent = pr.day_type_label;
      document.getElementById('summarySlotRange').textContent = `${stStr} - ${enStr}`;
      document.getElementById('summaryHours').textContent = `${pr.duration_hours} giờ (${pr.duration_hours * 60} phút)`;

      // Hiển thị bảng bóc tách từng phân đoạn giá (Time-Slice Breakdown)
      if (pr.breakdown && pr.breakdown.length > 0) {
        let html = '<div style="font-weight:700;color:#0f172a;margin-bottom:4px">Chi tiết phân đoạn giờ:</div>';
        pr.breakdown.forEach(seg => {
          html += `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px dashed #e2e8f0">
            <span>• <b>${seg.from}-${seg.to}</b> (${seg.hours}h - ${seg.label})</span>
            <b>${formatVND(seg.subtotal)}</b>
          </div>`;
        });
        segBox.innerHTML = html;
      }

      // Hiển thị cảnh báo nếu vướng lịch sân Cha hoặc sân Con
      if (!data.available && data.conflicts.length > 0) {
        const c = data.conflicts[0];
        conflictBox.style.display = 'block';
        conflictBox.textContent = c.relation_type === 'SELF'
          ? `⛔ Khung giờ này đã có người đặt (${c.start_time.slice(0,5)} - ${c.end_time.slice(0,5)}).`
          : `🔒 Khóa ghép/tách sân: ${c.field_name} đã có lịch trong khung giờ (${c.start_time.slice(0,5)} - ${c.end_time.slice(0,5)}).`;
      } else {
        conflictBox.style.display = 'none';
      }
      recalcPrice();
    }
  } catch (e) {
    recalcPrice();
  }
}

function recalcPrice() {
  const isRec = document.getElementById('radRecurring')?.checked;
  const weeks = isRec ? parseInt(document.getElementById('selWeeksCount').value || '4', 10) : 1;

  let svcCost = 0;
  document.querySelectorAll('.svc-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || '0', 10));
    const pr = parseInt(inp.dataset.price || '0', 10);
    svcCost += qty * pr;
  });

  const rawFieldCost = latestDynamicFieldPrice * weeks;
  const totalSvcCost = svcCost * weeks;
  const subtotal = rawFieldCost + totalSvcCost;

  document.getElementById('summaryFieldCost').textContent = formatVND(rawFieldCost) + (weeks > 1 ? ` (${weeks} tuần)` : '');
  document.getElementById('summarySvcCost').textContent = formatVND(totalSvcCost);

  let discount = 0;
  if (isRec) {
    // Hợp đồng cố định giảm 5%
    discount = Math.round((rawFieldCost * 0.05) / 1000) * 1000;
  } else if (currentVoucher && subtotal >= currentVoucher.min_order) {
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
  const subtotal = latestDynamicFieldPrice;

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
      msgEl.innerHTML = `<span style="color:#16a34a;font-weight:700">✔ ${data.message}</span>`;
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
  syncGridFromSelect30();
});
</script>
<?php else: ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:52px;margin-bottom:12px">🏟</div>
    <h3 style="color:#0f172a;font-family:var(--font-sport);font-size:22px;margin:0 0 8px">
      CHỌN SÂN BÓNG ĐỂ MỞ SƠ ĐỒ GIỜ TRỐNG
    </h3>
    <small style="color:#64748b;font-size:14px">
      Hệ thống sẽ hiển thị sơ đồ mặt sân cỏ, các ô giờ 30 phút và bảng tính giá động tự động.
    </small>
  </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
