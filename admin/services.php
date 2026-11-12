<?php
/**
 * ============================================================================
 * FILE: admin/services.php
 * MÔ TẢ: Trang Quản lý Dịch vụ tiện ích đi kèm (Thuê bóng, áo Bib, nước uống, trọng tài...).
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý Thêm mới, Cập nhật, Bật/Tắt kinh doanh và Xóa dịch vụ (POST).
 *   - Phần 2: Truy vấn dịch vụ cần sửa (?edit=ID) và danh sách toàn bộ dịch vụ.
 *   - Phần 3: Giao diện biểu mẫu Thêm mới / Chỉnh sửa dịch vụ tiện ích.
 *   - Phần 4: Bảng danh sách dịch vụ hiện có trong hệ thống.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ THÊM MỚI, CẬP NHẬT, BẬT/TẮT HOẶC XÓA DỊCH VỤ (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    // 1.1. Lưu thông tin dịch vụ (Thêm mới hoặc Cập nhật)
    if ($act === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = (int)($_POST['price'] ?? 0);

        if ($name === '' || $price <= 0) {
            flash('err', 'Vui lòng nhập tên dịch vụ và đơn giá hợp lệ.');
        } elseif ($id > 0) {
            // Cập nhật dịch vụ đã tồn tại
            $stmt = $pdo->prepare("
                UPDATE services
                SET name = ?, description = ?, price = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $desc, $price, $id]);
            flash('ok', 'Đã cập nhật thông tin dịch vụ #' . $id);
        } else {
            // Thêm dịch vụ tiện ích mới
            $stmt = $pdo->prepare("
                INSERT INTO services (name, description, price, status)
                VALUES (?, ?, ?, 'active')
            ");
            $stmt->execute([$name, $desc, $price]);
            flash('ok', 'Đã thêm dịch vụ tiện ích mới.');
        }
    }
    // 1.2. Chuyển đổi trạng thái Đang bán (active) <-> Tạm ngưng (inactive)
    elseif ($act === 'toggle') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            UPDATE services
            SET status = IF(status = 'active', 'inactive', 'active')
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        flash('ok', 'Đã cập nhật trạng thái kinh doanh của dịch vụ.');
    }
    // 1.3. Xóa dịch vụ khỏi CSDL (nếu chưa phát sinh trong hóa đơn đặt sân)
    elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$id]);
            flash('ok', 'Đã xóa dịch vụ tiện ích.');
        } catch (Exception $e) {
            flash('err', 'Dịch vụ đã có trong đơn đặt sân của khách, vui lòng chuyển sang trạng thái Tạm ngưng.');
        }
    }

    header('Location: services.php');
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN DỊCH VỤ CẦN SỬA (?edit=ID) & DANH SÁCH TOÀN BỘ DỊCH VỤ
// ----------------------------------------------------------------------------
$editRow = null;
if (!empty($_GET['edit'])) {
    $stE = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stE->execute([(int)$_GET['edit']]);
    $editRow = $stE->fetch();
}

$rows = $pdo->query("SELECT * FROM services ORDER BY id DESC")->fetchAll();

$page  = 'services';
$title = 'Quản lý Dịch vụ tiện ích';
include '../includes/admin_header.php';
?>

<!-- ==========================================================================
     PHẦN 3: BIỂU MẪU THÊM MỚI HOẶC CHỈNH SỬA DỊCH VỤ TIỆN ÍCH
     ========================================================================== -->
<div class="box" style="margin-bottom:16px">
  <b>
    <?=$editRow ? '✏️ Chỉnh sửa dịch vụ #' . $editRow['id'] : '➕ Thêm dịch vụ tiện ích đi kèm (Thuê bóng, Áo Bib, Nước uống, Trọng tài...)'?>
  </b>

  <form
    class="form"
    method="post"
    style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;max-width:100%;margin-top:10px"
  >
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=$editRow['id'] ?? 0?>">

    <div style="flex:1;min-width:200px">
      <label style="margin:0 0 4px">Tên dịch vụ</label>
      <input
        name="name"
        value="<?=e($editRow['name'] ?? '')?>"
        placeholder="VD: Thuê áo Bib"
        required
      >
    </div>

    <div style="flex:1.5;min-width:240px">
      <label style="margin:0 0 4px">Mô tả chi tiết</label>
      <input
        name="description"
        value="<?=e($editRow['description'] ?? '')?>"
        placeholder="Bộ 10 áo lưới phân đội..."
      >
    </div>

    <div style="width:160px">
      <label style="margin:0 0 4px">Đơn giá (VNĐ)</label>
      <input
        type="number"
        name="price"
        min="1000"
        step="1000"
        value="<?=e($editRow['price'] ?? 20000)?>"
        required
      >
    </div>

    <button class="btn" type="submit" style="height:40px">
      <?=$editRow ? '💾 Lưu cập nhật' : '+ Thêm dịch vụ'?>
    </button>

    <?php if ($editRow): ?>
      <a class="btn sm red" style="height:40px" href="services.php">Hủy</a>
    <?php endif; ?>
  </form>
</div>

<!-- ==========================================================================
     PHẦN 4: BẢNG DANH SÁCH CÁC DỊCH VỤ TIỆN ÍCH HIỆN CÓ
     ========================================================================== -->
<div class="box">
  <div class="tw">
    <table>
      <tr>
        <th>#</th>
        <th>Tên dịch vụ</th>
        <th>Mô tả</th>
        <th>Đơn giá</th>
        <th>Trạng thái</th>
        <th>Thao tác</th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b>#<?=$r['id']?></b></td>
          <td><b><?=e($r['name'])?></b></td>
          <td><?=e($r['description'])?></td>
          <td><b><?=money($r['price'])?></b></td>
          <td>
            <span class="tag <?=$r['status'] === 'active' ? 'green' : 'gray'?>">
              <?=$r['status'] === 'active' ? 'Đang bán' : 'Tạm ngưng'?>
            </span>
          </td>
          <td>
            <!-- Nút chọn sửa dịch vụ -->
            <a class="btn sm" href="?edit=<?=$r['id']?>">✏️ Sửa</a>

            <!-- Nút tạm ngưng hoặc mở bán lại dịch vụ -->
            <form class="inl" method="post">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn sm bl" type="submit">
                <?=$r['status'] === 'active' ? 'Tạm ngưng' : 'Kích hoạt'?>
              </button>
            </form>

            <!-- Nút xóa dịch vụ -->
            <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn xóa dịch vụ này?')">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn sm red" type="submit">Xóa</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
