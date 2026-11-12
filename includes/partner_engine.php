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
 * ============================================================================
 */

// ----------------------------------------------------------------------------
// PHẦN 1: MIGRATION CSDL CHO KÊNH ĐỐI TÁC (CHẠY 1 LẦN, IDEMPOTENT)
// ----------------------------------------------------------------------------

/**
 * Kiểm tra một cột đã tồn tại trong bảng hay chưa.
 */
function pe_column_exists($table, $column) {
    global $pdo;
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
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
    return $stmt->fetchAll();
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
    $pdo->prepare("
        UPDATE fields f
        INNER JOIN venues v ON v.id = f.venue_id
        SET f.owner_id = v.owner_id,
            f.address = v.address,
            f.district = v.district,
            f.latitude = v.latitude,
            f.longitude = v.longitude,
            f.contact_phone = IF(v.contact_phone = '', f.contact_phone, v.contact_phone)
        WHERE v.id = ?
    ")->execute([(int)$venueId]);

    // Cụm sân INACTIVE -> tạm ngưng toàn bộ sân con; ACTIVE -> giữ nguyên trạng thái bảo trì riêng của từng sân
    $stmt = $pdo->prepare("SELECT status FROM venues WHERE id = ?");
    $stmt->execute([(int)$venueId]);
    if ($stmt->fetchColumn() === 'INACTIVE') {
        $pdo->prepare("UPDATE fields SET status = 'maintenance' WHERE venue_id = ?")->execute([(int)$venueId]);
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
