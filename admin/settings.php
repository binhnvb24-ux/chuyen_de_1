<?php
/**
 * FILE: admin/settings.php
 * MÔ TẢ: Trang Cài đặt Hệ thống, Cấu hình Trợ lý AI & Tài khoản Ngân hàng VietQR.
 * CHỨC NĂNG: Lưu khóa Google Gemini API, tên Bot AI, thông tin tài khoản VietQR nhận tiền Demo,
 *            cấu hình tự động duyệt đơn và cập nhật hồ sơ/mật khẩu của Admin.
 */
require_once '../includes/auth.php';
$adm = require_admin();

// 1. Xử lý lưu thông tin cài đặt (Hồ sơ Admin, Đổi mật khẩu, Cấu hình AI & VietQR)
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check(); $a=$_POST['action']??'';
  if($a==='profile'){
    $n=trim($_POST['full_name']??''); $p=trim($_POST['phone']??''); $m=trim($_POST['email']??'');
    $s=$pdo->prepare("SELECT COUNT(*) FROM users WHERE email=? AND id<>?"); $s->execute([$m,$adm['id']]);
    if(mb_strlen($n)<2||!preg_match('/^0\d{9}$/',$p)||!filter_var($m,FILTER_VALIDATE_EMAIL)) flash('err','Thông tin không hợp lệ (SĐT 10 số bắt đầu bằng 0, email đúng định dạng).');
    elseif($s->fetchColumn()) flash('err','Email đã được sử dụng.');
    else { $pdo->prepare("UPDATE users SET full_name=?,phone=?,email=? WHERE id=?")->execute([$n,$p,$m,$adm['id']]); flash('ok','Đã cập nhật thông tin.'); }
  } elseif($a==='password'){
    $new=$_POST['new']??'';
    if(!password_verify($_POST['old']??'',$adm['password'])) flash('err','Mật khẩu hiện tại không đúng.');
    elseif(strlen($new)<6) flash('err','Mật khẩu mới tối thiểu 6 ký tự.');
    elseif($new!==($_POST['new2']??'')) flash('err','Mật khẩu nhập lại không khớp.');
    else { $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$adm['id']]); flash('ok','Đã đổi mật khẩu.'); }
  } elseif($a==='ai_payment'){
    set_setting('ai_bot_name', trim($_POST['ai_bot_name'] ?? 'Trợ lý AI Sân Bóng'));
    set_setting('gemini_api_key', trim($_POST['gemini_api_key'] ?? ''));
    set_setting('bank_id', strtoupper(trim($_POST['bank_id'] ?? 'MB')));
    set_setting('bank_account_no', trim($_POST['bank_account_no'] ?? '0901234567'));
    set_setting('bank_account_name', strtoupper(trim($_POST['bank_account_name'] ?? 'HE THONG SAN BONG PRO')));
    set_setting('auto_confirm_paid', isset($_POST['auto_confirm_paid']) ? '1' : '0');
    flash('ok', 'Đã lưu cấu hình Trợ lý AI & Cổng thanh toán Demo.');
  }
  header('Location: settings.php'); exit;
}
$page='settings'; $title='Cài đặt Hệ thống, AI & Thanh toán Demo'; include '../includes/admin_header.php'; ?>

<!-- 2. Biểu mẫu cấu hình Trợ lý AI & Cổng Thanh Toán Demo -->
<div class="box" style="margin-bottom:18px">
  <b>🤖 Cấu hình Trợ lý AI Tư vấn & Cổng Thanh Toán Demo (VietQR / MoMo / VNPay)</b>
  <form class="form" method="post" style="max-width:100%;margin-top:12px">
    <?=csrf_field()?><input type="hidden" name="action" value="ai_payment">
    <div class="two">
      <div>
        <label>Tên Trợ lý AI hiển thị</label>
        <input name="ai_bot_name" value="<?=e(get_setting('ai_bot_name','Trợ lý AI Sân Bóng'))?>">
        <label>Google Gemini API Key (Để trống sẽ dùng Smart Local AI tích hợp sẵn)</label>
        <input name="gemini_api_key" placeholder="AIzaSy..." value="<?=e(get_setting('gemini_api_key',''))?>">
        <label style="display:flex;align-items:center;gap:8px;margin-top:14px;cursor:pointer">
          <input type="checkbox" name="auto_confirm_paid" value="1" <?=get_setting('auto_confirm_paid','1')==='1'?'checked':''?> style="width:auto;min-height:auto;margin:0">
          <span>Tự động chuyển trạng thái đơn sang "Đã xác nhận" khi khách hoàn tất Thanh toán Demo</span>
        </label>
      </div>
      <div>
        <label>Mã Ngân hàng VietQR (VD: MB, VCB, TCB, ACB, BIDV)</label>
        <input name="bank_id" value="<?=e(get_setting('bank_id','MB'))?>">
        <label>Số tài khoản nhận tiền Demo</label>
        <input name="bank_account_no" value="<?=e(get_setting('bank_account_no','0901234567'))?>">
        <label>Tên chủ tài khoản</label>
        <input name="bank_account_name" value="<?=e(get_setting('bank_account_name','HE THONG SAN BONG PRO'))?>">
      </div>
    </div>
    <br><button class="btn">💾 Lưu cấu hình AI & Thanh toán</button>
  </form>
</div>

<!-- 3. Biểu mẫu cập nhật thông tin cá nhân & Đổi mật khẩu Admin -->
<div class="two">
  <div class="box"><b>Thông tin tài khoản Admin</b><form class="form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="profile">
  <label>Họ tên</label><input name="full_name" required value="<?=e($adm['full_name'])?>"><label>Số điện thoại</label><input name="phone" required value="<?=e($adm['phone'])?>"><label>Email</label><input type="email" name="email" required value="<?=e($adm['email'])?>"><br><br><button class="btn">Lưu thay đổi</button></form></div>
  <div class="box"><b>Đổi mật khẩu</b><form class="form" method="post"><?=csrf_field()?><input type="hidden" name="action" value="password">
  <label>Mật khẩu hiện tại</label><input type="password" name="old" required><label>Mật khẩu mới</label><input type="password" name="new" required><label>Nhập lại mật khẩu mới</label><input type="password" name="new2" required><br><br><button class="btn">Đổi mật khẩu</button></form></div>
</div>
<?php include '../includes/admin_footer.php'; ?>
