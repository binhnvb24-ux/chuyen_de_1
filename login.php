<?php
/**
 * ============================================================================
 * FILE: login.php
 * MÔ TẢ: Trang đăng nhập hệ thống dành cho Khách hàng và Quản trị viên.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý xác thực tài khoản và mật khẩu khi gửi biểu mẫu (POST).
 *   - Phần 2: Giao diện biểu mẫu Đăng nhập và gợi ý tài khoản dùng thử.
 * ============================================================================
 */

require_once 'includes/auth.php';

// Khởi tạo biến chứa thông báo lỗi đăng nhập
$err = '';

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ DỮ LIỆU KHI NGƯỜI DÙNG NHẤN NÚT ĐĂNG NHẬP (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1.1. Kiểm tra mã bảo mật CSRF Token
    csrf_check();

    // 1.2. Lấy thông tin tên đăng nhập và mật khẩu từ biểu mẫu
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1.3. Truy vấn tìm tài khoản theo username trong CSDL
    $s = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $s->execute([$username]);
    $u = $s->fetch();

    // 1.4. Kiểm tra mật khẩu bằng hàm password_verify() và kiểm tra trạng thái tài khoản
    if (!$u || !password_verify($password, $u['password'])) {
        $err = 'Sai tên đăng nhập hoặc mật khẩu.';
    } elseif ($u['status'] !== 'active') {
        $err = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Quản trị viên.';
    } else {
        // 1.5. Đăng nhập thành công: Làm mới Session ID chống Session Fixation
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];

        // 1.6. Điều hướng theo phân quyền: Admin vào Bảng điều khiển, User về Trang chủ
        if ($u['role'] === 'admin') {
            header('Location: admin/dashboard.php');
        } else {
            header('Location: index.php');
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Đăng nhập - Hệ thống Sân Bóng</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth">

  <!-- ========================================================================
       PHẦN 2: GIAO DIỆN BIỂU MẪU ĐĂNG NHẬP HỆ THỐNG
       ======================================================================== -->
  <form class="auth-box" method="post">
    <h2>⚽ Đăng nhập Sân Bóng</h2>
    <div class="auth-subtitle">Chào mừng bạn quay lại! Hãy đăng nhập để đặt sân</div>

    <!-- 2.1. Hiển thị thông báo Flash (VD: vừa đăng ký tài khoản thành công) -->
    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="alert ok">
        <span class="alert-icon">✔</span>
        <div class="alert-text"><?=e($_SESSION['flash'][1])?></div>
      </div>
      <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- 2.2. Hiển thị thông báo lỗi nếu đăng nhập thất bại -->
    <?php if ($err): ?>
      <div class="alert err">
        <span class="alert-icon">⚠️</span>
        <div class="alert-text"><?=e($err)?></div>
      </div>
    <?php endif; ?>

    <!-- 2.3. Trường ẩn bảo mật CSRF và các ô nhập liệu -->
    <?=csrf_field()?>

    <label>Tên đăng nhập</label>
    <input
      name="username"
      placeholder="Nhập tên đăng nhập..."
      required
      autofocus
      value="<?=e($_POST['username'] ?? '')?>"
    >

    <label>Mật khẩu</label>
    <input
      type="password"
      name="password"
      placeholder="••••••••"
      required
    >

    <button class="btn" type="submit">Đăng nhập ngay →</button>

    <!-- 2.4. Liên kết chuyển sang trang Đăng ký hoặc quay về Trang chủ -->
    <div class="auth-links">
      Chưa có tài khoản? <a href="register.php"><b>Đăng ký ngay</b></a>
      &nbsp;•&nbsp;
      <a href="index.php">← Về trang chủ</a>
    </div>

    <!-- 2.5. Hộp gợi ý tài khoản mẫu phục vụ chấm đồ án / kiểm thử -->
    <div class="demo-hint">
      <strong>🔑 Tài khoản dùng thử:</strong><br>
      • Quản trị: <b>admin</b> / <code>password</code><br>
      • Khách hàng: <b>nguyenvana</b> / <code>password</code>
    </div>
  </form>

</body>
</html>
