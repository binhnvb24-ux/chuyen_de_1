<?php
/**
 * ============================================================================
 * FILE: register.php
 * MÔ TẢ: Trang đăng ký tài khoản mới dành cho Khách hàng (Cầu thủ / Đội bóng).
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Kiểm tra dữ liệu đầu vào (Validate), kiểm tra trùng lặp và lưu CSDL.
 *   - Phần 2: Giao diện biểu mẫu Đăng ký tài khoản thành viên.
 * ============================================================================
 */

require_once 'includes/auth.php';

// Khởi tạo mảng lưu danh sách lỗi và mảng dữ liệu mặc định của biểu mẫu
$err = [];
$d   = [
    'username'  => '',
    'full_name' => '',
    'phone'     => '',
    'email'     => ''
];

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ KHI NGƯỜI DÙNG GỬI BIỂU MẪU ĐĂNG KÝ (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1.1. Kiểm tra mã bảo mật CSRF Token
    csrf_check();

    // 1.2. Đọc và cắt khoảng trắng thừa từ dữ liệu người dùng nhập
    foreach ($d as $k => $_) {
        $d[$k] = trim($_POST[$k] ?? '');
    }
    $p  = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';

    // 1.3. Kiểm tra tính hợp lệ (Validation) của từng trường thông tin
    if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $d['username'])) {
        $err[] = 'Tên đăng nhập phải từ 4-30 ký tự (chỉ gồm chữ cái, số và dấu gạch dưới _).';
    }
    if (mb_strlen($d['full_name']) < 2) {
        $err[] = 'Vui lòng nhập đầy đủ họ và tên.';
    }
    if (!preg_match('/^0\d{9}$/', $d['phone'])) {
        $err[] = 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'Địa chỉ Email không đúng định dạng.';
    }
    if (strlen($p) < 6) {
        $err[] = 'Mật khẩu phải có tối thiểu 6 ký tự.';
    }
    if ($p !== $p2) {
        $err[] = 'Mật khẩu xác nhận nhập lại không khớp.';
    }

    // 1.4. Nếu dữ liệu hợp lệ, kiểm tra trùng username hoặc email trong CSDL
    if (!$err) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $s->execute([$d['username'], $d['email']]);

        if ($s->fetchColumn()) {
            $err[] = 'Tên đăng nhập hoặc địa chỉ Email này đã được sử dụng.';
        } else {
            // 1.5. Mã hóa mật khẩu bằng chuẩn Bcrypt (PASSWORD_DEFAULT) và thêm vào CSDL
            $hashed_password = password_hash($p, PASSWORD_DEFAULT);

            $insertStmt = $pdo->prepare("
                INSERT INTO users (username, password, full_name, phone, email)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $d['username'],
                $hashed_password,
                $d['full_name'],
                $d['phone'],
                $d['email']
            ]);

            // 1.6. Gửi thông báo thành công và chuyển sang trang Đăng nhập
            flash('ok', 'Đăng ký tài khoản thành công! Vui lòng đăng nhập.');
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Đăng ký tài khoản - Sân Bóng</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth">

  <!-- ========================================================================
       PHẦN 2: GIAO DIỆN BIỂU MẪU ĐĂNG KÝ THÀNH VIÊN MỚI
       ======================================================================== -->
  <form class="auth-box" method="post" style="width:min(500px, 94vw);">
    <h2>⚽ Tạo tài khoản mới</h2>
    <div class="auth-subtitle">Trở thành thành viên để đặt sân nhanh & nhận nhiều ưu đãi</div>

    <!-- 2.1. Hiển thị danh sách các lỗi nhập liệu (nếu có) -->
    <?php foreach ($err as $m): ?>
      <div class="alert err">
        <span class="alert-icon">⚠️</span>
        <div class="alert-text"><?=e($m)?></div>
      </div>
    <?php endforeach; ?>

    <?=csrf_field()?>

    <!-- 2.2. Các trường nhập thông tin tài khoản -->
    <label>Tên đăng nhập</label>
    <input
      name="username"
      placeholder="Ví dụ: nguyenvana"
      required
      value="<?=e($d['username'])?>"
    >

    <label>Họ và tên</label>
    <input
      name="full_name"
      placeholder="Ví dụ: Nguyễn Văn A"
      required
      value="<?=e($d['full_name'])?>"
    >

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div>
        <label>Số điện thoại</label>
        <input
          name="phone"
          placeholder="0901234567"
          required
          value="<?=e($d['phone'])?>"
        >
      </div>
      <div>
        <label>Email</label>
        <input
          type="email"
          name="email"
          placeholder="email@vi-du.com"
          required
          value="<?=e($d['email'])?>"
        >
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div>
        <label>Mật khẩu</label>
        <input
          type="password"
          name="password"
          placeholder="Tối thiểu 6 ký tự"
          required
        >
      </div>
      <div>
        <label>Xác nhận mật khẩu</label>
        <input
          type="password"
          name="password2"
          placeholder="Nhập lại mật khẩu"
          required
        >
      </div>
    </div>

    <button class="btn" style="margin-top:22px" type="submit">Đăng ký tài khoản →</button>

    <!-- 2.3. Liên kết chuyển sang trang Đăng nhập hoặc về Trang chủ -->
    <div class="auth-links">
      Đã có tài khoản? <a href="login.php"><b>Đăng nhập tại đây</b></a>
      &nbsp;•&nbsp;
      <a href="index.php">← Về trang chủ</a>
    </div>
  </form>

</body>
</html>
