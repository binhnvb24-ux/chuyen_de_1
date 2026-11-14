<?php
/**
 * FILE: user/payment-demo.php
 * MÔ TẢ: Cổng Thanh Toán Trực Tuyến (Demo) & Vé Đặt Sân Điện Tử QR (Stadium Match Ticket).
 * CHỨC NĂNG: Sinh mã QR động chuẩn VietQR Napas247, giao diện MoMo / Thẻ ATM VNPay Test,
 *            Giả lập thanh toán 1-Click, và cấp Vé Thi Đấu Điện Tử (Boarding Pass) kèm QR Check-in tại sân.
 */
require_once '../includes/auth.php';
$u = require_login();
if ($u['role'] === 'admin') { header('Location: ../admin/dashboard.php'); exit; }

// 1. Lấy thông tin đơn đặt sân và giao dịch thanh toán tương ứng
$bid = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$stmt = $pdo->prepare("SELECT b.*, f.name fname, f.address, f.district, f.type ftype, f.image, p.id pid, p.method, p.amount, p.status pay_status, p.transaction_code, p.paid_at 
                       FROM bookings b 
                       JOIN fields f ON f.id = b.field_id 
                       LEFT JOIN payments p ON p.booking_id = b.id 
                       WHERE b.id = ? AND b.user_id = ?");
$stmt->execute([$bid, $u['id']]);
$bk = $stmt->fetch();

if (!$bk) {
  flash('err', 'Không tìm thấy đơn đặt sân cần thanh toán.');
  header('Location: my-bookings.php');
  exit;
}

// 2. Xử lý các thao tác trên Cổng Thanh Toán Demo (Đổi phương thức, Giả lập Thành công / Thất bại)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['action'] ?? '';

  if ($act === 'change_method' && $bk['pay_status'] !== 'paid') {
    $newMethod = in_array($_POST['method'] ?? '', ['bank','momo','vnpay','cash']) ? $_POST['method'] : 'bank';
    $pdo->prepare("UPDATE payments SET method=? WHERE booking_id=?")->execute([$newMethod, $bid]);
    header("Location: payment-demo.php?booking_id=$bid");
    exit;
  }

  if ($act === 'simulate_success' && $bk['pay_status'] !== 'paid') {
    $txn = 'TXN' . date('ymdHis') . rand(100, 999);
    $autoConfirm = get_setting('auto_confirm_paid', '1') === '1';
    $newBkStatus = ($autoConfirm && $bk['status'] === 'pending') ? 'confirmed' : $bk['status'];

    $pdo->beginTransaction();
    try {
      // Cập nhật thanh toán và xóa thời hạn giữ chỗ tạm thời (hold_expires_at = NULL)
      $pdo->prepare("UPDATE payments SET status='paid', transaction_code=?, paid_at=NOW() WHERE booking_id=?")
          ->execute([$txn, $bid]);
      $pdo->prepare("UPDATE bookings SET status=?, hold_expires_at=NULL WHERE id=?")
          ->execute([$newBkStatus, $bid]);

      // Nếu đơn này thuộc Hợp đồng lịch cố định hàng tuần, xác nhận luôn các buổi trong hợp đồng
      if (!empty($bk['recurring_contract_id'])) {
        $pdo->prepare("UPDATE bookings SET status='confirmed', hold_expires_at=NULL WHERE recurring_contract_id=? AND status='pending'")
            ->execute([$bk['recurring_contract_id']]);
        $pdo->prepare("UPDATE payments p INNER JOIN bookings b ON b.id=p.booking_id SET p.status='paid', p.transaction_code=?, p.paid_at=NOW() WHERE b.recurring_contract_id=?")
            ->execute([$txn, $bk['recurring_contract_id']]);
      }

      // Cộng +5 Điểm Uy Tín và Điểm tích lũy khi khách hàng hoàn tất thanh toán đúng hạn
      $loyaltyBonus = max(10, (int)round(((float)$bk['total_price']) / 10000));
      record_wallet_and_reputation(
        (int)$u['id'],
        'REPUTATION',
        0,
        5,
        $loyaltyBonus,
        "Hoàn tất thanh toán đúng hạn đơn #{$bid} ({$bk['fname']}) — Cộng +5 Điểm Uy Tín",
        $bid
      );

      notify($u['id'], "Thanh toán Demo thành công đơn #$bid ({$bk['fname']}). Mã GD: $txn. Bạn được cộng +5 Điểm Uy Tín!");
      $pdo->commit();

      flash('ok', "🎉 Thanh toán thành công đơn #$bid! Mã giao dịch: $txn. Bạn được cộng +5 Điểm Uy Tín & phát hành Vé Đặt Sân Điện Tử QR.");
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      flash('err', 'Lỗi xử lý thanh toán Demo: ' . $e->getMessage());
    }
    header("Location: payment-demo.php?booking_id=$bid");
    exit;
  }

  if ($act === 'simulate_fail' && $bk['pay_status'] !== 'paid') {
    flash('err', '⚠️ Giả lập giao dịch bị hủy hoặc thất bại. Bạn có thể thử thanh toán lại bất cứ lúc nào.');
    header("Location: payment-demo.php?booking_id=$bid");
    exit;
  }
}

// 3. Khởi tạo đường dẫn ảnh mã QR tự động từ cấu hình ngân hàng VietQR & Mã QR Check-in Vé Sân Bóng
$bank_id = get_setting('bank_id', 'MB') ?: 'MB';
$account_no = get_setting('bank_account_no', '0901234567') ?: '0901234567';
$account_name = get_setting('bank_account_name', 'HE THONG SAN BONG PRO') ?: 'HE THONG SAN BONG PRO';
$amount = (int)$bk['total_price'];
$memo = "DATSAN" . $bk['id'];
$qr_url = "https://img.vietqr.io/image/{$bank_id}-{$account_no}-compact2.png?amount={$amount}&addInfo={$memo}&accountName=" . urlencode($account_name);

// Mã QR Check-in Vé Thi Đấu tại sân
$checkin_payload = "SANBONGPRO|TICKET:#{$bk['id']}|FIELD:{$bk['fname']}|DATE:{$bk['booking_date']}|TIME:" . substr($bk['start_time'],0,5) . "-" . substr($bk['end_time'],0,5) . "|PLAYER:{$u['phone']}";
$checkin_qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" . urlencode($checkin_payload);

$methodLabels = [
  'bank'  => 'Chuyển khoản Ngân hàng VietQR (Napas247)',
  'momo'  => 'Ví điện tử MoMo (Cổng thanh toán Demo)',
  'vnpay' => 'Cổng VNPay QR / Thẻ ATM Nội địa (Demo)',
  'cash'  => 'Thanh toán Tiền mặt trực tiếp tại sân'
];

$active = 'mine';
$title = 'Vé Điện Tử & Cổng Thanh Toán - Đơn #' . $bk['id'];
include '../includes/header.php';
?>

<div class="sport-page-head">
  <div>
    <h2><?=$bk['pay_status'] === 'paid' ? 'Vé đặt sân điện tử' : 'Thanh toán VietQR (Giữ chỗ 10 phút)'?></h2>
    <small>Hoàn tất thanh toán VietQR / MoMo / VNPay và sử dụng Vé QR dưới đây để Check-in nhận sân</small>
  </div>
  <div style="display:flex;gap:10px">
    <button type="button" class="btn sm bl" onclick="window.print()">🖨 IN VÉ / LƯU PDF</button>
    <a href="my-bookings.php" class="btn sm outline">← Lịch đặt của tôi</a>
  </div>
</div>

<!-- Thanh tiến trình 3 bước đặt sân chuyên nghiệp (Booking Stepper) -->
<?php $isPaidOrDone = ($bk['pay_status'] === 'paid' || $bk['status'] === 'confirmed'); ?>
<div class="booking-stepper">
  <div class="step-node done">
    <div class="step-num">✔</div>
    <div class="step-info">
      <b>Chọn Sân & Khung Giờ 30p</b>
      <small>Đã chọn <?=e($bk['fname'])?></small>
    </div>
  </div>
  <div class="step-node <?=$isPaidOrDone ? 'done' : 'active'?>">
    <div class="step-num"><?=$isPaidOrDone ? '✔' : '2'?></div>
    <div class="step-info">
      <b>Giữ Chỗ HOLD 10 Phút</b>
      <small><?=$isPaidOrDone ? 'Đã xác nhận thanh toán' : 'Đang khóa sân chờ thanh toán'?></small>
    </div>
  </div>
  <div class="step-node <?=$isPaidOrDone ? 'active' : ''?>">
    <div class="step-num">3</div>
    <div class="step-info">
      <b>Nhận Vé Điện Tử QR</b>
      <small><?=$isPaidOrDone ? 'Vé QR đã sẵn sàng Check-in!' : 'Kích hoạt ngay sau khi thanh toán'?></small>
    </div>
  </div>
</div>

<!-- Đồng hồ đếm ngược 10 phút giữ chỗ tạm thời (HOLD Countdown) -->
<?php if ($bk['status'] === 'pending' && !empty($bk['hold_expires_at']) && $bk['pay_status'] !== 'paid'):
  $remainSec = max(0, strtotime($bk['hold_expires_at']) - time());
?>
  <div style="background:linear-gradient(90deg,#fef3c7,#fffbeb);border:2px solid #f59e0b;border-radius:14px;padding:14px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <b style="color:#92400e;font-size:15px">⏳ ĐANG GIỮ CHỖ TẠM THỜI (HOLD LOCK - CHỐNG ĐẶT TRÙNG)</b>
      <small style="color:#b45309;margin:2px 0 0">
        Sân (cùng các sân Cha/Con liên quan) đang được khóa riêng cho bạn. Nếu quá 10 phút chưa thanh toán, hệ thống sẽ tự động giải phóng sân.
      </small>
    </div>
    <div style="background:#92400e;color:#fef3c7;font-family:var(--font-sport);font-size:22px;font-weight:800;padding:8px 18px;border-radius:10px;letter-spacing:1px" id="holdCountdownBox" data-sec="<?=$remainSec?>">
      <?=sprintf('%02d:%02d', intdiv($remainSec, 60), $remainSec % 60)?>
    </div>
  </div>
  <script>
    (function() {
      var box = document.getElementById('holdCountdownBox');
      if (!box) return;
      var sec = parseInt(box.getAttribute('data-sec') || '0', 10);
      var timer = setInterval(function() {
        sec--;
        if (sec <= 0) {
          clearInterval(timer);
          box.textContent = 'HẾT HẠN';
          location.reload();
          return;
        }
        var m = String(Math.floor(sec / 60)).padStart(2, '0');
        var s = String(sec % 60).padStart(2, '0');
        box.textContent = m + ':' + s;
      }, 1000);
    })();
  </script>
<?php endif; ?>

<!-- 4. Vé Đặt Sân Điện Tử kiểu Boarding Pass Sân Vận Động (Screen 09) -->
<div class="sport-ticket">
  <div class="ticket-main">
    <div class="ticket-head">
      <div>
        <span style="color:#065f46;font-size:12px;font-weight:800">⚽ OFFICIAL MATCH PASS</span>
        <small style="margin:1px 0 0;font-size:10.5px;color:#047857">Vé đặt sân điện tử hợp lệ để Check-in</small>
      </div>
      <div style="text-align:right">
        <b style="font-family:var(--font-sport);font-size:15px;color:#16a34a">MÃ VÉ #SB<?=str_pad((string)$bk['id'], 4, '0', STR_PAD_LEFT)?></b>
      </div>
    </div>

    <div class="ticket-body-inner">
      <h3 style="margin:0 0 14px;font-family:var(--font-sport);font-size:22px;color:#0f172a;text-transform:uppercase">
        <?=e($bk['fname'])?> • SÂN <?=$bk['ftype']?> NGƯỜI
      </h3>

      <div class="ticket-grid">
        <div class="ticket-item">
          <small>Đội trưởng / Người đặt</small>
          <b><?=e($u['full_name'])?> (<?=e($u['phone'])?>)</b>
        </div>
        <div class="ticket-item">
          <small>Ngày thi đấu</small>
          <b><?=date('d/m/Y', strtotime($bk['booking_date']))?></b>
        </div>
        <div class="ticket-item">
          <small>Khung giờ</small>
          <b style="color:#0f172a"><?=substr($bk['start_time'],0,5)?> – <?=substr($bk['end_time'],0,5)?></b>
        </div>
        <div class="ticket-item">
          <small>Địa điểm</small>
          <b><?=e($bk['address'])?></b>
        </div>
      </div>

      <div style="margin-top:16px;padding-top:12px;border-top:1px dashed #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <span class="tag green">
            <?=$bk['pay_status']==='paid'?'✔ ĐÃ THANH TOÁN VIETQR':'⏳ CHỜ THANH TOÁN'?>
          </span>
          <span class="tag green">✔ GIỮ SÂN ĐÚNG GIỜ</span>
        </div>
        <div style="font-family:var(--font-sport);font-size:22px;font-weight:800;color:#16a34a">
          <?=money($amount)?>
        </div>
      </div>
    </div>
  </div>

  <!-- Cuống vé chứa mã QR Check-in -->
  <div class="ticket-stub">
    <small style="color:#a7f3d0;font-weight:800;font-size:10.5px;letter-spacing:0.6px;margin-bottom:8px">QR CHECK-IN TẠI SÂN</small>
    <img src="<?=$checkin_qr_url?>" alt="QR Check-in">
    <small style="color:#d1fae5;font-size:10.5px;margin:4px 0 0">Quét mã để mở cổng sân</small>
  </div>
</div>

<!-- 5. Khu vực Cổng Thanh Toán Demo & Chi tiết hóa đơn -->
<div style="display:grid;grid-template-columns:1.35fr 1fr;gap:24px;align-items:start">
  <!-- Cột trái: Giao diện Cổng Thanh Toán -->
  <div class="box" style="text-align:center;padding:28px">
    <?php if ($bk['pay_status'] === 'paid'): ?>
      <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:16px;padding:28px">
        <div style="font-size:52px;margin-bottom:10px">🏆</div>
        <h3 style="margin:0 0 8px;color:#14532d;font-family:var(--font-sport);font-size:22px">SẴN SÀNG CHÁY HẾT MÌNH TRÊN SÂN CỎ!</h3>
        <p style="color:#166534;font-size:14px;margin:0 0 18px">Đơn đặt sân đã thanh toán thành công. Vé điện tử QR ở phía trên đã có hiệu lực để Check-in tại sân.</p>
        <div style="max-width:420px;margin:0 auto;background:#fff;border-radius:12px;padding:16px;border:1px solid #86efac;text-align:left;font-size:13.5px">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span>Mã giao dịch:</span><b style="color:#16a34a"><?=e($bk['transaction_code'] ?: 'TXN-DEMO')?></b></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span>Phương thức:</span><b><?=e($methodLabels[$bk['method']] ?? $bk['method'])?></b></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span>Số tiền đã trả:</span><b style="color:#16a34a;font-size:16px"><?=money($amount)?></b></div>
          <div style="display:flex;justify-content:space-between"><span>Thời gian TT:</span><b><?=e($bk['paid_at'])?></b></div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <button type="button" class="btn" onclick="window.print()">🖨 Tải / In vé QR về máy</button>
          <a href="matchmaking.php?new=1" class="btn bl">⚔️ Chia sẻ lên Sàn Cáp Kèo</a>
          <a href="my-bookings.php" class="btn outline">Xem Lịch Đặt Của Tôi →</a>
        </div>
      </div>
    <?php else: ?>
      <form method="post" style="margin-bottom:20px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="change_method">
        <?php foreach (['bank'=>'🏦 VietQR Ngân hàng','momo'=>'📱 Ví MoMo','vnpay'=>'💳 Cổng VNPay','cash'=>'💵 Tiền mặt'] as $mk=>$mv): ?>
          <button type="submit" name="method" value="<?=$mk?>" class="btn sm <?=$bk['method']===$mk?'':'outline'?>" style="border-radius:999px">
            <?=$mv?>
          </button>
        <?php endforeach; ?>
      </form>

      <?php
      $feePct  = get_platform_fee_percent();
      $feeAmt  = (int)round($amount * ($feePct / 100));
      $ownerNet = max(0, $amount - $feeAmt);
      ?>

      <div style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #86efac;border-radius:16px;padding:22px;margin-bottom:20px">
        <span class="tag green" style="margin-bottom:10px">⏳ Đang chờ thanh toán • Thời gian giữ chỗ: <b id="payTimer">10:00</b></span>
        <h3 style="margin:6px 0;color:#064e3b;font-family:var(--font-sport);font-size:19px"><?=e($methodLabels[$bk['method']] ?? 'Thanh toán trực tuyến')?></h3>

        <?php if ($bk['method'] === 'vnpay'): ?>
          <div style="max-width:380px;margin:16px auto;background:#fff;padding:18px;border-radius:14px;border:1px solid #cbd5e1;text-align:left">
            <b style="display:block;margin-bottom:10px;color:#1d4ed8">💳 Thẻ ATM / Tài khoản Ngân hàng Demo (VNPay)</b>
            <label style="font-size:12px">Ngân hàng Demo</label>
            <select style="margin-bottom:10px;width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1"><option>NCB - Ngân hàng Quốc Dân (Test Card)</option><option>Vietcombank Demo</option><option>MBBank Demo</option></select>
            <label style="font-size:12px">Số thẻ Demo</label>
            <input value="9704198526191432198" readonly style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;background:#f8fafc;margin-bottom:10px">
            <label style="font-size:12px">Tên chủ thẻ & OTP Demo</label>
            <input value="NGUYEN VAN A - OTP: 123456" readonly style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;background:#f8fafc">
          </div>
        <?php else: ?>
          <div style="margin:16px auto;display:inline-block;padding:14px;background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,0.08);border:2px solid <?=$bk['method']==='momo'?'#d946ef':'#16a34a'?>">
            <img src="<?=$qr_url?>" alt="QR Code Thanh toán" style="max-width:230px;display:block;border-radius:8px">
          </div>
        <?php endif; ?>

        <div style="max-width:420px;margin:0 auto;background:#fff;padding:14px 16px;border-radius:12px;border:1px solid #d1fae5;text-align:left;font-size:13.5px">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="color:#64748b">Tài khoản Admin thu hộ:</span>
            <b><?=e($bank_id)?> - <?=e($account_no)?></b>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="color:#64748b">Đơn vị thụ hưởng (Admin):</span>
            <b><?=e($account_name)?></b>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="color:#64748b">Số tiền thanh toán:</span>
            <b style="color:#ef4444;font-size:16px"><?=money($amount)?></b>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span style="color:#64748b">Nội dung CK:</span>
            <b style="background:#ecfdf5;color:#16a34a;padding:2px 8px;border-radius:6px"><?=$memo?></b>
          </div>
        </div>
      </div>

      <div style="background:#f8fafc;border:1px dashed #94a3b8;border-radius:14px;padding:18px">
        <b style="display:block;color:#0f172a;margin-bottom:6px">🧪 Bảng Điều Khiển Giả Lập Thanh Toán (Chế độ Demo)</b>
        <small style="color:#64748b;margin-bottom:14px">Bấm nút bên dưới để mô phỏng hệ thống ngân hàng trả kết quả thanh toán thành công tức thì:</small>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
          <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="simulate_success">
            <button type="submit" class="btn" style="padding:12px 22px;font-size:14.5px">
              ✅ Xác nhận Đã Chuyển Khoản về TK Admin (Giả lập 1-Click)
            </button>
          </form>
          <form method="post">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="simulate_fail">
            <button type="submit" class="btn red sm" style="padding:12px 16px">
              ❌ Giả lập Giao dịch Lỗi / Hủy
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Cột phải: Chi tiết hóa đơn đặt sân -->
  <div class="box">
    <h3 style="margin:0 0 14px;font-family:var(--font-sport);font-size:18px;color:#0f172a">🧾 CHI TIẾT HÓA ĐƠN #<?=$bk['id']?></h3>
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e2e8f0">
      <img src="<?=e(img_src($bk['image']))?>" alt="" style="width:76px;height:58px;border-radius:10px;object-fit:cover">
      <div>
        <b style="font-size:16px;color:#0f172a"><?=e($bk['fname'])?></b>
        <small>📍 <?=e($bk['address'])?></small>
        <span class="tag green">Sân <?=e($bk['ftype'])?> người</span>
      </div>
    </div>

    <div style="font-size:13.5px;display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;justify-content:space-between"><span style="color:#64748b">Khách hàng:</span><b><?=e($u['full_name'])?> (<?=e($u['phone'])?>)</b></div>
      <div style="display:flex;justify-content:space-between"><span style="color:#64748b">Ngày thi đấu:</span><b><?=date('d/m/Y', strtotime($bk['booking_date']))?></b></div>
      <div style="display:flex;justify-content:space-between"><span style="color:#64748b">Khung giờ:</span><b><?=substr($bk['start_time'],0,5)?> - <?=substr($bk['end_time'],0,5)?></b></div>
      <div style="display:flex;justify-content:space-between"><span style="color:#64748b">Trạng thái đơn:</span><span class="tag <?=$bk['status']==='confirmed'?'green':'blue'?>"><?=$bk['status']==='confirmed'?'Đã xác nhận':'Chờ xác nhận'?></span></div>
      <div style="display:flex;justify-content:space-between"><span style="color:#64748b">Trạng thái TT:</span><span class="tag <?=$bk['pay_status']==='paid'?'green':'amber'?>"><?=$bk['pay_status']==='paid'?'Đã thanh toán':'Chờ thanh toán'?></span></div>
    </div>


    <div style="margin-top:16px;padding-top:14px;border-top:1.5px dashed #cbd5e1;display:flex;justify-content:space-between;align-items:center">
      <b style="font-size:15px">Tổng cộng:</b>
      <b style="font-family:var(--font-sport);font-size:24px;color:#16a34a"><?=money($amount)?></b>
    </div>
  </div>
</div>

<!-- Đồng hồ đếm ngược thời gian giữ chỗ thanh toán (10 phút) -->
<script>
let sec = 600;
const timerEl = document.getElementById('payTimer');
if (timerEl) {
  setInterval(() => {
    if (sec > 0) sec--;
    const m = String(Math.floor(sec / 60)).padStart(2, '0');
    const s = String(sec % 60).padStart(2, '0');
    timerEl.textContent = `${m}:${s}`;
  }, 1000);
}
</script>

<?php include '../includes/footer.php'; ?>
