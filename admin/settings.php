<?php
/**
 * ============================================================================
 * FILE: admin/settings.php
 * MÔ TẢ: Trang Cài đặt Hệ thống, Cấu hình Trợ lý AI & Tài khoản Ngân hàng VietQR.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý lưu thông tin Hồ sơ Admin, Đổi mật khẩu và Cấu hình AI/VietQR (POST).
 *   - Phần 2: Biểu mẫu cấu hình Trợ lý AI Tư vấn & Cổng Thanh Toán Demo.
 *   - Phần 3: Biểu mẫu cập nhật thông tin cá nhân & Đổi mật khẩu Admin.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ LƯU CÀI ĐẶT KHI ADMIN GỬI BIỂU MẪU (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? '';

    // 1.1. Cập nhật thông tin hồ sơ cá nhân của Admin
    if ($a === 'profile') {
        $n = trim($_POST['full_name'] ?? '');
        $p = trim($_POST['phone'] ?? '');
        $m = trim($_POST['email'] ?? '');

        // Kiểm tra email có bị trùng với tài khoản khác hay không
        $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $s->execute([$m, $adm['id']]);

        if (mb_strlen($n) < 2 || !preg_match('/^0\d{9}$/', $p) || !filter_var($m, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'Thông tin không hợp lệ (SĐT gồm 10 số bắt đầu bằng 0, email đúng định dạng).');
        } elseif ($s->fetchColumn()) {
            flash('err', 'Địa chỉ Email này đã được sử dụng bởi tài khoản khác.');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?");
            $stmt->execute([$n, $p, $m, $adm['id']]);
            flash('ok', 'Đã cập nhật thông tin tài khoản Admin.');
        }
    }
    // 1.2. Đổi mật khẩu đăng nhập của Admin
    elseif ($a === 'password') {
        $old  = $_POST['old'] ?? '';
        $new  = $_POST['new'] ?? '';
        $new2 = $_POST['new2'] ?? '';

        if (!password_verify($old, $adm['password'])) {
            flash('err', 'Mật khẩu hiện tại không đúng.');
        } elseif (strlen($new) < 6) {
            flash('err', 'Mật khẩu mới phải có tối thiểu 6 ký tự.');
        } elseif ($new !== $new2) {
            flash('err', 'Mật khẩu xác nhận nhập lại không khớp.');
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $adm['id']]);
            flash('ok', 'Đã đổi mật khẩu Quản trị viên thành công.');
        }
    }
    // 1.3. Lưu cấu hình Trợ lý AI (Gemini API Key) và tài khoản nhận tiền VietQR Demo
    elseif ($a === 'ai_payment') {
        set_setting('ai_bot_name',       trim($_POST['ai_bot_name'] ?? 'Trợ lý AI Sân Bóng'));
        set_setting('gemini_api_key',    trim($_POST['gemini_api_key'] ?? ''));
        set_setting('bank_id',           strtoupper(trim($_POST['bank_id'] ?? 'MB')));
        set_setting('bank_account_no',   trim($_POST['bank_account_no'] ?? '0901234567'));
        set_setting('bank_account_name', strtoupper(trim($_POST['bank_account_name'] ?? 'HE THONG SAN BONG PRO')));
        set_setting('auto_confirm_paid', isset($_POST['auto_confirm_paid']) ? '1' : '0');

        flash('ok', 'Đã lưu cấu hình Trợ lý AI & Cổng thanh toán Demo.');
    }

    header('Location: settings.php');
    exit;
}

$page  = 'settings';
$title = 'Cài đặt Hệ thống, AI & Thanh toán Demo';
include '../includes/admin_header.php';
?>

<!-- ==========================================================================
     PHẦN 2: BIỂU MẪU CẤU HÌNH TRỢ LÝ AI & CỔNG THANH TOÁN VIETQR DEMO
     ========================================================================== -->
<div class="box" style="margin-bottom:18px">
  <b>🤖 Cấu hình Trợ lý AI Tư vấn & Cổng Thanh Toán Demo (VietQR / MoMo / VNPay)</b>

  <form class="form" method="post" style="max-width:100%;margin-top:12px">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="ai_payment">

    <div class="two">
      <!-- Cột trái: Cấu hình tên Bot AI và khóa Google Gemini API -->
      <div>
        <label>Tên Trợ lý AI hiển thị</label>
        <input
          name="ai_bot_name"
          value="<?=e(get_setting('ai_bot_name', 'Trợ lý AI Sân Bóng'))?>"
        >

        <label>Google Gemini API Key (Để trống sẽ dùng Smart Local AI tích hợp sẵn)</label>
        <input
          name="gemini_api_key"
          placeholder="AIzaSy' . '..."
          value="<?=e(get_setting('gemini_api_key', ''))?>"
        >

        <label style="display:flex;align-items:center;gap:8px;margin-top:14px;cursor:pointer">
          <input
            type="checkbox"
            name="auto_confirm_paid"
            value="1"
            <?=get_setting('auto_confirm_paid', '1') === '1' ? 'checked' : ''?>
            style="width:auto;min-height:auto;margin:0"
          >
          <span>Tự động chuyển trạng thái đơn sang "Đã xác nhận" khi khách hoàn tất Thanh toán Demo</span>
        </label>
      </div>

      <!-- Cột phải: Cấu hình ngân hàng tạo mã QR tự động -->
      <div>
        <label>Mã Ngân hàng VietQR (VD: MB, VCB, TCB, ACB, BIDV)</label>
        <input
          name="bank_id"
          value="<?=e(get_setting('bank_id', 'MB'))?>"
        >

        <label>Số tài khoản nhận tiền Demo</label>
        <input
          name="bank_account_no"
          value="<?=e(get_setting('bank_account_no', '0901234567'))?>"
        >

        <label>Tên chủ tài khoản</label>
        <input
          name="bank_account_name"
          value="<?=e(get_setting('bank_account_name', 'HE THONG SAN BONG PRO'))?>"
        >
      </div>
    </div>

    <br>
    <button class="btn" type="submit">💾 Lưu cấu hình AI & Thanh toán</button>
  </form>
</div>

<!-- ==========================================================================
     PHẦN 3: BIỂU MẪU CẬP NHẬT HỒ SƠ CÁ NHÂN & ĐỔI MẬT KHẨU ADMIN
     ========================================================================== -->
<div class="two">
  <!-- Cột 1: Thông tin liên hệ của Admin -->
  <div class="box">
    <b>👤 Thông tin tài khoản Admin</b>
    <form class="form" method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="profile">

      <label>Họ và tên</label>
      <input name="full_name" required value="<?=e($adm['full_name'])?>">

      <label>Số điện thoại Hotline</label>
      <input name="phone" required value="<?=e($adm['phone'])?>">

      <label>Địa chỉ Email liên hệ</label>
      <input type="email" name="email" required value="<?=e($adm['email'])?>">

      <br><br>
      <button class="btn" type="submit">Lưu thay đổi</button>
    </form>
  </div>

  <!-- Cột 2: Đổi mật khẩu bảo mật cho Admin -->
  <div class="box">
    <b>🔒 Đổi mật khẩu Quản trị</b>
    <form class="form" method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="password">

      <label>Mật khẩu hiện tại</label>
      <input type="password" name="old" required>

      <label>Mật khẩu mới (Tối thiểu 6 ký tự)</label>
      <input type="password" name="new" required>

      <label>Nhập lại mật khẩu mới</label>
      <input type="password" name="new2" required>

      <br><br>
      <button class="btn" type="submit">Đổi mật khẩu</button>
    </form>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
