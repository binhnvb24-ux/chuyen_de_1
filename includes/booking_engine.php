<?php
/**
 * ============================================================================
 * FILE: includes/booking_engine.php
 * MÔ TẢ: Engine xử lý 5 bài toán cốt lõi của Hệ thống Quản lý & Đặt Sân Bóng:
 *   1. Ghép sân - Tách sân phân cấp (Hierarchical Pitch Mapping: Sân 5 <-> Sân 7 <-> Sân 11).
 *   2. Kiểm tra trùng lịch & tìm sân trống theo đơn vị 30 phút (Overlap Detection).
 *   3. Giữ chỗ tạm thời 10 phút (HOLD) & Chống đặt trùng bằng Pessimistic Locking.
 *   4. Tính giá động theo phân đoạn thời gian & loại ngày (Dynamic Pricing Engine).
 *   5. Đặt lịch cố định hàng tuần & Báo nghỉ / Xả sân trước 24h (Recurring & Exception).
 * ============================================================================
 */

// ----------------------------------------------------------------------------
// PHẦN 1: TỰ ĐỘNG KHỞI TẠO CẤU TRÚC BẢNG & DỮ LIỆU MẪU CHO 5 BÀI TOÁN LÕI
// ----------------------------------------------------------------------------

/**
 * Hàm init_advanced_booking_engine():
 * Tự động nâng cấp CSDL hiện tại để hỗ trợ đầy đủ 5 bài toán nâng cao.
 */
function init_advanced_booking_engine() {
    global $pdo;
    static $initialized = false;

    if ($initialized) {
        return;
    }
    $initialized = true;

    try {
        // 1.1. Bổ sung cột parent_id (Sân cha) và pitch_code (Mã sân: 5A, 7A, 11A) vào bảng fields
        $colParent = $pdo->query("SHOW COLUMNS FROM fields LIKE 'parent_id'")->fetch();
        if (!$colParent) {
            $pdo->exec("ALTER TABLE fields ADD COLUMN parent_id INT UNSIGNED NULL AFTER id");
            $pdo->exec("ALTER TABLE fields ADD COLUMN pitch_code VARCHAR(20) NULL AFTER parent_id");
            $pdo->exec("ALTER TABLE fields ADD INDEX idx_fields_parent (parent_id)");
        }

        // 1.2. Tạo bảng ánh xạ xung đột ghép sân - tách sân (field_conflict_map)
        $pdo->exec("CREATE TABLE IF NOT EXISTS field_conflict_map (
            field_id INT UNSIGNED NOT NULL COMMENT 'Sân đang xét',
            conflict_field_id INT UNSIGNED NOT NULL COMMENT 'Sân bị khóa chéo (Cha, Con, Chính nó)',
            relation_type ENUM('SELF', 'ANCESTOR', 'DESCENDANT') NOT NULL DEFAULT 'SELF',
            PRIMARY KEY (field_id, conflict_field_id),
            INDEX idx_fcm_conflict (conflict_field_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 1.3. Tạo bảng cấu hình giá linh hoạt theo khung giờ & loại ngày (pricing_rules)
        $pdo->exec("CREATE TABLE IF NOT EXISTS pricing_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            field_id INT UNSIGNED NOT NULL,
            day_type ENUM('WEEKDAY', 'WEEKEND', 'HOLIDAY') NOT NULL DEFAULT 'WEEKDAY',
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            price_per_hour INT UNSIGNED NOT NULL,
            label VARCHAR(80) NOT NULL DEFAULT 'Giờ tiêu chuẩn',
            INDEX idx_pr_field_day (field_id, day_type, start_time, end_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 1.4. Tạo bảng ngày Lễ đặc biệt (special_holidays)
        $pdo->exec("CREATE TABLE IF NOT EXISTS special_holidays (
            holiday_date DATE PRIMARY KEY,
            description VARCHAR(150) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Thêm các ngày lễ phổ biến trong năm nếu bảng đang trống
        $holCount = (int)$pdo->query("SELECT COUNT(*) FROM special_holidays")->fetchColumn();
        if ($holCount === 0) {
            $yr = date('Y');
            $pdo->exec("INSERT IGNORE INTO special_holidays (holiday_date, description) VALUES
                ('$yr-01-01', 'Tết Dương Lịch'),
                ('$yr-04-30', 'Ngày Giải phóng miền Nam 30/4'),
                ('$yr-05-01', 'Ngày Quốc tế Lao động 1/5'),
                ('$yr-09-02', 'Ngày Quốc khánh 2/9')");
        }

        // 1.5. Tạo bảng Hợp đồng đặt sân cố định hàng tuần (recurring_contracts)
        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_contracts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            field_id INT UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1: Thứ 2 -> 7: Chủ Nhật',
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            weeks_count TINYINT UNSIGNED NOT NULL DEFAULT 4,
            total_contract_price INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 1.6. Tạo bảng lưu lịch sử Báo nghỉ / Xả sân cố định trước 24h (recurring_exceptions)
        $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_exceptions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_id INT UNSIGNED NOT NULL,
            booking_id INT UNSIGNED NOT NULL,
            exception_date DATE NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT 'Đội cố định báo nghỉ trước 24h',
            released_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_contract_ex_date (contract_id, exception_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 1.7. Bổ sung cột hold_expires_at (Timeout 10 phút), recurring_contract_id, cancel_reason vào bảng bookings
        $colHold = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'hold_expires_at'")->fetch();
        if (!$colHold) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN recurring_contract_id INT UNSIGNED NULL AFTER field_id");
            $pdo->exec("ALTER TABLE bookings ADD COLUMN hold_expires_at DATETIME NULL AFTER status");
            $pdo->exec("ALTER TABLE bookings ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER hold_expires_at");
            $pdo->exec("ALTER TABLE bookings ADD INDEX idx_bk_hold_exp (status, hold_expires_at)");
            $pdo->exec("ALTER TABLE bookings ADD INDEX idx_bk_avail (booking_date, field_id, status, start_time, end_time)");
        }

        // 1.8. Tự động thiết lập liên kết Ghép sân mẫu (Sân 5 -> Sân 7 -> Sân 11) nếu chưa có parent_id nào
        $hasParent = (int)$pdo->query("SELECT COUNT(*) FROM fields WHERE parent_id IS NOT NULL")->fetchColumn();
        if ($hasParent === 0) {
            seed_default_hierarchy_and_conflict_map();
        }

        // 1.9. Tự động khởi tạo bảng giá động theo khung giờ (pricing_rules) nếu đang trống
        $prCount = (int)$pdo->query("SELECT COUNT(*) FROM pricing_rules")->fetchColumn();
        if ($prCount === 0) {
            seed_default_pricing_rules();
        }

        // 1.10. Tự động giải phóng các đơn giữ chỗ (HOLD / pending) đã quá 10 phút chưa thanh toán
        auto_release_expired_holds();

    } catch (Exception $e) {
        // Đảm bảo tương thích an toàn nếu CSDL đang bận
    }
}

// ----------------------------------------------------------------------------
// PHẦN 2: QUẢN LÝ CÂY PHÂN CẤP GHÉP SÂN - TÁCH SÂN (5 <-> 7 <-> 11)
// ----------------------------------------------------------------------------

/**
 * Hàm seed_default_hierarchy_and_conflict_map():
 * Tự động gắn quan hệ Cha - Con mẫu cho các sân 5, 7, 11 hiện có trong dự án
 * và xây dựng bảng ánh xạ xung đột field_conflict_map.
 */
function seed_default_hierarchy_and_conflict_map() {
    global $pdo;

    $allFields = $pdo->query("SELECT id, name, type FROM fields ORDER BY id ASC")->fetchAll();
    $f11 = [];
    $f7  = [];
    $f5  = [];

    foreach ($allFields as $f) {
        if ($f['type'] === '11') {
            $f11[] = $f['id'];
        } elseif ($f['type'] === '7') {
            $f7[] = $f['id'];
        } else {
            $f5[] = $f['id'];
        }
    }

    // Gán mã sân (pitch_code) và liên kết:
    // - Các sân 7 người ghép lại thành Sân 11 người đầu tiên
    // - Các sân 5 người ghép lại thành Sân 7 người đầu tiên
    $root11 = $f11[0] ?? null;
    if ($root11) {
        $pdo->prepare("UPDATE fields SET pitch_code = '11A' WHERE id = ?")->execute([$root11]);
    }

    $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($f7 as $idx => $id7) {
        $code7 = '7' . ($letters[$idx] ?? ($idx + 1));
        $pdo->prepare("UPDATE fields SET parent_id = ?, pitch_code = ? WHERE id = ?")
            ->execute([$root11, $code7, $id7]);
    }

    $parent7 = $f7[0] ?? $root11;
    foreach ($f5 as $idx => $id5) {
        $code5 = '5' . ($letters[$idx] ?? ($idx + 1));
        // 3 sân 5 đầu tiên ghép vào sân 7A, các sân 5 tiếp theo ghép vào sân 7B (nếu có)
        $targetParent = ($idx < 3) ? ($f7[0] ?? $root11) : ($f7[1] ?? $parent7);
        $pdo->prepare("UPDATE fields SET parent_id = ?, pitch_code = ? WHERE id = ?")
            ->execute([$targetParent, $code5, $id5]);
    }

    rebuild_field_conflict_map();
}

/**
 * Hàm rebuild_field_conflict_map():
 * Tính toán lại toàn bộ tập xung đột Cha - Con (SELF, ANCESTOR, DESCENDANT)
 * từ cột parent_id của bảng fields và lưu vào bảng field_conflict_map.
 */
function rebuild_field_conflict_map() {
    global $pdo;

    $rows = $pdo->query("SELECT id, parent_id FROM fields")->fetchAll();
    $parentMap   = [];
    $childrenMap = [];

    foreach ($rows as $r) {
        $id  = (int)$r['id'];
        $pid = $r['parent_id'] ? (int)$r['parent_id'] : null;
        $parentMap[$id] = $pid;
        if ($pid) {
            $childrenMap[$pid][] = $id;
        }
    }

    $pdo->exec("DELETE FROM field_conflict_map");
    $ins = $pdo->prepare("
        INSERT IGNORE INTO field_conflict_map (field_id, conflict_field_id, relation_type)
        VALUES (?, ?, ?)
    ");

    foreach ($rows as $r) {
        $id = (int)$r['id'];

        // 1. Chính nó (SELF)
        $ins->execute([$id, $id, 'SELF']);

        // 2. Duyệt ngược lên tất cả Sân Cha / Tổ tiên (ANCESTOR)
        $curr = $parentMap[$id] ?? null;
        $guard = 0;
        while ($curr && $guard < 10) {
            $ins->execute([$id, $curr, 'ANCESTOR']);
            $curr = $parentMap[$curr] ?? null;
            $guard++;
        }

        // 3. Duyệt xuôi xuống tất cả Sân Con / Cháu (DESCENDANT) bằng BFS
        $queue = $childrenMap[$id] ?? [];
        $visited = [];
        while (!empty($queue)) {
            $childId = array_shift($queue);
            if (isset($visited[$childId])) {
                continue;
            }
            $visited[$childId] = true;
            $ins->execute([$id, $childId, 'DESCENDANT']);
            if (!empty($childrenMap[$childId])) {
                foreach ($childrenMap[$childId] as $grandChild) {
                    $queue[] = $grandChild;
                }
            }
        }
    }
}

/**
 * Hàm get_field_hierarchy_info():
 * Trả về thông tin Sân Cha và các Sân Con của 1 sân bóng để hiển thị trực quan trên giao diện.
 */
function get_field_hierarchy_info($field_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT fcm.relation_type, f.id, f.name, f.type, f.pitch_code
        FROM field_conflict_map fcm
        INNER JOIN fields f ON f.id = fcm.conflict_field_id
        WHERE fcm.field_id = ? AND fcm.relation_type <> 'SELF'
        ORDER BY f.type DESC, f.id ASC
    ");
    $stmt->execute([(int)$field_id]);
    $related = $stmt->fetchAll();

    $ancestors   = [];
    $descendants = [];
    foreach ($related as $rel) {
        if ($rel['relation_type'] === 'ANCESTOR') {
            $ancestors[] = $rel;
        } elseif ($rel['relation_type'] === 'DESCENDANT') {
            $descendants[] = $rel;
        }
    }

    return [
        'ancestors'   => $ancestors,
        'descendants' => $descendants,
        'all_related' => $related,
    ];
}

// ----------------------------------------------------------------------------
// PHẦN 3: THUẬT TOÁN KIỂM TRA TRÙNG LỊCH & LẤY SƠ ĐỒ 30 PHÚT (OVERLAP DETECTION)
// ----------------------------------------------------------------------------

/**
 * Hàm check_field_availability():
 * Kiểm tra xem sân $field_id có trống trong khoảng [$start_time, $end_time] ngày $date không.
 * Tự động kiểm tra toàn bộ các sân Cha & Con trong bảng field_conflict_map.
 */
function check_field_availability($field_id, $date, $start_time, $end_time) {
    global $pdo;

    // Chuẩn hóa định dạng HH:MM:SS
    $st = strlen($start_time) === 5 ? $start_time . ':00' : $start_time;
    $en = strlen($end_time) === 5   ? $end_time . ':00'   : $end_time;

    $sql = "
        SELECT
            b.id AS booking_id,
            b.field_id,
            f.name AS field_name,
            f.type AS field_type,
            f.pitch_code,
            fcm.relation_type,
            b.start_time,
            b.end_time,
            b.status,
            b.hold_expires_at
        FROM field_conflict_map fcm
        INNER JOIN bookings b
            ON b.field_id = fcm.conflict_field_id
        INNER JOIN fields f
            ON f.id = b.field_id
        WHERE fcm.field_id = ?
          AND b.booking_date = ?
          AND (
              b.status IN ('confirmed', 'completed')
              OR (b.status = 'pending' AND (b.hold_expires_at IS NULL OR b.hold_expires_at > NOW()))
          )
          AND b.start_time < ?
          AND b.end_time   > ?
        ORDER BY b.start_time ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$field_id, $date, $en, $st]);
    $conflicts = $stmt->fetchAll();

    return [
        'available' => empty($conflicts),
        'conflicts' => $conflicts,
    ];
}

/**
 * Hàm get_field_busy_slots_30m():
 * Trả về mảng trạng thái của từng ô 30 phút (từ 06:00 đến 22:30) trong ngày $date
 * cho sân $field_id (bao gồm cả thông tin bị khóa chéo do Sân Cha hoặc Sân Con).
 */
function get_field_busy_slots_30m($field_id, $date) {
    global $pdo;

    $sql = "
        SELECT
            b.id AS booking_id,
            b.field_id,
            f.name AS field_name,
            f.type AS field_type,
            fcm.relation_type,
            b.start_time,
            b.end_time,
            b.status,
            b.hold_expires_at
        FROM field_conflict_map fcm
        INNER JOIN bookings b
            ON b.field_id = fcm.conflict_field_id
        INNER JOIN fields f
            ON f.id = b.field_id
        WHERE fcm.field_id = ?
          AND b.booking_date = ?
          AND (
              b.status IN ('confirmed', 'completed')
              OR (b.status = 'pending' AND (b.hold_expires_at IS NULL OR b.hold_expires_at > NOW()))
          )
        ORDER BY b.start_time ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$field_id, $date]);
    $busyBookings = $stmt->fetchAll();

    // Khởi tạo lưới 34 ô (Mỗi ô 30 phút từ 06:00 đến 23:00)
    $slots = [];
    $baseTs = strtotime("$date 06:00:00");
    $endTs  = strtotime("$date 23:00:00");
    $nowTs  = time();

    for ($ts = $baseTs; $ts < $endTs; $ts += 1800) {
        $nextTs  = $ts + 1800;
        $key     = date('H:i', $ts);
        $nextKey = date('H:i', $nextTs);
        $sTime   = date('H:i:s', $ts);
        $eTime   = date('H:i:s', $nextTs);

        $isPast = ($ts <= $nowTs);
        $conflictInfo = null;

        foreach ($busyBookings as $bk) {
            if ($bk['start_time'] < $eTime && $bk['end_time'] > $sTime) {
                $conflictInfo = $bk;
                break;
            }
        }

        $slots[$key] = [
            'start'         => $key,
            'end'           => $nextKey,
            'label'         => "$key - $nextKey",
            'is_past'       => $isPast,
            'is_busy'       => ($isPast || $conflictInfo !== null),
            'conflict_type' => $conflictInfo ? $conflictInfo['relation_type'] : ($isPast ? 'PAST' : 'FREE'),
            'conflict_name' => $conflictInfo ? $conflictInfo['field_name'] : '',
            'booking_status'=> $conflictInfo ? $conflictInfo['status'] : '',
        ];
    }

    return [
        'slots'         => $slots,
        'busy_bookings' => $busyBookings,
    ];
}

// ----------------------------------------------------------------------------
// PHẦN 4: THUẬT TOÁN TÍNH GIÁ ĐỘNG THEO PHÂN ĐOẠN THỜI GIAN (DYNAMIC PRICING)
// ----------------------------------------------------------------------------

/**
 * Hàm seed_default_pricing_rules():
 * Tạo cấu hình giá mẫu theo từng khung giờ (Ca sáng / Ca chiều / Giờ Vàng)
 * và loại ngày (Ngày thường WEEKDAY / Cuối tuần WEEKEND / Lễ HOLIDAY) cho các sân.
 */
function seed_default_pricing_rules() {
    global $pdo;

    $fields = $pdo->query("SELECT id, price_per_hour FROM fields")->fetchAll();
    $ins = $pdo->prepare("
        INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($fields as $f) {
        $fid  = (int)$f['id'];
        $base = (int)$f['price_per_hour'];

        // Làm tròn đến hàng nghìn
        $morningWeekday = (int)(round(($base * 0.85) / 1000) * 1000);
        $normalWeekday  = $base;
        $peakWeekday    = (int)(round(($base * 1.20) / 1000) * 1000);

        $dayWeekend     = (int)(round(($base * 1.10) / 1000) * 1000);
        $peakWeekend    = (int)(round(($base * 1.35) / 1000) * 1000);

        $holidayRate    = (int)(round(($base * 1.50) / 1000) * 1000);

        // 1. Ngày thường (Thứ 2 - Thứ 6)
        $ins->execute([$fid, 'WEEKDAY', '06:00:00', '16:00:00', $morningWeekday, 'Ca Sáng/Trưa (Ưu đãi -15%)']);
        $ins->execute([$fid, 'WEEKDAY', '16:00:00', '17:30:00', $normalWeekday,  'Ca Chiều Tiêu Chuẩn']);
        $ins->execute([$fid, 'WEEKDAY', '17:30:00', '23:00:00', $peakWeekday,    '🔥 Giờ Vàng Buổi Tối (+20%)']);

        // 2. Cuối tuần (Thứ 7 - Chủ Nhật)
        $ins->execute([$fid, 'WEEKEND', '06:00:00', '17:30:00', $dayWeekend,     'Cuối Tuần Ban Ngày (+10%)']);
        $ins->execute([$fid, 'WEEKEND', '17:30:00', '23:00:00', $peakWeekend,    '🔥 Giờ Vàng Cuối Tuần (+35%)']);

        // 3. Ngày Lễ (Holiday)
        $ins->execute([$fid, 'HOLIDAY', '06:00:00', '23:00:00', $holidayRate,    '🎉 Khung Giá Ngày Lễ (+50%)']);
    }
}

/**
 * Hàm calculate_dynamic_price():
 * Tính tiền thuê sân linh hoạt theo từng lát cắt 30 phút (Time-slice)
 * khi người dùng đặt vắt qua nhiều khung giờ (VD: 17:00 -> 18:30 vắt qua 17:30).
 */
function calculate_dynamic_price($field_id, $date, $start_time, $end_time) {
    global $pdo;

    $st = strlen($start_time) === 5 ? $start_time . ':00' : $start_time;
    $en = strlen($end_time) === 5   ? $end_time . ':00'   : $end_time;

    // 1. Kiểm tra loại ngày: Ngày Lễ (HOLIDAY) > Cuối tuần (WEEKEND) > Ngày thường (WEEKDAY)
    $stmtHol = $pdo->prepare("SELECT description FROM special_holidays WHERE holiday_date = ?");
    $stmtHol->execute([$date]);
    $holidayDesc = $stmtHol->fetchColumn();

    $dow = (int)date('N', strtotime($date)); // 1 (Thứ 2) -> 7 (Chủ Nhật)
    if ($holidayDesc) {
        $dayType      = 'HOLIDAY';
        $dayTypeLabel = '🎉 Ngày Lễ (' . $holidayDesc . ')';
    } elseif ($dow >= 6) {
        $dayType      = 'WEEKEND';
        $dayTypeLabel = '🌟 Cuối tuần (' . ($dow === 6 ? 'Thứ 7' : 'Chủ Nhật') . ')';
    } else {
        $dayType      = 'WEEKDAY';
        $dayTypeLabel = '📅 Ngày thường (Thứ ' . ($dow + 1) . ')';
    }

    // 2. Lấy giá cơ sở của sân và các quy tắc giá của loại ngày tương ứng
    $stmtF = $pdo->prepare("SELECT price_per_hour FROM fields WHERE id = ?");
    $stmtF->execute([(int)$field_id]);
    $baseRate = (int)$stmtF->fetchColumn();

    $stmtR = $pdo->prepare("
        SELECT start_time, end_time, price_per_hour, label
        FROM pricing_rules
        WHERE field_id = ? AND day_type = ?
        ORDER BY start_time ASC
    ");
    $stmtR->execute([(int)$field_id, $dayType]);
    $rules = $stmtR->fetchAll();

    // 3. Cắt lát từng bước 30 phút (1800 giây) và cộng dồn
    $slotStart = strtotime("$date $st");
    $slotEnd   = strtotime("$date $en");

    if (!$slotStart || !$slotEnd || $slotEnd <= $slotStart) {
        return [
            'day_type'       => $dayType,
            'day_type_label' => $dayTypeLabel,
            'duration_hours' => 0,
            'total_price'    => 0,
            'breakdown'      => [],
        ];
    }

    $totalPrice = 0;
    $segments   = [];

    for ($cur = $slotStart; $cur < $slotEnd; $cur += 1800) {
        $next      = $cur + 1800;
        $sliceFrom = date('H:i:s', $cur);
        $sliceTo   = date('H:i:s', $next);

        $rate  = $baseRate;
        $label = 'Giờ tiêu chuẩn';

        foreach ($rules as $r) {
            if ($sliceFrom >= $r['start_time'] && $sliceTo <= $r['end_time']) {
                $rate  = (int)$r['price_per_hour'];
                $label = $r['label'];
                break;
            }
        }

        $sliceCost   = (int)round($rate * 0.5);
        $totalPrice += $sliceCost;

        // Gom các lát cắt 30 phút liên tiếp có cùng đơn giá thành 1 dòng hiển thị gọn gàng
        $lastIdx = count($segments) - 1;
        if ($lastIdx >= 0 && $segments[$lastIdx]['rate'] === $rate && $segments[$lastIdx]['to'] === substr($sliceFrom, 0, 5)) {
            $segments[$lastIdx]['to']       = substr($sliceTo, 0, 5);
            $segments[$lastIdx]['hours']   += 0.5;
            $segments[$lastIdx]['subtotal']+= $sliceCost;
        } else {
            $segments[] = [
                'from'     => substr($sliceFrom, 0, 5),
                'to'       => substr($sliceTo, 0, 5),
                'label'    => $label,
                'rate'     => $rate,
                'hours'    => 0.5,
                'subtotal' => $sliceCost,
            ];
        }
    }

    $durationHours = round(($slotEnd - $slotStart) / 3600, 1);

    return [
        'day_type'       => $dayType,
        'day_type_label' => $dayTypeLabel,
        'duration_hours' => $durationHours,
        'total_price'    => $totalPrice,
        'breakdown'      => $segments,
    ];
}

// ----------------------------------------------------------------------------
// PHẦN 5: CƠ CHẾ TIMEOUT 10 PHÚT CHO ĐƠN GIỮ CHỖ TẠM THỜI (HOLD EXPIRY)
// ----------------------------------------------------------------------------

/**
 * Hàm auto_release_expired_holds():
 * Tự động giải phóng các đơn đặt sân ở trạng thái giữ chỗ chờ thanh toán (pending/HOLD)
 * đã vượt quá 10 phút mà chưa thanh toán thành công.
 */
function auto_release_expired_holds() {
    global $pdo;

    try {
        $stmt = $pdo->query("
            SELECT b.id, b.user_id, f.name AS fname
            FROM bookings b
            INNER JOIN fields f ON f.id = b.field_id
            WHERE b.status = 'pending'
              AND b.hold_expires_at IS NOT NULL
              AND b.hold_expires_at <= NOW()
        ");
        $expiredRows = $stmt->fetchAll();

        if (!empty($expiredRows)) {
            $upd = $pdo->prepare("
                UPDATE bookings
                SET status = 'cancelled',
                    cancel_reason = 'Tự động hủy do hết hạn giữ chỗ 10 phút'
                WHERE id = ? AND status = 'pending'
            ");

            foreach ($expiredRows as $er) {
                $upd->execute([$er['id']]);
                notify(
                    $er['user_id'],
                    "Đơn giữ chỗ #{$er['id']} ({$er['fname']}) đã tự động hủy do quá thời hạn giữ chỗ 10 phút chưa thanh toán."
                );
            }
        }
    } catch (Exception $e) {}
}

// ----------------------------------------------------------------------------
// PHẦN 6: ĐẶT LỊCH CỐ ĐỊNH HÀNG TUẦN & XẢ SÂN TRƯỚC 24H (RECURRING & EXCEPTION)
// ----------------------------------------------------------------------------

/**
 * Hàm create_recurring_booking_contract():
 * Tạo hợp đồng đặt sân cố định theo thứ hàng tuần (VD: 4, 8 hoặc 12 tuần).
 * Tự động kiểm tra khóa chéo Ghép sân - Tách sân trên toàn bộ các tuần trước khi lưu.
 */
function create_recurring_booking_contract(
    $user_id,
    $field_id,
    $start_date,
    $start_time,
    $end_time,
    $weeks_count,
    $payment_method = 'bank',
    $note = ''
) {
    global $pdo;

    $weeks = max(2, min(24, (int)$weeks_count));
    $st    = strlen($start_time) === 5 ? $start_time . ':00' : $start_time;
    $en    = strlen($end_time) === 5   ? $end_time . ':00'   : $end_time;

    // Tính danh sách các ngày cố định theo chu kỳ mỗi 7 ngày
    $dates = [];
    $baseTs = strtotime($start_date);
    $dow    = (int)date('N', $baseTs);

    for ($i = 0; $i < $weeks; $i++) {
        $dates[] = date('Y-m-d', strtotime("+$i week", $baseTs));
    }

    $pdo->beginTransaction();
    try {
        // 1. Khóa Pessimistic toàn bộ cụm sân liên quan theo thứ tự ID tăng dần (chống Deadlock)
        $stmtLock = $pdo->prepare("
            SELECT f.id
            FROM fields f
            INNER JOIN field_conflict_map fcm ON fcm.conflict_field_id = f.id
            WHERE fcm.field_id = ? AND f.status = 'active'
            ORDER BY f.id ASC
            FOR UPDATE
        ");
        $stmtLock->execute([(int)$field_id]);
        if (!$stmtLock->fetchAll()) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Sân bóng không tồn tại hoặc đang bảo trì.'];
        }

        // 2. Kiểm tra trùng lịch cho toàn bộ các buổi trong hợp đồng
        $conflictsFound = [];
        foreach ($dates as $d) {
            $chk = check_field_availability($field_id, $d, $st, $en);
            if (!$chk['available']) {
                $c0 = $chk['conflicts'][0];
                $conflictsFound[] = date('d/m/Y', strtotime($d)) . ' (Trùng ' . $c0['field_name'] . ')';
            }
        }

        if (!empty($conflictsFound)) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'message' => 'Không thể tạo lịch cố định do bị kẹt sân vào các ngày: ' . implode(', ', $conflictsFound),
            ];
        }

        // 3. Tạo bản ghi Hợp đồng cố định trong bảng recurring_contracts
        $endDate = end($dates);
        $stmtContract = $pdo->prepare("
            INSERT INTO recurring_contracts (
                user_id, field_id, day_of_week, start_time, end_time,
                start_date, end_date, weeks_count, total_contract_price, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'active')
        ");
        $stmtContract->execute([
            $user_id, $field_id, $dow, $st, $en, $start_date, $endDate, $weeks
        ]);
        $contractId = (int)$pdo->lastInsertId();

        // 4. Sinh trước (Materialize) các buổi đặt sân vào bảng bookings
        $holdExp = ($payment_method === 'cash') ? null : date('Y-m-d H:i:s', time() + 600);
        $stmtBk  = $pdo->prepare("
            INSERT INTO bookings (
                user_id, field_id, recurring_contract_id, booking_date,
                start_time, end_time, total_price, status, hold_expires_at, note
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtPay = $pdo->prepare("
            INSERT INTO payments (booking_id, method, amount, status)
            VALUES (?, ?, ?, 'pending')
        ");

        $totalContractPrice = 0;
        $firstBookingId     = 0;

        foreach ($dates as $idx => $d) {
            $pr   = calculate_dynamic_price($field_id, $d, $st, $en);
            // Ưu đãi giảm 5% cho hợp đồng đặt lịch cố định dài hạn
            $cost = (int)(round(($pr['total_price'] * 0.95) / 1000) * 1000);
            $totalContractPrice += $cost;

            $bkStatus = ($payment_method === 'cash') ? 'confirmed' : 'pending';
            $sessionNote = "Hợp đồng cố định #$contractId (Buổi " . ($idx + 1) . "/$weeks)" . ($note ? " - $note" : '');

            $stmtBk->execute([
                $user_id, $field_id, $contractId, $d,
                $st, $en, $cost, $bkStatus, $holdExp, $sessionNote
            ]);
            $bid = (int)$pdo->lastInsertId();
            if ($firstBookingId === 0) {
                $firstBookingId = $bid;
            }

            $stmtPay->execute([$bid, $payment_method, $cost]);
        }

        // Cập nhật tổng giá trị hợp đồng
        $pdo->prepare("UPDATE recurring_contracts SET total_contract_price = ? WHERE id = ?")
            ->execute([$totalContractPrice, $contractId]);

        notify(
            $user_id,
            "Đã đăng ký Hợp đồng lịch cố định #$contractId ($weeks tuần, từ " .
            date('d/m/Y', strtotime($start_date)) . " đến " . date('d/m/Y', strtotime($endDate)) . ")."
        );

        $pdo->commit();

        return [
            'ok'               => true,
            'contract_id'      => $contractId,
            'first_booking_id' => $firstBookingId,
            'weeks_count'      => $weeks,
            'total_price'      => $totalContractPrice,
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Lỗi tạo hợp đồng cố định: ' . $e->getMessage()];
    }
}

/**
 * Hàm release_recurring_slot():
 * Xử lý nghiệp vụ "Báo nghỉ / Xả sân" 1 buổi cụ thể trong chuỗi lịch cố định (Trước 24h).
 * Mở lại đúng slot của ngày hôm đó cho khách vãng lai đặt mà không làm hỏng các tuần tiếp theo.
 */
function release_recurring_slot($user_id, $booking_id, $reason = 'Đội cố định báo nghỉ / xả sân trước 24h') {
    global $pdo;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, f.name AS fname
            FROM bookings b
            INNER JOIN fields f ON f.id = b.field_id
            WHERE b.id = ?
              AND b.user_id = ?
              AND b.recurring_contract_id IS NOT NULL
              AND b.status IN ('pending', 'confirmed')
            FOR UPDATE
        ");
        $stmt->execute([(int)$booking_id, (int)$user_id]);
        $bk = $stmt->fetch();

        if (!$bk) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Không tìm thấy buổi đặt sân cố định hợp lệ để xả sân.'];
        }

        // Kiểm tra điều kiện: Phải báo nghỉ trước giờ bóng lăn tối thiểu 24 giờ (86400 giây)
        $matchTs = strtotime($bk['booking_date'] . ' ' . $bk['start_time']);
        if (($matchTs - time()) < 86400) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'message' => 'Quy định Hợp đồng cố định: Chỉ được Báo nghỉ / Xả sân trước giờ thi đấu tối thiểu 24 giờ.',
            ];
        }

        // 1. Lưu vào bảng recurring_exceptions
        $stmtEx = $pdo->prepare("
            INSERT IGNORE INTO recurring_exceptions (contract_id, booking_id, exception_date, reason)
            VALUES (?, ?, ?, ?)
        ");
        $stmtEx->execute([$bk['recurring_contract_id'], $bk['id'], $bk['booking_date'], $reason]);

        // 2. Chuyển trạng thái buổi đặt sân hôm đó sang 'cancelled' để mở khóa sân cho khách vãng lai
        $stmtUpd = $pdo->prepare("
            UPDATE bookings
            SET status = 'cancelled',
                cancel_reason = 'Xả sân cố định trước 24h (Mở bán lại cho khách vãng lai)'
            WHERE id = ?
        ");
        $stmtUpd->execute([$bk['id']]);

        // 3. Hoàn tiền / hủy thanh toán của buổi nghỉ đó
        $pdo->prepare("
            UPDATE payments
            SET status = IF(status = 'paid', 'refunded', status)
            WHERE booking_id = ?
        ")->execute([$bk['id']]);

        $dateFormatted = date('d/m/Y', strtotime($bk['booking_date']));
        notify(
            $user_id,
            "Đã báo nghỉ & xả sân buổi cố định ngày {$dateFormatted} ({$bk['fname']}). Các tuần tiếp theo trong hợp đồng vẫn giữ nguyên!"
        );

        $pdo->commit();
        return [
            'ok'      => true,
            'message' => "Đã báo nghỉ và xả sân ngày {$dateFormatted} thành công! Khung giờ này đã được mở lại cho khách vãng lai, các tuần tiếp theo của đội bạn vẫn giữ nguyên.",
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Lỗi xử lý xả sân: ' . $e->getMessage()];
    }
}

// Khởi chạy Engine ngay khi tệp được nạp
init_advanced_booking_engine();

