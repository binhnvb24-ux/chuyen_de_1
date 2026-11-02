<?php
/**
 * FILE: admin/fields.php
 * MÔ TẢ: Trang Quản lý Danh sách Sân bóng dành cho Admin.
 * CHỨC NĂNG: Thêm mới, chỉnh sửa thông tin sân, tải ảnh sân lên máy chủ (upload) và xóa sân.
 */

// Nạp tệp xác thực và yêu cầu quyền admin
require_once '../includes/auth.php';
$adm = require_admin();

/**
 * 1. HÀM XỬ LÝ UPLOAD ẢNH
 * Kiểm tra định dạng, kích thước (tối đa 2MB) và lưu file ảnh sân tải lên.
 * Trả về đường dẫn ảnh nếu thành công, false nếu lỗi, hoặc null nếu không có ảnh tải lên.
 */
function upload_image(&$redirect_url) {
    // Nếu không có file được chọn, trả về null
    if (empty($_FILES['image']['name'])) {
        return null;
    }

    $f = $_FILES['image'];

    // Kiểm tra lỗi upload hoặc dung lượng vượt quá 2MB (2 * 1024 * 1024 bytes)
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
        $err = 'Ảnh bị lỗi hoặc có kích thước lớn hơn 2MB.';
        return false;
    }

    // Kiểm tra MIME type để đảm bảo file thực sự là ảnh
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);

    // Ánh xạ MIME type sang phần mở rộng file (extension)
    $allowed_types = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    $ext = $allowed_types[$mime] ?? null;

    // Nếu không thuộc các định dạng cho phép
    if (!$ext) {
        $err = 'Chỉ chấp nhận các định dạng ảnh: JPG, PNG, WEBP.';
        return false;
    }

    // Tạo tên file ngẫu nhiên để tránh trùng lặp và nối với phần mở rộng
    $name = 'assets/images/uploads/' . bin2hex(random_bytes(8)) . '.' . $ext;

    // Di chuyển file từ thư mục tạm sang thư mục đích
    move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $name);

    return $name;
}

/**
 * 2. XỬ LÝ CÁC YÊU CẦU POST (THÊM, SỬA, XÓA)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kiểm tra token CSRF để bảo mật form
    csrf_check();
    $act = $_POST['action'] ?? '';

    // -- Xử lý Xóa sân --
    if ($act === 'delete') {
        $id = (int)$_POST['id'];

        // Kiểm tra xem sân đã có lịch đặt (booking) nào chưa
        $s = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE field_id = ?");
        $s->execute([$id]);

        if ($s->fetchColumn()) {
            flash('err', 'Sân đã có lịch đặt, không thể xóa. Hãy chuyển sang trạng thái bảo trì.');
        } else {
            $pdo->prepare("DELETE FROM fields WHERE id = ?")->execute([$id]);
            rebuild_field_conflict_map();
            flash('ok', 'Đã xóa sân thành công.');
        }

        // Chuyển hướng về trang danh sách
        header('Location: fields.php');
        exit;
    }

    // -- Xử lý Thêm mới hoặc Cập nhật sân --
    $id = (int)($_POST['id'] ?? 0);

    // Lấy và làm sạch dữ liệu đầu vào (Bao gồm cả Mã sân & Sân Cha ghép sân)
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    if ($parent_id === $id) {
        $parent_id = null;
    }

    $d = [
        'name'        => trim($_POST['name'] ?? ''),
        'pitch_code'  => strtoupper(trim($_POST['pitch_code'] ?? '')),
        'parent_id'   => $parent_id,
        'type'        => $_POST['type'] ?? '5',
        'address'     => trim($_POST['address'] ?? ''),
        'district'    => trim($_POST['district'] ?? ''),
        'price'       => (int)($_POST['price'] ?? 0),
        'description' => trim($_POST['description'] ?? ''),
        'status'      => $_POST['status'] ?? 'active'
    ];

    $err = '';
    $img = null;

    // Validate dữ liệu
    if (mb_strlen($d['name']) < 3 || $d['address'] === '') {
        $err = 'Vui lòng nhập tên sân (ít nhất 3 ký tự) và địa chỉ.';
    } elseif ($d['price'] <= 0) {
        $err = 'Giá thuê sân phải lớn hơn 0.';
    } elseif (!in_array($d['type'], ['5', '7', '11']) || !in_array($d['status'], ['active', 'maintenance'])) {
        $err = 'Dữ liệu không hợp lệ (sai loại sân hoặc trạng thái).';
    } else {
        // Nếu không có lỗi dữ liệu, tiến hành upload ảnh
        $img = upload_image($err);
    }

    // Nếu có lỗi, lưu thông báo và quay lại trang form (chế độ sửa hoặc thêm mới)
    if ($err) {
        flash('err', $err);
        $redirect_url = 'fields.php?' . ($id ? "edit=$id" : 'new=1');
        header("Location: $redirect_url");
        exit;
    }

    // Cập nhật Database
    if ($id) {
        // Update sân đã có
        $pdo->prepare("UPDATE fields SET parent_id=?, pitch_code=?, name=?, type=?, address=?, district=?, price_per_hour=?, description=?, status=? WHERE id=?")
            ->execute([$d['parent_id'], $d['pitch_code'], $d['name'], $d['type'], $d['address'], $d['district'], $d['price'], $d['description'], $d['status'], $id]);

        // Nếu có ảnh mới được tải lên thì cập nhật cột image
        if ($img) {
            $pdo->prepare("UPDATE fields SET image=? WHERE id=?")->execute([$img, $id]);
        }
        rebuild_field_conflict_map();
        flash('ok', 'Đã cập nhật thông tin sân và cấu trúc Ghép/Tách sân.');
    } else {
        // Thêm sân mới
        $final_img = $img ?: 'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=600';

        $pdo->prepare("INSERT INTO fields (parent_id, pitch_code, name, type, address, district, price_per_hour, description, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$d['parent_id'], $d['pitch_code'], $d['name'], $d['type'], $d['address'], $d['district'], $d['price'], $d['description'], $d['status'], $final_img]);

        rebuild_field_conflict_map();
        flash('ok', 'Đã thêm sân mới thành công.');
    }

    header('Location: fields.php');
    exit;
}

/**
 * 3. CHUẨN BỊ DỮ LIỆU ĐỂ HIỂN THỊ GIAO DIỆN
 */
$form = null;

// Nếu là chế độ chỉnh sửa (URL có tham số ?edit=id)
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM fields WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $form = $s->fetch();
}
// Nếu là chế độ thêm mới (URL có tham số ?new=1)
elseif (isset($_GET['new'])) {
    $form = [
        'id'             => 0,
        'parent_id'      => null,
        'pitch_code'     => '',
        'name'           => '',
        'type'           => '5',
        'address'        => '',
        'district'       => '',
        'price_per_hour' => '',
        'description'    => '',
        'status'         => 'active'
    ];
}

// Lấy danh sách tất cả các sân kèm tên Sân Cha (nếu có) để hiển thị ở bảng
$rows = $pdo->query("
    SELECT f.*, p.name AS parent_name, p.type AS parent_type, p.pitch_code AS parent_code
    FROM fields f
    LEFT JOIN fields p ON p.id = f.parent_id
    ORDER BY f.type DESC, f.id ASC
")->fetchAll();

$page = 'fields';
$title = 'Quản lý sân bóng & Cấu trúc Ghép/Tách sân';
include '../includes/admin_header.php';
?>

<!-- 4. GIAO DIỆN HIỂN THỊ -->
<?php if ($form): ?>
    <!-- Hiển thị Form Thêm/Sửa sân -->
    <div class="box">
        <form class="form" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $form['id'] ?>">

            <label>Tên sân</label>
            <input name="name" required value="<?= e($form['name']) ?>">

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Mã sân định danh (VD: 5A, 5B, 7A, 11A)</label>
                    <input name="pitch_code" placeholder="VD: 5A, 7A, 11A" value="<?= e($form['pitch_code'] ?? '') ?>">
                </div>
                <div>
                    <label>Loại sân</label>
                    <select name="type">
                        <?php foreach (['5' => 'Sân 5 người', '7' => 'Sân 7 người', '11' => 'Sân 11 người'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $form['type'] == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label>🔗 Ghép vào Sân Cha (Sân 5 ghép thành Sân 7 / Sân 7 ghép thành Sân 11)</label>
            <select name="parent_id">
                <option value="">-- Không thuộc sân nào (Sân độc lập hoặc Sân gốc 11 người) --</option>
                <?php foreach ($rows as $pItem): ?>
                    <?php if ((int)$pItem['id'] !== (int)$form['id'] && in_array($pItem['type'], ['7', '11'])): ?>
                        <option value="<?= $pItem['id'] ?>" <?= ($form['parent_id'] ?? null) == $pItem['id'] ? 'selected' : '' ?>>
                            🏟 [Sân <?= $pItem['type'] ?> - <?= e($pItem['pitch_code'] ?: ('#' . $pItem['id'])) ?>] <?= e($pItem['name']) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>

            <label>Địa chỉ</label>
            <input name="address" required value="<?= e($form['address']) ?>">

            <label>Quận/Huyện</label>
            <input name="district" value="<?= e($form['district']) ?>">

            <label>Giá thuê cơ sở / giờ (đ)</label>
            <input type="number" min="1000" step="1000" name="price" required value="<?= e($form['price_per_hour']) ?>">

            <label>Mô tả</label>
            <input name="description" value="<?= e($form['description']) ?>">

            <label>Trạng thái</label>
            <select name="status">
                <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Hoạt động</option>
                <option value="maintenance" <?= $form['status'] === 'maintenance' ? 'selected' : '' ?>>Bảo trì</option>
            </select>

            <label>Ảnh sân (JPG/PNG/WEBP, tối đa 2MB)</label>
            <input type="file" name="image" accept="image/*">
            <br><br>

            <button class="btn">💾 Lưu thông tin sân</button>
            <a class="btn sm red" href="fields.php">Hủy</a>
        </form>
    </div>
<?php else: ?>
    <!-- Hiển thị Bảng Danh sách sân -->
    <p>
        <a class="btn sm" style="display:inline-block" href="?new=1">+ Thêm sân mới</a>
    </p>

    <div class="box">
        <?php if (!$rows): ?>
            <div class="empty">Chưa có sân nào.</div>
        <?php else: ?>
            <div class="tw">
                <table>
                    <tr>
                        <th>Ảnh</th>
                        <th>Mã & Tên sân</th>
                        <th>Loại</th>
                        <th>Sân Cha (Ghép sân)</th>
                        <th>Địa chỉ</th>
                        <th>Giá cơ sở/giờ</th>
                        <th>Trạng thái</th>
                        <th>Hành động</th>
                    </tr>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <img src="<?= e(img_src($r['image'])) ?>" width="64" height="44" style="object-fit:cover; border-radius:4px" alt="Ảnh sân">
                            </td>
                            <td>
                                <?php if (!empty($r['pitch_code'])): ?>
                                    <span class="tag blue" style="font-size:11px"><?= e($r['pitch_code']) ?></span>
                                <?php endif; ?>
                                <b><?= e($r['name']) ?></b>
                            </td>
                            <td>Sân <?= e($r['type']) ?></td>
                            <td>
                                <?php if (!empty($r['parent_id'])): ?>
                                    <span class="tag green">
                                        🔗 Thuộc <?= e($r['parent_name']) ?> (Sân <?= e($r['parent_type']) ?>)
                                    </span>
                                <?php else: ?>
                                    <small>Sân gốc / Độc lập</small>
                                <?php endif; ?>
                            </td>
                            <td><?= e($r['address']) ?></td>
                            <td><b><?= money($r['price_per_hour']) ?></b></td>
                            <td>
                                <span class="tag <?= $r['status'] === 'active' ? 'green' : 'gray' ?>">
                                    <?= $r['status'] === 'active' ? 'Hoạt động' : 'Bảo trì' ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn sm" href="?edit=<?= $r['id'] ?>">Sửa</a>
                                <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn xóa sân này?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn sm red">Xóa</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>