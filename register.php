<?php
/**
 * FILE: register.php
 * MÔ TẢ: Trang đăng ký tài khoản mới dành cho Khách hàng.
 * CHỨC NĂNG: Kiểm tra tính hợp lệ của dữ liệu đầu vào, kiểm tra trùng username/email và mã hóa mật khẩu Bcrypt.
 */
require_once 'includes/auth.php';
$err=[]; $d=['username'=>'','full_name'=>'','phone'=>'','email'=>''];

// Xử lý khi người dùng gửi biểu mẫu đăng ký (POST)
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  foreach($d as $k=>$_) $d[$k]=trim($_POST[$k]??'');
  $p=$_POST['password']??'';
  if(!preg_match('/^[A-Za-z0-9_]{4,30}$/',$d['username'])) $err[]='Tên đăng nhập 4-30 ký tự (chữ, số, _).';
  if(mb_strlen($d['full_name'])<2) $err[]='Vui lòng nhập họ tên.';
  if(!preg_match('/^0\d{9}$/',$d['phone'])) $err[]='Số điện thoại phải gồm 10 số, bắt đầu bằng 0.';
  if(!filter_var($d['email'],FILTER_VALIDATE_EMAIL)) $err[]='Email không hợp lệ.';
  if(strlen($p)<6) $err[]='Mật khẩu tối thiểu 6 ký tự.';
  if($p!==($_POST['password2']??'')) $err[]='Mật khẩu nhập lại không khớp.';
  if(!$err){
    $s=$pdo->prepare("SELECT COUNT(*) FROM users WHERE username=? OR email=?"); $s->execute([$d['username'],$d['email']]);
    if($s->fetchColumn()) $err[]='Tên đăng nhập hoặc email đã tồn tại.';
    else { 
      $pdo->prepare("INSERT INTO users(username,password,full_name,phone,email) VALUES(?,?,?,?,?)")
        ->execute([$d['username'],password_hash($p,PASSWORD_DEFAULT),$d['full_name'],$d['phone'],$d['email']]);
      flash('ok','Đăng ký thành công! Vui lòng đăng nhập.'); 
      header('Location: login.php'); 
      exit; 
    }
  }
}
?><!DOCTYPE html>
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
  <!-- Biểu mẫu đăng ký thành viên -->
  <form class="auth-box" method="post" style="width:min(500px, 94vw);">
    <h2>⚽ Tạo tài khoản mới</h2>
    <div class="auth-subtitle">Trở thành thành viên để đặt sân nhanh & nhận nhiều ưu đãi</div>

    <?php foreach($err as $m): ?>
      <div class="alert err">
        <span class="alert-icon">⚠️</span>
        <div class="alert-text"><?=e($m)?></div>
      </div>
    <?php endforeach; ?>

    <?=csrf_field()?>
    
    <label>Tên đăng nhập</label>
    <input name="username" placeholder="Ví dụ: nguyenvana" required value="<?=e($d['username'])?>">

    <label>Họ và tên</label>
    <input name="full_name" placeholder="Ví dụ: Nguyễn Văn A" required value="<?=e($d['full_name'])?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div>
        <label>Số điện thoại</label>
        <input name="phone" placeholder="0901234567" required value="<?=e($d['phone'])?>">
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" placeholder="email@vi-du.com" required value="<?=e($d['email'])?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div>
        <label>Mật khẩu</label>
        <input type="password" name="password" placeholder="Tối thiểu 6 ký tự" required>
      </div>
      <div>
        <label>Xác nhận mật khẩu</label>
        <input type="password" name="password2" placeholder="Nhập lại mật khẩu" required>
      </div>
    </div>

    <button class="btn" style="margin-top:22px" type="submit">Đăng ký tài khoản →</button>

    <div class="auth-links">
      Đã có tài khoản? <a href="login.php"><b>Đăng nhập tại đây</b></a> &nbsp;•&nbsp; <a href="index.php">← Về trang chủ</a>
    </div>
  </form>
</body>
</html>
