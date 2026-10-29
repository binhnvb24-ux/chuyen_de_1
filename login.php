<?php
/**
 * FILE: login.php
 * MÔ TẢ: Trang đăng nhập hệ thống dành cho Khách hàng và Quản trị viên.
 * CHỨC NĂNG: Kiểm tra thông tin đăng nhập bằng password_verify(), chống CSRF và phân luồng theo vai trò (role).
 */
require_once 'includes/auth.php';
$err='';

// Xử lý dữ liệu khi người dùng nhấn nút Đăng nhập (POST)
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $s=$pdo->prepare("SELECT * FROM users WHERE username=?"); $s->execute([trim($_POST['username']??'')]);
  $u=$s->fetch();
  if(!$u || !password_verify($_POST['password']??'',$u['password'])) $err='Sai tên đăng nhập hoặc mật khẩu.';
  elseif($u['status']!=='active') $err='Tài khoản đã bị khóa.';
  else { 
    session_regenerate_id(true); 
    $_SESSION['uid']=$u['id'];
    header('Location: '.($u['role']==='admin'?'admin/dashboard.php':'index.php')); 
    exit; 
  }
}
?><!DOCTYPE html>
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
  <!-- Biểu mẫu đăng nhập -->
  <form class="auth-box" method="post">
    <h2>⚽ Đăng nhập Sân Bóng</h2>
    <div class="auth-subtitle">Chào mừng bạn quay lại! Hãy đăng nhập để đặt sân</div>

    <?php if(!empty($_SESSION["flash"])): ?>
      <div class="alert ok">
        <span class="alert-icon">✔</span>
        <div class="alert-text"><?=e($_SESSION["flash"][1])?></div>
      </div>
      <?php unset($_SESSION["flash"]); ?>
    <?php endif; ?>

    <?php if($err): ?>
      <div class="alert err">
        <span class="alert-icon">⚠️</span>
        <div class="alert-text"><?=e($err)?></div>
      </div>
    <?php endif; ?>

    <?=csrf_field()?>
    <label>Tên đăng nhập</label>
    <input name="username" placeholder="Nhập tên đăng nhập..." required autofocus value="<?=e($_POST['username']??'')?>">

    <label>Mật khẩu</label>
    <input type="password" name="password" placeholder="••••••••" required>

    <button class="btn" type="submit">Đăng nhập ngay →</button>

    <div class="auth-links">
      Chưa có tài khoản? <a href="register.php"><b>Đăng ký ngay</b></a> &nbsp;•&nbsp; <a href="index.php">← Về trang chủ</a>
    </div>

    <div class="demo-hint">
      <strong>🔑 Tài khoản dùng thử:</strong><br>
      • Quản trị: <b>admin</b> / <code>password</code><br>
      • Khách hàng: <b>nguyenvana</b> / <code>password</code>
    </div>
  </form>
</body>
</html>
