<?php
/**
 * ============================================================================
 * FILE: api/owner_chat.php
 * MÔ TẢ: API xử lý hệ thống "Chat Trực Tiếp Với Chủ Sân" & "Trung Tâm Tin Nhắn Đối Tác".
 * CHỨC NĂNG:
 *   1. list_pitches          : Lấy danh sách 10 cụm sân thực tế kèm trạng thái chưa đọc cho Widget.
 *   2. load_customer_thread  : Tải lịch sử tin nhắn giữa Khách và Chủ sân theo `field_id` đã chọn.
 *   3. send_customer_message : Khách gửi tin nhắn (hoặc đính kèm Thẻ hỏi sân) tới Chủ sân.
 *   4. owner_conversations   : Lấy danh sách cuộc hội thoại của khách cho Chủ sân (`owner_id`).
 *   5. owner_load_thread     : Chủ sân mở chi tiết cuộc trò chuyện với 1 khách hàng & đánh dấu đã xem.
 *   6. owner_send_message    : Chủ sân trả lời tin nhắn hoặc gửi Thẻ Đặt Sân / Báo Giá Nhanh (Pitch Card).
 * ============================================================================
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$u    = current_user();
$base = function_exists('project_base') ? project_base() : '/quan-ly-san-bong/';

// Đọc dữ liệu đầu vào từ JSON body hoặc $_POST / $_GET
$rawInput = file_get_contents('php://input');
$jsonBody = $rawInput ? json_decode($rawInput, true) : [];
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$action = trim((string)($jsonBody['action'] ?? $_POST['action'] ?? $_GET['action'] ?? 'list_pitches'));

/**
 * Định dạng đối tượng tin nhắn trả về cho Frontend.
 */
function format_owner_chat_row(array $row, string $base): array {
    $card = null;
    if (!empty($row['pitch_card_json'])) {
        $decoded = json_decode((string)$row['pitch_card_json'], true);
        if (is_array($decoded)) {
            $rawImg = $decoded['image'] ?? 'assets/images/field-1.jpg';
            $rawUrl = $decoded['book_url'] ?? ('user/booking.php?field_id=' . (int)($row['field_id'] ?? 1));
            if (strpos($rawImg, 'http') !== 0 && strpos($rawImg, '/') !== 0) {
                $rawImg = $base . ltrim($rawImg, '/');
            }
            if (strpos($rawUrl, 'http') !== 0 && strpos($rawUrl, '/') !== 0) {
                $rawUrl = $base . ltrim($rawUrl, '/');
            }
            $card = [
                'field_id'   => (int)($decoded['field_id'] ?? $row['field_id'] ?? 1),
                'name'       => (string)($decoded['name'] ?? 'Sân Bóng Pro'),
                'pitch_code' => (string)($decoded['pitch_code'] ?? 'Sân 5A'),
                'time_slot'  => (string)($decoded['time_slot'] ?? '18:00 - 19:30'),
                'price'      => (string)($decoded['price'] ?? '300.000đ'),
                'image'      => $rawImg,
                'book_url'   => $rawUrl,
            ];
        }
    }

    $ts = !empty($row['created_at']) ? strtotime($row['created_at']) : time();
    return [
        'id'          => (int)($row['id'] ?? 0),
        'customer_id' => (int)($row['customer_id'] ?? 0),
        'owner_id'    => (int)($row['owner_id'] ?? 0),
        'field_id'    => (int)($row['field_id'] ?? 0),
        'sender_id'   => (int)($row['sender_id'] ?? 0),
        'sender_role' => (string)($row['sender_role'] ?? 'CUSTOMER'),
        'message'     => (string)($row['message'] ?? ''),
        'pitch_card'  => $card,
        'is_read'     => (int)($row['is_read'] ?? 0),
        'time_label'  => date('H:i', $ts),
        'date_label'  => date('d/m/Y H:i', $ts),
    ];
}

// ============================================================================
// 1. LẤY DANH SÁCH CỤM SÂN CHO WIDGET CHAT CHỦ SÂN
// ============================================================================
if ($action === 'list_pitches') {
    $cid     = $u ? (int)$u['id'] : 0;
    $pitches = get_owner_chat_pitches_catalog($cid);
    echo json_encode([
        'ok'           => true,
        'pitches'      => $pitches,
        'total_unread' => $cid > 0 ? get_customer_unread_chat_count($cid) : 0,
        'is_logged_in' => (bool)$u,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 2. KHÁCH HÀNG TẢI LUỒNG HỘI THOẠI THEO TỪNG SÂN (`field_id`)
// ============================================================================
if ($action === 'load_customer_thread') {
    $reqFieldId = (int)($jsonBody['field_id'] ?? $_GET['field_id'] ?? $_POST['field_id'] ?? 1);
    $fieldInfo  = resolve_owner_chat_field($reqFieldId);
    if (!$fieldInfo) {
        echo json_encode(['ok' => false, 'message' => 'Không tìm thấy thông tin sân bóng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fid     = (int)$fieldInfo['id'];
    $ownerId = (int)$fieldInfo['resolved_owner_id'];
    $cid     = $u ? (int)$u['id'] : 0;

    $normalPrice = (int)($fieldInfo['normal_price'] ?? $fieldInfo['price_per_hour']);
    $peakPrice   = (int)($fieldInfo['peak_price'] ?? round($fieldInfo['price_per_hour'] * 1.6));
    $defaultCode = !empty($fieldInfo['pitch_code']) ? ('Sân ' . $fieldInfo['pitch_code']) : ('Sân ' . $fieldInfo['type'] . 'A');

    // Nếu khách đã đăng nhập: kiểm tra và tạo lời chào + Thẻ báo giá ban đầu nếu chưa có tin nhắn nào ở sân này
    if ($cid > 0) {
        $stCheck = $pdo->prepare("SELECT COUNT(*) FROM owner_chat_messages WHERE customer_id = ? AND field_id = ?");
        $stCheck->execute([$cid, $fid]);
        if ((int)$stCheck->fetchColumn() === 0) {
            $initCard = json_encode([
                'field_id'   => $fid,
                'name'       => $fieldInfo['name'],
                'pitch_code' => $defaultCode,
                'time_slot'  => '18:00 - 19:30',
                'price'      => money($peakPrice),
                'image'      => $fieldInfo['image'] ?: 'assets/images/field-1.jpg',
                'book_url'   => "user/booking.php?field_id={$fid}&start=18:00&end=19:30",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $welcomeMsg = "👋 Chào " . ($u['full_name'] ?: 'bạn') . "! BQL {$fieldInfo['name']} ({$fieldInfo['district']}) đang trực tuyến. Mình gửi nhanh Thẻ báo giá ca đẹp tối nay bên dưới, bạn cần hỏi giờ trống hay giữ sân cứ nhắn trực tiếp cho chủ sân nhé!";
            $pdo->prepare("
                INSERT INTO owner_chat_messages
                    (customer_id, owner_id, field_id, sender_id, sender_role, message, pitch_card_json, is_read)
                VALUES (?, ?, ?, ?, 'OWNER', ?, ?, 1)
            ")->execute([$cid, $ownerId, $fid, $ownerId, $welcomeMsg, $initCard]);
        }

        // Đánh dấu các tin nhắn do Chủ sân gửi trong luồng này là Đã xem
        $pdo->prepare("
            UPDATE owner_chat_messages
            SET is_read = 1
            WHERE customer_id = ? AND field_id = ? AND sender_role = 'OWNER' AND is_read = 0
        ")->execute([$cid, $fid]);

        $stMsgs = $pdo->prepare("
            SELECT *
            FROM owner_chat_messages
            WHERE customer_id = ? AND field_id = ?
            ORDER BY id ASC
            LIMIT 120
        ");
        $stMsgs->execute([$cid, $fid]);
        $rawMsgs = $stMsgs->fetchAll();
    } else {
        // Chế độ giới thiệu cho khách chưa đăng nhập
        $demoCard = json_encode([
            'field_id'   => $fid,
            'name'       => $fieldInfo['name'],
            'pitch_code' => $defaultCode,
            'time_slot'  => '18:00 - 19:30',
            'price'      => money($peakPrice),
            'image'      => $fieldInfo['image'] ?: 'assets/images/field-1.jpg',
            'book_url'   => "user/booking.php?field_id={$fid}&start=18:00&end=19:30",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $rawMsgs = [[
            'id'              => 1,
            'customer_id'     => 0,
            'owner_id'        => $ownerId,
            'field_id'        => $fid,
            'sender_id'       => $ownerId,
            'sender_role'     => 'OWNER',
            'message'         => "👋 Chào mừng bạn đến với {$fieldInfo['name']} ({$fieldInfo['district']})! Chủ sân đang trực tuyến. Bạn có thể bấm Đặt Ngay trên thẻ sân bên dưới hoặc đăng nhập để nhắn tin trực tiếp với BQL sân nhé!",
            'pitch_card_json' => $demoCard,
            'is_read'         => 1,
            'created_at'      => date('Y-m-d H:i:s'),
        ]];
    }

    $messages = array_map(function ($m) use ($base) {
        return format_owner_chat_row($m, $base);
    }, $rawMsgs);

    $pitches = get_owner_chat_pitches_catalog($cid);

    echo json_encode([
        'ok'           => true,
        'is_logged_in' => (bool)$u,
        'login_url'    => $base . 'login.php',
        'field'        => [
            'id'           => $fid,
            'name'         => $fieldInfo['name'],
            'district'     => $fieldInfo['district'],
            'address'      => $fieldInfo['address'],
            'type'         => $fieldInfo['type'],
            'pitch_code'   => $defaultCode,
            'phone'        => $fieldInfo['contact_phone'] ?: '0901.234.567',
            'owner_id'     => $ownerId,
            'owner_name'   => 'Chủ sân ' . $fieldInfo['name'],
            'bql_title'    => 'BQL ' . $fieldInfo['name'] . ' - Đang online',
            'normal_price' => money($normalPrice),
            'peak_price'   => money($peakPrice),
            'image'        => img_src($fieldInfo['image'] ?: 'assets/images/field-1.jpg'),
            'book_url'     => $base . 'user/booking.php?field_id=' . $fid,
            'detail_url'   => $base . 'user/field-detail.php?id=' . $fid,
        ],
        'messages'     => $messages,
        'pitches'      => $pitches,
        'total_unread' => $cid > 0 ? get_customer_unread_chat_count($cid) : 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 3. KHÁCH HÀNG GỬI TIN NHẮN TỚI CHỦ SÂN
// ============================================================================
if ($action === 'send_customer_message') {
    if (!$u) {
        echo json_encode([
            'ok'            => false,
            'require_login' => true,
            'login_url'     => $base . 'login.php',
            'message'       => '🔒 Vui lòng đăng nhập tài khoản để gửi tin nhắn trực tiếp cho Chủ sân!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reqFieldId = (int)($jsonBody['field_id'] ?? $_POST['field_id'] ?? 1);
    $msgText    = trim((string)($jsonBody['message'] ?? $_POST['message'] ?? ''));
    $attachCard = !empty($jsonBody['attach_pitch_card']) || !empty($_POST['attach_pitch_card']);
    $slotLabel  = trim((string)($jsonBody['time_slot'] ?? $_POST['time_slot'] ?? '18:00 - 19:30'));

    $fieldInfo = resolve_owner_chat_field($reqFieldId);
    if (!$fieldInfo) {
        echo json_encode(['ok' => false, 'message' => 'Không tìm thấy sân bóng cần liên hệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($msgText === '' && !$attachCard) {
        echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập nội dung tin nhắn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fid     = (int)$fieldInfo['id'];
    $ownerId = (int)$fieldInfo['resolved_owner_id'];
    $cid     = (int)$u['id'];

    $cardJson = null;
    if ($attachCard) {
        $peakPrice = (int)($fieldInfo['peak_price'] ?? round($fieldInfo['price_per_hour'] * 1.6));
        $subPitch  = $fieldInfo['requested_sub_pitch'] ?? $fieldInfo;
        $pCode     = !empty($subPitch['pitch_code']) ? ('Sân ' . $subPitch['pitch_code']) : ('Sân ' . $subPitch['type'] . 'A');
        $cardJson  = json_encode([
            'field_id'   => (int)$subPitch['id'],
            'name'       => $fieldInfo['name'],
            'pitch_code' => $pCode,
            'time_slot'  => $slotLabel,
            'price'      => money($peakPrice),
            'image'      => $fieldInfo['image'] ?: 'assets/images/field-1.jpg',
            'book_url'   => "user/booking.php?field_id={$subPitch['id']}&start=" . substr($slotLabel, 0, 5) . "&end=" . substr($slotLabel, -5),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($msgText === '') {
            $msgText = "Chủ sân ơi, mình muốn hỏi đặt {$pCode} khung giờ {$slotLabel} tại {$fieldInfo['name']} ạ!";
        }
    }

    $pdo->prepare("
        INSERT INTO owner_chat_messages
            (customer_id, owner_id, field_id, sender_id, sender_role, message, pitch_card_json, is_read)
        VALUES (?, ?, ?, ?, 'CUSTOMER', ?, ?, 0)
    ")->execute([$cid, $ownerId, $fid, $cid, $msgText, $cardJson]);

    $newId = (int)$pdo->lastInsertId();
    $stRow = $pdo->prepare("SELECT * FROM owner_chat_messages WHERE id = ?");
    $stRow->execute([$newId]);
    $insertedRow = $stRow->fetch();

    // Thông báo cho Chủ sân
    if ($ownerId > 0 && $ownerId !== $cid) {
        notify($ownerId, "💬 Khách hàng {$u['full_name']} vừa nhắn tin hỏi sân {$fieldInfo['name']}: \"" . mb_substr($msgText, 0, 70) . "\"");
    }

    echo json_encode([
        'ok'      => true,
        'message' => format_owner_chat_row($insertedRow, $base),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 4. CHỦ SÂN LẤY DANH SÁCH HỘI THOẠI KHÁCH HÀNG (`owner_conversations`)
// ============================================================================
if ($action === 'owner_conversations') {
    if (!$u || (!is_pitch_owner($u) && get_user_role_code($u) !== 'ADMIN')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền truy cập Trung tâm Tin nhắn Chủ sân.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isAdmin  = (get_user_role_code($u) === 'ADMIN');
    $ownerId  = (int)$u['id'];
    $searchQ  = trim((string)($jsonBody['q'] ?? $_GET['q'] ?? $_POST['q'] ?? ''));
    $venueId  = (int)($jsonBody['venue_id'] ?? $_GET['venue_id'] ?? $_POST['venue_id'] ?? 0);

    $ownerFieldIds = get_owner_field_ids($u);
    if (!$isAdmin && !$ownerFieldIds) {
        echo json_encode(['ok' => true, 'conversations' => [], 'total_unread' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $whereSql = "WHERE 1=1";
    $params   = [];

    if (!$isAdmin) {
        $in = implode(',', array_fill(0, count($ownerFieldIds), '?'));
        $whereSql .= " AND (m.owner_id = ? OR m.field_id IN ($in))";
        $params[] = $ownerId;
        foreach ($ownerFieldIds as $ofid) {
            $params[] = $ofid;
        }
    }

    // Lấy danh sách cặp (customer_id, field_id) có tin nhắn mới nhất
    $sql = "
        SELECT
            m.customer_id,
            m.field_id,
            MAX(m.id) AS last_msg_id,
            SUM(CASE WHEN m.sender_role = 'CUSTOMER' AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM owner_chat_messages m
        $whereSql
        GROUP BY m.customer_id, m.field_id
        ORDER BY unread_count DESC, last_msg_id DESC
        LIMIT 80
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $threads = $st->fetchAll();

    $conversations = [];
    $stLast = $pdo->prepare("SELECT * FROM owner_chat_messages WHERE id = ?");
    $stCust = $pdo->prepare("SELECT id, username, full_name, phone, avatar, reputation_score FROM users WHERE id = ?");
    $stFld  = $pdo->prepare("SELECT id, name, pitch_code, type, district, image, price_per_hour, peak_price FROM fields WHERE id = ?");

    foreach ($threads as $th) {
        $cid = (int)$th['customer_id'];
        $fid = (int)$th['field_id'];

        $stLast->execute([(int)$th['last_msg_id']]);
        $lastMsg = $stLast->fetch();

        $stCust->execute([$cid]);
        $cust = $stCust->fetch();

        $stFld->execute([$fid]);
        $fld = $stFld->fetch();

        if (!$cust || !$fld || !$lastMsg) {
            continue;
        }

        // Lọc theo từ khóa tìm kiếm nếu có
        if ($searchQ !== '') {
            $haystack = mb_strtolower($cust['full_name'] . ' ' . $cust['phone'] . ' ' . $fld['name'] . ' ' . $lastMsg['message']);
            if (mb_strpos($haystack, mb_strtolower($searchQ)) === false) {
                continue;
            }
        }

        $pCode = !empty($fld['pitch_code']) ? ('Sân ' . $fld['pitch_code']) : ('Sân ' . $fld['type'] . 'A');
        $shortFieldName = preg_replace('/^(Sân Banh|Sân Bóng Đá|Sân bóng đá mini|Sân vận động|Sân banh|Sân bóng)\s+/iu', '', $fld['name']);

        $ts = !empty($lastMsg['created_at']) ? strtotime($lastMsg['created_at']) : time();
        $conversations[] = [
            'customer_id'      => $cid,
            'customer_name'    => $cust['full_name'] ?: $cust['username'],
            'customer_phone'   => $cust['phone'] ?: '0909.123.456',
            'customer_avatar'  => !empty($cust['avatar']) ? img_src($cust['avatar']) : '',
            'customer_initial' => mb_strtoupper(mb_substr($cust['full_name'] ?: 'K', 0, 1)),
            'reputation_score' => (int)($cust['reputation_score'] ?? 100),
            'field_id'         => $fid,
            'field_name'       => $fld['name'],
            'field_district'   => $fld['district'],
            'pitch_badge'      => $pCode . ' - ' . $shortFieldName,
            'last_message'     => ($lastMsg['sender_role'] === 'OWNER' ? 'Bạn: ' : '') . $lastMsg['message'],
            'last_time'        => date('H:i', $ts),
            'unread_count'     => (int)$th['unread_count'],
        ];
    }

    echo json_encode([
        'ok'            => true,
        'conversations' => $conversations,
        'total_unread'  => get_owner_unread_chat_count($u),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 5. CHỦ SÂN TẢI CHI TIẾT HỘI THOẠI VỚI 1 KHÁCH HÀNG (`owner_load_thread`)
// ============================================================================
if ($action === 'owner_load_thread') {
    if (!$u || (!is_pitch_owner($u) && get_user_role_code($u) !== 'ADMIN')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Không có quyền truy cập.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cid = (int)($jsonBody['customer_id'] ?? $_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    $fid = (int)($jsonBody['field_id'] ?? $_GET['field_id'] ?? $_POST['field_id'] ?? 0);

    $fld = owner_get_field($u, $fid);
    if (!$fld) {
        echo json_encode(['ok' => false, 'message' => 'Sân bóng này không thuộc quyền quản lý của bạn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stCust = $pdo->prepare("SELECT id, username, full_name, phone, email, avatar, reputation_score FROM users WHERE id = ?");
    $stCust->execute([$cid]);
    $cust = $stCust->fetch();
    if (!$cust) {
        echo json_encode(['ok' => false, 'message' => 'Không tìm thấy thông tin khách hàng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Đánh dấu tin nhắn của khách trong cuộc hội thoại này là Đã đọc (is_read = 1)
    $pdo->prepare("
        UPDATE owner_chat_messages
        SET is_read = 1
        WHERE customer_id = ? AND field_id = ? AND sender_role = 'CUSTOMER' AND is_read = 0
    ")->execute([$cid, $fid]);

    $stMsgs = $pdo->prepare("
        SELECT *
        FROM owner_chat_messages
        WHERE customer_id = ? AND field_id = ?
        ORDER BY id ASC
        LIMIT 150
    ");
    $stMsgs->execute([$cid, $fid]);
    $rawMsgs = $stMsgs->fetchAll();

    // Lấy danh sách các sân con trong cùng Cụm sân để Chủ sân chọn gửi Thẻ báo giá nhanh
    $subPitches = [];
    if (!empty($fld['venue_id'])) {
        $stSubs = $pdo->prepare("SELECT id, name, pitch_code, type, price_per_hour, peak_price, image FROM fields WHERE venue_id = ? ORDER BY FIELD(type,'5','7','11'), id ASC");
        $stSubs->execute([(int)$fld['venue_id']]);
        $subPitches = $stSubs->fetchAll();
    }
    if (!$subPitches) {
        $stSubs = $pdo->prepare("SELECT id, name, pitch_code, type, price_per_hour, peak_price, image FROM fields WHERE id = ? OR parent_id = ? ORDER BY FIELD(type,'5','7','11'), id ASC");
        $stSubs->execute([$fid, $fid]);
        $subPitches = $stSubs->fetchAll();
    }

    $subPitchOptions = [];
    foreach ($subPitches as $sp) {
        $code = !empty($sp['pitch_code']) ? ('Sân ' . $sp['pitch_code']) : ('Sân ' . $sp['type'] . 'A');
        $pk   = (int)($sp['peak_price'] ?? round($sp['price_per_hour'] * 1.6));
        $subPitchOptions[] = [
            'id'         => (int)$sp['id'],
            'name'       => $sp['name'],
            'pitch_code' => $code,
            'type'       => $sp['type'],
            'price'      => (int)$sp['price_per_hour'],
            'peak_price' => $pk,
            'price_fmt'  => money($pk),
        ];
    }

    $messages = array_map(function ($m) use ($base) {
        return format_owner_chat_row($m, $base);
    }, $rawMsgs);

    echo json_encode([
        'ok'           => true,
        'customer'     => [
            'id'               => (int)$cust['id'],
            'full_name'        => $cust['full_name'] ?: $cust['username'],
            'phone'            => $cust['phone'] ?: '0909.123.456',
            'email'            => $cust['email'] ?? '',
            'avatar'           => !empty($cust['avatar']) ? img_src($cust['avatar']) : '',
            'initial'          => mb_strtoupper(mb_substr($cust['full_name'] ?: 'K', 0, 1)),
            'reputation_score' => (int)($cust['reputation_score'] ?? 100),
        ],
        'field'        => [
            'id'         => (int)$fld['id'],
            'name'       => $fld['name'],
            'district'   => $fld['district'] ?? '',
            'pitch_code' => !empty($fld['pitch_code']) ? ('Sân ' . $fld['pitch_code']) : ('Sân ' . $fld['type'] . 'A'),
            'image'      => img_src($fld['image'] ?: 'assets/images/field-1.jpg'),
        ],
        'sub_pitches'  => $subPitchOptions,
        'messages'     => $messages,
        'total_unread' => get_owner_unread_chat_count($u),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 6. CHỦ SÂN GỬI TIN NHẮN HOẶC THẺ ĐẶT SÂN / BÁO GIÁ NHANH CHO KHÁCH (`owner_send_message`)
// ============================================================================
if ($action === 'owner_send_message') {
    if (!$u || (!is_pitch_owner($u) && get_user_role_code($u) !== 'ADMIN')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền gửi tin nhắn với tư cách Chủ sân.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cid     = (int)($jsonBody['customer_id'] ?? $_POST['customer_id'] ?? 0);
    $fid     = (int)($jsonBody['field_id'] ?? $_POST['field_id'] ?? 0);
    $msgText = trim((string)($jsonBody['message'] ?? $_POST['message'] ?? ''));
    $cardReq = $jsonBody['pitch_card'] ?? null;

    $fld = owner_get_field($u, $fid);
    if (!$fld) {
        echo json_encode(['ok' => false, 'message' => 'Cụm sân này không thuộc quyền sở hữu của bạn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($cid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu thông tin khách hàng nhận tin nhắn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cardJson = null;
    if (is_array($cardReq) && !empty($cardReq['enabled'])) {
        $targetFieldId = (int)($cardReq['sub_field_id'] ?? $fid);
        $targetSub     = owner_get_field($u, $targetFieldId) ?: $fld;
        $pitchCodeLbl  = trim((string)($cardReq['pitch_code'] ?? (!empty($targetSub['pitch_code']) ? 'Sân ' . $targetSub['pitch_code'] : 'Sân 5A')));
        $timeSlotLbl   = trim((string)($cardReq['time_slot'] ?? '18:00 - 19:30'));
        $rawPriceVal   = (int)preg_replace('/\D+/', '', (string)($cardReq['price'] ?? '300000'));
        if ($rawPriceVal <= 0) {
            $rawPriceVal = (int)($targetSub['peak_price'] ?? $targetSub['price_per_hour'] ?? 300000);
        }
        $priceFormatted = money($rawPriceVal);
        $dateParam      = trim((string)($cardReq['date'] ?? date('Y-m-d')));

        $startPart = '18:00';
        $endPart   = '19:30';
        if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $timeSlotLbl, $mSlot)) {
            $startPart = $mSlot[1];
            $endPart   = $mSlot[2];
        }

        $cardJson = json_encode([
            'field_id'   => (int)$targetSub['id'],
            'name'       => $fld['name'],
            'pitch_code' => $pitchCodeLbl,
            'time_slot'  => $timeSlotLbl,
            'price'      => $priceFormatted,
            'image'      => $targetSub['image'] ?: ($fld['image'] ?: 'assets/images/field-1.jpg'),
            'book_url'   => "user/booking.php?field_id={$targetSub['id']}&date={$dateParam}&start={$startPart}&end={$endPart}",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($msgText === '') {
            $msgText = "🏟 BQL {$fld['name']} gửi bạn Thẻ báo giá & giữ sân nhanh: {$pitchCodeLbl} ({$timeSlotLbl}) • Giá {$priceFormatted}. Bạn bấm Đặt Ngay để giữ chỗ 10 phút nhé!";
        }
    }

    if ($msgText === '' && !$cardJson) {
        echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập nội dung tin nhắn hoặc chọn gửi Thẻ báo giá sân.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerId = (int)($fld['owner_id'] ?: $u['id']);
    $pdo->prepare("
        INSERT INTO owner_chat_messages
            (customer_id, owner_id, field_id, sender_id, sender_role, message, pitch_card_json, is_read)
        VALUES (?, ?, ?, ?, 'OWNER', ?, ?, 0)
    ")->execute([$cid, $ownerId, $fid, (int)$u['id'], $msgText, $cardJson]);

    $newId = (int)$pdo->lastInsertId();
    $stRow = $pdo->prepare("SELECT * FROM owner_chat_messages WHERE id = ?");
    $stRow->execute([$newId]);
    $insertedRow = $stRow->fetch();

    // Đánh dấu mọi tin nhắn trước đó của khách là đã đọc
    $pdo->prepare("
        UPDATE owner_chat_messages
        SET is_read = 1
        WHERE customer_id = ? AND field_id = ? AND sender_role = 'CUSTOMER' AND is_read = 0
    ")->execute([$cid, $fid]);

    notify($cid, "💬 Chủ sân {$fld['name']} vừa trả lời tin nhắn của bạn: \"" . mb_substr($msgText, 0, 75) . "\"");

    echo json_encode([
        'ok'           => true,
        'message'      => format_owner_chat_row($insertedRow, $base),
        'total_unread' => get_owner_unread_chat_count($u),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Hành động không hợp lệ.'], JSON_UNESCAPED_UNICODE);
