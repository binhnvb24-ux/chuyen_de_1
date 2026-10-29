<?php
/**
 * FILE: admin/users.php
 * MÔ TẢ: Trang Quản lý Người dùng dành cho Admin.
 * CHỨC NĂNG: Tìm kiếm tài khoản, xem lịch sử đặt sân của từng khách hàng, khóa/mở khóa tài khoản và đổi quyền (User/Admin).
 */
require_once '../includes/auth.php';
$adm=require_admin();

// 1. Xử lý khóa/mở khóa tài khoản hoặc thay đổi phân quyền (POST)
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check(); $id=(int)$_POST['id']; $a=$_POST['action']??'';
  if($id===(int)$adm['id']) flash('err','Không thể thay đổi chính tài khoản của bạn.');
  elseif($a==='toggle'){ $pdo->prepare("UPDATE users SET status=IF(status='active','locked','active') WHERE id=?")->execute([$id]); flash('ok','Đã đổi trạng thái tài khoản.'); }
  elseif($a==='role'){ $pdo->prepare("UPDATE users SET role=IF(role='admin','user','admin') WHERE id=?")->execute([$id]); flash('ok','Đã đổi phân quyền.'); }
  header('Location: users.php'); exit;
}

// 2. Tìm kiếm người dùng và lấy lịch sử đặt sân nếu bấm "Lịch sử" (?view=id)
$q=trim($_GET['q']??''); $s=$pdo->prepare("SELECT u.*,(SELECT COUNT(*) FROM bookings WHERE user_id=u.id) n FROM users u WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? ORDER BY id");
$s->execute(["%$q%","%$q%","%$q%"]); $rows=$s->fetchAll();
$hist=[]; $hu=null;
if(isset($_GET['view'])){ $s=$pdo->prepare("SELECT * FROM users WHERE id=?"); $s->execute([(int)$_GET['view']]); $hu=$s->fetch();
  if($hu){ $s=$pdo->prepare("SELECT b.*,f.name FROM bookings b JOIN fields f ON f.id=b.field_id WHERE user_id=? ORDER BY booking_date DESC"); $s->execute([$hu['id']]); $hist=$s->fetchAll(); } }
$lbl=['pending'=>'Chờ xác nhận','confirmed'=>'Đã xác nhận','cancelled'=>'Đã hủy','completed'=>'Hoàn thành'];
$page='users'; $title='Quản lý người dùng'; include '../includes/admin_header.php'; ?>

<!-- 3. Giao diện tìm kiếm, xem lịch sử đặt sân của khách và bảng danh sách tài khoản -->
<form class="sbar" method="get" style="background:#fff;margin:0 0 14px"><input name="q" placeholder="Tìm theo tên, tài khoản, email" value="<?=e($q)?>"><button class="btn">Tìm</button></form>
<?php if($hu): ?><div class="box" style="margin-bottom:14px"><div class="bh"><b>Lịch sử đặt sân: <?=e($hu['full_name'])?> (<?=e($hu['phone'])?>)</b><a href="users.php">Đóng</a></div>
<?php if(!$hist): ?><div class="empty">Chưa có lịch đặt.</div><?php else: ?><div class="tw"><table><tr><th>Sân</th><th>Ngày</th><th>Giờ</th><th>Tiền</th><th>Trạng thái</th></tr><?php foreach($hist as $h): ?><tr><td><?=e($h['name'])?></td><td><?=date('d/m/Y',strtotime($h['booking_date']))?></td><td><?=substr($h['start_time'],0,5)?>-<?=substr($h['end_time'],0,5)?></td><td><?=money($h['total_price'])?></td><td><?=$lbl[$h['status']]?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div><?php endif; ?>
<div class="box"><?php if(!$rows): ?><div class="empty">Không tìm thấy người dùng.</div><?php else: ?><div class="tw"><table><tr><th>Tài khoản</th><th>Họ tên</th><th>Email</th><th>Vai trò</th><th>Trạng thái</th><th>Lượt đặt</th><th>Thao tác</th></tr>
<?php foreach($rows as $r): $self=$r['id']==$adm['id']; ?><tr><td><?=e($r['username'])?></td><td><?=e($r['full_name'])?></td><td><?=e($r['email'])?></td><td><?=$r['role']==='admin'?'Admin':'User'?></td>
<td><span class="tag <?=$r['status']==='active'?'green':'gray'?>"><?=$r['status']==='active'?'Hoạt động':'Đã khóa'?></span></td><td><?=$r['n']?></td><td><a class="btn sm bl" href="?view=<?=$r['id']?>">Lịch sử</a>
<?php if(!$self): foreach([['toggle',$r['status']==='active'?'Khóa':'Mở khóa','red',"Xác nhận thay đổi trạng thái tài khoản?"],['role',$r['role']==='admin'?'Hạ xuống User':'Lên Admin','pu',"Xác nhận đổi phân quyền?"]] as $b): ?>
<form class="inl" method="post" onsubmit="return confirm('<?=$b[3]?>')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="<?=$b[0]?>"><button class="btn sm <?=$b[2]?>"><?=$b[1]?></button></form> <?php endforeach; endif; ?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>
<?php include '../includes/admin_footer.php';
