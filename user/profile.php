<?php
/**
 * FILE: user/profile.php
 * MÔ TẢ: Trang quản lý Hồ sơ cá nhân của Khách hàng.
 * CHỨC NĂNG: Cập nhật họ tên, số điện thoại, địa chỉ email và đổi mật khẩu bảo mật.
 */
require_once '../includes/auth.php';
$u = require_login(); 
if($u['role'] === 'admin'){ header('Location: ../admin/settings.php'); exit; }

// Xử lý cập nhật thông tin liên hệ hoặc đổi mật khẩu (POST)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  csrf_check(); 
  $a = $_POST['action'] ?? '';
  if($a === 'profile'){
    $n = trim($_POST['full_name'] ?? ''); 
    $p = trim($_POST['phone'] ?? ''); 
    $m = trim($_POST['email'] ?? '');
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=? AND id<>?"); 
    $s->execute([$m, $u['id']]);
    if(mb_strlen($n) < 2 || !preg_match('/^0\d{9}$/', $p) || !filter_var($m, FILTER_VALIDATE_EMAIL)) 
      flash('err', 'Thông tin không hợp lệ (SĐT phải là 10 số bắt đầu bằng 0, email hợp lệ).');
    elseif($s->fetchColumn()) 
      flash('err', 'Email này đã được sử dụng bởi tài khoản khác.');
    else { 
      $pdo->prepare("UPDATE users SET full_name=?,phone=?,email=? WHERE id=?")->execute([$n, $p, $m, $u['id']]); 
      flash('ok', 'Cập nhật thông tin cá nhân thành công.'); 
    }
  } elseif($a === 'password'){
    $new = $_POST['new'] ?? '';
    if(!password_verify($_POST['old'] ?? '', $u['password'])) 
      flash('err', 'Mật khẩu hiện tại không chính xác.');
    elseif(strlen($new) < 6) 
      flash('err', 'Mật khẩu mới phải có tối thiểu 6 ký tự.');
    elseif($new !== ($_POST['new2'] ?? '')) 
      flash('err', 'Mật khẩu nhập lại không khớp.');
    else { 
      $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]); 
      flash('ok', 'Đã đổi mật khẩu thành công.'); 
    }
  }
  header('Location: profile.php'); 
  exit;
}

$active = 'prof'; 
$title = 'Hồ sơ cá nhân'; 
include '../includes/header.php'; 
?>

<div style="margin-bottom:24px">
  <h2 style="margin:0 0 6px;font-size:24px;font-weight:800;color:#0f172a">👤 Tài Khoản Cá Nhân</h2>
  <small style="font-size:13.5px;color:#64748b">Quản lý thông tin liên hệ và bảo mật tài khoản của bạn</small>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">
  <!-- Cột 1: Thông tin cá nhân -->
  <div class="box">
    <h3 style="margin:0 0 16px;font-size:17px;color:#0f172a;display:flex;align-items:center;gap:8px">
      📝 Thông tin liên hệ
    </h3>
    <form method="post" class="form" style="max-width:100%">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="profile">

      <label>Tên đăng nhập (Không thể thay đổi)</label>
      <input value="<?=e($u['username'])?>" disabled>

      <label>Họ và tên</label>
      <input name="full_name" required value="<?=e($u['full_name'])?>">

      <label>Số điện thoại nhận tin</label>
      <input name="phone" required value="<?=e($u['phone'])?>">

      <label>Địa chỉ Email</label>
      <input type="email" name="email" required value="<?=e($u['email'])?>">

      <button class="btn" style="margin-top:20px;width:100%" type="submit">Lưu thông tin cá nhân</button>
    </form>
  </div>

  <!-- Cột 2: Đổi mật khẩu -->
  <div class="box">
    <h3 style="margin:0 0 16px;font-size:17px;color:#0f172a;display:flex;align-items:center;gap:8px">
      🔒 Đổi mật khẩu bảo vệ
    </h3>
    <form method="post" class="form" style="max-width:100%">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="password">

      <label>Mật khẩu hiện tại</label>
      <input type="password" name="old" placeholder="••••••••" required>

      <label>Mật khẩu mới (Tối thiểu 6 ký tự)</label>
      <input type="password" name="new" placeholder="••••••••" required>

      <label>Nhập lại mật khẩu mới</label>
      <input type="password" name="new2" placeholder="••••••••" required>

      <button class="btn" style="margin-top:20px;width:100%" type="submit">Xác nhận đổi mật khẩu</button>
    </form>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
