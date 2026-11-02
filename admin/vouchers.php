<?php
/**
 * ============================================================================
 * FILE: admin/vouchers.php
 * MÔ TẢ: Trang Quản lý Mã giảm giá (Voucher khuyến mãi) dành cho Admin.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý Thêm mới, Cập nhật, Bật/Tắt trạng thái và Xóa Voucher (POST).
 *   - Phần 2: Truy vấn thông tin Voucher cần sửa (?edit=ID) và danh sách Voucher.
 *   - Phần 3: Giao diện biểu mẫu Phát hành / Chỉnh sửa mã giảm giá.
 *   - Phần 4: Bảng danh sách toàn bộ các mã giảm giá Voucher.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ THÊM MỚI, CẬP NHẬT, KHÓA/MỞ HOẶC XÓA VOUCHER (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    // 1.1. Lưu mã giảm giá (Thêm mới hoặc Cập nhật)
    if ($act === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $code  = strtoupper(trim($_POST['code'] ?? ''));
        $type  = $_POST['discount_type'] ?? 'percent';
        $val   = (int)($_POST['discount_value'] ?? 0);
        $min   = (int)($_POST['min_order'] ?? 0);
        $max_d = (int)($_POST['max_discount'] ?? 0);
        $limit = max(1, (int)($_POST['usage_limit'] ?? 100));
        $exp   = $_POST['expired_at'] ?? '';

        if ($code === '' || $val <= 0 || !$exp) {
            flash('err', 'Thông tin mã giảm giá không hợp lệ. Vui lòng kiểm tra lại.');
        } elseif ($id > 0) {
            // Cập nhật Voucher đã tồn tại
            $stmt = $pdo->prepare("
                UPDATE vouchers
                SET code = ?,
                    discount_type = ?,
                    discount_value = ?,
                    min_order = ?,
                    max_discount = ?,
                    usage_limit = ?,
                    expired_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$code, $type, $val, $min, $max_d, $limit, $exp, $id]);
            flash('ok', 'Đã cập nhật mã giảm giá ' . $code);
        } else {
            // Thêm mới Voucher vào CSDL
            $stmt = $pdo->prepare("
                INSERT INTO vouchers (
                    code, discount_type, discount_value,
                    min_order, max_discount, usage_limit, expired_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$code, $type, $val, $min, $max_d, $limit, $exp]);
            flash('ok', 'Đã phát hành mã giảm giá mới: ' . $code);
        }
    }
    // 1.2. Đổi trạng thái Đang mở (active) <-> Tạm khóa (inactive)
    elseif ($act === 'toggle') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("
            UPDATE vouchers
            SET status = IF(status = 'active', 'inactive', 'active')
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        flash('ok', 'Đã thay đổi trạng thái kích hoạt của mã Voucher.');
    }
    // 1.3. Xóa mã Voucher khỏi CSDL
    elseif ($act === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM vouchers WHERE id = ?");
        $stmt->execute([$id]);
        flash('ok', 'Đã xóa mã giảm giá.');
    }

    header('Location: vouchers.php');
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN VOUCHER CẦN SỬA (?edit=ID) & DANH SÁCH TẤT CẢ VOUCHER
// ----------------------------------------------------------------------------
$editV = null;
if (!empty($_GET['edit'])) {
    $stV = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
    $stV->execute([(int)$_GET['edit']]);
    $editV = $stV->fetch();
}

$rows = $pdo->query("SELECT * FROM vouchers ORDER BY id DESC")->fetchAll();

$page  = 'vouchers';
$title = 'Quản lý mã giảm giá Voucher';
include '../includes/admin_header.php';
?>

<!-- ==========================================================================
     PHẦN 3: BIỂU MẪU PHÁT HÀNH HOẶC CHỈNH SỬA MÃ GIẢM GIÁ (VOUCHER)
     ========================================================================== -->
<div class="box" style="margin-bottom:14px">
  <b>
    <?=$editV ? '✏️ Chỉnh sửa mã Voucher: ' . e($editV['code']) : '➕ Phát hành mã giảm giá (Voucher) mới'?>
  </b>

  <form
    class="form"
    method="post"
    style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;max-width:100%;margin-top:10px"
  >
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=$editV['id'] ?? 0?>">

    <div>
      <label style="margin:0 0 4px">Mã Voucher</label>
      <input
        name="code"
        value="<?=e($editV['code'] ?? '')?>"
        placeholder="GIAM20K"
        required
        style="margin:0;width:130px"
      >
    </div>

    <div>
      <label style="margin:0 0 4px">Loại giảm</label>
      <select name="discount_type" style="margin:0">
        <option value="percent" <?=($editV['discount_type'] ?? '') === 'percent' ? 'selected' : ''?>>
          % phần trăm
        </option>
        <option value="fixed" <?=($editV['discount_type'] ?? '') === 'fixed' ? 'selected' : ''?>>
          VNĐ cố định
        </option>
      </select>
    </div>

    <div>
      <label style="margin:0 0 4px">Giá trị giảm</label>
      <input
        type="number"
        name="discount_value"
        value="<?=e($editV['discount_value'] ?? 10)?>"
        required
        style="margin:0;width:110px"
      >
    </div>

    <div>
      <label style="margin:0 0 4px">Đơn tối thiểu</label>
      <input
        type="number"
        name="min_order"
        value="<?=e($editV['min_order'] ?? 100000)?>"
        style="margin:0;width:130px"
      >
    </div>

    <div>
      <label style="margin:0 0 4px">Giảm tối đa</label>
      <input
        type="number"
        name="max_discount"
        value="<?=e($editV['max_discount'] ?? 50000)?>"
        style="margin:0;width:130px"
      >
    </div>

    <div>
      <label style="margin:0 0 4px">Giới hạn lượt</label>
      <input
        type="number"
        name="usage_limit"
        value="<?=e($editV['usage_limit'] ?? 100)?>"
        style="margin:0;width:100px"
      >
    </div>

    <div>
      <label style="margin:0 0 4px">Hạn sử dụng</label>
      <input
        type="date"
        name="expired_at"
        value="<?=e($editV['expired_at'] ?? date('Y-12-31'))?>"
        required
        style="margin:0"
      >
    </div>

    <button class="btn" type="submit" style="height:40px">
      <?=$editV ? '💾 Lưu mã' : '+ Thêm mã'?>
    </button>

    <?php if ($editV): ?>
      <a class="btn sm red" style="height:40px" href="vouchers.php">Hủy</a>
    <?php endif; ?>
  </form>
</div>

<!-- ==========================================================================
     PHẦN 4: BẢNG DANH SÁCH TẤT CẢ MÃ GIẢM GIÁ VOUCHER
     ========================================================================== -->
<div class="box">
  <div class="tw">
    <table>
      <tr>
        <th>Mã Voucher</th>
        <th>Hình thức</th>
        <th>Mức giảm</th>
        <th>Đơn tối thiểu</th>
        <th>Lượt đã dùng</th>
        <th>Hạn sử dụng</th>
        <th>Trạng thái</th>
        <th>Thao tác</th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><b><?=e($r['code'])?></b></td>
          <td><?=$r['discount_type'] === 'percent' ? 'Phần trăm (%)' : 'Số tiền cố định'?></td>
          <td>
            <b>
              <?=$r['discount_type'] === 'percent' ? $r['discount_value'] . '%' : money($r['discount_value'])?>
            </b>
            <?php if ($r['discount_type'] === 'percent' && $r['max_discount'] > 0): ?>
              <small>Tối đa <?=money($r['max_discount'])?></small>
            <?php endif; ?>
          </td>
          <td><?=money($r['min_order'])?></td>
          <td><?=$r['used_count']?> / <?=$r['usage_limit']?></td>
          <td><?=date('d/m/Y', strtotime($r['expired_at']))?></td>
          <td>
            <span class="tag <?=$r['status'] === 'active' ? 'green' : 'gray'?>">
              <?=$r['status'] === 'active' ? 'Đang mở' : 'Tạm khóa'?>
            </span>
          </td>
          <td>
            <!-- Nút chọn sửa thông tin Voucher -->
            <a class="btn sm" href="?edit=<?=$r['id']?>">✏️ Sửa</a>

            <!-- Nút bật/tắt trạng thái kích hoạt -->
            <form class="inl" method="post">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn sm bl" type="submit">
                <?=$r['status'] === 'active' ? 'Khóa' : 'Mở'?>
              </button>
            </form>

            <!-- Nút xóa mã Voucher -->
            <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn xóa mã giảm giá này?')">
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
