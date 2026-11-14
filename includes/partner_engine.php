<?php
/**
 * ============================================================================
 * FILE: includes/partner_engine.php
 * MÔ TẢ: Engine Kênh Đối tác Chủ sân (Partner / Pitch Owner) & Middleware phân quyền.
 *
 * NỘI DUNG:
 *   PHẦN 1: Migration CSDL an toàn (không mất dữ liệu cũ)
 *     - Bảng `venues` (Cụm sân): owner_id, lat/lng, open_time, close_time, amenities, status DRAFT/ACTIVE/INACTIVE.
 *     - Bảng `fields` (Sân con, giữ nguyên tên bảng cũ để không phá Booking Engine):
 *         + venue_id  : Thuộc Cụm sân nào.
 *         + pitch_type: Cột sinh tự động 'PITCH_5' | 'PITCH_7' | 'PITCH_11' từ cột `type`.
 *         + parent_id : (đã có) đóng vai trò `parent_pitch_id` -> khóa lịch chéo qua `field_conflict_map`.
 *     - VIEW `pitches`       : Ánh xạ chuẩn tên gọi mới trên bảng `fields`.
 *     - VIEW `pitch_pricing` : Ánh xạ chuẩn tên gọi mới trên bảng `pricing_rules` (giờ thường / giờ vàng / cuối tuần).
 *     - Bảng `bookings`: booking_source (ONLINE / WALK_IN), walkin_name, walkin_phone, checked_in_at, no_show.
 *     - Bảng `payments`: bổ sung phương thức 'wallet' (Ví nội bộ).
 *     - Bảng `partner_applications`: bổ sung `approved_venue_id`.
 *   PHẦN 2: Middleware phân quyền CUSTOMER / PITCH_OWNER / ADMIN.
 *   PHẦN 3: Helpers dữ liệu Cụm sân / Sân con theo `owner_id`.
 *   PHẦN 4: Nghiệp vụ POS của Chủ sân: Check-in, No-show (trừ uy tín), Walk-in booking.
 *   PHẦN 5: Đăng xuất an toàn (xóa Session + Cookie).
 *   PHẦN 6: Hệ thống Chat trực tiếp với Chủ sân & Trung tâm Tin nhắn Đối tác (`owner_chat_messages`).
 *   PHẦN 7: Hệ thống Voucher khuyến mãi phân quyền riêng cho từng Tài khoản Đối tác Chủ sân (`vouchers.owner_id`).
 * ============================================================================
 */

// ----------------------------------------------------------------------------
// PHẦN 1: MIGRATION CSDL CHO KÊNH ĐỐI TÁC (CHẠY 1 LẦN, IDEMPOTENT)
// ----------------------------------------------------------------------------

/**
 * Kiểm tra một cột đã tồn tại trong bảng hay chưa (Hỗ trợ 100% Native Prepared Statements của MySQL/MariaDB).
 */
function pe_column_exists($table, $column) {
    global $pdo;
    try {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        $safeCol   = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
        return $stmt ? (bool)$stmt->fetch() : false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Hàm ensure_partner_venue_schema(): Nâng cấp CSDL hỗ trợ Cụm sân / Sân con / Bảng giá / POS.
 * Mỗi bước chạy trong try riêng để một bước lỗi không chặn các bước còn lại.
 */
function ensure_partner_venue_schema() {
    global $pdo;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Bỏ qua nếu đã nâng cấp phiên bản này (tránh SHOW COLUMNS ở mỗi request)
    try {
        $ver = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='partner_venue_schema_v2'")->fetchColumn();
        if ($ver === '1') {
            return;
        }
    } catch (Exception $e) {
        return;
    }

    // 1.1. Bảng Cụm sân (venues)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS venues (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Liên kết users.id (PITCH_OWNER)',
            name VARCHAR(180) NOT NULL,
            address VARCHAR(255) NOT NULL DEFAULT '',
            district VARCHAR(100) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT 'TP. Hồ Chí Minh',
            latitude DECIMAL(10,7) NOT NULL DEFAULT 10.7768890,
            longitude DECIMAL(10,7) NOT NULL DEFAULT 106.7008060,
            open_time TIME NOT NULL DEFAULT '05:30:00',
            close_time TIME NOT NULL DEFAULT '23:00:00',
            amenities VARCHAR(255) NOT NULL DEFAULT 'wifi,parking,canteen' COMMENT 'CSV: wifi,parking,canteen,shoes,shower,referee,camera',
            images TEXT NULL COMMENT 'JSON mảng đường dẫn ảnh thực tế',
            contact_phone VARCHAR(30) NOT NULL DEFAULT '',
            description TEXT NULL,
            status ENUM('DRAFT','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_venues_owner (owner_id),
            INDEX idx_venues_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    // 1.2. Bảng fields (Sân con): venue_id + pitch_type sinh tự động
    try {
        if (!pe_column_exists('fields', 'venue_id')) {
            $pdo->exec("ALTER TABLE fields ADD COLUMN venue_id INT UNSIGNED NULL DEFAULT NULL AFTER id");
            $pdo->exec("ALTER TABLE fields ADD INDEX idx_fields_venue (venue_id)");
        }
    } catch (Exception $e) {}
    try {
        if (!pe_column_exists('fields', 'pitch_type')) {
            $pdo->exec("ALTER TABLE fields ADD COLUMN pitch_type VARCHAR(10) AS (CONCAT('PITCH_', `type`)) VIRTUAL AFTER `type`");
        }
    } catch (Exception $e) {}

    // 1.3. Bảng bookings: nguồn đặt, khách vãng lai tại quầy, check-in, no-show
    try {
        if (!pe_column_exists('bookings', 'booking_source')) {
            $pdo->exec("ALTER TABLE bookings
                ADD COLUMN booking_source ENUM('ONLINE','WALK_IN') NOT NULL DEFAULT 'ONLINE' AFTER note,
                ADD COLUMN walkin_name VARCHAR(120) NULL DEFAULT NULL AFTER booking_source,
                ADD COLUMN walkin_phone VARCHAR(30) NULL DEFAULT NULL AFTER walkin_name,
                ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL AFTER walkin_phone,
                ADD COLUMN no_show TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_at");
        }
    } catch (Exception $e) {}

    // 1.4. Bảng payments: bổ sung phương thức Ví nội bộ
    try {
        $pdo->exec("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank','transfer','qr','momo','vnpay','wallet') NOT NULL DEFAULT 'cash'");
    } catch (Exception $e) {}

    // 1.5. partner_applications: liên kết Cụm sân được tạo khi duyệt
    try {
        if (!pe_column_exists('partner_applications', 'approved_venue_id')) {
            $pdo->exec("ALTER TABLE partner_applications ADD COLUMN approved_venue_id INT UNSIGNED NULL DEFAULT NULL AFTER approved_field_id");
        }
    } catch (Exception $e) {}

    // 1.6. VIEW chuẩn hóa tên gọi mới (pitches / pitch_pricing) trên bảng cũ
    try {
        $pdo->exec("CREATE OR REPLACE VIEW pitches AS
            SELECT f.id, f.venue_id, f.owner_id, f.parent_id AS parent_pitch_id, f.pitch_code, f.name,
                   CONCAT('PITCH_', f.type) AS pitch_type, f.price_per_hour,
                   UPPER(f.status) AS status, f.image, f.created_at
            FROM fields f");
    } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE OR REPLACE VIEW pitch_pricing AS
            SELECT pr.id, pr.field_id AS pitch_id, pr.day_type, pr.start_time, pr.end_time,
                   pr.price_per_hour AS price, pr.label,
                   CASE WHEN pr.start_time >= '17:00:00' AND pr.end_time <= '20:30:00' THEN 1 ELSE 0 END AS is_golden_hour
            FROM pricing_rules pr");
    } catch (Exception $e) {}

    // 1.7. Khởi tạo Cụm sân từ dữ liệu cũ: mỗi sân gốc (parent_id IS NULL) -> 1 venue, gán venue_id cho cả cây con
    try {
        $hasVenues = (int)$pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn();
        if ($hasVenues === 0) {
            $roots = $pdo->query("SELECT * FROM fields WHERE parent_id IS NULL ORDER BY id ASC")->fetchAll();
            $insV = $pdo->prepare("
                INSERT INTO venues (owner_id, name, address, district, latitude, longitude, open_time, close_time,
                                    amenities, images, contact_phone, description, status)
                VALUES (?, ?, ?, ?, ?, ?, '05:30:00', '23:00:00', 'wifi,parking,canteen,shoes', ?, ?, ?, ?)
            ");
            foreach ($roots as $r) {
                $insV->execute([
                    $r['owner_id'] ?? null,
                    $r['name'],
                    $r['address'],
                    $r['district'],
                    $r['latitude'] ?? 10.7768890,
                    $r['longitude'] ?? 106.7008060,
                    json_encode([$r['image']], JSON_UNESCAPED_SLASHES),
                    $r['contact_phone'] ?? '',
                    $r['description'] ?? '',
                    $r['status'] === 'active' ? 'ACTIVE' : 'INACTIVE'
                ]);
                $vid = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE fields SET venue_id = ? WHERE id = ?")->execute([$vid, $r['id']]);
                $pdo->prepare("
                    UPDATE fields f
                    INNER JOIN field_conflict_map m ON m.conflict_field_id = f.id
                    SET f.venue_id = ?
                    WHERE m.field_id = ? AND m.relation_type = 'DESCENDANT'
                ")->execute([$vid, $r['id']]);
            }
            // Đồng bộ venue_id cho hồ sơ đối tác đã duyệt
            $pdo->exec("
                UPDATE partner_applications pa
                INNER JOIN fields f ON f.id = pa.approved_field_id
                SET pa.approved_venue_id = f.venue_id
                WHERE pa.approved_field_id IS NOT NULL
            ");
        }
    } catch (Exception $e) {}

    // 1.8. Bảng partner_payout_requests (Lịch sử yêu cầu rút tiền đối soát về tài khoản Ngân hàng của Đối tác)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_payout_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            bank_name VARCHAR(80) NOT NULL DEFAULT '',
            bank_account_number VARCHAR(60) NOT NULL DEFAULT '',
            bank_account_holder VARCHAR(120) NOT NULL DEFAULT '',
            status ENUM('PENDING','COMPLETED','REJECTED') NOT NULL DEFAULT 'COMPLETED',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ppr_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('partner_venue_schema_v2', '1')")->execute();
    } catch (Exception $e) {}
}

ensure_partner_venue_schema();

/**
 * Hàm ensure_clean_partner_ownership_and_venues():
 * Đảm bảo mỗi Chủ sân sở hữu đúng các cụm sân của mình (không bị gộp nhầm parent_id sang cụm sân khác quận),
 * đồng thời đảm bảo mỗi cụm sân gốc đều có bản ghi `venues` riêng và đồng bộ `venue_id` chính xác.
 */
/**
 * Hàm compute_sub_pitch_micro_offset():
 * Tính độ lệch tọa độ vi mô (±0.00014 độ ~ 15 mét) cho các sân con (5A, 5B, 5C, 7A, 7B...)
 * để mỗi sân con hiển thị một ghim độc lập đúng sơ đồ mặt cỏ trên Bản đồ Vệ tinh thay vì chồng khít lên nhau.
 */
function compute_sub_pitch_micro_offset($pitchCode, $index = 0) {
    $code = strtoupper(trim((string)$pitchCode));
    $mapOffsets = [
        '11A' => [0.0000000,  0.0000000],
        '7A'  => [0.0001300, -0.0001300],
        '7B'  => [0.0001300,  0.0001300],
        '5A'  => [-0.0001400, -0.0001600],
        '5B'  => [-0.0001400,  0.0000000],
        '5C'  => [-0.0001400,  0.0001600],
        '5D'  => [0.0000000,  -0.0001800],
        '5E'  => [0.0000000,   0.0001800],
        '5F'  => [0.0001600,   0.0000000],
    ];
    if (isset($mapOffsets[$code])) {
        return $mapOffsets[$code];
    }
    $angle = (($index % 8) * 45) * (M_PI / 180);
    $radius = 0.0001500;
    return [round(sin($angle) * $radius, 7), round(cos($angle) * $radius, 7)];
}

function ensure_clean_partner_ownership_and_venues() {
    global $pdo;
    static $repaired = false;
    if ($repaired) {
        return;
    }
    $repaired = true;

    try {
        $ver = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='partner_ownership_repaired_v4'")->fetchColumn();
        if ($ver === '1') {
            return;
        }
    } catch (Exception $e) {
        return;
    }

    try {
        // 0. Đồng bộ chuẩn tọa độ GPS thực tế của 10 cụm sân chính (ID 1..10) từ danh mục chuẩn TP.HCM
        if (function_exists('get_verified_hcmc_pitches_catalog')) {
            $catalog = get_verified_hcmc_pitches_catalog();
            $stUpdExact = $pdo->prepare("
                UPDATE fields
                SET address = ?, district = ?, latitude = ?, longitude = ?, google_maps_url = ?, contact_phone = ?
                WHERE id = ?
            ");
            foreach ($catalog as $cid => $cInfo) {
                $stUpdExact->execute([
                    $cInfo['address'],
                    $cInfo['district'],
                    $cInfo['lat'],
                    $cInfo['lng'],
                    $cInfo['gmap'],
                    $cInfo['phone'],
                    (int)$cid
                ]);
            }
        }

        // 1. Gán lại đúng owner_id cho 10 cụm sân chính (ID 1..10) theo 6 tài khoản Chủ sân mẫu
        $ownerMap = [
            'chusan_chaolua' => [1],
            'chusan_k34'     => [2, 10],
            'chusan_giadinh' => [3, 8],
            'chusan_phutho'  => [4],
            'chusan_taodan'  => [5],
            'chusan_tdtu'    => [6, 7, 9],
        ];
        $stUid = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        foreach ($ownerMap as $uname => $fids) {
            $stUid->execute([$uname]);
            $uid = (int)$stUid->fetchColumn();
            if ($uid > 0) {
                foreach ($fids as $fid) {
                    $pdo->prepare("UPDATE fields SET owner_id = ?, parent_id = NULL WHERE id = ?")->execute([$uid, $fid]);
                }
            }
        }

        // 2. Gỡ bỏ mọi liên kết parent_id bị lệch giữa 2 chủ sân khác nhau
        $pdo->exec("
            UPDATE fields c
            INNER JOIN fields p ON c.parent_id = p.id
            SET c.parent_id = NULL
            WHERE c.owner_id IS NOT NULL AND p.owner_id IS NOT NULL AND c.owner_id <> p.owner_id
        ");

        // 3. Đảm bảo mỗi sân gốc (parent_id IS NULL) có 1 bản ghi Cụm sân (venues) tương ứng với đúng owner_id & tọa độ chuẩn
        $roots = $pdo->query("SELECT * FROM fields WHERE parent_id IS NULL ORDER BY id ASC")->fetchAll();
        $stFindV = $pdo->prepare("SELECT id FROM venues WHERE id = ? LIMIT 1");
        $stFindVByName = $pdo->prepare("SELECT id FROM venues WHERE name = ? AND (owner_id = ? OR owner_id IS NULL) ORDER BY id ASC LIMIT 1");
        $stInsV = $pdo->prepare("
            INSERT INTO venues (owner_id, name, address, district, latitude, longitude, open_time, close_time,
                                amenities, images, contact_phone, description, status)
            VALUES (?, ?, ?, ?, ?, ?, '05:30:00', '23:00:00', 'wifi,parking,canteen,shoes,light', ?, ?, ?, ?)
        ");

        foreach ($roots as $r) {
            $vid = (int)($r['venue_id'] ?? 0);
            $validVenue = false;
            if ($vid > 0) {
                $stFindV->execute([$vid]);
                if ($stFindV->fetchColumn()) {
                    $validVenue = true;
                }
            }
            if (!$validVenue) {
                $stFindVByName->execute([$r['name'], $r['owner_id'] ?? null]);
                $vid = (int)$stFindVByName->fetchColumn();
            }
            if ($vid <= 0) {
                $stInsV->execute([
                    $r['owner_id'] ?? null,
                    $r['name'],
                    $r['address'],
                    $r['district'],
                    $r['latitude'] ?? 10.7744400,
                    $r['longitude'] ?? 106.6925000,
                    json_encode([$r['image']], JSON_UNESCAPED_SLASHES),
                    $r['contact_phone'] ?? '',
                    $r['description'] ?? '',
                    $r['status'] === 'active' ? 'ACTIVE' : 'INACTIVE'
                ]);
                $vid = (int)$pdo->lastInsertId();
            } else {
                // Cập nhật tọa độ chuẩn và địa chỉ từ sân gốc chuẩn sang bảng venues
                $pdo->prepare("
                    UPDATE venues
                    SET owner_id = COALESCE(?, owner_id),
                        address = ?,
                        district = ?,
                        latitude = ?,
                        longitude = ?
                    WHERE id = ?
                ")->execute([
                    $r['owner_id'] ?? null,
                    $r['address'],
                    $r['district'],
                    $r['latitude'],
                    $r['longitude'],
                    $vid
                ]);
            }
            $pdo->prepare("UPDATE fields SET venue_id = ? WHERE id = ?")->execute([$vid, $r['id']]);
        }

        // 4. Đồng bộ venue_id, owner_id và tạo tọa độ vi mô chuẩn cho các sân Con (để không bị trùng khít tọa độ với sân Cha)
        for ($step = 0; $step < 2; $step++) {
            $pdo->exec("
                UPDATE fields c
                INNER JOIN fields p ON c.parent_id = p.id
                SET c.venue_id = p.venue_id,
                    c.owner_id = COALESCE(c.owner_id, p.owner_id),
                    c.district = p.district
                WHERE p.venue_id IS NOT NULL
            ");
        }

        $children = $pdo->query("
            SELECT c.id, c.pitch_code, c.latitude, c.longitude, p.latitude AS p_lat, p.longitude AS p_lng
            FROM fields c
            INNER JOIN fields p ON c.parent_id = p.id
            ORDER BY c.id ASC
        ")->fetchAll();
        $stUpdChildGeo = $pdo->prepare("UPDATE fields SET latitude = ?, longitude = ?, google_maps_url = ? WHERE id = ?");
        foreach ($children as $idx => $ch) {
            $dLat = abs((float)$ch['latitude'] - (float)$ch['p_lat']);
            $dLng = abs((float)$ch['longitude'] - (float)$ch['p_lng']);
            // Nếu sân con đang bị trùng khít tọa độ với sân cha hoặc bị lệch sang quận khác (> 1km), gán tọa độ vi mô chuẩn quanh sân cha
            if (($dLat < 0.00001 && $dLng < 0.00001) || $dLat > 0.01 || $dLng > 0.01) {
                [$offLat, $offLng] = compute_sub_pitch_micro_offset($ch['pitch_code'] ?? '', $idx + 1);
                $newLat = round((float)$ch['p_lat'] + $offLat, 7);
                $newLng = round((float)$ch['p_lng'] + $offLng, 7);
                $newGmap = "https://www.google.com/maps/dir/?api=1&destination={$newLat},{$newLng}";
                $stUpdChildGeo->execute([$newLat, $newLng, $newGmap, (int)$ch['id']]);
            }
        }

        // 5. Đồng bộ tọa độ chuẩn sang bảng partner_applications
        $pdo->exec("
            UPDATE partner_applications pa
            INNER JOIN fields f ON f.id = pa.approved_field_id
            SET pa.latitude = f.latitude,
                pa.longitude = f.longitude,
                pa.venue_address = f.address,
                pa.district = f.district,
                pa.approved_venue_id = f.venue_id
            WHERE pa.approved_field_id IS NOT NULL
        ");

        if (function_exists('rebuild_field_conflict_map')) {
            rebuild_field_conflict_map();
        }

        $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('partner_ownership_repaired_v4', '1')")->execute();
    } catch (Exception $e) {}
}

ensure_clean_partner_ownership_and_venues();

// ----------------------------------------------------------------------------
// PHẦN 2: MIDDLEWARE PHÂN QUYỀN (CUSTOMER / PITCH_OWNER / ADMIN)
// ----------------------------------------------------------------------------

/**
 * Hàm require_role(): Bắt buộc tài khoản đăng nhập có một trong các vai trò cho phép.
 * ADMIN luôn được phép truy cập mọi kênh.
 * @param array $roles VD: ['PITCH_OWNER'] hoặc ['CUSTOMER','PITCH_OWNER']
 */
function require_role(array $roles) {
    $user = require_login();
    $code = get_user_role_code($user);

    if (($user['account_status'] ?? 'ACTIVE') === 'BANNED') {
        flash('err', 'Tài khoản đang bị khóa (BANNED). Vui lòng liên hệ Quản trị viên.');
        header('Location: ' . project_base() . 'login.php');
        exit;
    }
    if ($code === 'ADMIN' || in_array($code, $roles, true)) {
        return $user;
    }
    // PITCH_OWNER được xác định theo partner_status APPROVED nhưng role cũ chưa đồng bộ
    if (in_array('PITCH_OWNER', $roles, true) && is_pitch_owner($user)) {
        return $user;
    }

    http_response_code(403);
    flash('err', 'Bạn không có quyền truy cập khu vực này. Vui lòng đăng ký Hợp tác Mở sân để sử dụng Kênh Đối tác.');
    header('Location: ' . project_base() . 'user/profile.php?tab=partner');
    exit;
}

/**
 * Hàm require_pitch_owner(): Middleware dành riêng cho Kênh Đối tác Chủ sân.
 */
function require_pitch_owner() {
    return require_role(['PITCH_OWNER']);
}

// ----------------------------------------------------------------------------
// PHẦN 3: HELPERS DỮ LIỆU CỤM SÂN / SÂN CON THEO OWNER_ID
// ----------------------------------------------------------------------------

/** Danh sách tiện ích chuẩn của Cụm sân. */
function venue_amenity_catalog() {
    return [
        'wifi'    => '📶 Wifi miễn phí',
        'parking' => '🅿️ Bãi đỗ xe',
        'canteen' => '🥤 Căng tin',
        'shoes'   => '👟 Thuê giày',
        'shower'  => '🚿 Phòng tắm',
        'referee' => '🧑‍⚖️ Trọng tài',
        'camera'  => '🎥 Camera quay trận',
        'light'   => '💡 Đèn LED chống lóa',
    ];
}

/**
 * Lấy danh sách Cụm sân của chủ sân (Admin xem được tất cả).
 */
function get_owner_venues($user) {
    global $pdo;
    if (get_user_role_code($user) === 'ADMIN') {
        return $pdo->query("SELECT * FROM venues ORDER BY id ASC")->fetchAll();
    }
    $stmt = $pdo->prepare("SELECT * FROM venues WHERE owner_id = ? ORDER BY id ASC");
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetchAll();
}

/**
 * Kiểm tra quyền quản lý Cụm sân. Trả về bản ghi venue hoặc null.
 */
function owner_get_venue($user, $venueId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM venues WHERE id = ?");
    $stmt->execute([(int)$venueId]);
    $v = $stmt->fetch();
    if (!$v) {
        return null;
    }
    if (get_user_role_code($user) === 'ADMIN' || (int)$v['owner_id'] === (int)$user['id']) {
        return $v;
    }
    return null;
}

/**
 * Kiểm tra quyền quản lý Sân con. Trả về bản ghi field hoặc null.
 */
function owner_get_field($user, $fieldId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT f.*, v.owner_id AS venue_owner_id, v.name AS venue_name
        FROM fields f
        LEFT JOIN venues v ON v.id = f.venue_id
        WHERE f.id = ?
    ");
    $stmt->execute([(int)$fieldId]);
    $f = $stmt->fetch();
    if (!$f) {
        return null;
    }
    $uid = (int)$user['id'];
    if (get_user_role_code($user) === 'ADMIN' || (int)$f['owner_id'] === $uid || (int)$f['venue_owner_id'] === $uid) {
        return $f;
    }
    return null;
}

/**
 * Lấy danh sách Sân con trong một Cụm sân (sắp xếp sân lớn trước).
 */
function get_venue_fields($venueId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT f.*, p.name AS parent_name, p.pitch_code AS parent_code
        FROM fields f
        LEFT JOIN fields p ON p.id = f.parent_id
        WHERE f.venue_id = ?
        ORDER BY FIELD(f.type, '11', '7', '5'), f.pitch_code ASC, f.id ASC
    ");
    $stmt->execute([(int)$venueId]);
    $rows = $stmt->fetchAll();
    if (!$rows && $venueId > 0) {
        $stV = $pdo->prepare("SELECT owner_id, name FROM venues WHERE id = ? LIMIT 1");
        $stV->execute([(int)$venueId]);
        $vInfo = $stV->fetch();
        if ($vInfo && !empty($vInfo['owner_id'])) {
            $pdo->prepare("UPDATE fields SET venue_id = ? WHERE owner_id = ? AND (venue_id IS NULL OR venue_id = 0)")
                ->execute([(int)$venueId, (int)$vInfo['owner_id']]);
            $stmt->execute([(int)$venueId]);
            $rows = $stmt->fetchAll();
        }
    }
    return $rows;
}

/**
 * Lấy toàn bộ ID sân con thuộc các cụm sân của chủ sân.
 */
function get_owner_field_ids($user) {
    global $pdo;
    if (get_user_role_code($user) === 'ADMIN') {
        return array_map('intval', $pdo->query("SELECT id FROM fields")->fetchAll(PDO::FETCH_COLUMN));
    }
    $stmt = $pdo->prepare("
        SELECT f.id FROM fields f
        LEFT JOIN venues v ON v.id = f.venue_id
        WHERE f.owner_id = ? OR v.owner_id = ?
    ");
    $stmt->execute([(int)$user['id'], (int)$user['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Đồng bộ thông tin Cụm sân xuống các Sân con (địa chỉ, tọa độ, chủ sân, SĐT).
 */
function sync_venue_to_fields($venueId) {
    global $pdo;
    // 1. Cập nhật thông tin chung và tọa độ tâm cho sân gốc (parent_id IS NULL)
    $pdo->prepare("
        UPDATE fields f
        INNER JOIN venues v ON v.id = f.venue_id
        SET f.owner_id = v.owner_id,
            f.address = v.address,
            f.district = v.district,
            f.latitude = v.latitude,
            f.longitude = v.longitude,
            f.contact_phone = IF(v.contact_phone = '', f.contact_phone, v.contact_phone)
        WHERE v.id = ? AND f.parent_id IS NULL
    ")->execute([(int)$venueId]);

    // 2. Với các sân con (parent_id IS NOT NULL): giữ nguyên tọa độ tùy chỉnh riêng nếu nằm trong phạm vi cụm sân (< 500m),
    //    nếu bị lệch xa hoặc trùng khít thì cấp tọa độ vi mô chuẩn quanh tâm Cụm sân
    $stV = $pdo->prepare("SELECT owner_id, address, district, latitude, longitude, contact_phone, status FROM venues WHERE id = ? LIMIT 1");
    $stV->execute([(int)$venueId]);
    $vRow = $stV->fetch();
    if ($vRow) {
        $vLat = (float)$vRow['latitude'];
        $vLng = (float)$vRow['longitude'];
        $stSubs = $pdo->prepare("SELECT id, pitch_code, latitude, longitude FROM fields WHERE venue_id = ? AND parent_id IS NOT NULL ORDER BY id ASC");
        $stSubs->execute([(int)$venueId]);
        $subs = $stSubs->fetchAll();
        $stUpdSub = $pdo->prepare("
            UPDATE fields
            SET owner_id = ?, district = ?, latitude = ?, longitude = ?,
                contact_phone = IF(? = '', contact_phone, ?)
            WHERE id = ?
        ");
        foreach ($subs as $idx => $sb) {
            $sLat = (float)$sb['latitude'];
            $sLng = (float)$sb['longitude'];
            if (abs($sLat - $vLat) > 0.005 || abs($sLng - $vLng) > 0.005 || (abs($sLat - $vLat) < 0.00001 && abs($sLng - $vLng) < 0.00001)) {
                [$offLat, $offLng] = compute_sub_pitch_micro_offset($sb['pitch_code'] ?? '', $idx + 1);
                $sLat = round($vLat + $offLat, 7);
                $sLng = round($vLng + $offLng, 7);
            }
            $stUpdSub->execute([
                $vRow['owner_id'],
                $vRow['district'],
                $sLat,
                $sLng,
                (string)$vRow['contact_phone'],
                (string)$vRow['contact_phone'],
                (int)$sb['id']
            ]);
        }

        if ($vRow['status'] === 'INACTIVE') {
            $pdo->prepare("UPDATE fields SET status = 'maintenance' WHERE venue_id = ?")->execute([(int)$venueId]);
        }
    }
}

/**
 * Kiểm tra đặt parent_id có tạo vòng lặp (sân là tổ tiên của chính nó) hay không.
 */
function field_parent_creates_cycle($fieldId, $parentId) {
    global $pdo;
    if (!$fieldId || !$parentId) {
        return false;
    }
    $curr  = (int)$parentId;
    $guard = 0;
    $stmt  = $pdo->prepare("SELECT parent_id FROM fields WHERE id = ?");
    while ($curr && $guard < 20) {
        if ($curr === (int)$fieldId) {
            return true;
        }
        $stmt->execute([$curr]);
        $curr = (int)$stmt->fetchColumn();
        $guard++;
    }
    return false;
}

// ----------------------------------------------------------------------------
// PHẦN 4: NGHIỆP VỤ POS CỦA CHỦ SÂN (CHECK-IN / NO-SHOW / WALK-IN)
// ----------------------------------------------------------------------------

/**
 * Lấy đơn đặt sân thuộc quyền quản lý của chủ sân.
 */
function owner_get_booking($user, $bookingId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT b.*, f.name AS fname, f.owner_id AS field_owner_id, v.owner_id AS venue_owner_id,
               p.status AS pay_status, p.amount AS pay_amount
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        LEFT JOIN venues v ON v.id = f.venue_id
        LEFT JOIN payments p ON p.booking_id = b.id
        WHERE b.id = ?
    ");
    $stmt->execute([(int)$bookingId]);
    $b = $stmt->fetch();
    if (!$b) {
        return null;
    }
    $uid = (int)$user['id'];
    if (get_user_role_code($user) === 'ADMIN' || (int)$b['field_owner_id'] === $uid || (int)$b['venue_owner_id'] === $uid) {
        return $b;
    }
    return null;
}

/**
 * Check-in: Khách đã đến sân -> hoàn tất đơn, thu tiền mặt nếu chưa thanh toán, +5 uy tín cho khách online.
 */
function owner_check_in_booking($user, $bookingId) {
    global $pdo;
    $b = owner_get_booking($user, $bookingId);
    if (!$b) {
        return ['ok' => false, 'message' => 'Không tìm thấy đơn đặt sân thuộc cụm sân của bạn.'];
    }
    if (!in_array($b['status'], ['pending', 'confirmed'], true)) {
        return ['ok' => false, 'message' => 'Đơn #' . $b['id'] . ' không ở trạng thái chờ nhận sân.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE bookings SET status = 'completed', checked_in_at = NOW(), hold_expires_at = NULL WHERE id = ?")
            ->execute([$b['id']]);
        $pdo->prepare("
            UPDATE payments
            SET status = 'paid',
                paid_at = IFNULL(paid_at, NOW()),
                transaction_code = IFNULL(transaction_code, CONCAT('POS', DATE_FORMAT(NOW(), '%y%m%d%H%i%s')))
            WHERE booking_id = ? AND status <> 'refunded'
        ")->execute([$b['id']]);

        if ($b['booking_source'] !== 'WALK_IN') {
            record_wallet_and_reputation(
                (int)$b['user_id'],
                'REPUTATION',
                0,
                5,
                max(10, (int)round($b['total_price'] / 10000)),
                "Check-in đúng hẹn tại {$b['fname']} (Đơn #{$b['id']}) — Cộng +5 Điểm Uy Tín",
                (int)$b['id'],
                (int)$user['id']
            );
            notify((int)$b['user_id'], "✅ Bạn đã check-in sân {$b['fname']} (Đơn #{$b['id']}). Cộng +5 Điểm Uy Tín. Chúc trận đấu vui vẻ!");
        }
        $pdo->commit();
        return ['ok' => true, 'message' => "Đã xác nhận khách đến sân (Check-in) cho đơn #{$b['id']}."];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Lỗi check-in: ' . $e->getMessage()];
    }
}

/**
 * No-show: Khách bùng kèo -> hủy đơn, giữ tiền cọc, trừ -20 uy tín (khách online).
 */
function owner_mark_no_show($user, $bookingId) {
    global $pdo;
    $b = owner_get_booking($user, $bookingId);
    if (!$b) {
        return ['ok' => false, 'message' => 'Không tìm thấy đơn đặt sân thuộc cụm sân của bạn.'];
    }
    if (!in_array($b['status'], ['pending', 'confirmed'], true)) {
        return ['ok' => false, 'message' => 'Đơn #' . $b['id'] . ' không thể báo bùng kèo ở trạng thái hiện tại.'];
    }
    if (strtotime($b['booking_date'] . ' ' . $b['start_time']) > time()) {
        return ['ok' => false, 'message' => 'Chỉ được báo bùng kèo sau khi đã đến giờ bắt đầu trận đấu.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE bookings
            SET status = 'cancelled', no_show = 1, hold_expires_at = NULL,
                cancel_reason = 'Khách bùng kèo (No-show) — Chủ sân báo cáo'
            WHERE id = ?
        ")->execute([$b['id']]);

        if ($b['booking_source'] !== 'WALK_IN') {
            record_wallet_and_reputation(
                (int)$b['user_id'],
                'REPUTATION',
                0,
                -20,
                0,
                "Bùng kèo không đến sân {$b['fname']} (Đơn #{$b['id']}) — Chủ sân báo cáo No-show",
                (int)$b['id'],
                (int)$user['id']
            );
            notify((int)$b['user_id'], "⚠️ Chủ sân báo cáo bạn không đến sân {$b['fname']} (Đơn #{$b['id']}). Tài khoản bị trừ -20 Điểm Uy Tín.");
        }
        $pdo->commit();
        return ['ok' => true, 'message' => "Đã ghi nhận khách bùng kèo đơn #{$b['id']} và trừ -20 Điểm Uy Tín của khách."];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Lỗi báo No-show: ' . $e->getMessage()];
    }
}

/**
 * Tìm tài khoản khách theo SĐT, nếu chưa có thì tạo tài khoản khách vãng lai (guest).
 */
function find_or_create_walkin_customer($name, $phone) {
    global $pdo;
    $phoneNorm = preg_replace('/\D+/', '', (string)$phone);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE REPLACE(REPLACE(REPLACE(phone,' ',''),'.',''),'-','') = ? LIMIT 1");
    $stmt->execute([$phoneNorm]);
    $uid = (int)$stmt->fetchColumn();
    if ($uid > 0) {
        return $uid;
    }
    $username = 'guest_' . $phoneNorm;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $uid = (int)$stmt->fetchColumn();
    if ($uid > 0) {
        return $uid;
    }
    $pdo->prepare("
        INSERT INTO users (username, password, full_name, phone, email, role, status)
        VALUES (?, ?, ?, ?, ?, 'user', 'active')
    ")->execute([
        $username,
        password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        $name !== '' ? $name : ('Khách vãng lai ' . $phoneNorm),
        $phoneNorm,
        $username . '@walkin.sanbong.local'
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Walk-in booking: Chủ sân tạo lịch đặt trực tiếp tại quầy / qua điện thoại.
 * Dùng chung Pessimistic Lock + kiểm tra khóa chéo sân Cha-Con + Giá động của Booking Engine.
 */
function owner_create_walkin_booking($user, $fieldId, $date, $start, $end, $name, $phone, $paid, $note = '') {
    global $pdo;
    $field = owner_get_field($user, $fieldId);
    if (!$field) {
        return ['ok' => false, 'message' => 'Sân không thuộc quyền quản lý của bạn.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:(00|30)$/', $start) || !preg_match('/^\d{2}:(00|30)$/', $end)) {
        return ['ok' => false, 'message' => 'Ngày hoặc khung giờ không hợp lệ (bước 30 phút).'];
    }
    if (strtotime("$date $end") - strtotime("$date $start") < 3600) {
        return ['ok' => false, 'message' => 'Thời lượng tối thiểu 60 phút.'];
    }
    $phoneNorm = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($phoneNorm) < 9) {
        return ['ok' => false, 'message' => 'Vui lòng nhập số điện thoại khách hợp lệ.'];
    }

    $pdo->beginTransaction();
    try {
        // Khóa bi quan toàn bộ cụm sân liên quan (chính nó + cha + con) theo thứ tự id chống deadlock
        $lock = $pdo->prepare("
            SELECT f.id FROM fields f
            INNER JOIN field_conflict_map m ON m.conflict_field_id = f.id
            WHERE m.field_id = ?
            ORDER BY f.id ASC
            FOR UPDATE
        ");
        $lock->execute([(int)$fieldId]);

        $avail = check_field_availability((int)$fieldId, $date, $start, $end);
        if (!$avail['available']) {
            $pdo->rollBack();
            $c0 = $avail['conflicts'][0];
            $msg = ($c0['relation_type'] === 'SELF')
                ? 'Khung giờ này đã có khách đặt.'
                : "Khung giờ bị khóa ghép/tách sân do {$c0['field_name']} đã có lịch.";
            return ['ok' => false, 'message' => $msg];
        }

        $pricing = calculate_dynamic_price((int)$fieldId, $date, $start, $end);
        $total   = (int)$pricing['total_price'];
        $custId  = find_or_create_walkin_customer(trim($name), $phoneNorm);

        $pdo->prepare("
            INSERT INTO bookings (user_id, field_id, booking_date, start_time, end_time, total_price, note,
                                  booking_source, walkin_name, walkin_phone, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'WALK_IN', ?, ?, 'confirmed')
        ")->execute([
            $custId, (int)$fieldId, $date, $start . ':00', $end . ':00', $total,
            $note !== '' ? $note : 'Đặt tại quầy bởi Chủ sân',
            trim($name), $phoneNorm
        ]);
        $bid = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO payments (booking_id, method, amount, status, transaction_code, paid_at)
            VALUES (?, 'cash', ?, ?, ?, ?)
        ")->execute([
            $bid, $total,
            $paid ? 'paid' : 'pending',
            $paid ? ('POS' . date('ymdHis') . $bid) : null,
            $paid ? date('Y-m-d H:i:s') : null
        ]);

        $pdo->commit();
        return ['ok' => true, 'booking_id' => $bid, 'message' => "Đã tạo lịch tại quầy #{$bid} ({$field['name']} • $start-$end) — Tổng " . money($total)];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Lỗi tạo lịch tại quầy: ' . $e->getMessage()];
    }
}

// ----------------------------------------------------------------------------
// PHẦN 5: ĐĂNG XUẤT AN TOÀN (XÓA SESSION + COOKIE)
// ----------------------------------------------------------------------------

/**
 * Hàm perform_logout(): Xóa toàn bộ dữ liệu Session, hủy Cookie phiên và Cookie "ghi nhớ" (nếu có),
 * sau đó mở một Session mới sạch để mang thông báo Flash "Đăng xuất thành công".
 */
function perform_logout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    foreach (['remember_token', 'remember_me', 'auth_token'] as $ck) {
        if (isset($_COOKIE[$ck])) {
            setcookie($ck, '', time() - 42000, '/');
        }
    }
    session_destroy();

    // Session mới chỉ chứa thông báo Flash
    session_start();
    session_regenerate_id(true);
    $_SESSION['flash'] = ['ok', '👋 Bạn đã đăng xuất khỏi tài khoản thành công. Hẹn gặp lại trên sân cỏ!'];
}

// ----------------------------------------------------------------------------
// PHẦN 6: HỆ THỐNG CHAT TRỰC TIẾP VỚI CHỦ SÂN & TRUNG TÂM TIN NHẮN ĐỐI TÁC
// ----------------------------------------------------------------------------

/**
 * Hàm ensure_owner_chat_schema():
 * Tự động khởi tạo bảng `owner_chat_messages` trong CSDL `quan_ly_san_bong`
 * và nạp dữ liệu hội thoại mẫu ban đầu kèm Thẻ báo giá sân (Pitch Card).
 */
function ensure_owner_chat_schema() {
    global $pdo;
    static $chatSchemaDone = false;
    if ($chatSchemaDone) {
        return;
    }
    $chatSchemaDone = true;

    try {
        $ver = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='owner_chat_schema_v2'")->fetchColumn();
        if ($ver === '1') {
            return;
        }
    } catch (Exception $e) {
        return;
    }

    // 6.1. Tạo bảng owner_chat_messages theo chuẩn Schema
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS owner_chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL COMMENT 'ID khách đặt sân - liên kết users.id',
            owner_id INT UNSIGNED NOT NULL COMMENT 'ID chủ sân sở hữu sân đó - liên kết users.id',
            field_id INT UNSIGNED NOT NULL COMMENT 'ID cụm sân/sân bóng khách chọn liên hệ - liên kết fields.id',
            sender_id INT UNSIGNED NOT NULL COMMENT 'ID người gửi tin nhắn',
            sender_role ENUM('CUSTOMER', 'OWNER') NOT NULL DEFAULT 'CUSTOMER',
            message TEXT NOT NULL COMMENT 'Nội dung tin nhắn',
            pitch_card_json TEXT NULL COMMENT 'Chuỗi JSON chứa thông tin thẻ đặt sân nhanh: field_id, name, pitch_code, time_slot, price, image, book_url',
            is_read TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0: Chưa xem, 1: Đã xem',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ocm_cust_field (customer_id, field_id, created_at),
            INDEX idx_ocm_owner_unread (owner_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        return;
    }

    // 6.2. Khởi tạo dữ liệu hội thoại mẫu nếu bảng đang trống
    try {
        $msgCount = (int)$pdo->query("SELECT COUNT(*) FROM owner_chat_messages")->fetchColumn();
        if ($msgCount === 0) {
            // Tìm hoặc tạo các tài khoản Khách đặt sân mẫu để Trung tâm Tin nhắn Chủ sân hiển thị sinh động
            $findOrCreateDemoCustomer = function ($username, $fullName, $phone, $email) use ($pdo) {
                $st = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $st->execute([$username]);
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
                $pdo->prepare("
                    INSERT INTO users (username, password, full_name, phone, email, role, status, reputation_score, wallet_balance)
                    VALUES (?, ?, ?, ?, ?, 'user', 'active', 100, 250000)
                ")->execute([
                    $username,
                    password_hash('password', PASSWORD_DEFAULT),
                    $fullName,
                    $phone,
                    $email
                ]);
                return (int)$pdo->lastInsertId();
            };

            $stMainCust = $pdo->prepare("SELECT id FROM users WHERE username = 'nguyenvana' LIMIT 1");
            $stMainCust->execute();
            $custNguyenVanA = (int)$stMainCust->fetchColumn();
            if ($custNguyenVanA <= 0) {
                $custNguyenVanA = $findOrCreateDemoCustomer('nguyenvana', 'Nguyễn Văn A', '0909123456', 'nguyenvana@gmail.com');
            }
            $custNguyenHai = $findOrCreateDemoCustomer('nguyenhai_fc', 'Nguyễn Hải', '0918234567', 'nguyenhai@gmail.com');
            $custTranLam   = $findOrCreateDemoCustomer('tranlam_sport', 'Trần Lâm', '0987654321', 'tranlam@gmail.com');

            // Lấy thông tin 10 cụm sân chính
            $fieldsRows = $pdo->query("SELECT id, owner_id, name, pitch_code, type, district, price_per_hour, peak_price, image FROM fields WHERE id BETWEEN 1 AND 10 ORDER BY id ASC")->fetchAll();
            $fieldsById = [];
            foreach ($fieldsRows as $fr) {
                $fieldsById[(int)$fr['id']] = $fr;
            }

            // Fallback owner_id nếu sân chưa có owner_id
            $defaultAdminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;

            $insMsg = $pdo->prepare("
                INSERT INTO owner_chat_messages
                    (customer_id, owner_id, field_id, sender_id, sender_role, message, pitch_card_json, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // Luồng 1: Khách Nguyễn Văn A nhắn với Chủ sân Chảo Lửa (Field #1)
            if (isset($fieldsById[1])) {
                $f1 = $fieldsById[1];
                $o1 = (int)($f1['owner_id'] ?: $defaultAdminId);
                $card1 = json_encode([
                    'field_id'   => 1,
                    'name'       => $f1['name'],
                    'pitch_code' => 'Sân 5A',
                    'time_slot'  => '18:00 - 19:30',
                    'price'      => '300.000đ',
                    'image'      => $f1['image'] ?: 'assets/images/field-1.jpg',
                    'book_url'   => 'user/booking.php?field_id=1&start=18:00&end=19:30'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $insMsg->execute([
                    $custNguyenVanA, $o1, 1, $custNguyenVanA, 'CUSTOMER',
                    'Chào BQL Sân Banh Chảo Lửa! Tối nay khung giờ vàng 18:00 - 19:30 bên mình còn trống sân 5A không ạ?',
                    null, 1, date('Y-m-d H:i:s', time() - 1800)
                ]);
                $insMsg->execute([
                    $custNguyenVanA, $o1, 1, $o1, 'OWNER',
                    'Dạ chào bạn! Tối nay Sân 5A (18:00 - 19:30) bên mình đang còn trống đúng 1 sân mặt cỏ FIFA mới thay. Mình gửi Thẻ đặt sân nhanh bên dưới, bạn bấm Đặt Ngay để hệ thống giữ chỗ 10 phút nhé! ⚽',
                    $card1, 1, date('Y-m-d H:i:s', time() - 1500)
                ]);
                $insMsg->execute([
                    $custNguyenVanA, $o1, 1, $custNguyenVanA, 'CUSTOMER',
                    'Tuyệt quá chủ sân ơi! Giữ sân giúp mình 10 phút nhé, đội mình đang vào đặt cọc QR ngay đây ạ.',
                    null, 0, date('Y-m-d H:i:s', time() - 900)
                ]);

                // Luồng 1B: Khách Nguyễn Hải nhắn hỏi Sân 5A - Chảo Lửa (Chưa đọc: 2)
                $insMsg->execute([
                    $custNguyenHai, $o1, 1, $custNguyenHai, 'CUSTOMER',
                    'Chủ sân ơi cho mình hỏi thứ 7 tuần này ca 19:00 - 20:30 còn trống 2 sân 5 liền nhau không?',
                    null, 0, date('Y-m-d H:i:s', time() - 720)
                ]);
                $insMsg->execute([
                    $custNguyenHai, $o1, 1, $custNguyenHai, 'CUSTOMER',
                    'Đội mình muốn đặt giao hữu nội bộ công ty, gửi báo giá giúp mình nhé!',
                    null, 0, date('Y-m-d H:i:s', time() - 600)
                ]);

                // Luồng 1C: Khách Trần Lâm nhắn hỏi Sân 7A - Chảo Lửa (Chưa đọc: 1)
                $insMsg->execute([
                    $custTranLam, $o1, 1, $custTranLam, 'CUSTOMER',
                    'BQL Chảo Lửa cho mình xin ảnh mặt cỏ thực tế sân 7A và giá thuê cố định tháng nhé!',
                    null, 0, date('Y-m-d H:i:s', time() - 420)
                ]);
            }

            // Luồng 2..10: Khởi tạo lời chào + Thẻ báo giá mẫu từ Chủ sân cho các cụm sân còn lại để khách chuyển tab là thấy ngay
            $sampleSlots = [
                2  => ['code' => 'Sân 7A (Mái che)', 'slot' => '19:00 - 20:30', 'price' => '300.000đ', 'greet' => 'Chào đội bóng! Hệ thống Sân Bóng Đá K34 có mái che thoáng mát đang mở lịch tối nay. Bạn cần đặt sân 5 hay sân 7 ạ?'],
                3  => ['code' => 'Sân 11A Chuẩn',    'slot' => '17:30 - 19:00', 'price' => '310.000đ', 'greet' => 'BQL Sân vận động Gia Định (Bình Thạnh) xin chào! Mình gửi bạn thẻ báo giá ca chiều tối nay đang còn trống nhé.'],
                4  => ['code' => 'Sân 5B Phú Thọ',   'slot' => '18:30 - 20:00', 'price' => '280.000đ', 'greet' => 'BQL Sân bóng đá mini Nhà Tập Luyện Phú Thọ (Quận 11) chào bạn! Sân đang có ưu đãi giảm 10% khi đặt online.'],
                5  => ['code' => 'Sân 7A Tao Đàn',   'slot' => '18:00 - 19:30', 'price' => '360.000đ', 'greet' => 'BQL Sân banh Tao Đàn (Trung tâm Quận 1) xin chào! Mặt sân cỏ nhân tạo dưới tán cây xanh mát đang sẵn sàng phục vụ đội bạn.'],
                6  => ['code' => 'Sân 11A FIFA 2*',  'slot' => '19:30 - 21:00', 'price' => '350.000đ', 'greet' => 'BQL SVĐ Trường ĐH Tôn Đức Thắng (Quận 7 - Chuẩn FIFA 2 sao) chào bạn! Mình gửi thông tin ca trống tối nay bên dưới nhé.'],
                7  => ['code' => 'Sân 7A Phú Lợi',   'slot' => '18:00 - 19:30', 'price' => '270.000đ', 'greet' => 'BQL Sân bóng Phú Lợi - Phạm Văn Đồng (Thủ Đức) xin chào! Mặt cỏ mới thay êm ái, bãi đỗ ô tô miễn phí.'],
                8  => ['code' => 'Sân 5A Ven Sông',  'slot' => '17:30 - 19:00', 'price' => '240.000đ', 'greet' => 'BQL Sân bóng Bình Quới - Thanh Đa xin chào! Cụm sân ven sông thoáng mát đang còn trống ca chiều tối nay.'],
                9  => ['code' => 'Sân 7B Vạn Phúc',  'slot' => '19:00 - 20:30', 'price' => '290.000đ', 'greet' => 'BQL Sân bóng Vạn Phúc City (Thủ Đức) chào bạn! Hệ thống đèn LED chống lóa & phòng tắm nước nóng sẵn sàng.'],
                10 => ['code' => 'Sân 5A Đạt Đức',   'slot' => '18:00 - 19:30', 'price' => '250.000đ', 'greet' => 'BQL Sân bóng Đạt Đức (Gò Vấp) xin chào! Bạn cần giữ sân ca mấy giờ tối nay để mình kiểm tra giúp nhé?'],
            ];

            foreach ($sampleSlots as $fid => $sInfo) {
                if (!isset($fieldsById[$fid])) {
                    continue;
                }
                $fRow  = $fieldsById[$fid];
                $ownId = (int)($fRow['owner_id'] ?: $defaultAdminId);
                $cardJson = json_encode([
                    'field_id'   => $fid,
                    'name'       => $fRow['name'],
                    'pitch_code' => $sInfo['code'],
                    'time_slot'  => $sInfo['slot'],
                    'price'      => $sInfo['price'],
                    'image'      => $fRow['image'] ?: 'assets/images/field-2.jpg',
                    'book_url'   => "user/booking.php?field_id={$fid}&start=" . substr($sInfo['slot'], 0, 5) . "&end=" . substr($sInfo['slot'], -5)
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $insMsg->execute([
                    $custNguyenVanA, $ownId, $fid, $ownId, 'OWNER',
                    $sInfo['greet'],
                    $cardJson, 1, date('Y-m-d H:i:s', time() - 3600)
                ]);

                // Thêm 1 tin nhắn khách hỏi cho mỗi Chủ sân để bất kỳ tài khoản chusan_* nào đăng nhập cũng thấy có khách nhắn
                $insMsg->execute([
                    $custNguyenHai, $ownId, $fid, $custNguyenHai, 'CUSTOMER',
                    "Chào chủ sân {$fRow['name']}, tối mai khung 19:00 - 20:30 còn sân trống không? Báo giá giúp đội mình nhé!",
                    null, 0, date('Y-m-d H:i:s', time() - 500)
                ]);
            }
        }

        $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('owner_chat_schema_v2', '1')")->execute();
    } catch (Exception $e) {}
}

ensure_owner_chat_schema();

/**
 * Hàm resolve_owner_chat_field():
 * Chuẩn hóa field_id về cụm sân chính (hoặc giữ nguyên nếu là sân gốc) và trả về thông tin sân + chủ sân.
 */
function resolve_owner_chat_field($fieldId) {
    global $pdo;
    $fieldId = (int)$fieldId;
    if ($fieldId <= 0) {
        $fieldId = 1;
    }

    $stmt = $pdo->prepare("
        SELECT f.*, v.owner_id AS venue_owner_id, v.name AS venue_name,
               u.full_name AS owner_full_name, u.username AS owner_username, u.phone AS owner_phone, u.avatar AS owner_avatar
        FROM fields f
        LEFT JOIN venues v ON v.id = f.venue_id
        LEFT JOIN users u ON u.id = COALESCE(f.owner_id, v.owner_id)
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->execute([$fieldId]);
    $f = $stmt->fetch();

    if (!$f) {
        $stmt->execute([1]);
        $f = $stmt->fetch();
    }
    if (!$f) {
        return null;
    }

    // Nếu là sân con (parent_id IS NOT NULL) và không nằm trong 10 cụm sân chính (1..10), ưu tiên lấy sân gốc của cụm sân để đồng nhất cuộc hội thoại
    if (!empty($f['parent_id']) && (int)$f['id'] > 10) {
        $stmt->execute([(int)$f['parent_id']]);
        $parentField = $stmt->fetch();
        if ($parentField) {
            $parentField['requested_sub_pitch'] = $f;
            $f = $parentField;
        }
    }

    $ownerId = (int)($f['owner_id'] ?: ($f['venue_owner_id'] ?: 0));
    if ($ownerId <= 0) {
        $ownerId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
    }
    $f['resolved_owner_id'] = $ownerId;
    if (empty($f['owner_full_name'])) {
        $f['owner_full_name'] = 'BQL ' . $f['name'];
    }
    return $f;
}

/**
 * Hàm get_owner_chat_pitches_catalog():
 * Trả về danh sách các Cụm sân chính (10 cụm sân thực tế + các sân gốc active) để hiển thị trên Dropdown
 * và Dải Chip Chuyển Nhanh Sân trong Widget Chat Chủ Sân.
 */
function get_owner_chat_pitches_catalog($customerId = 0) {
    global $pdo;
    $customerId = (int)$customerId;

    $rows = $pdo->query("
        SELECT f.id, f.owner_id, f.venue_id, f.name, f.pitch_code, f.type, f.district, f.address,
               f.contact_phone, f.price_per_hour, f.normal_price, f.peak_price, f.image, f.sub_pitches,
               v.owner_id AS venue_owner_id,
               u.full_name AS owner_name, u.username AS owner_username
        FROM fields f
        LEFT JOIN venues v ON v.id = f.venue_id
        LEFT JOIN users u ON u.id = COALESCE(f.owner_id, v.owner_id)
        WHERE f.status = 'active' AND (f.parent_id IS NULL OR f.id BETWEEN 1 AND 10)
        ORDER BY (f.id BETWEEN 1 AND 10) DESC, f.id ASC
    ")->fetchAll();

    $unreadMap = [];
    if ($customerId > 0) {
        $stUnread = $pdo->prepare("
            SELECT field_id, COUNT(*) AS cnt
            FROM owner_chat_messages
            WHERE customer_id = ? AND sender_role = 'OWNER' AND is_read = 0
            GROUP BY field_id
        ");
        $stUnread->execute([$customerId]);
        foreach ($stUnread->fetchAll() as $ur) {
            $unreadMap[(int)$ur['field_id']] = (int)$ur['cnt'];
        }
    }

    $shortNames = [
        1  => 'Sân Chảo Lửa',
        2  => 'Sân K34',
        3  => 'SVĐ Gia Định',
        4  => 'Sân Phú Thọ',
        5  => 'Sân Tao Đàn',
        6  => 'SVĐ Tôn Đức Thắng',
        7  => 'Sân Phú Lợi',
        8  => 'Sân Bình Quới',
        9  => 'Sân Vạn Phúc',
        10 => 'Sân Đạt Đức',
    ];

    $catalog = [];
    foreach ($rows as $r) {
        $fid = (int)$r['id'];
        $normalPrice = (int)($r['normal_price'] ?? $r['price_per_hour']);
        $peakPrice   = (int)($r['peak_price'] ?? round($r['price_per_hour'] * 1.6));
        $catalog[] = [
            'id'             => $fid,
            'owner_id'       => (int)($r['owner_id'] ?: ($r['venue_owner_id'] ?: 1)),
            'owner_name'     => $r['owner_name'] ?: ('BQL ' . $r['name']),
            'owner_username' => $r['owner_username'] ?: 'chusan_pro',
            'name'           => $r['name'],
            'short_name'     => $shortNames[$fid] ?? $r['name'],
            'district'       => $r['district'],
            'address'        => $r['address'],
            'type'           => $r['type'],
            'pitch_code'     => $r['pitch_code'] ?: ('S' . $r['type'] . 'A'),
            'phone'          => $r['contact_phone'] ?: '0901.234.567',
            'normal_price'   => money($normalPrice),
            'peak_price'     => money($peakPrice),
            'raw_price'      => $normalPrice,
            'raw_peak_price' => $peakPrice,
            'image'          => img_src($r['image'] ?: 'assets/images/field-1.jpg'),
            'book_url'       => project_base() . 'user/booking.php?field_id=' . $fid,
            'detail_url'     => project_base() . 'user/field-detail.php?id=' . $fid,
            'unread_count'   => $unreadMap[$fid] ?? 0,
        ];
    }
    return $catalog;
}

/**
 * Đếm tổng số tin nhắn chưa đọc từ Khách hàng gửi tới Chủ sân.
 */
function get_owner_unread_chat_count($user) {
    global $pdo;
    if (!$user || empty($user['id'])) {
        return 0;
    }
    try {
        if (get_user_role_code($user) === 'ADMIN') {
            return (int)$pdo->query("SELECT COUNT(*) FROM owner_chat_messages WHERE sender_role = 'CUSTOMER' AND is_read = 0")->fetchColumn();
        }
        $fieldIds = get_owner_field_ids($user);
        if (!$fieldIds) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM owner_chat_messages WHERE owner_id = ? AND sender_role = 'CUSTOMER' AND is_read = 0");
            $st->execute([(int)$user['id']]);
            return (int)$st->fetchColumn();
        }
        $in = implode(',', array_fill(0, count($fieldIds), '?'));
        $params = array_merge([(int)$user['id']], $fieldIds);
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM owner_chat_messages
            WHERE (owner_id = ? OR field_id IN ($in))
              AND sender_role = 'CUSTOMER'
              AND is_read = 0
        ");
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Đếm tổng số tin nhắn chưa đọc từ Chủ sân gửi tới Khách hàng.
 */
function get_customer_unread_chat_count($customerId) {
    global $pdo;
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        return 0;
    }
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM owner_chat_messages
            WHERE customer_id = ? AND sender_role = 'OWNER' AND is_read = 0
        ");
        $st->execute([$customerId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ----------------------------------------------------------------------------
// PHẦN 7: HỆ THỐNG VOUCHER RIÊNG CHO TỪNG TÀI KHOẢN ĐỐI TÁC CHỦ SÂN
// ----------------------------------------------------------------------------

/**
 * Tự động nâng cấp bảng `vouchers` thêm `owner_id`, `venue_id`, `field_id`
 * và phân bổ / tạo sẵn mã giảm giá riêng cho từng Đối tác Chủ sân.
 */
function ensure_partner_vouchers_schema() {
    global $pdo;
    static $voucherSchemaDone = false;
    if ($voucherSchemaDone) {
        return;
    }
    $voucherSchemaDone = true;

    // Đảm bảo 4 cột mở rộng của bảng vouchers luôn tồn tại thực tế trong MySQL
    $hasOwnerCol = pe_column_exists('vouchers', 'owner_id');
    $hasVenueCol = pe_column_exists('vouchers', 'venue_id');
    $hasFieldCol = pe_column_exists('vouchers', 'field_id');
    $hasDescCol  = pe_column_exists('vouchers', 'description');

    if (!$hasOwnerCol) {
        try {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN owner_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Chủ sân phát hành (users.id)' AFTER id");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE vouchers ADD INDEX idx_vouchers_owner (owner_id)");
        } catch (Throwable $e) {}
    }

    if (!$hasVenueCol) {
        try {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN venue_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Cụm sân áp dụng (venues.id)' AFTER owner_id");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE vouchers ADD INDEX idx_vouchers_venue (venue_id)");
        } catch (Throwable $e) {}
    }

    if (!$hasFieldCol) {
        try {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN field_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Sân cụ thể áp dụng (fields.id)' AFTER venue_id");
        } catch (Throwable $e) {}
    }

    if (!$hasDescCol) {
        try {
            $pdo->exec("ALTER TABLE vouchers ADD COLUMN description VARCHAR(255) NULL DEFAULT NULL COMMENT 'Mô tả chương trình ưu đãi của Chủ sân' AFTER code");
        } catch (Throwable $e) {}
    }

    try {
        $ver = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='partner_voucher_schema_v3'")->fetchColumn();
        if ($ver === '1' && $hasOwnerCol && $hasVenueCol && $hasFieldCol && $hasDescCol) {
            return;
        }
    } catch (Throwable $e) {}

    // Gán các mã Voucher cũ chưa có owner_id về cho các Chủ sân và khởi tạo Voucher riêng cho từng Cụm sân Đối tác
    try {
        $venues = $pdo->query("SELECT id, owner_id, name, district FROM venues WHERE owner_id IS NOT NULL AND owner_id > 0 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$venues) {
            $venues = $pdo->query("SELECT id AS field_id, venue_id AS id, owner_id, name, district FROM fields WHERE owner_id IS NOT NULL AND owner_id > 0 AND parent_id IS NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($venues) {
            // 1. Gán các voucher cũ chưa có owner_id cho Đối tác đầu tiên
            $firstV = $venues[0];
            $pdo->prepare("UPDATE vouchers SET owner_id = ?, venue_id = ? WHERE owner_id IS NULL OR owner_id = 0")
                ->execute([(int)$firstV['owner_id'], (int)($firstV['id'] ?? 0) ?: null]);

            // 2. Tạo mã giảm giá đặc trưng cho từng Đối tác Chủ sân nếu Cụm sân đó chưa có mã riêng
            $stCheck = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE owner_id = ? AND (venue_id = ? OR venue_id IS NULL)");
            $stIns   = $pdo->prepare("
                INSERT IGNORE INTO vouchers
                    (owner_id, venue_id, field_id, code, description, discount_type, discount_value, min_order, max_discount, usage_limit, used_count, expired_at, status)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 150, ?, ?, 'active')
            ");

            $expDate = date('Y-m-d', strtotime('+180 days'));
            foreach ($venues as $idx => $vn) {
                $oid = (int)$vn['owner_id'];
                $vid = (int)($vn['id'] ?? 0);
                if ($oid <= 0) {
                    continue;
                }
                $stCheck->execute([$oid, $vid]);
                if ((int)$stCheck->fetchColumn() === 0) {
                    $codePrefix = 'SAN' . ($vid > 0 ? $vid : ($idx + 1));
                    $nameLower  = mb_strtolower((string)$vn['name'], 'UTF-8');
                    if (strpos($nameLower, 'k34') !== false) $codePrefix = 'K34';
                    elseif (strpos($nameLower, 'chảo lửa') !== false) $codePrefix = 'CHAOLUA';
                    elseif (strpos($nameLower, 'phú thọ') !== false) $codePrefix = 'PHUTHO';
                    elseif (strpos($nameLower, 'tao đàn') !== false) $codePrefix = 'TAODAN';
                    elseif (strpos($nameLower, 'gia định') !== false) $codePrefix = 'GIADINH';
                    elseif (strpos($nameLower, 'rạch miễu') !== false) $codePrefix = 'RACHMIEU';
                    elseif (strpos($nameLower, 'thành phát') !== false) $codePrefix = 'THANHPHAT';
                    elseif (strpos($nameLower, 'đầm sen') !== false) $codePrefix = 'DAMSEN';
                    elseif (strpos($nameLower, 'celadon') !== false) $codePrefix = 'CELADON';
                    elseif (strpos($nameLower, 'him lam') !== false) $codePrefix = 'HIMLAM';

                    $vShortName = trim(preg_replace('/\s*-\s*Sân.*$/u', '', (string)$vn['name']));
                    $stIns->execute([
                        $oid, $vid ?: null, $codePrefix . '15',
                        "Ưu đãi giảm 15% đặt sân tại {$vShortName}",
                        'percent', 15, 200000, 60000, rand(2, 18), $expDate
                    ]);
                    $stIns->execute([
                        $oid, $vid ?: null, $codePrefix . '30K',
                        "Giảm trực tiếp 30.000đ cho đội đặt lịch tại {$vShortName}",
                        'fixed', 30000, 180000, 30000, rand(3, 22), $expDate
                    ]);
                }
            }
        }

        $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('partner_voucher_schema_v3', '1')")->execute();
    } catch (Throwable $e) {}
}

/**
 * Lấy danh sách các mã giảm giá tiêu biểu từ các Chủ sân Đối tác để hiển thị trên Trang chủ.
 */
function get_partner_vouchers_showcase($limit = 4) {
    global $pdo;
    ensure_partner_vouchers_schema();
    $limit = max(1, min(12, (int)$limit));
    try {
        $rows = $pdo->query("
            SELECT v.*,
                   vn.name AS venue_name,
                   vn.district AS venue_district,
                   fd.id AS direct_field_id,
                   fd.name AS direct_field_name,
                   (SELECT f2.id FROM fields f2 WHERE (f2.venue_id = v.venue_id OR f2.owner_id = v.owner_id) AND f2.status = 'active' ORDER BY f2.id ASC LIMIT 1) AS resolved_field_id,
                   (SELECT f3.name FROM fields f3 WHERE (f3.venue_id = v.venue_id OR f3.owner_id = v.owner_id) AND f3.status = 'active' ORDER BY f3.id ASC LIMIT 1) AS resolved_field_name
            FROM vouchers v
            LEFT JOIN venues vn ON vn.id = v.venue_id
            LEFT JOIN fields fd ON fd.id = v.field_id
            WHERE v.status = 'active'
              AND v.expired_at >= CURDATE()
              AND v.used_count < v.usage_limit
            ORDER BY v.id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $targetFid = (int)($r['direct_field_id'] ?: ($r['resolved_field_id'] ?: 1));
            $r['target_field_id']   = $targetFid;
            $r['target_venue_name'] = $r['venue_name'] ?: ($r['direct_field_name'] ?: ($r['resolved_field_name'] ?: 'Cụm sân Đối tác'));
            $r['target_venue_name'] = trim(preg_replace('/\s*-\s*Sân\s+[0-9A-Za-z]+.*$/u', '', (string)$r['target_venue_name']));
        }
        unset($r);
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Lấy danh sách các mã giảm giá (Voucher) đang hoạt động của đúng Chủ sân / Cụm sân thuộc sân `$fieldId`.
 */
function get_active_vouchers_for_field($fieldId) {
    global $pdo;
    ensure_partner_vouchers_schema();
    $fieldId = (int)$fieldId;
    if ($fieldId <= 0) {
        return [];
    }

    try {
        $stF = $pdo->prepare("SELECT id, owner_id, venue_id, parent_id FROM fields WHERE id = ? LIMIT 1");
        $stF->execute([$fieldId]);
        $f = $stF->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return [];
        }

        $ownerId = (int)($f['owner_id'] ?? 0);
        $venueId = (int)($f['venue_id'] ?? 0);
        $rootId  = !empty($f['parent_id']) ? (int)$f['parent_id'] : (int)$f['id'];

        $st = $pdo->prepare("
            SELECT v.*, vn.name AS venue_name, fd.name AS field_name, fd.pitch_code
            FROM vouchers v
            LEFT JOIN venues vn ON vn.id = v.venue_id
            LEFT JOIN fields fd ON fd.id = v.field_id
            WHERE v.status = 'active'
              AND v.expired_at >= CURDATE()
              AND v.used_count < v.usage_limit
              AND (
                  (v.field_id IS NOT NULL AND v.field_id IN (?, ?))
                  OR (v.field_id IS NULL AND v.venue_id IS NOT NULL AND v.venue_id = ?)
                  OR (v.field_id IS NULL AND (v.venue_id IS NULL OR v.venue_id = 0) AND v.owner_id = ?)
              )
            ORDER BY v.discount_value DESC, v.id DESC
        ");
        $st->execute([$fieldId, $rootId, $venueId, $ownerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Kiểm tra một mã Voucher có thuộc quyền phát hành của Chủ sân / Cụm sân đang đặt hay không.
 */
function can_apply_voucher_to_field(array $voucher, array $field): array {
    $vOwnerId = (int)($voucher['owner_id'] ?? 0);
    $vVenueId = (int)($voucher['venue_id'] ?? 0);
    $vFieldId = (int)($voucher['field_id'] ?? 0);

    $fId      = (int)($field['id'] ?? 0);
    $fRootId  = !empty($field['parent_id']) ? (int)$field['parent_id'] : $fId;
    $fVenueId = (int)($field['venue_id'] ?? 0);
    $fOwnerId = (int)($field['owner_id'] ?? 0);

    if ($vFieldId > 0 && $vFieldId !== $fId && $vFieldId !== $fRootId) {
        return [
            'ok'      => false,
            'message' => 'Mã giảm giá này chỉ áp dụng riêng cho một sân bóng khác của Đối tác.'
        ];
    }
    if ($vVenueId > 0 && $fVenueId > 0 && $vVenueId !== $fVenueId) {
        return [
            'ok'      => false,
            'message' => 'Mã giảm giá này chỉ áp dụng cho Cụm sân đã phát hành mã, không áp dụng cho sân hiện tại.'
        ];
    }
    if ($vOwnerId > 0 && $fOwnerId > 0 && $vOwnerId !== $fOwnerId) {
        return [
            'ok'      => false,
            'message' => 'Mã giảm giá này thuộc về Chủ sân đối tác khác, không áp dụng cho sân bạn đang chọn.'
        ];
    }
    return ['ok' => true, 'message' => 'Hợp lệ'];
}

// Tự động chạy nâng cấp schema Voucher theo Đối tác
ensure_partner_vouchers_schema();


