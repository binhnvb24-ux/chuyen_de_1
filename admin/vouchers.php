<?php
/**
 * FILE: admin/vouchers.php
 * MÔ TẢ: Trang Quản lý Mã giảm giá (Voucher khuyến mãi).
 * CHỨC NĂNG: Tạo mã giảm giá mới (theo % hoặc số tiền cố định) và xóa mã giảm giá.
 */
require_once '../includes/auth.php';
$adm = require_admin();

// 1. Xử lý tạo mới hoặc xóa mã giảm giá Voucher (POST)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  csrf_check();
  $act = $_POST['action'] ?? '';
  if($act === 'create'){
    $code = strtoupper(trim($_POST['code']??''));
    $type = $_POST['discount_type'] ?? 'percent';
    $val = (int)($_POST['discount_value'] ?? 0);
    $min = (int)($_POST['min_order'] ?? 0);
    $exp = $_POST['expired_at'] ?? '';

    if($code === '' || $val <= 0 || !$exp) flash('err','Thông tin mã giảm giá không hợp lệ.');
    else {
      $pdo->prepare("INSERT INTO vouchers(code,discount_type,discount_value,min_order,expired_at) VALUES(?,?,?,?,?)")
          ->execute([$code, $type, $val, $min, $exp]);
      flash('ok','Đã thêm mã giảm giá mới.');
    }
  } elseif($act === 'delete'){
    $id = (int)$_POST['id'];
    $pdo->prepare("DELETE FROM vouchers WHERE id=?")->execute([$id]);
    flash('ok','Đã xóa mã.');
  }
  header('Location: vouchers.php'); exit;
}

// 2. Truy vấn danh sách tất cả các mã Voucher
$rows = $pdo->query("SELECT * FROM vouchers ORDER BY id DESC")->fetchAll();
$page = 'vouchers'; $title = 'Quản lý Voucher'; include '../includes/admin_header.php';
?>

<!-- 3. Biểu mẫu phát hành mã giảm giá mới -->
<div class="box" style="margin-bottom:14px">
  <b>Thêm mã giảm giá mới</b>
  <form class="form" method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;max-width:100%">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div><label>Mã Voucher</label><input name="code" placeholder="GIAM20K" required style="margin:0"></div>
    <div><label>Loại</label><select name="discount_type" style="margin:0"><option value="percent">% phần trăm</option><option value="fixed">VNĐ cố định</option></select></div>
    <div><label>Giá trị</label><input type="number" name="discount_value" required style="margin:0"></div>
    <div><label>Đơn tối thiểu</label><input type="number" name="min_order" value="0" style="margin:0"></div>
    <div><label>Hạn dùng</label><input type="date" name="expired_at" required style="margin:0"></div>
    <button class="btn" style="height:38px">Thêm mã</button>
  </form>
</div>

<!-- 4. Bảng danh sách mã Voucher -->
<div class="box">
  <div class="tw">
    <table>
      <tr><th>Mã</th><th>Loại</th><th>Giá trị</th><th>Đơn tối thiểu</th><th>Đã dùng</th><th>Hạn sử dụng</th><th>Thao tác</th></tr>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><b><?=e($r['code'])?></b></td>
          <td><?=$r['discount_type']==='percent'?'Phần trăm':'Cố định'?></td>
          <td><?=$r['discount_type']==='percent'?$r['discount_value'].'%':money($r['discount_value'])?></td>
          <td><?=money($r['min_order'])?></td>
          <td><?=$r['used_count']?>/<?=$r['usage_limit']?></td>
          <td><?=date('d/m/Y',strtotime($r['expired_at']))?></td>
          <td>
            <form class="inl" method="post" onsubmit="return confirm('Xóa mã này?')">
              <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn sm red">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
