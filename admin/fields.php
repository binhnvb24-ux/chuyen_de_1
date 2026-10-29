<?php
/**
 * FILE: admin/fields.php
 * MÔ TẢ: Trang Quản lý Danh sách Sân bóng dành cho Admin.
 * CHỨC NĂNG: Thêm mới, chỉnh sửa thông tin sân, tải ảnh sân lên máy chủ (upload) và xóa sân.
 */
require_once '../includes/auth.php';
$adm=require_admin();

// 1. Hàm kiểm tra định dạng, kích thước (tối đa 2MB) và lưu file ảnh sân tải lên
function upload_image(&$err){
  if(empty($_FILES['image']['name'])) return null;
  $f=$_FILES['image'];
  if($f['error']!==UPLOAD_ERR_OK||$f['size']>2*1024*1024){ $err='Ảnh lỗi hoặc lớn hơn 2MB.'; return false; }
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
  if(!$ext){ $err='Chỉ chấp nhận ảnh JPG, PNG, WEBP.'; return false; }
  $name='assets/images/uploads/'.bin2hex(random_bytes(8)).'.'.$ext;
  move_uploaded_file($f['tmp_name'],__DIR__.'/../'.$name); return $name;
}

// 2. Xử lý thêm mới, cập nhật hoặc xóa sân bóng (POST)
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check(); $act=$_POST['action']??'';
  if($act==='delete'){
    $id=(int)$_POST['id']; $s=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE field_id=?"); $s->execute([$id]);
    if($s->fetchColumn()) flash('err','Sân đã có lịch đặt, không thể xóa. Hãy chuyển sang trạng thái bảo trì.');
    else { $pdo->prepare("DELETE FROM fields WHERE id=?")->execute([$id]); flash('ok','Đã xóa sân.'); }
    header('Location: fields.php'); exit;
  }
  $id=(int)($_POST['id']??0); $d=['name'=>trim($_POST['name']??''),'type'=>$_POST['type']??'5','address'=>trim($_POST['address']??''),'district'=>trim($_POST['district']??''),
    'price'=>(int)($_POST['price']??0),'description'=>trim($_POST['description']??''),'status'=>$_POST['status']??'active'];
  $err='';
  if(mb_strlen($d['name'])<3||$d['address']==='') $err='Vui lòng nhập tên sân và địa chỉ.';
  elseif($d['price']<=0) $err='Giá thuê phải lớn hơn 0.';
  elseif(!in_array($d['type'],['5','7','11'])||!in_array($d['status'],['active','maintenance'])) $err='Dữ liệu không hợp lệ.';
  else { $img=upload_image($err); }
  if($err){ flash('err',$err); header('Location: fields.php?'.($id?"edit=$id":'new=1')); exit; }
  if($id){ $pdo->prepare("UPDATE fields SET name=?,type=?,address=?,district=?,price_per_hour=?,description=?,status=? WHERE id=?")->execute([$d['name'],$d['type'],$d['address'],$d['district'],$d['price'],$d['description'],$d['status'],$id]);
    if($img) $pdo->prepare("UPDATE fields SET image=? WHERE id=?")->execute([$img,$id]); flash('ok','Đã cập nhật sân.'); }
  else { $pdo->prepare("INSERT INTO fields(name,type,address,district,price_per_hour,description,status,image) VALUES(?,?,?,?,?,?,?,?)")->execute([$d['name'],$d['type'],$d['address'],$d['district'],$d['price'],$d['description'],$d['status'],$img?:'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=600']); flash('ok','Đã thêm sân mới.'); }
  header('Location: fields.php'); exit;
}

// 3. Chuẩn bị dữ liệu cho biểu mẫu thêm/sửa hoặc bảng danh sách sân
$form=null;
if(isset($_GET['edit'])){ $s=$pdo->prepare("SELECT * FROM fields WHERE id=?"); $s->execute([(int)$_GET['edit']]); $form=$s->fetch(); }
elseif(isset($_GET['new'])) $form=['id'=>0,'name'=>'','type'=>'5','address'=>'','district'=>'','price_per_hour'=>'','description'=>'','status'=>'active'];
$rows=$pdo->query("SELECT * FROM fields ORDER BY id DESC")->fetchAll();
$page='fields'; $title='Quản lý sân'; include '../includes/admin_header.php'; ?>

<!-- 4. Giao diện biểu mẫu thêm/sửa sân hoặc bảng danh sách sân -->
<?php if($form): ?><div class="box"><form class="form" method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="id" value="<?=$form['id']?>">
<label>Tên sân</label><input name="name" required value="<?=e($form['name'])?>"><label>Loại sân</label><select name="type"><?php foreach(['5'=>'Sân 5','7'=>'Sân 7','11'=>'Sân 11'] as $k=>$v): ?><option value="<?=$k?>" <?=$form['type']==$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
<label>Địa chỉ</label><input name="address" required value="<?=e($form['address'])?>"><label>Quận/Huyện</label><input name="district" value="<?=e($form['district'])?>">
<label>Giá thuê / giờ (đ)</label><input type="number" min="1000" step="1000" name="price" required value="<?=e($form['price_per_hour'])?>"><label>Mô tả</label><input name="description" value="<?=e($form['description'])?>">
<label>Trạng thái</label><select name="status"><option value="active" <?=$form['status']==='active'?'selected':''?>>Hoạt động</option><option value="maintenance" <?=$form['status']==='maintenance'?'selected':''?>>Bảo trì</option></select>
<label>Ảnh sân (JPG/PNG/WEBP, tối đa 2MB)</label><input type="file" name="image" accept="image/*"><br><br><button class="btn">Lưu</button> <a href="fields.php">Hủy</a></form></div>
<?php else: ?><p><a class="btn sm" style="display:inline-block" href="?new=1">+ Thêm sân mới</a></p><div class="box"><?php if(!$rows): ?><div class="empty">Chưa có sân nào.</div><?php else: ?><div class="tw"><table><tr><th>Ảnh</th><th>Tên sân</th><th>Loại</th><th>Địa chỉ</th><th>Giá/giờ</th><th>Trạng thái</th><th></th></tr>
<?php foreach($rows as $r): ?><tr><td><img src="<?=e(img_src($r['image']))?>" width="64" height="44" style="object-fit:cover;border-radius:4px" alt=""></td><td><?=e($r['name'])?></td><td>Sân <?=e($r['type'])?></td><td><?=e($r['address'])?></td><td><?=money($r['price_per_hour'])?></td>
<td><span class="tag <?=$r['status']==='active'?'green':'gray'?>"><?=$r['status']==='active'?'Hoạt động':'Bảo trì'?></span></td><td><a class="btn sm" href="?edit=<?=$r['id']?>">Sửa</a>
<form class="inl" method="post" onsubmit="return confirm('Xóa sân này?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn sm red">Xóa</button></form></td></tr><?php endforeach; ?></table></div><?php endif; ?></div><?php endif; include '../includes/admin_footer.php';
