<?php
/**
 * FILE: user/payment-demo.php
 * MÔ TẢ: Cổng Thanh Toán Trực Tuyến (Demo) & Biên lai điện tử.
 * CHỨC NĂNG: Sinh mã QR động chuẩn VietQR Napas247, giao diện MoMo / Thẻ ATM VNPay Test,
 *            và Bảng điều khiển giả lập thanh toán thành công 1-Click (tự động gạch nợ & duyệt đơn).
 */
require_once '../includes/auth.php';
$u = require_login();
if ($u['role'] === 'admin') { header('Location: ../admin/dashboard.php'); exit; }

// 1. Lấy thông tin đơn đặt sân và giao dịch thanh toán tương ứng
$bid = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$stmt = $pdo->prepare("SELECT b.*, f.name fname, f.address, f.type ftype, f.image, p.id pid, p.method, p.amount, p.status pay_status, p.transaction_code, p.paid_at 
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

// Lấy danh sách dịch vụ tiện ích đi kèm của đơn đặt sân
$svcStmt = $pdo->prepare("SELECT bs.*, s.name FROM booking_services bs JOIN services s ON s.id=bs.service_id WHERE bs.booking_id=?");
$svcStmt->execute([$bid]);
$bk_services = $svcStmt->fetchAll();

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
    $pdo->prepare("UPDATE payments SET status='paid', transaction_code=?, paid_at=NOW() WHERE booking_id=?")
        ->execute([$txn, $bid]);
    $pdo->prepare("UPDATE bookings SET status=? WHERE id=?")
        ->execute([$newBkStatus, $bid]);
    notify($u['id'], "Thanh toán Demo thành công đơn #$bid ({$bk['fname']}). Mã GD: $txn. Trạng thái đơn: Đã xác nhận.");
    $pdo->commit();

    flash('ok', "🎉 Thanh toán thành công đơn #$bid! Mã giao dịch: $txn. Lịch đặt sân của bạn đã được xác nhận.");
    header("Location: payment-demo.php?booking_id=$bid");
    exit;
  }

  if ($act === 'simulate_fail' && $bk['pay_status'] !== 'paid') {
    flash('err', '⚠️ Giả lập giao dịch bị hủy hoặc thất bại. Bạn có thể thử thanh toán lại bất cứ lúc nào.');
    header("Location: payment-demo.php?booking_id=$bid");
    exit;
  }
}

// 3. Khởi tạo đường dẫn ảnh mã QR tự động từ cấu hình ngân hàng VietQR
$bank_id = get_setting('bank_id', 'MB') ?: 'MB';
$account_no = get_setting('bank_account_no', '0901234567') ?: '0901234567';
$account_name = get_setting('bank_account_name', 'HE THONG SAN BONG PRO') ?: 'HE THONG SAN BONG PRO';
$amount = (int)$bk['total_price'];
$memo = "DATSAN" . $bk['id'];
$qr_url = "https://img.vietqr.io/image/{$bank_id}-{$account_no}-compact2.png?amount={$amount}&addInfo={$memo}&accountName=" . urlencode($account_name);

$methodLabels = [
  'bank'  => 'Chuyển khoản Ngân hàng VietQR (Napas247)',
  'momo'  => 'Ví điện tử MoMo (Cổng thanh toán Demo)',
  'vnpay' => 'Cổng VNPay QR / Thẻ ATM Nội địa (Demo)',
  'cash'  => 'Thanh toán Tiền mặt trực tiếp tại sân'
];

$active = 'mine';
$title = 'Cổng Thanh Toán Demo - Đơn #' . $bk['id'];
include '../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#0f172a">💳 Cổng Thanh Toán Trực Tuyến (Demo)</h2>
    <small style="color:#64748b;font-size:13.5px">Mô phỏng quy trình thanh toán tự động VietQR, MoMo, VNPay và cấp biên lai điện tử</small>
  </div>
  <a href="my-bookings.php" class="btn sm outline">← Quay lại Lịch đặt của tôi</a>
</div>

<div style="display:grid;grid-template-columns:1.35fr 1fr;gap:24px;align-items:start">
  <!-- Cột trái: Giao diện Cổng Thanh Toán -->
  <div class="box" style="text-align:center;padding:28px">
    <?php if ($bk['pay_status'] === 'paid'): ?>
      <div style="background:#ecfdf5;border:1.5px solid #6ee7b7;border-radius:16px;padding:28px">
        <div style="font-size:52px;margin-bottom:10px">✅</div>
        <h3 style="margin:0 0 8px;color:#065f46;font-size:22px">Đơn Đặt Sân Đã Thanh Toán Thành Công!</h3>
        <p style="color:#047857;font-size:14px;margin:0 0 18px">Cảm ơn bạn! Hệ thống đã ghi nhận thanh toán và xác nhận lịch thi đấu.</p>
        <div style="max-width:420px;margin:0 auto;background:#fff;border-radius:12px;padding:16px;border:1px solid #a7f3d0;text-align:left;font-size:13.5px">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span>Mã giao dịch:</span><b style="color:#059669"><?=e($bk['transaction_code'] ?: 'TXN-DEMO')?></b></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span>Phương thức:</span><b><?=e($methodLabels[$bk['method']] ?? $bk['method'])?></b></div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px"><span>Số tiền đã trả:</span><b style="color:#059669;font-size:16px"><?=money($amount)?></b></div>
          <div style="display:flex;justify-content:space-between"><span>Thời gian:</span><b><?=e($bk['paid_at'])?></b></div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:center">
          <a href="my-bookings.php" class="btn">Xem Lịch Đặt Của Tôi →</a>
          <button type="button" class="btn outline" onclick="window.print()">🖨 In biên lai</button>
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

      <div style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #86efac;border-radius:16px;padding:22px;margin-bottom:20px">
        <span class="tag green" style="margin-bottom:10px">⏳ Đang chờ thanh toán • Thời gian giữ chỗ: <b id="payTimer">10:00</b></span>
        <h3 style="margin:6px 0;color:#064e3b;font-size:19px"><?=e($methodLabels[$bk['method']] ?? 'Thanh toán trực tuyến')?></h3>

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
          <div style="margin:16px auto;display:inline-block;padding:14px;background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,0.08);border:2px solid <?=$bk['method']==='momo'?'#d946ef':'#10b981'?>">
            <img src="<?=$qr_url?>" alt="QR Code Thanh toán" style="max-width:230px;display:block;border-radius:8px">
          </div>
        <?php endif; ?>

        <div style="max-width:420px;margin:0 auto;background:#fff;padding:14px 16px;border-radius:12px;border:1px solid #d1fae5;text-align:left;font-size:13.5px">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="color:#64748b">Ngân hàng / Ví nhận:</span>
            <b><?=e($bank_id)?> - <?=e($account_no)?></b>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="color:#64748b">Chủ tài khoản:</span>
            <b><?=e($account_name)?></b>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="color:#64748b">Số tiền thanh toán:</span>
            <b style="color:#ef4444;font-size:16px"><?=money($amount)?></b>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span style="color:#64748b">Nội dung CK:</span>
            <b style="background:#ecfdf5;color:#059669;padding:2px 8px;border-radius:6px"><?=$memo?></b>
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
              ✅ Giả lập Thanh toán Thành công (1-Click)
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
    <h3 style="margin:0 0 14px;font-size:17px;color:#0f172a">🧾 Thông Tin Đơn Đặt Sân #<?=$bk['id']?></h3>
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

    <?php if ($bk_services): ?>
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0">
        <b style="font-size:13.5px;display:block;margin-bottom:8px">🥤 Dịch vụ đi kèm:</b>
        <?php foreach ($bk_services as $bs): ?>
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
            <span><?=e($bs['name'])?> (x<?=$bs['quantity']?>)</span>
            <b><?=money($bs['price'] * $bs['quantity'])?></b>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div style="margin-top:16px;padding-top:14px;border-top:1.5px dashed #cbd5e1;display:flex;justify-content:space-between;align-items:center">
      <b style="font-size:15px">Tổng cộng:</b>
      <b style="font-size:22px;color:#059669"><?=money($amount)?></b>
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
