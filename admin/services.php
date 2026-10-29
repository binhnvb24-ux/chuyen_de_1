<?php
/**
 * FILE: admin/services.php
 * MÔ TẢ: Trang Quản lý Dịch vụ tiện ích đi kèm (Thuê bóng, áo Bib, nước uống, trọng tài...).
 * CHỨC NĂNG: Thêm dịch vụ mới, bật/tắt trạng thái kinh doanh và xóa dịch vụ.
 */
require_once '../includes/auth.php';
$adm = require_admin();

// 1. Xử lý thêm mới, bật/tắt trạng thái hoặc xóa dịch vụ (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['action'] ?? '';
  if ($act === 'create') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $price = (int)($_POST['price'] ?? 0);
    if ($name === '' || $price <= 0) flash('err', 'Vui lòng nhập tên dịch vụ và đơn giá hợp lệ.');
    else {
      $pdo->prepare("INSERT INTO services(name,description,price,status) VALUES(?,?,?,'active')")->execute([$name, $desc, $price]);
      flash('ok', 'Đã thêm dịch vụ tiện ích mới.');
    }
  } elseif ($act === 'toggle') {
    $id = (int)$_POST['id'];
    $pdo->prepare("UPDATE services SET status=IF(status='active','inactive','active') WHERE id=?")->execute([$id]);
    flash('ok', 'Đã cập nhật trạng thái dịch vụ.');
  } elseif ($act === 'delete') {
    $id = (int)$_POST['id'];
    try {
      $pdo->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
      flash('ok', 'Đã xóa dịch vụ.');
    } catch (Exception $e) {
      flash('err', 'Dịch vụ đã có trong đơn đặt sân, hãy chuyển sang Ngừng hoạt động.');
    }
  }
  header('Location: services.php');
  exit;
}

// 2. Lấy danh sách toàn bộ dịch vụ tiện ích
$rows = $pdo->query("SELECT * FROM services ORDER BY id DESC")->fetchAll();
$page = 'services';
$title = 'Quản lý Dịch vụ tiện ích';
include '../includes/admin_header.php';
?>

<!-- 3. Biểu mẫu thêm nhanh dịch vụ mới -->
<div class="box" style="margin-bottom:16px">
  <b>➕ Thêm dịch vụ tiện ích đi kèm (Thuê bóng, Áo Bib, Nước uống, Trọng tài...)</b>
  <form class="form" method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;max-width:100%;margin-top:10px">
    <?=csrf_field()?><input type="hidden" name="action" value="create">
    <div style="flex:1;min-width:200px"><label style="margin:0 0 4px">Tên dịch vụ</label><input name="name" placeholder="VD: Thuê áo Bib" required></div>
    <div style="flex:1.5;min-width:240px"><label style="margin:0 0 4px">Mô tả</label><input name="description" placeholder="Bộ 10 áo lưới phân đội..."></div>
    <div style="width:160px"><label style="margin:0 0 4px">Đơn giá (VNĐ)</label><input type="number" name="price" min="1000" step="1000" value="20000" required></div>
    <button class="btn" style="height:40px">+ Thêm dịch vụ</button>
  </form>
</div>

<!-- 4. Bảng danh sách dịch vụ hiện có -->
<div class="box">
  <div class="tw">
    <table>
      <tr><th>#</th><th>Tên dịch vụ</th><th>Mô tả</th><th>Đơn giá</th><th>Trạng thái</th><th>Thao tác</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?=$r['id']?></td>
          <td><b><?=e($r['name'])?></b></td>
          <td><?=e($r['description'])?></td>
          <td><b><?=money($r['price'])?></b></td>
          <td><span class="tag <?=$r['status']==='active'?'green':'gray'?>"><?=$r['status']==='active'?'Đang bán':'Tạm ngưng'?></span></td>
          <td>
            <form class="inl" method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn sm bl"><?=$r['status']==='active'?'Tạm ngưng':'Kích hoạt'?></button></form>
            <form class="inl" method="post" onsubmit="return confirm('Xóa dịch vụ này?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn sm red">Xóa</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
