<?php
/**
 * ============================================================================
 * FILE: admin/fields.php
 * MÔ TẢ: Module 3 - Quản lý Hệ thống Sân bóng, Chủ sân sở hữu (`owner_id`),
 *        Cấu trúc Ghép/Tách sân (`parent_id`) & Đối soát Doanh thu Chủ sân.
 * ============================================================================
 */

require_once '../includes/auth.php';
$adm = require_admin();

/**
 * 1. HÀM XỬ LÝ UPLOAD ẢNH SÂN
 */
function upload_image(&$err) {
    if (empty($_FILES['image']['name'])) {
        return null;
    }

    $f = $_FILES['image'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 4 * 1024 * 1024) {
        $err = 'Ảnh bị lỗi hoặc có kích thước lớn hơn 4MB.';
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    $allowed_types = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    $ext = $allowed_types[$mime] ?? null;

    if (!$ext) {
        $err = 'Chỉ chấp nhận các định dạng ảnh: JPG, PNG, WEBP.';
        return false;
    }

    $name = 'assets/images/uploads/' . bin2hex(random_bytes(8)) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $name);
    return $name;
}

/**
 * 2. XỬ LÝ CÁC YÊU CẦU POST (THÊM, SỬA, XÓA, ĐỔI NHANH TRẠNG THÁI)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    // 2.1. Xóa sân
    if ($act === 'delete') {
        $id = (int)$_POST['id'];
        $s = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE field_id = ?");
        $s->execute([$id]);

        if ($s->fetchColumn()) {
            flash('err', 'Sân đã có lịch đặt, không thể xóa. Hãy chuyển sang trạng thái bảo trì.');
        } else {
            $pdo->prepare("DELETE FROM fields WHERE id = ?")->execute([$id]);
            rebuild_field_conflict_map();
            flash('ok', 'Đã xóa sân thành công.');
        }
        header('Location: fields.php');
        exit;
    }

    // 2.2. Đổi nhanh trạng thái Hoạt động / Bảo trì
    if ($act === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE fields SET status = IF(status = 'active', 'maintenance', 'active') WHERE id = ?")
            ->execute([$id]);
        flash('ok', 'Đã cập nhật trạng thái hoạt động/bảo trì của sân.');
        header('Location: fields.php');
        exit;
    }

    // 2.3. Thêm mới hoặc Cập nhật sân
    $id        = (int)($_POST['id'] ?? 0);
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $owner_id  = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
    if ($parent_id === $id) {
        $parent_id = null;
    }

    $d = [
        'owner_id'    => $owner_id,
        'name'        => trim($_POST['name'] ?? ''),
        'pitch_code'  => strtoupper(trim($_POST['pitch_code'] ?? '')),
        'parent_id'   => $parent_id,
        'type'        => $_POST['type'] ?? '5',
        'address'     => trim($_POST['address'] ?? ''),
        'district'    => trim($_POST['district'] ?? ''),
        'latitude'    => !empty($_POST['latitude']) ? (float)$_POST['latitude'] : 10.7769,
        'longitude'   => !empty($_POST['longitude']) ? (float)$_POST['longitude'] : 106.7009,
        'price'       => (int)($_POST['price'] ?? 0),
        'description' => trim($_POST['description'] ?? ''),
        'status'      => $_POST['status'] ?? 'active'
    ];

    $err = '';
    $img = null;

    if (mb_strlen($d['name']) < 3 || $d['address'] === '') {
        $err = 'Vui lòng nhập tên sân (ít nhất 3 ký tự) và địa chỉ.';
    } elseif ($d['price'] <= 0) {
        $err = 'Giá thuê sân phải lớn hơn 0.';
    } elseif (!in_array($d['type'], ['5', '7', '11']) || !in_array($d['status'], ['active', 'maintenance'])) {
        $err = 'Dữ liệu không hợp lệ (sai loại sân hoặc trạng thái).';
    } else {
        $img = upload_image($err);
    }

    if ($err) {
        flash('err', $err);
        $redirect_url = 'fields.php?' . ($id ? "edit=$id" : 'new=1');
        header("Location: $redirect_url");
        exit;
    }

    if ($id) {
        $pdo->prepare("
            UPDATE fields
            SET owner_id=?, parent_id=?, pitch_code=?, name=?, type=?, address=?, district=?,
                latitude=?, longitude=?, price_per_hour=?, description=?, status=?
            WHERE id=?
        ")->execute([
            $d['owner_id'], $d['parent_id'], $d['pitch_code'], $d['name'], $d['type'],
            $d['address'], $d['district'], $d['latitude'], $d['longitude'],
            $d['price'], $d['description'], $d['status'], $id
        ]);

        if ($img) {
            $pdo->prepare("UPDATE fields SET image=? WHERE id=?")->execute([$img, $id]);
        }
        rebuild_field_conflict_map();
        flash('ok', 'Đã cập nhật thông tin sân, Chủ sân sở hữu và cấu trúc Ghép/Tách sân.');
    } else {
        $final_img = $img ?: 'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=600';

        $pdo->prepare("
            INSERT INTO fields (
                owner_id, parent_id, pitch_code, name, type, address, district,
                latitude, longitude, price_per_hour, description, status, image
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $d['owner_id'], $d['parent_id'], $d['pitch_code'], $d['name'], $d['type'],
            $d['address'], $d['district'], $d['latitude'], $d['longitude'],
            $d['price'], $d['description'], $d['status'], $final_img
        ]);

        rebuild_field_conflict_map();
        flash('ok', 'Đã thêm sân mới thành công.');
    }

    header('Location: fields.php');
    exit;
}

/**
 * 3. CHUẨN BỊ DỮ LIỆU GIAO DIỆN & BẢNG ĐỐI SOÁT DOANH THU THEO CHỦ SÂN
 */
$form = null;
$ownersList = $pdo->query("
    SELECT id, username, full_name, phone, account_role
    FROM users
    WHERE account_role IN ('PITCH_OWNER', 'ADMIN') OR role IN ('pitch_owner', 'admin')
    ORDER BY account_role DESC, full_name ASC
")->fetchAll();

if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM fields WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $form = $s->fetch();
} elseif (isset($_GET['new'])) {
    $form = [
        'id'             => 0,
        'owner_id'       => null,
        'parent_id'      => null,
        'pitch_code'     => '',
        'name'           => '',
        'type'           => '5',
        'address'        => '',
        'district'       => '',
        'latitude'       => 10.7991,
        'longitude'      => 106.6650,
        'price_per_hour' => '',
        'description'    => '',
        'status'         => 'active'
    ];
}

// Lọc theo Chủ sân (?owner_id=...)
$ownerFilter = isset($_GET['owner_id']) && $_GET['owner_id'] !== '' ? (int)$_GET['owner_id'] : null;

$sqlFields = "
    SELECT f.*,
           p.name AS parent_name, p.type AS parent_type, p.pitch_code AS parent_code,
           u.full_name AS owner_name, u.phone AS owner_phone, u.username AS owner_username,
           (SELECT COUNT(*) FROM bookings b WHERE b.field_id = f.id AND b.status != 'cancelled') AS total_bookings,
           (SELECT COALESCE(SUM(b.total_price), 0) FROM bookings b WHERE b.field_id = f.id AND b.status IN ('confirmed', 'completed')) AS total_revenue
    FROM fields f
    LEFT JOIN fields p ON p.id = f.parent_id
    LEFT JOIN users u ON u.id = f.owner_id
";
$paramsFields = [];
if ($ownerFilter !== null) {
    $sqlFields .= " WHERE f.owner_id = ?";
    $paramsFields[] = $ownerFilter;
}
$sqlFields .= " ORDER BY f.owner_id DESC, f.type DESC, f.id ASC";
$stF = $pdo->prepare($sqlFields);
$stF->execute($paramsFields);
$rows = $stF->fetchAll();

// Bảng tổng hợp Đối soát Doanh thu theo từng Chủ sân (Module 3 Reconciliation)
$reconcileRows = $pdo->query("
    SELECT
        u.id AS owner_id,
        u.full_name,
        u.username,
        u.phone,
        COUNT(DISTINCT f.id) AS pitch_count,
        COUNT(b.id) AS booking_count,
        COALESCE(SUM(CASE WHEN b.status IN ('confirmed', 'completed') THEN b.total_price ELSE 0 END), 0) AS total_revenue,
        (SELECT CONCAT(pa.bank_name, ' • ', pa.bank_account_number, ' (', pa.bank_account_holder, ')')
         FROM partner_applications pa
         WHERE pa.user_id = u.id AND pa.status = 'APPROVED'
         ORDER BY pa.id DESC LIMIT 1) AS bank_info
    FROM users u
    LEFT JOIN fields f ON f.owner_id = u.id
    LEFT JOIN bookings b ON b.field_id = f.id
    WHERE u.account_role = 'PITCH_OWNER' OR u.role = 'pitch_owner' OR f.owner_id IS NOT NULL
    GROUP BY u.id, u.full_name, u.username, u.phone
    ORDER BY total_revenue DESC, pitch_count DESC
")->fetchAll();

$page  = 'fields';
$title = 'Quản lý Hệ thống Sân, Chủ sân & Đối soát Doanh thu';
include '../includes/admin_header.php';
?>

<!-- 4. GIAO DIỆN HIỂN THỊ -->
<?php if ($form): ?>
    <!-- Form Thêm/Sửa sân kèm gán Chủ sân sở hữu (owner_id) -->
    <div class="box">
        <div class="bh">
            <b><?= $form['id'] ? '✏️ Chỉnh sửa Sân bóng #' . $form['id'] : '➕ Thêm Sân bóng mới' ?></b>
            <a href="fields.php" class="btn sm red">✕ Đóng</a>
        </div>
        <form class="form" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $form['id'] ?>">

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Tên sân bóng *</label>
                    <input name="name" required value="<?= e($form['name']) ?>">
                </div>
                <div>
                    <label>👤 Chủ sân sở hữu (Pitch Owner)</label>
                    <select name="owner_id">
                        <option value="">-- Hệ thống Sân Bóng Pro quản lý trực tiếp --</option>
                        <?php foreach ($ownersList as $ow): ?>
                            <option value="<?= $ow['id'] ?>" <?= ($form['owner_id'] ?? null) == $ow['id'] ? 'selected' : '' ?>>
                                🏟 <?= e($ow['full_name']) ?> (@<?= e($ow['username']) ?> • <?= e($ow['phone']) ?>) [<?= e($ow['account_role'] ?: 'PITCH_OWNER') ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

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

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Địa chỉ chi tiết *</label>
                    <input name="address" required value="<?= e($form['address']) ?>">
                </div>
                <div>
                    <label>Quận / Huyện</label>
                    <input name="district" value="<?= e($form['district']) ?>">
                </div>
            </div>

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Vĩ độ (Latitude)</label>
                    <input type="number" step="any" name="latitude" value="<?= e($form['latitude'] ?? 10.7991) ?>">
                </div>
                <div>
                    <label>Kinh độ (Longitude)</label>
                    <input type="number" step="any" name="longitude" value="<?= e($form['longitude'] ?? 106.6650) ?>">
                </div>
            </div>

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Giá thuê cơ sở / giờ (đ) *</label>
                    <input type="number" min="1000" step="1000" name="price" required value="<?= e($form['price_per_hour']) ?>">
                </div>
                <div>
                    <label>Trạng thái vận hành</label>
                    <select name="status">
                        <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>🟢 Hoạt động (Mở đặt sân)</option>
                        <option value="maintenance" <?= $form['status'] === 'maintenance' ? 'selected' : '' ?>>🟠 Đang bảo trì (Tạm ngưng)</option>
                    </select>
                </div>
            </div>

            <label>Mô tả đặc điểm mặt sân</label>
            <input name="description" value="<?= e($form['description']) ?>">

            <label>Ảnh sân (JPG/PNG/WEBP, tối đa 4MB)</label>
            <input type="file" name="image" accept="image/*">
            <br><br>

            <button class="btn">💾 Lưu thông tin sân</button>
            <a class="btn sm red" href="fields.php">Hủy</a>
        </form>
    </div>
<?php else: ?>
    <!-- Bảng Tổng hợp Đối soát Doanh thu theo Chủ sân (Module 3 Reconciliation) -->
    <?php if ($reconcileRows): ?>
        <div class="box" style="margin-bottom:18px;border-left:4px solid #10b981">
            <div class="bh">
                <b>💰 Bảng Tổng Hợp Đối Soát Doanh Thu Theo Chủ Sân Đối Tác (<?= count($reconcileRows) ?> Chủ sân)</b>
                <a href="partners.php" class="btn sm bl">🤝 Duyệt Hồ Sơ Mở Sân</a>
            </div>
            <div class="tw">
                <table>
                    <tr>
                        <th>Chủ sân Đối tác</th>
                        <th>Liên hệ</th>
                        <th>Số lượng sân</th>
                        <th>Tổng lượt đặt</th>
                        <th>Doanh thu đối soát</th>
                        <th>Tài khoản Ngân hàng nhận đối soát</th>
                        <th>Lọc sân</th>
                    </tr>
                    <?php foreach ($reconcileRows as $rc): ?>
                        <tr>
                            <td>
                                <b><?= e($rc['full_name']) ?></b>
                                <small style="display:block">@<?= e($rc['username']) ?></small>
                            </td>
                            <td><?= e($rc['phone']) ?></td>
                            <td><b><?= (int)$rc['pitch_count'] ?></b> sân</td>
                            <td><b><?= (int)$rc['booking_count'] ?></b> đơn</td>
                            <td><b style="color:#10b981;font-size:15px"><?= money($rc['total_revenue']) ?></b></td>
                            <td><small><?= e($rc['bank_info'] ?: 'Thanh toán qua tài khoản hệ thống') ?></small></td>
                            <td>
                                <a class="btn sm <?= $ownerFilter === (int)$rc['owner_id'] ? '' : 'gray' ?>" href="?owner_id=<?= (int)$rc['owner_id'] ?>">
                                    🏟 Xem sân (<?= (int)$rc['pitch_count'] ?>)
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Thanh công cụ Thêm sân & Bộ lọc Chủ sân -->
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
        <div style="display:flex;gap:8px;align-items:center">
            <a class="btn sm" href="?new=1">+ Thêm sân mới</a>
            <?php if ($ownerFilter !== null): ?>
                <a class="btn sm gray" href="fields.php">✕ Bỏ lọc Chủ sân (Xem tất cả)</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bảng Danh sách toàn bộ các Sân bóng kèm Chủ sân tương ứng -->
    <div class="box">
        <?php if (!$rows): ?>
            <div class="empty">Chưa có sân nào.</div>
        <?php else: ?>
            <div class="tw">
                <table>
                    <tr>
                        <th>Ảnh</th>
                        <th>Mã & Tên sân</th>
                        <th>Chủ sân sở hữu</th>
                        <th>Loại & Ghép sân</th>
                        <th>Địa chỉ</th>
                        <th>Giá/giờ</th>
                        <th>Đơn / Doanh thu</th>
                        <th>Trạng thái</th>
                        <th>Hành động</th>
                    </tr>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <img src="<?= e(img_src($r['image'])) ?>" width="64" height="44" style="object-fit:cover; border-radius:6px" alt="Ảnh sân">
                            </td>
                            <td>
                                <?php if (!empty($r['pitch_code'])): ?>
                                    <span class="tag blue" style="font-size:11px"><?= e($r['pitch_code']) ?></span>
                                <?php endif; ?>
                                <b><?= e($r['name']) ?></b>
                            </td>
                            <td>
                                <?php if (!empty($r['owner_id'])): ?>
                                    <span class="tag green">🏟 <?= e($r['owner_name']) ?></span>
                                    <small style="display:block">📞 <?= e($r['owner_phone']) ?></small>
                                <?php else: ?>
                                    <span class="tag gray">Hệ thống Sân Bóng Pro</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <b>Sân <?= e($r['type']) ?> người</b>
                                <?php if (!empty($r['parent_id'])): ?>
                                    <small style="color:#10b981;font-weight:700;display:block">
                                        🔗 Thuộc <?= e($r['parent_name']) ?>
                                    </small>
                                <?php else: ?>
                                    <small style="display:block">Sân gốc / Độc lập</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($r['address']) ?>
                                <small style="display:block"><?= e($r['district']) ?></small>
                            </td>
                            <td><b><?= money($r['price_per_hour']) ?></b></td>
                            <td>
                                <b><?= (int)$r['total_bookings'] ?> đơn</b>
                                <small style="color:#10b981;font-weight:700;display:block"><?= money($r['total_revenue']) ?></small>
                            </td>
                            <td>
                                <form method="post" class="inl">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="tag <?= $r['status'] === 'active' ? 'green' : 'amber' ?>" style="cursor:pointer;border:none">
                                        <?= $r['status'] === 'active' ? '🟢 Hoạt động' : '🟠 Bảo trì' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <a class="btn sm" href="?edit=<?= $r['id'] ?>">Sửa</a>
                                    <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn xóa sân này?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button class="btn sm red">Xóa</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
