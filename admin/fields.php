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
    if ($parent_id === $id || ($id > 0 && function_exists('field_parent_creates_cycle') && field_parent_creates_cycle($id, $parent_id))) {
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
        'latitude'    => !empty($_POST['latitude']) ? (float)$_POST['latitude'] : 10.8058333,
        'longitude'   => !empty($_POST['longitude']) ? (float)$_POST['longitude'] : 106.6583333,
        'gmap_url'    => trim($_POST['google_maps_url'] ?? ''),
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
                latitude=?, longitude=?, google_maps_url=?, price_per_hour=?, description=?, status=?
            WHERE id=?
        ")->execute([
            $d['owner_id'], $d['parent_id'], $d['pitch_code'], $d['name'], $d['type'],
            $d['address'], $d['district'], $d['latitude'], $d['longitude'],
            ($d['gmap_url'] ?: "https://www.google.com/maps/dir/?api=1&destination={$d['latitude']},{$d['longitude']}"),
            $d['price'], $d['description'], $d['status'], $id
        ]);

        if ($img) {
            $pdo->prepare("UPDATE fields SET image=? WHERE id=?")->execute([$img, $id]);
        }
        rebuild_field_conflict_map();
        flash('ok', 'Đã cập nhật thông tin sân, Chủ sân sở hữu và cấu trúc Ghép/Tách sân.');
    } else {
        $final_img = $img ?: 'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=600';

        $venueId = null;
        if ($d['parent_id']) {
            $stPV = $pdo->prepare("SELECT venue_id FROM fields WHERE id = ?");
            $stPV->execute([$d['parent_id']]);
            $venueId = $stPV->fetchColumn() ?: null;
        }
        if (!$venueId && $d['owner_id']) {
            $stOV = $pdo->prepare("SELECT id FROM venues WHERE owner_id = ? ORDER BY id ASC LIMIT 1");
            $stOV->execute([$d['owner_id']]);
            $venueId = $stOV->fetchColumn() ?: null;
        }

        $pdo->prepare("
            INSERT INTO fields (
                venue_id, owner_id, parent_id, pitch_code, name, type, address, district,
                latitude, longitude, price_per_hour, normal_price, peak_price, description, status, image
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $venueId, $d['owner_id'], $d['parent_id'], $d['pitch_code'], $d['name'], $d['type'],
            $d['address'], $d['district'], $d['latitude'], $d['longitude'],
            $d['price'], $d['price'], (int)round($d['price'] * 1.4), $d['description'], $d['status'], $final_img
        ]);
        $newFieldId = (int)$pdo->lastInsertId();

        if ($newFieldId > 0) {
            $baseP = (int)$d['price'];
            $peakP = (int)round($baseP * 1.35);
            $stPr = $pdo->prepare("INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label) VALUES (?, ?, ?, ?, ?, ?)");
            $stPr->execute([$newFieldId, 'WEEKDAY', '05:00:00', '16:00:00', $baseP, 'Giờ thường ban ngày']);
            $stPr->execute([$newFieldId, 'WEEKDAY', '16:00:00', '20:30:00', $peakP, '🔥 Giờ Vàng cao điểm']);
            $stPr->execute([$newFieldId, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($baseP + $peakP) / 2), 'Giờ tối muộn']);
            $stPr->execute([$newFieldId, 'WEEKEND', '05:00:00', '16:00:00', (int)round($baseP * 1.15), 'Cuối tuần ban ngày']);
            $stPr->execute([$newFieldId, 'WEEKEND', '16:00:00', '23:00:00', (int)round($peakP * 1.1), '🔥 Cuối tuần Giờ Vàng']);
        }

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

            <?php $adminPresetCatalog = function_exists('get_verified_hcmc_pitches_catalog') ? get_verified_hcmc_pitches_catalog() : []; ?>
            <label>📌 Chọn nhanh tọa độ chuẩn từ 10 Cụm Sân Thực Tế TP.HCM</label>
            <select onchange="applyAdminFieldPreset(this)" style="margin-bottom:10px">
                <option value="">-- Chọn cụm sân mẫu để tự động điền Địa chỉ & Tọa độ GPS chuẩn --</option>
                <?php foreach ($adminPresetCatalog as $apc): ?>
                    <option value="<?= e(json_encode(['lat'=>$apc['lat'],'lng'=>$apc['lng'],'addr'=>$apc['address'],'dist'=>$apc['district'],'gmap'=>$apc['gmap']], JSON_UNESCAPED_UNICODE)) ?>">
                        ⚽ <?= e($apc['name']) ?> — <?= e($apc['district']) ?> (<?= number_format($apc['lat'], 6) ?>, <?= number_format($apc['lng'], 6) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Địa chỉ chi tiết *</label>
                    <input name="address" id="admFieldAddr" required value="<?= e($form['address']) ?>">
                </div>
                <div>
                    <label>Quận / Huyện</label>
                    <input name="district" id="admFieldDist" value="<?= e($form['district']) ?>">
                </div>
            </div>

            <div class="two" style="margin-bottom:0">
                <div>
                    <label>Vĩ độ GPS (Latitude)</label>
                    <input type="number" step="0.0000001" name="latitude" id="admFieldLat" value="<?= e($form['latitude'] ?? 10.8058333) ?>" oninput="syncAdminFieldPin()">
                </div>
                <div>
                    <label>Kinh độ GPS (Longitude)</label>
                    <input type="number" step="0.0000001" name="longitude" id="admFieldLng" value="<?= e($form['longitude'] ?? 106.6583333) ?>" oninput="syncAdminFieldPin()">
                </div>
            </div>

            <label>🧭 Link Google Maps chỉ đường (Place ID / Tọa độ)</label>
            <input name="google_maps_url" id="admFieldGmap" value="<?= e($form['google_maps_url'] ?? '') ?>" placeholder="https://www.google.com/maps/dir/?api=1&destination=...">

            <div style="margin:10px 0 14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                    <label style="margin:0">🛰 Bản đồ Định vị Mặt sân (Kéo thả ghim hoặc click lên mặt cỏ vệ tinh)</label>
                    <div style="display:flex;gap:6px">
                        <button type="button" class="btn sm" onclick="setAdminFieldMapMode('sat')">🛰 Vệ tinh sân cỏ</button>
                        <button type="button" class="btn sm gray" onclick="setAdminFieldMapMode('street')">🗺 Đường phố</button>
                    </div>
                </div>
                <div id="adminFieldMapPicker" style="width:100%;height:280px;border-radius:12px;border:2px solid #10b981;overflow:hidden"></div>
            </div>
            <script>
              var admMap = null, admMarker = null, admLayers = {};
              document.addEventListener('DOMContentLoaded', function () {
                var el = document.getElementById('adminFieldMapPicker');
                if (!el || typeof L === 'undefined') return;
                var lat = parseFloat(document.getElementById('admFieldLat').value) || 10.8058333;
                var lng = parseFloat(document.getElementById('admFieldLng').value) || 106.6583333;
                admMap = L.map('adminFieldMapPicker').setView([lat, lng], 18);
                admLayers.sat = L.tileLayer('https://{s}.google.com/vt/lyrs=y&hl=vi&x={x}&y={y}&z={z}', {
                  maxZoom: 20, subdomains: ['mt0','mt1','mt2','mt3'], attribution: '© Google Satellite'
                });
                admLayers.street = L.tileLayer('https://{s}.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}', {
                  maxZoom: 20, subdomains: ['mt0','mt1','mt2','mt3'], attribution: '© Google Maps'
                });
                admLayers.sat.addTo(admMap);
                admMarker = L.marker([lat, lng], { draggable: true }).addTo(admMap)
                  .bindPopup('<b>📍 Vị trí Sân bóng</b><br>Kéo thả ghim để cập nhật tọa độ chuẩn').openPopup();
                var updateAdmCoords = function (lt, lg) {
                  document.getElementById('admFieldLat').value = lt.toFixed(7);
                  document.getElementById('admFieldLng').value = lg.toFixed(7);
                  document.getElementById('admFieldGmap').value = 'https://www.google.com/maps/dir/?api=1&destination=' + lt.toFixed(7) + ',' + lg.toFixed(7);
                };
                admMarker.on('dragend', function (e) {
                  var p = e.target.getLatLng();
                  updateAdmCoords(p.lat, p.lng);
                });
                admMap.on('click', function (e) {
                  admMarker.setLatLng(e.latlng);
                  updateAdmCoords(e.latlng.lat, e.latlng.lng);
                });
              });
              function setAdminFieldMapMode(m) {
                if (!admMap) return;
                if (m === 'sat') { admMap.removeLayer(admLayers.street); admLayers.sat.addTo(admMap); }
                else { admMap.removeLayer(admLayers.sat); admLayers.street.addTo(admMap); }
              }
              function syncAdminFieldPin() {
                if (!admMap || !admMarker) return;
                var lt = parseFloat(document.getElementById('admFieldLat').value);
                var lg = parseFloat(document.getElementById('admFieldLng').value);
                if (!isNaN(lt) && !isNaN(lg)) { admMarker.setLatLng([lt, lg]); admMap.panTo([lt, lg]); }
              }
              function applyAdminFieldPreset(sel) {
                if (!sel || !sel.value) return;
                try {
                  var d = JSON.parse(sel.value);
                  document.getElementById('admFieldLat').value = Number(d.lat).toFixed(7);
                  document.getElementById('admFieldLng').value = Number(d.lng).toFixed(7);
                  if (d.addr) document.getElementById('admFieldAddr').value = d.addr;
                  if (d.dist) document.getElementById('admFieldDist').value = d.dist;
                  if (d.gmap) document.getElementById('admFieldGmap').value = d.gmap;
                  if (admMarker && admMap) { admMarker.setLatLng([d.lat, d.lng]); admMap.flyTo([d.lat, d.lng], 18); }
                } catch (e) {}
              }
            </script>

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
    <?php if ($reconcileRows):
        $afFeePct = get_platform_fee_percent();
        $afNetPct = 100 - $afFeePct;
    ?>
        <div class="box" style="margin-bottom:18px;border-left:4px solid #10b981">
            <div class="bh">
                <b>💰 Bảng Tổng Hợp Dòng Tiền Về TK Admin & Đối Soát Chủ Sân Sau Trừ Phí Sàn (<?=$afFeePct?>%)</b>
                <a href="revenue.php" class="btn sm bl">📈 Chi Tiết Doanh Thu & Phí Sàn →</a>
            </div>
            <div class="tw">
                <table>
                    <tr>
                        <th>Chủ sân Đối tác</th>
                        <th>Số sân / Số đơn</th>
                        <th>Tổng thu về TK Admin (100%)</th>
                        <th>Phí sàn Admin giữ (<?=$afFeePct?>%)</th>
                        <th>Chủ sân thực nhận (<?=$afNetPct?>%)</th>
                        <th>Tài khoản Ngân hàng nhận đối soát</th>
                        <th>Lọc sân</th>
                    </tr>
                    <?php foreach ($reconcileRows as $rc):
                        $rcGross = (int)$rc['total_revenue'];
                        $rcFee   = (int)round($rcGross * ($afFeePct / 100));
                        $rcNet   = max(0, $rcGross - $rcFee);
                    ?>
                        <tr>
                            <td>
                                <b><?= e($rc['full_name']) ?></b>
                                <small style="display:block">@<?= e($rc['username']) ?> • 📞 <?= e($rc['phone']) ?></small>
                            </td>
                            <td><b><?= (int)$rc['pitch_count'] ?></b> sân • <b><?= (int)$rc['booking_count'] ?></b> đơn</td>
                            <td><b><?= money($rcGross) ?></b></td>
                            <td style="color:#f59e0b"><b>-<?= money($rcFee) ?></b></td>
                            <td><b style="color:#10b981;font-size:15px"><?= money($rcNet) ?></b></td>
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
                                <small style="display:block"><?= e($r['district']) ?> • 🌐 <code><?= number_format((float)($r['latitude'] ?? 10.8058333), 5) ?>, <?= number_format((float)($r['longitude'] ?? 106.6583333), 5) ?></code></small>
                                <?php $rGmap = !empty($r['google_maps_url']) ? $r['google_maps_url'] : ('https://www.google.com/maps/dir/?api=1&destination=' . ($r['latitude'] ?? 10.8058333) . ',' . ($r['longitude'] ?? 106.6583333)); ?>
                                <a href="<?= e($rGmap) ?>" target="_blank" rel="noopener" style="font-size:11.5px;color:#10b981;font-weight:700;text-decoration:none">🧭 Mở Google Maps ↗</a>
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
