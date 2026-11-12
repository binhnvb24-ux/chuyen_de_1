<?php
/**
 * ============================================================================
 * FILE: index.php
 * MÔ TẢ: Trang chủ của hệ thống Đặt Sân Bóng Trực Tuyến (Dynamic Daylight Sport Pro).
 * CẤU TRÚC BỐ CỤC CHÍNH:
 *   - Phần 1: Kiểm tra tài khoản đăng nhập hiện tại.
 *   - Phần 2: Xử lý bộ lọc tìm kiếm sân bóng (Từ khóa, Loại sân, Khu vực, Giá, Giờ trống).
 *   - Phần 3: Truy vấn danh sách sân, trạng thái sân bận/trống, Sàn Cáp Kèo & Voucher.
 *   - Phần 4: Truy vấn lịch đặt sân gần đây & thông báo của người dùng.
 *   - Phần 5: Giao diện Thanh điều hướng trên cùng (Header Topbar).
 *   - Phần 6: Cột 1 - Menu điều hướng nhanh bên trái (Sidebar Left).
 *   - Phần 7: Cột 2 - Nội dung chính (Hero Banner, Bảng tỉ số Live, Danh sách sân,
 *                     Sàn Cáp Kèo, Danh mục sân 5/7/11, Lịch đặt gần đây, Ưu đãi).
 *   - Phần 8: Cột 3 - Tiện ích bên phải (Vé của tôi, Bản tin sân cỏ, Hotline).
 *   - Phần 9: Chân trang (Footer) và Tiện ích Trợ lý AI (Chat Widget).
 * ============================================================================
 */

require_once 'includes/auth.php';
require_once 'includes/football_news_feed.php';

// ----------------------------------------------------------------------------
// PHẦN 1: KIỂM TRA NGƯỜI DÙNG ĐANG ĐĂNG NHẬP
// (Cho phép cả Khách hàng và Admin xem giao diện Trang chủ)
// ----------------------------------------------------------------------------
$u = current_user();

// ----------------------------------------------------------------------------
// PHẦN 1B: XỬ LÝ FORM ĐĂNG KÝ HỢP TÁC CHỦ SÂN (PARTNER PITCH REGISTRATION)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'partner_register') {
    csrf_check();
    $pFieldName = trim($_POST['field_name'] ?? '');
    $pDistrict  = trim($_POST['district'] ?? 'Quận 1');
    $pType      = in_array($_POST['type'] ?? '', ['5', '7', '11'], true) ? $_POST['type'] : '5';
    $pAddress   = trim($_POST['address'] ?? '');
    $pPrice     = max(80000, min(1500000, (int)($_POST['price_per_hour'] ?? 160000)));
    $pOwnerName = trim($_POST['owner_name'] ?? ($u['full_name'] ?? 'Chủ sân đối tác'));
    $pPhone     = trim($_POST['owner_phone'] ?? ($u['phone'] ?? '0901234567'));
    $pImage     = trim($_POST['image'] ?? '') ?: 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900';

    if ($pFieldName === '' || $pAddress === '') {
        flash('err', 'Vui lòng nhập đầy đủ Tên sân bóng và Địa chỉ cụm sân.');
    } else {
        $applicantUid = $u ? (int)$u['id'] : 2;
        $stmtInsApp = $pdo->prepare("
            INSERT INTO partner_applications (
                user_id, venue_name, venue_address, district, city, latitude, longitude,
                pitch_summary, cnt_pitch_5, cnt_pitch_7, cnt_pitch_11, price_normal, price_peak,
                contact_phone, venue_images, bank_name, bank_account_number, bank_account_holder, status
            ) VALUES (?, ?, ?, ?, 'TP. Hồ Chí Minh', 10.7768890, 106.7008060, ?, 4, 2, 1, ?, ?, ?, ?, 'MB Bank', ?, ?, 'PENDING')
        ");
        $stmtInsApp->execute([
            $applicantUid,
            $pFieldName,
            $pAddress,
            $pDistrict,
            "Cụm sân {$pType} người tiêu chuẩn (4 sân 5, 2 sân 7, ghép sân 11)",
            $pPrice,
            (int)round($pPrice * 1.65),
            $pPhone,
            $pImage,
            $pPhone,
            mb_strtoupper($pOwnerName)
        ]);
        if ($u && ($u['partner_status'] ?? 'NONE') !== 'APPROVED') {
            $pdo->prepare("UPDATE users SET partner_status = 'PENDING' WHERE id = ?")->execute([$u['id']]);
        }

        // Gửi thông báo cho Admin và người dùng hiện tại
        $admId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($admId > 0) {
            notify($admId, "🤝 Chủ sân {$pOwnerName} ({$pPhone}) vừa gửi hồ sơ đăng ký mở sân mới: {$pFieldName} ({$pDistrict}) — Đang chờ Admin xét duyệt.");
        }
        if ($u && (int)$u['id'] !== $admId) {
            notify($u['id'], "⏳ Đã gửi hồ sơ đăng ký hợp tác cụm sân '{$pFieldName}' ({$pDistrict}). Vui lòng theo dõi tiến trình xét duyệt tại mục Hồ sơ -> Đăng ký Mở sân.");
            flash('ok', "🎉 Đã gửi hồ sơ đăng ký cụm sân '{$pFieldName}'! Bạn có thể bổ sung giấy tờ pháp lý, chọn tọa độ trên bản đồ và theo dõi tiến trình duyệt đơn tại đây.");
            header('Location: user/profile.php?tab=partner');
            exit;
        }

        flash('ok', "🎉 Đã gửi hồ sơ đăng ký hợp tác cụm sân '{$pFieldName}'! Vui lòng đăng nhập để theo dõi tiến trình xét duyệt.");
        header('Location: login.php');
        exit;
    }
}

// ----------------------------------------------------------------------------
// PHẦN 2: XỬ LÝ THAM SỐ TÌM KIẾM & BỘ LỌC SÂN BÓNG TỪ URL ($_GET)
// ----------------------------------------------------------------------------
$q     = trim($_GET['q'] ?? '');
$type  = in_array($_GET['type'] ?? '', ['5', '7', '11']) ? $_GET['type'] : '';
$dist  = trim($_GET['district'] ?? '');
$max   = (int)($_GET['max'] ?? 0);
$date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
$start = preg_match('/^\d{2}:00$/', $_GET['start'] ?? '') ? $_GET['start'] : '';

// Khởi tạo câu truy vấn cơ sở lấy các sân đang hoạt động (active)
$sql = "SELECT * FROM fields f WHERE f.status = 'active' AND (f.name LIKE ? OR f.address LIKE ?)";
$p   = ["%$q%", "%$q%"];

// Nối thêm điều kiện lọc theo Loại sân (5, 7, 11 người)
if ($type) {
    $sql .= " AND f.type = ?";
    $p[]  = $type;
}

// Nối thêm điều kiện lọc theo Quận / Huyện
if ($dist) {
    $sql .= " AND f.district = ?";
    $p[]  = $dist;
}

// Nối thêm điều kiện lọc theo Mức giá tối đa mỗi giờ
if ($max) {
    $sql .= " AND f.price_per_hour <= ?";
    $p[]  = $max;
}

// Nối thêm điều kiện kiểm tra sân còn trống trong Ngày & Khung giờ người dùng chọn
if ($date && $start) {
    $end  = sprintf('%02d:00', (int)$start + 1);
    $sql .= " AND NOT EXISTS (
                SELECT 1 FROM bookings b
                WHERE b.field_id = f.id
                  AND b.booking_date = ?
                  AND b.status IN ('pending', 'confirmed', 'completed')
                  AND b.start_time < ?
                  AND b.end_time > ?
              )";
    array_push($p, $date, $end, $start);
}

// ----------------------------------------------------------------------------
// PHẦN 3: TRUY VẤN DANH SÁCH SÂN, QUẬN/HUYỆN, SÂN ĐANG BẬN & SÀN CÁP KÈO
// ----------------------------------------------------------------------------

// 3.1. Lấy tối đa 12 sân bóng sắp xếp theo điểm đánh giá (rating) cao nhất
$s = $pdo->prepare($sql . " ORDER BY f.rating DESC LIMIT 12");
$s->execute($p);
$fields = $s->fetchAll();

// 3.2. Lấy danh sách các Quận/Huyện hiện có để đổ vào ô <select> bộ lọc
$districts = $pdo->query("
    SELECT DISTINCT district
    FROM fields
    WHERE status = 'active' AND district <> ''
    ORDER BY district
")->fetchAll(PDO::FETCH_COLUMN);

// 3.2B. Thống kê khu vực kèm ảnh đại diện, tọa độ GPS & thông tin 6 Điểm dừng cho mục "Tìm Sân Theo Khu Vực"
$areaSql = "
    SELECT district, COUNT(*) AS cnt, MIN(image) AS thumb, MIN(price_per_hour) AS min_price, MAX(rating) AS max_rating
    FROM fields f
    WHERE f.status = 'active' AND f.district <> ''
";
$areaParams = [];
if ($type) {
    $areaSql .= " AND f.type = ?";
    $areaParams[] = $type;
}
$areaSql .= " GROUP BY district";
$stmtArea = $pdo->prepare($areaSql);
$stmtArea->execute($areaParams);
$rawAreaMap = [];
foreach ($stmtArea->fetchAll() as $rRow) {
    $rawAreaMap[trim($rRow['district'])] = $rRow;
}

// Cấu hình 6 Khu vực trọng điểm gắn liền với 6 Điểm dừng sân bóng nổi tiếng TP.HCM
$PresetDistricts = [
    [
        'name'      => 'Tân Bình',
        'stop_tag'  => 'Điểm dừng 1 & 2',
        'highlight' => 'Sân Banh Chảo Lửa & Sân K34',
        'sub_info'  => 'Phan Thúc Duyện & Bạch Đằng • Sân 5-7-11',
        'lat'       => 10.8016800,
        'lng'       => 106.6626100,
        'focus_fid' => 1,
        'image'     => 'assets/images/field-1.jpg',
        'rating'    => '4.3 (3.6K+)',
        'min_price' => 260000,
    ],
    [
        'name'      => 'Bình Thạnh',
        'stop_tag'  => 'Điểm dừng 3',
        'highlight' => 'Sân vận động Gia Định',
        'sub_info'  => '2A Phan Chu Trinh • Sân 11 chuẩn & sân 5-7',
        'lat'       => 10.8087500,
        'lng'       => 106.6961500,
        'focus_fid' => 3,
        'image'     => 'assets/images/field-3.jpg',
        'rating'    => '4.3/5',
        'min_price' => 200000,
    ],
    [
        'name'      => 'Quận 11',
        'stop_tag'  => 'Điểm dừng 4',
        'highlight' => 'Nhà Tập Luyện Phú Thọ',
        'sub_info'  => '219 Lý Thường Kiệt • Mở cửa 05:00 – 23:00',
        'lat'       => 10.7694500,
        'lng'       => 106.6579200,
        'focus_fid' => 4,
        'image'     => 'assets/images/field-1.jpg',
        'rating'    => '4.4/5',
        'min_price' => 250000,
    ],
    [
        'name'      => 'Quận 1',
        'stop_tag'  => 'Điểm dừng 5',
        'highlight' => 'Sân banh Tao Đàn',
        'sub_info'  => 'Huyền Trân Công Chúa, Bến Thành • Sân 5-7-11',
        'lat'       => 10.7751200,
        'lng'       => 106.6928500,
        'focus_fid' => 5,
        'image'     => 'assets/images/field-2.jpg',
        'rating'    => '4.2/5',
        'min_price' => 320000,
    ],
    [
        'name'      => 'Quận 7',
        'stop_tag'  => 'Điểm dừng 6',
        'highlight' => 'SVĐ ĐH Tôn Đức Thắng',
        'sub_info'  => 'Đường D6, Tân Hưng • Chuẩn FIFA 2 sao',
        'lat'       => 10.7326500,
        'lng'       => 106.6997800,
        'focus_fid' => 6,
        'image'     => 'assets/images/field-3.jpg',
        'rating'    => '4.7/5',
        'min_price' => 400000,
    ],
    [
        'name'      => 'Thủ Đức',
        'stop_tag'  => 'Cụm sân lớn',
        'highlight' => 'Phú Lợi & Vạn Phúc City',
        'sub_info'  => 'Phạm Văn Đồng & Quốc lộ 13 • Sân 5-7-11',
        'lat'       => 10.8286400,
        'lng'       => 106.7219500,
        'focus_fid' => 7,
        'image'     => 'assets/images/field-2.jpg',
        'rating'    => '4.9/5',
        'min_price' => 220000,
    ],
];

$areaCards = [];
foreach ($PresetDistricts as $pd) {
    $dKey = $pd['name'];
    $dbRow = $rawAreaMap[$dKey] ?? null;
    $areaCards[] = [
        'name'      => $dKey,
        'count'     => $dbRow ? (int)$dbRow['cnt'] : 1,
        'image'     => ($dbRow && !empty($dbRow['thumb'])) ? $dbRow['thumb'] : $pd['image'],
        'stop_tag'  => $pd['stop_tag'],
        'highlight' => $pd['highlight'],
        'sub_info'  => $pd['sub_info'],
        'lat'       => $pd['lat'],
        'lng'       => $pd['lng'],
        'focus_fid' => $pd['focus_fid'],
        'rating'    => $pd['rating'],
        'min_price' => $dbRow ? (int)$dbRow['min_price'] : $pd['min_price'],
    ];
}

// Danh sách 6 Điểm dừng sân bóng thực tế nổi tiếng TP.HCM
$famousStops = [
    [
        'stop_no'    => 1,
        'field_id'   => 1,
        'name'       => 'Sân Banh Chảo Lửa',
        'district'   => 'Tân Bình',
        'address'    => '38 Đ. Phan Thúc Duyện, Tân Sơn Nhất, TP.HCM',
        'contact'    => '📞 0777 700 000',
        'rating_txt' => '4.3/5 (3.600+ đánh giá)',
        'scale'      => 'Quy mô lớn gồm nhiều sân 5 và 7 người liên hoàn, ghép thành sân 11 người tiêu chuẩn.',
        'lat'        => 10.8016800,
        'lng'        => 106.6626100,
        'price'      => 300000,
        'image'      => 'assets/images/field-1.jpg',
    ],
    [
        'stop_no'    => 2,
        'field_id'   => 2,
        'name'       => 'Sân Bóng Đá K34',
        'district'   => 'Tân Bình',
        'address'    => 'Bạch Đằng, Tân Sơn Hòa, TP.HCM',
        'contact'    => '📞 0908 787 798',
        'rating_txt' => '4.2/5',
        'scale'      => 'Hệ thống sân cỏ nhân tạo có mái che cực kỳ thoáng mát, thi đấu bất chấp nắng mưa.',
        'lat'        => 10.8132500,
        'lng'        => 106.6694200,
        'price'      => 260000,
        'image'      => 'assets/images/field-2.jpg',
    ],
    [
        'stop_no'    => 3,
        'field_id'   => 3,
        'name'       => 'Sân vận động Gia Định',
        'district'   => 'Bình Thạnh',
        'address'    => '2A Phan Chu Trinh, Bình Thạnh, TP.HCM',
        'contact'    => '🕒 Mở cửa: 05:30 – 22:00',
        'rating_txt' => '4.3/5',
        'scale'      => 'Tổ hợp SVĐ lớn có mặt sân 11 người chuẩn thi đấu và cụm sân 5 - 7 người vệ tinh xung quanh.',
        'lat'        => 10.8087500,
        'lng'        => 106.6961500,
        'price'      => 350000,
        'image'      => 'assets/images/field-3.jpg',
    ],
    [
        'stop_no'    => 4,
        'field_id'   => 4,
        'name'       => 'Sân bóng đá mini Nhà Tập Luyện Phú Thọ',
        'district'   => 'Quận 11',
        'address'    => '219 Đ. Lý Thường Kiệt, Quận 11, TP.HCM',
        'contact'    => '🕒 Mở cửa: 05:00 – 23:00',
        'rating_txt' => '4.4/5',
        'scale'      => 'Nằm trong khu liên hợp thể thao SVĐ Phú Thọ, mặt cỏ đạt chuẩn, dễ kết nối từ trung tâm.',
        'lat'        => 10.7694500,
        'lng'        => 106.6579200,
        'price'      => 250000,
        'image'      => 'assets/images/field-1.jpg',
    ],
    [
        'stop_no'    => 5,
        'field_id'   => 5,
        'name'       => 'Sân banh Tao Đàn',
        'district'   => 'Quận 1',
        'address'    => 'Huyền Trân Công Chúa, Bến Thành, TP.HCM',
        'contact'    => '📞 028 3822 4158',
        'rating_txt' => '4.2/5',
        'scale'      => 'Nằm ngay "lá phổi xanh" trung tâm thành phố, mặt sân 11 người chia tách linh hoạt sân 7 & 5.',
        'lat'        => 10.7751200,
        'lng'        => 106.6928500,
        'price'      => 320000,
        'image'      => 'assets/images/field-2.jpg',
    ],
    [
        'stop_no'    => 6,
        'field_id'   => 6,
        'name'       => 'Sân vận động Trường ĐH Tôn Đức Thắng',
        'district'   => 'Quận 7',
        'address'    => 'Đường D6, Tân Hưng, Quận 7, TP.HCM',
        'contact'    => '📞 028 3775 5035',
        'rating_txt' => '4.7/5 (FIFA 2 sao)',
        'scale'      => 'SVĐ đạt chuẩn FIFA 2 sao với mặt cỏ cực đẹp, khán đài lớn và đường chạy điền kinh bao quanh.',
        'lat'        => 10.7326500,
        'lng'        => 106.6997800,
        'price'      => 400000,
        'image'      => 'assets/images/field-3.jpg',
    ],
];

$activePinLabel = $dist ? ('Khu vực ' . $dist) : 'Toàn TP. Hồ Chí Minh (10 cụm sân)';

// 3.2C. Truy vấn danh sách ca trống thực tế cho phần "Đặt Sân Nhanh Theo Giờ Vàng"
$targetGoldenDate = $date ?: date('Y-m-d');
$goldenWindows = [
    ['start' => '17:30', 'end' => '19:00', 'label' => 'Giờ trống còn lại trong ngày (17:30 - 19:00)'],
    ['start' => '19:00', 'end' => '20:30', 'label' => 'Giờ trống còn lại trong ngày (19:00 - 20:30)'],
    ['start' => '20:30', 'end' => '22:00', 'label' => 'Giờ trống còn lại trong ngày (20:30 - 22:00)'],
];
$goldenSlotsData = [];
foreach ($goldenWindows as $gw) {
    $gwSql = "
        SELECT f.id, f.name, f.district, f.type, f.price_per_hour
        FROM fields f
        WHERE f.status = 'active'
          AND NOT EXISTS (
              SELECT 1 FROM bookings b
              WHERE b.field_id = f.id
                AND b.booking_date = ?
                AND b.status IN ('pending', 'confirmed', 'completed')
                AND b.start_time < ?
                AND b.end_time > ?
          )
    ";
    $gwParams = [$targetGoldenDate, $gw['end'] . ':00', $gw['start'] . ':00'];
    if ($dist !== '') {
        $gwSql .= " ORDER BY (f.district = ?) DESC, f.rating DESC";
        $gwParams[] = $dist;
    } else {
        $gwSql .= " ORDER BY f.rating DESC";
    }
    $stmtGw = $pdo->prepare($gwSql);
    $stmtGw->execute($gwParams);
    $freeFieldsInSlot = $stmtGw->fetchAll();

    $goldenSlotsData[] = [
        'start'       => $gw['start'],
        'end'         => $gw['end'],
        'label'       => $gw['label'],
        'free_fields' => $freeFieldsInSlot,
        'free_count'  => count($freeFieldsInSlot),
    ];
}

// 3.3. Lấy danh sách ID các sân đang có trận đấu diễn ra ngay tại thời điểm hiện tại
$busyNow = $pdo->query("
    SELECT DISTINCT field_id
    FROM bookings
    WHERE booking_date = CURDATE()
      AND status IN ('pending', 'confirmed')
      AND start_time <= CURTIME()
      AND end_time > CURTIME()
")->fetchAll(PDO::FETCH_COLUMN);

// 3.4. Thống kê số lượng sân và giá thấp nhất theo từng thể thức (Sân 5, Sân 7, Sân 11)
$cats = $pdo->query("
    SELECT type, COUNT(*) AS n, MIN(price_per_hour) AS p, MIN(image) AS img
    FROM fields
    WHERE status = 'active'
    GROUP BY type
    ORDER BY type
")->fetchAll();

// 3.5. Lấy 3 kèo giao hữu đang mở gần nhất và tổng số mã giảm giá Voucher đang có hiệu lực
$openMatches  = [];
$voucherCount = 0;
try {
    $openMatches = $pdo->query("
        SELECT m.*, f.name AS fname, f.type AS ftype, f.district
        FROM match_requests m
        JOIN fields f ON f.id = m.field_id
        WHERE m.status = 'open' AND m.match_date >= CURDATE()
        ORDER BY m.match_date ASC, m.start_time ASC
        LIMIT 3
    ")->fetchAll();

    $voucherCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM vouchers
        WHERE status = 'active' AND expired_at >= CURDATE()
    ")->fetchColumn();
} catch (Exception $e) {}

// 3.6. Lấy 5 bản tin thể thao bóng đá nóng hổi hiện nay (RSS VnExpress Thể Thao + Cache 15p)
$footballNews = get_latest_football_news(5);

// ----------------------------------------------------------------------------
// PHẦN 4: TRUY VẤN LỊCH ĐẶT GẦN ĐÂY & THÔNG BÁO CỦA NGƯỜI DÙNG (NẾU ĐÃ LOGIN)
// ----------------------------------------------------------------------------
$my     = [];
$unread = 0;

if ($u) {
    // Lấy 3 đơn đặt sân gần nhất của người dùng
    $s = $pdo->prepare("
        SELECT b.*, f.name, f.image
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT 3
    ");
    $s->execute([$u['id']]);
    $my = $s->fetchAll();

    // Đếm số thông báo chưa đọc
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$u['id']]);
    $unread = (int)$s->fetchColumn();
}

// Lấy thông tin số điện thoại và email của Quản trị viên để hiển thị ở phần Liên hệ
$ad = $pdo->query("
    SELECT phone, email
    FROM users
    WHERE role = 'admin' AND status = 'active'
    LIMIT 1
")->fetch() ?: ['phone' => '', 'email' => ''];

// Bảng ánh xạ trạng thái đơn đặt sân sang nhãn tiếng Việt và màu sắc thẻ tag
$st = [
    'pending'   => ['Chờ xác nhận', 'blue'],
    'confirmed' => ['Đã xác nhận', 'green'],
    'cancelled' => ['Đã hủy', 'gray'],
    'completed' => ['Hoàn thành', 'green']
];

// Kiểm tra xem người dùng có đang sử dụng bộ lọc tìm kiếm hay không
$filtered          = ($q !== '' || $type || $dist || $max || ($date && $start));
$totalActiveFields = count($fields);
$freeNowCount      = max(0, $totalActiveFields - count($busyNow));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sân Bóng Pro - Đặt sân trực tuyến & Sàn cáp kèo thể thao</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Chakra+Petch:ital,wght@0,600;0,700;1,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__ . '/assets/css/style.css')?>">
  <link rel="stylesheet" href="assets/css/home.css?v=<?=filemtime(__DIR__ . '/assets/css/home.css')?>">
  <link rel="stylesheet" href="assets/css/effects.css?v=<?=filemtime(__DIR__ . '/assets/css/effects.css')?>">
  <!-- Thư viện Bản đồ trực tuyến Leaflet.js + OpenStreetMap (Miễn phí, hỗ trợ nhiều ghim Markers) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
</head>
<body class="hp">

<!-- ==========================================================================
     PHẦN 5: THANH HEADER ĐIỀU HƯỚNG TRÊN CÙNG (CLEAN WHITE TOPBAR)
     ========================================================================== -->
<header class="topbar">
  <div class="topbar-container">
    <a class="brand" href="index.php">
      <div class="brand-icon">⚽</div>
      <div>
        <b>SÂN BÓNG <span class="sport-badge-inline">PRO</span></b>
        <small>ARENA BOOKING & MATCHMAKING</small>
      </div>
    </a>

    <nav class="topbar-nav">
      <a class="nav-item active" href="index.php">Trang chủ</a>
      <a class="nav-item" href="#fields">Sân bóng</a>
      <a class="nav-item" href="user/booking.php">Đặt sân</a>
      <a class="nav-item" href="user/matchmaking.php">Cáp kèo</a>
      <a class="nav-item" href="user/my-bookings.php">Vé của tôi</a>
    </nav>

    <div class="tb-right">
      <?php if ($u && is_pitch_owner($u)): ?>
        <a class="tb-partner-portal-btn" href="user/owner-dashboard.php" style="display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;background:linear-gradient(135deg,#059669,#047857);border:1.5px solid #4ade80;color:#ffffff !important;font-size:12.5px;font-weight:800;text-decoration:none;box-shadow:0 6px 16px rgba(16,185,129,0.32)" title="Mở Kênh Chủ Sân (Partner Portal) — Quản lý Sân, Giá & Lịch POS">
          <span>🏟 Kênh Chủ Sân (Partner Portal)</span>
          <span style="padding:2px 7px;border-radius:999px;background:#dcfce7;color:#065f46;font-size:10px;font-weight:800">✔ ĐÃ XÁC MINH</span>
        </a>
      <?php endif; ?>

      <a class="tb-create-match" href="user/matchmaking.php#create-match-form">
        + Tạo kèo
      </a>

      <a class="btn-noti" href="user/notifications.php" title="Thông báo">
        🔔
        <span class="noti-count"><?=$unread?></span>
      </a>

      <?php if ($u): ?>
        <a class="user-chip" href="user/profile.php" style="text-decoration:none" title="Mở Trang Hồ sơ cá nhân, Chuyển kênh & Đăng xuất">
          <?php if (!empty($u['avatar'])): ?>
            <img src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name'])?>" class="avatar" referrerpolicy="no-referrer" style="object-fit:cover;padding:0" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
            <span class="avatar" style="display:none"><?=e(mb_substr($u['full_name'], 0, 1))?></span>
          <?php else: ?>
            <span class="avatar"><?=e(mb_substr($u['full_name'], 0, 1))?></span>
          <?php endif; ?>
          <div class="user-meta">
            <span class="uname"><?=e($u['full_name'])?></span>
            <small class="urole">
              <?php if (get_user_role_code($u) === 'ADMIN'): ?>
                🛡️ Quản trị viên • Hồ sơ
              <?php elseif (is_pitch_owner($u)): ?>
                🏟 Chủ sân đối tác • Hồ sơ
              <?php else: ?>
                🛡️ <?=(int)($u['reputation_score'] ?? 100)?>đ • 💰 <?=money($u['wallet_balance'] ?? 0)?>
              <?php endif; ?>
            </small>
          </div>
        </a>
      <?php else: ?>
        <a class="btn-login" href="login.php">Đăng nhập →</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Thanh Trung Tâm Trực Tiếp LIVE ARENA (Mint Daylight Broadcast Bar) -->
<div class="sport-live-ticker">
  <div class="arena-left">
    <span class="arena-live-badge">
      <i class="arena-live-dot"></i> LIVE ARENA
    </span>
    <span class="arena-clock" id="liveArenaClock">
      🕒 <?=date('H:i:s')?>
    </span>
  </div>

  <div class="ticker-track">
    <div class="ticker-content">
      <a class="arena-pill" href="user/booking.php">
        <span><b style="color:#16a34a">Sân trống:</b> Sẵn sàng thi đấu (<?=$freeNowCount?>/<?=$totalActiveFields?> sân)</span>
      </a>
      <a class="arena-pill" href="user/booking.php">
        <span>🏟 Sơ đồ chọn giờ 30 phút, ghép/tách sân</span>
      </a>
      <a class="arena-pill" href="user/matchmaking.php">
        <span>⚔️ <b style="color:#16a34a">Kèo giao hữu:</b> Đang tìm đối (<?=count($openMatches)?> kèo)</span>
      </a>
      <?php if (!empty($footballNews[0])): ?>
        <a class="arena-pill" href="#footballNewsBox">
          <span>📰 <?=e($footballNews[0]['title'])?></span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="arena-right">
    <a class="arena-quick-link" href="user/booking.php">⚡ ĐẶT SÂN NGAY • CÁP KÈO</a>
  </div>
</div>

<!-- ==========================================================================
     KHUNG BỐ CỤC 2 CỘT CHUẨN APP SHELL: SIDEBAR TRÁI + NỘI DUNG CHÍNH
     ========================================================================== -->
<div class="hp-wrap">

  <!-- ========================================================================
       PHẦN 6: CỘT 1 - MENU ĐIỀU HƯỚNG NHANH BÊN TRÁI (LEFT SIDEBAR)
       ======================================================================== -->
  <nav class="hp-side">
    <div class="side-user-card">
      <div class="side-avatar" style="overflow:hidden">
        <?php if (!empty($u['avatar'])): ?>
          <img src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name'] ?? 'N')?>" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover;border-radius:50%" onerror="this.style.display='none'">
        <?php else: ?>
          <?=e(mb_substr($u['full_name'] ?? 'N', 0, 1))?>
        <?php endif; ?>
      </div>
      <div class="side-name"><?=e($u['full_name'] ?? 'Nguyễn Văn A')?></div>
      <div class="side-status">● Đội trưởng FC</div>
    </div>

    <div class="side-menu-group">ĐIỀU HƯỚNG</div>
    <a class="side-link on" href="index.php"><span>🏟 Trang chủ</span></a>
    <a class="side-link" href="#fields"><span>🔎 Khám phá sân</span></a>
    <a class="side-link" href="user/booking.php"><span>📅 Đặt sân</span></a>
    <a class="side-link" href="user/matchmaking.php"><span>⚔️ Cáp kèo / Tìm đối</span></a>
    <a class="side-link" href="user/my-bookings.php"><span>🎫 Vé của tôi</span></a>
    <a class="side-link" href="user/notifications.php">
      <span>🔔 Thông báo</span>
      <?php if ($unread): ?>
        <span class="badge-mini green"><?=$unread?></span>
      <?php endif; ?>
    </a>
    <a class="side-link" href="user/profile.php"><span>👤 Hồ sơ & Ví nội bộ</span></a>

    <?php if ($u && is_pitch_owner($u)): ?>
      <a class="side-link" href="user/owner-dashboard.php"><span>🏟 Kênh Chủ Sân</span></a>
    <?php elseif ($u && $u['role'] !== 'admin'): ?>
      <a class="side-link" href="user/profile.php?tab=partner"><span>🤝 Đăng ký Mở sân</span></a>
    <?php endif; ?>

    <?php if ($u && $u['role'] === 'admin'): ?>
      <a class="side-link" href="admin/dashboard.php"><span>⚙️ Quản trị Admin</span></a>
    <?php endif; ?>

    <div class="side-footer">
      <div class="pitch-mini-card">
        <b>🏆 GIỜ VÀNG THI ĐẤU</b>
        <small>17:00 - 21:00 thường kín sân sớm. Đặt trước để giữ lịch đẹp!</small>
      </div>
    </div>
  </nav>

  <!-- ========================================================================
       PHẦN 7: CỘT 2 - NỘI DUNG CHÍNH TRANG CHỦ (MAIN CONTENT)
       ======================================================================== -->
  <main class="hp-main">

    <?php if (!empty($_SESSION['flash'])): [$ft, $fm] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert <?=$ft?>" style="margin-bottom:16px">
        <span class="alert-icon"><?=$ft === 'ok' ? '✔' : '⚠️'?></span>
        <div class="alert-text"><?=e($fm)?></div>
      </div>
    <?php endif; ?>

    <!-- 7.1. Phần Hero Banner CR7 "SIUUU! ⚡7" & Bộ lọc nổi (Screen 01 & 02) -->
    <div class="hp-hero-wrapper">
      <section class="hp-hero">
        <div class="hp-hero-content">
          <small class="k">⚽ TRANG CHỦ • ĐẶT SÂN ĐÁ BÓNG</small>
          <h1>
            ĐẶT SÂN NHANH
            <span>BÙNG NỔ CÙNG CR7!</span>
          </h1>
          <p class="hp-hero-desc">
            Khám phá sân gần bạn, đặt giờ trực quan, thanh toán tiện lợi và nhận vé điện tử QR.
            Hãy sẵn sàng cho trận đấu của chúng bạn!
          </p>

          <div class="hp-chips">
            <span><i>✔</i> Đặt sân nhanh</span>
            <span><i>✔</i> Sơ đồ 30 phút</span>
            <span><i>✔</i> Vé QR Check-in</span>
          </div>
        </div>

        <!-- Ảnh CR7 ăn mừng bên phải Banner kèm chữSIUUU! ⚡ và chữ ký CR7 -->
        <div class="hp-hero-cr7">
          <div class="hero-siuuu-text">SIUUU! ⚡</div>
          <div class="hero-cr7-sig">CR7</div>
        </div>
      </section>

      <!-- Thanh tìm kiếm 4 cột + nút nổi đè lên mép dưới Hero Banner -->
      <form class="hp-form" method="get" action="index.php#fields">
        <input type="hidden" name="q" value="<?=e($q)?>">

        <div class="hp-filter-col">
          <label>📍 Khu vực</label>
          <select name="district">
            <option value="">Quận 1, TP.HCM (Tất cả)</option>
            <?php foreach ($districts as $d): ?>
              <option value="<?=e($d)?>" <?=$dist === $d ? 'selected' : ''?>><?=e($d)?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="hp-filter-col">
          <label>⚽ Loại sân</label>
          <select name="type">
            <option value="">Sân 5 / 7 / 11 người</option>
            <?php foreach (['5', '7', '11'] as $tp): ?>
              <option value="<?=$tp?>" <?=$type === $tp ? 'selected' : ''?>>Sân <?=$tp?> người</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="hp-filter-col">
          <label>📅 Ngày thi đấu</label>
          <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($date ?: date('Y-m-d'))?>">
        </div>

        <div class="hp-filter-col">
          <label>🕒 Khung giờ</label>
          <select name="start">
            <option value="">18:00 - 19:30 (Tất cả)</option>
            <?php for ($h = 6; $h <= 22; $h++): $v = sprintf('%02d:00', $h); ?>
              <option value="<?=$v?>" <?=$start === $v ? 'selected' : ''?>><?=$v?> - <?=sprintf('%02d:00', $h + 1)?></option>
            <?php endfor; ?>
          </select>
        </div>

        <button class="btn hp-search-btn" type="submit">🔍 TÌM SÂN TRỐNG</button>
      </form>
    </div>

    <!-- 7.2. Hàng 4 Thẻ Thống Kê Trắng (4 Clean KPI Cards - Screen 01) -->
    <div class="hp-kpi-row">
      <div class="hp-kpi-card">
        <div>
          <div class="kpi-val"><?=$totalActiveFields?></div>
          <div class="kpi-lbl">Sân đang mở</div>
        </div>
        <div class="kpi-ico green">⚽</div>
      </div>

      <div class="hp-kpi-card">
        <div>
          <div class="kpi-val">12</div>
          <div class="kpi-lbl">Lịch đặt trong tuần</div>
        </div>
        <div class="kpi-ico red">📅</div>
      </div>

      <div class="hp-kpi-card">
        <div>
          <div class="kpi-val"><?=count($openMatches) ?: 3?></div>
          <div class="kpi-lbl">Kèo giao hữu đang mở</div>
        </div>
        <div class="kpi-ico blue">⚔️</div>
      </div>

      <div class="hp-kpi-card">
        <div>
          <div class="kpi-val"><?=$voucherCount ?: 3?></div>
          <div class="kpi-lbl">Ưu đãi dành cho bạn</div>
        </div>
        <div class="kpi-ico amber">🎁</div>
      </div>
    </div>

    <!-- ======================================================================
         7.2B. PHẦN 1: TÌM SÂN THEO KHU VỰC (THIẾT KẾ HÀI HÒA + BẢN ĐỒ LEAFLET + 6 ĐIỂM DỪNG)
         ====================================================================== -->
    <section class="hp-area-section" id="find-by-area">
      <!-- Thanh tiêu đề & bộ lọc hài hòa -->
      <div class="hp-area-header">
        <div class="hp-area-title-wrap">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <h3 class="hp-area-main-title">Tìm Sân Theo Khu Vực</h3>
            <span class="hp-area-sub-pill">⚽ 6 Khu vực & Điểm dừng nổi tiếng TP.HCM</span>
            <?php if ($dist || $type || $date): ?>
              <a href="index.php#find-by-area" class="area-reset-badge" title="Xóa bộ lọc khu vực">
                ✕ Đang lọc: <?=e($dist ?: 'Tất cả quận')?> <?=$type ? "• Sân {$type}" : ''?> <?=$date ? '• ' . date('d/m', strtotime($date)) : ''?>
              </a>
            <?php endif; ?>
          </div>
          <small class="hp-area-desc">
            Rê chuột hoặc bấm vào thẻ Khu vực / Điểm dừng để bản đồ tự động bay (flyTo) tới đúng vị trí sân
          </small>
        </div>

        <!-- Cụm nút định vị GPS & 3 ô bộ lọc nhỏ ở góc phải: Tìm sân gần tôi | Chọn khu vực ⌄ | Chọn ngày ⌄ | Bộ lọc ⌄ -->
        <div class="hp-area-controls">
          <button type="button" class="area-near-btn" id="btnFindNearMe" onclick="findNearestPitches()">
            📍 Tìm sân gần tôi
          </button>

          <form class="hp-area-mini-filters" id="areaMiniFilterForm" method="get" action="index.php#find-by-area">
            <div class="mini-select-box">
              <select name="district" id="areaDistSelect" aria-label="Chọn khu vực" onchange="this.form.submit()">
                <option value="">Chọn khu vực</option>
                <?php foreach ($districts as $d): ?>
                  <option value="<?=e($d)?>" <?=$dist === $d ? 'selected' : ''?>><?=e($d)?></option>
                <?php endforeach; ?>
              </select>
              <span class="caret">⌄</span>
            </div>

            <div class="mini-select-box">
              <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($date ?: date('Y-m-d'))?>" title="Chọn ngày thi đấu" onchange="this.form.submit()">
              <span class="caret">⌄</span>
            </div>

            <div class="mini-select-box">
              <select name="type" aria-label="Bộ lọc" onchange="this.form.submit()">
                <option value="">Bộ lọc (Loại sân)</option>
                <option value="5" <?=$type === '5' ? 'selected' : ''?>>Sân 5 người</option>
                <option value="7" <?=$type === '7' ? 'selected' : ''?>>Sân 7 người</option>
                <option value="11" <?=$type === '11' ? 'selected' : ''?>>Sân 11 người</option>
              </select>
              <span class="caret">⌄</span>
            </div>
          </form>
        </div>
      </div>

      <!-- Thông báo kết quả định vị GPS (ẩn mặc định, hiện khi bấm "Tìm sân gần tôi") -->
      <div id="geoStatusBanner" class="area-geo-banner" style="display:none"></div>

      <!-- BỐ CỤC CHÍNH 2 CỘT HÀI HÒA:
           - Cột trái: Bản đồ trực tuyến Leaflet.js + OpenStreetMap hiển thị ghim các cụm sân
           - Cột phải: Lưới 3x2 thẻ Khu vực cân đối chiều cao với bản đồ bên trái -->
      <div class="hp-area-grid">
        <!-- Cột trái: Bản đồ tương tác Leaflet.js đồng bộ trực tiếp với các thẻ khu vực -->
        <div class="hp-map-card" id="areaInteractiveMap">
          <div class="area-map-top-bar">
            <span class="am-live-pill"><i></i> Bản đồ trực tuyến TP.HCM</span>
            <button type="button" class="am-reset-btn" onclick="resetAreaLeafletMap()">🔄 Toàn TP.HCM</button>
          </div>

          <div id="areaLeafletMap" class="hp-leaflet-area-canvas"></div>

          <!-- Nhãn trạng thái nổi dưới đáy bản đồ -->
          <div class="map-floating-label" id="areaMapStatusLabel">
            📍 <?=e($activePinLabel)?>
          </div>
        </div>

        <!-- Cột phải: Lưới 3x2 thẻ Khu vực thiết kế hài hòa (Ảnh + Tag Điểm dừng + Tên Quận + Sân tiêu biểu + Giá/Khoảng cách) -->
        <div class="hp-district-cards" id="areaDistrictGrid">
          <?php foreach ($areaCards as $ac):
              $cardUrl = 'index.php?district=' . urlencode($ac['name'])
                       . ($date ? '&date=' . urlencode($date) : '')
                       . ($type ? '&type=' . urlencode($type) : '')
                       . '#fields';
          ?>
            <a class="hp-district-card <?=$dist === $ac['name'] ? 'active' : ''?>"
               href="<?=$cardUrl?>"
               data-fid="<?=(int)$ac['focus_fid']?>"
               data-lat="<?=$ac['lat']?>"
               data-lng="<?=$ac['lng']?>"
               onmouseenter="focusAreaPitchOnMap(<?=(int)$ac['focus_fid']?>, '<?=e($ac['name'])?> • <?=e($ac['highlight'])?>', false)"
               title="Xem các cụm sân tại <?=e($ac['name'])?>">
              <div class="dist-card-img">
                <img src="<?=e(img_src($ac['image']))?>" alt="<?=e($ac['name'])?>">
                <span class="dist-stop-pill"><?=e($ac['stop_tag'])?></span>
                <span class="dist-rating-pill">★ <?=e($ac['rating'])?></span>
              </div>

              <div class="dist-card-body">
                <div class="dist-card-top">
                  <b class="dist-name"><?=e($ac['name'])?></b>
                  <span class="dist-count-badge"><?=$ac['count']?> sân</span>
                </div>

                <div class="dist-highlight">🏟 <?=e($ac['highlight'])?></div>
                <small class="dist-sub-info"><?=e($ac['sub_info'])?></small>

                <div class="dist-card-meta">
                  <span class="dist-price">Từ <b><?=money($ac['min_price'])?></b>/h</span>
                  <span class="dist-km-pill" id="areaCardKm_<?=(int)$ac['focus_fid']?>" style="display:none"></span>
                  <span class="dist-go-text">Khám phá →</span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- DẢI 6 ĐIỂM DỪNG SÂN BÓNG NỔI TIẾNG NHẤT TP.HCM (ĐỒNG BỘ TRỰC TIẾP VỚI BẢN ĐỒ) -->
      <div class="hp-stops-showcase">
        <div class="stops-showcase-head">
          <div>
            <b>🏆 Hành Trình 6 Điểm Dừng Sân Bóng Nổi Tiếng TP.HCM</b>
            <small>Chạm vào bất kỳ điểm dừng nào để định vị ngay trên bản đồ khu vực, xem chỉ đường hoặc đặt lịch</small>
          </div>
          <span class="stops-count-tag">6 Điểm dừng thực tế</span>
        </div>

        <div class="stops-showcase-grid" id="famousStopsGrid">
          <?php foreach ($famousStops as $stItem):
              $stGmap = "https://www.google.com/maps/dir/?api=1&destination={$stItem['lat']},{$stItem['lng']}";
          ?>
            <div class="stop-item-card"
                 id="stopCard_<?=(int)$stItem['field_id']?>"
                 data-fid="<?=(int)$stItem['field_id']?>"
                 data-lat="<?=$stItem['lat']?>"
                 data-lng="<?=$stItem['lng']?>"
                 onmouseenter="focusAreaPitchOnMap(<?=(int)$stItem['field_id']?>, 'Điểm dừng <?=$stItem['stop_no']?>: <?=e($stItem['name'])?>', false)"
                 onclick="focusAreaPitchOnMap(<?=(int)$stItem['field_id']?>, 'Điểm dừng <?=$stItem['stop_no']?>: <?=e($stItem['name'])?>', true)">
              <div class="sic-top">
                <span class="sic-badge">Điểm dừng <?=$stItem['stop_no']?></span>
                <div style="display:flex;align-items:center;gap:5px">
                  <span class="sic-km" id="stopKm_<?=(int)$stItem['field_id']?>" style="display:none"></span>
                  <span class="sic-rating">⭐ <?=e($stItem['rating_txt'])?></span>
                </div>
              </div>

              <b class="sic-name"><?=e($stItem['name'])?></b>
              <div class="sic-addr">📍 <?=e($stItem['address'])?></div>
              <div class="sic-contact"><?=e($stItem['contact'])?> • <span style="color:#16a34a;font-weight:800">Từ <?=money($stItem['price'])?>/h</span></div>
              <p class="sic-scale"><?=e($stItem['scale'])?></p>

              <div class="sic-actions" onclick="event.stopPropagation()">
                <button type="button"
                        class="sic-btn map"
                        onclick="focusAreaPitchOnMap(<?=(int)$stItem['field_id']?>, 'Điểm dừng <?=$stItem['stop_no']?>: <?=e($stItem['name'])?>', true)">
                  📍 Trên bản đồ
                </button>
                <a class="sic-btn dir" href="<?=e($stGmap)?>" target="_blank" rel="noopener">🧭 Chỉ đường</a>
                <a class="sic-btn book" href="user/booking.php?field_id=<?=(int)$stItem['field_id']?>">⚡ Đặt sân</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ======================================================================
         7.2C. PHẦN 2: DÀNH CHO CHỦ SÂN (QUẢN LÝ SÂN) + ĐẶT SÂN NHANH GIỜ VÀNG
         ====================================================================== -->
    <section class="hp-owner-section" id="for-owners">
      <h3 class="hp-owner-top-title">Dành Cho Chủ Sân (Quản Lý Sân)</h3>

      <div class="hp-owner-grid">
        <!-- Cột trái: Văn bản giới thiệu + 3 Tab tính năng tương tác + Nút xanh "Đăng ký hợp tác" -->
        <div class="hp-owner-content">
          <h4 class="hp-owner-subtitle">
            Hệ thống quản lý sân tiện lợi<br>cho các chủ sân
          </h4>

          <ul class="hp-owner-bullet-list">
            <li class="owner-tab-trigger active" onclick="switchOwnerPreviewTab(1, this)">
              <span class="bullet-ic">✔</span>
              <span>Quản lý lịch đặt <small style="display:inline;color:#16a34a;font-weight:600">(Xem màn hình →)</small></span>
            </li>
            <li class="owner-tab-trigger" onclick="switchOwnerPreviewTab(2, this)">
              <span class="bullet-ic">✔</span>
              <span>Doanh thu, khách hàng <small style="display:inline;color:#64748b;font-weight:600">(Xem biểu đồ →)</small></span>
            </li>
            <li class="owner-tab-trigger" onclick="switchOwnerPreviewTab(3, this)">
              <span class="bullet-ic">✔</span>
              <span>Khách hàng, nhân viên <small style="display:inline;color:#64748b;font-weight:600">(Xem đội bóng →)</small></span>
            </li>
          </ul>

          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button type="button" class="hp-owner-coop-btn" onclick="openPartnerRegisterModal()" style="border:0;cursor:pointer">
              Đăng ký hợp tác
            </button>
            <?php if ($u && $u['role'] === 'admin'): ?>
              <a href="admin/dashboard.php" class="hp-owner-admin-link">⚙️ Vào trang Quản trị →</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Cột phải: Bộ 3 thiết bị (Màn hình máy tính bàn iMac + Laptop + Điện thoại) chuyển đổi nội dung theo Tab -->
        <div class="hp-owner-devices">
          <!-- 1. Màn hình máy tính lớn ở giữa (Desktop Monitor) -->
          <div class="dev-monitor">
            <div class="mon-bezel">
              <div class="mon-screen">
                <!-- Sidebar xanh đen bên trái màn hình quản lý -->
                <div class="mon-sidebar">
                  <div class="ms-logo">⚽ Sân Bóng Pro</div>
                  <div class="ms-item on" id="msNav1" onclick="switchOwnerPreviewTab(1)" style="cursor:pointer">📅 Lịch đặt sân</div>
                  <div class="ms-item" id="msNav2" onclick="switchOwnerPreviewTab(2)" style="cursor:pointer">💰 Doanh thu</div>
                  <div class="ms-item" id="msNav3" onclick="switchOwnerPreviewTab(3)" style="cursor:pointer">👥 Khách hàng</div>
                  <div class="ms-item" onclick="openPartnerRegisterModal()" style="cursor:pointer">🏟 + Thêm sân</div>
                </div>

                <!-- TAB 1: Quản lý lịch đặt sân -->
                <div class="mon-main" id="monTab1">
                  <div class="mm-top">
                    <b>Bảng Điều Khiển Lịch Đặt Sân</b>
                    <a href="user/booking.php" class="mm-pill" style="text-decoration:none">+ Tạo lịch mới</a>
                  </div>
                  <div class="mm-stats">
                    <div class="mm-stat"><span>Tổng sân mở</span><b><?=$totalActiveFields?> sân</b></div>
                    <div class="mm-stat"><span>Đang trống</span><b style="color:#16a34a"><?=$freeNowCount?> sân</b></div>
                    <div class="mm-stat"><span>Kèo đang mở</span><b><?=count($openMatches)?> kèo</b></div>
                  </div>
                  <div class="mm-table">
                    <div class="mm-row head"><span>Sân</span><span>Khung giờ</span><span>Trạng thái</span></div>
                    <div class="mm-row"><span>Sân Minh Phát</span><span>17:30 - 19:00</span><i class="st-ok">Đã xác nhận</i></div>
                    <div class="mm-row"><span>Sân Phú Lợi</span><span>18:00 - 19:30</span><i class="st-ok">Đã thanh toán</i></div>
                    <div class="mm-row"><span>Sân Galaxy</span><span>19:00 - 20:30</span><i class="st-wait">Giữ chỗ 10p</i></div>
                    <div class="mm-row"><span>Sân K34 Sport</span><span>20:00 - 21:30</span><i class="st-ok">Cố định tuần</i></div>
                  </div>
                </div>

                <!-- TAB 2: Doanh thu & Khách hàng -->
                <div class="mon-main" id="monTab2" style="display:none">
                  <div class="mm-top">
                    <b>Thống Kê Doanh Thu & Đối Soát QR</b>
                    <span class="mm-pill">VietQR 24/7</span>
                  </div>
                  <div class="mm-stats">
                    <div class="mm-stat"><span>Tháng này</span><b style="color:#16a34a">48.5M</b></div>
                    <div class="mm-stat"><span>Tỷ lệ lấp đầy</span><b>92%</b></div>
                    <div class="mm-stat"><span>Voucher mở</span><b><?=$voucherCount?> mã</b></div>
                  </div>
                  <div class="mm-table" style="padding:6px">
                    <div style="font-size:8px;font-weight:700;color:#475569;margin-bottom:4px">Biểu đồ doanh thu các ngày trong tuần:</div>
                    <div style="display:flex;align-items:flex-end;gap:6px;height:52px;padding-top:4px">
                      <div style="flex:1;background:#bbf7d0;height:45%;border-radius:3px 3px 0 0" title="Thứ 2"></div>
                      <div style="flex:1;background:#86efac;height:60%;border-radius:3px 3px 0 0" title="Thứ 3"></div>
                      <div style="flex:1;background:#86efac;height:55%;border-radius:3px 3px 0 0" title="Thứ 4"></div>
                      <div style="flex:1;background:#4ade80;height:75%;border-radius:3px 3px 0 0" title="Thứ 5"></div>
                      <div style="flex:1;background:#22c55e;height:85%;border-radius:3px 3px 0 0" title="Thứ 6"></div>
                      <div style="flex:1;background:#16a34a;height:100%;border-radius:3px 3px 0 0" title="Thứ 7"></div>
                      <div style="flex:1;background:#15803d;height:95%;border-radius:3px 3px 0 0" title="Chủ nhật"></div>
                    </div>
                  </div>
                </div>

                <!-- TAB 3: Khách hàng & Nhân viên -->
                <div class="mon-main" id="monTab3" style="display:none">
                  <div class="mm-top">
                    <b>Đội Bóng Cố Định & Nhân Viên Trực</b>
                    <span class="mm-pill">18 Đội FC</span>
                  </div>
                  <div class="mm-table">
                    <div class="mm-row head"><span>Đội bóng / Nhân sự</span><span>Lịch cố định</span><span>Ưu đãi</span></div>
                    <div class="mm-row"><span>FC Anh Em Q1</span><span>T3 • 19:00-20:30</span><i class="st-ok">-10% HĐ</i></div>
                    <div class="mm-row"><span>Thủ Đức United</span><span>T5 • 18:00-19:30</span><i class="st-ok">-10% HĐ</i></div>
                    <div class="mm-row"><span>FC Bình Thạnh</span><span>T7 • 17:30-19:00</span><i class="st-ok">-10% HĐ</i></div>
                    <div class="mm-row"><span>NV: Trần Văn B</span><span>Ca tối 17h-23h</span><i class="st-wait">Đang trực</i></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="mon-chin"></div>
            <div class="mon-stand"></div>
            <div class="mon-foot"></div>
          </div>

          <!-- 2. Laptop nằm đè góc dưới bên trái -->
          <div class="dev-laptop">
            <div class="lap-screen">
              <div class="lap-side"></div>
              <div class="lap-content">
                <div class="lap-head">📈 Báo cáo doanh thu & lịch</div>
                <div class="lap-grid">
                  <span class="g"></span><span class="y"></span><span class="g"></span>
                  <span class="b"></span><span class="g"></span><span class="r"></span>
                </div>
              </div>
            </div>
            <div class="lap-base"></div>
          </div>

          <!-- 3. Điện thoại nằm đè góc dưới bên phải -->
          <div class="dev-phone">
            <div class="ph-notch-bar"></div>
            <div class="ph-body">
              <div class="ph-top-title">Lịch đặt hôm nay</div>
              <div class="ph-row-item"><span class="dot g"></span><span>Sân 5A • 17:30</span></div>
              <div class="ph-row-item"><span class="dot g"></span><span>Sân 7A • 19:00</span></div>
              <div class="ph-row-item"><span class="dot y"></span><span>Sân 5B • 20:30</span></div>
              <div class="ph-btn-mini">✔ Quét QR Vé</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Khối nền cỏ xanh bên dưới: "Đặt Sân Nhanh Theo Giờ Vàng" (Kết nối dữ liệu sân trống thực tế) -->
      <div class="hp-golden-quick-box">
        <div class="golden-head">
          <h4>Đặt Sân Nhanh Theo Giờ Vàng</h4>
          <small>
            Danh sách các ca trống ngày <b><?=date('d/m/Y', strtotime($targetGoldenDate))?></b>
            <?=$dist ? 'tại <b>' . e($dist) . '</b>' : 'các sân gần bạn'?> — Chọn sân và bấm Đặt gấp để áp mã giảm giá!
          </small>
        </div>

        <div class="golden-rows">
          <?php foreach ($goldenSlotsData as $gIdx => $gs): ?>
            <div class="golden-bar-item">
              <div class="gb-time-wrap">
                <b class="gb-time"><?=e($gs['label'])?></b>
                <small class="gb-sub-free">🟢 Còn <?=$gs['free_count']?> sân đang trống ca này</small>
              </div>

              <!-- Ô chọn nhanh sân bóng đang trống trong ca Giờ Vàng này -->
              <div class="gb-select-wrap">
                <?php if ($gs['free_fields']): ?>
                  <select class="gb-field-select" id="goldenFieldSelect_<?=$gIdx?>" aria-label="Chọn sân gần bạn">
                    <?php foreach ($gs['free_fields'] as $ff): ?>
                      <option value="<?=(int)$ff['id']?>">
                        🏟 <?=e($ff['name'])?> (<?=e($ff['district'])?> • Sân <?=$ff['type']?> • <?=money($ff['price_per_hour'])?>/h)
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <span class="gb-near" style="color:#dc2626">Tạm kín sân ca này</span>
                <?php endif; ?>
              </div>

              <span class="gb-discount">Đã giảm sâu (-10% SANBONG10)</span>

              <?php if ($gs['free_fields']): ?>
                <button type="button"
                        class="gb-btn"
                        onclick="bookGoldenSlotNow(<?=$gIdx?>, '<?=e($targetGoldenDate)?>', '<?=$gs['start']?>', '<?=$gs['end']?>')">
                  Đặt gấp
                </button>
              <?php else: ?>
                <a class="gb-btn" style="background:#64748b" href="user/booking.php?date=<?=urlencode($targetGoldenDate)?>">Xem giờ khác</a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ======================================================================
         MODAL ĐĂNG KÝ HỢP TÁC CHỦ SÂN (PARTNER PITCH REGISTRATION MODAL)
         ====================================================================== -->
    <div id="partnerRegisterModal" class="partner-modal-backdrop" onclick="if(event.target===this) closePartnerRegisterModal()">
      <div class="partner-modal-card">
        <div class="partner-modal-head">
          <div>
            <b>🤝 Đăng Ký Hợp Tác Chủ Sân — Sân Bóng Pro</b>
            <small>Đưa cụm sân bóng của bạn lên bản đồ trực tuyến & nhận lịch đặt tự động 24/7</small>
          </div>
          <button type="button" class="partner-close-btn" onclick="closePartnerRegisterModal()">✕</button>
        </div>

        <form method="post" action="index.php" class="partner-modal-form">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="partner_register">

          <div class="pm-grid-2">
            <div>
              <label>🏟 Tên cụm sân bóng *</label>
              <input type="text" name="field_name" required placeholder="VD: Sân Bóng Chảo Lửa">
            </div>
            <div>
              <label>📍 Khu vực (Quận / Huyện) *</label>
              <select name="district" required>
                <?php foreach (['Tân Bình', 'Bình Thạnh', 'Quận 11', 'Quận 1', 'Quận 7', 'Thủ Đức', 'Gò Vấp', 'Quận 12'] as $optD): ?>
                  <option value="<?=e($optD)?>" <?=$dist === $optD ? 'selected' : ''?>><?=e($optD)?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="pm-grid-2">
            <div>
              <label>⚽ Loại sân chính *</label>
              <select name="type">
                <option value="5">Sân 5 người (Chuẩn FIFA Mini)</option>
                <option value="7">Sân 7 người (Phong trào)</option>
                <option value="11">Sân 11 người (Tiêu chuẩn)</option>
              </select>
            </div>
            <div>
              <label>💰 Giá thuê tiêu chuẩn (VNĐ/giờ) *</label>
              <input type="number" name="price_per_hour" min="80000" step="10000" value="140000" required>
            </div>
          </div>

          <div>
            <label>🗺 Địa chỉ chi tiết cụm sân *</label>
            <input type="text" name="address" required placeholder="VD: 38 Phan Thúc Duyện, Quận Tân Bình, TP.HCM">
          </div>

          <div class="pm-grid-2">
            <div>
              <label>👤 Họ tên Chủ sân / Quản lý *</label>
              <input type="text" name="owner_name" required value="<?=e($u['full_name'] ?? '')?>" placeholder="Nhập họ tên của bạn">
            </div>
            <div>
              <label>📞 Số điện thoại Hotline *</label>
              <input type="text" name="owner_phone" required value="<?=e($u['phone'] ?? '')?>" placeholder="VD: 0901234567">
            </div>
          </div>

          <div class="pm-foot">
            <button type="button" class="btn gray sm" onclick="closePartnerRegisterModal()">Đóng</button>
            <button type="submit" class="btn sm" style="background:#2e7d32;padding:10px 20px">🚀 Kích hoạt & Đưa sân lên hệ thống</button>
          </div>
        </form>
      </div>
    </div>

    <?php
    // Chia danh sách sân thành 3 sân nổi bật đầu tiên và các sân khám phá thêm bên dưới
    $featuredFields = $filtered ? $fields : array_slice($fields, 0, 3);
    $moreFields     = $filtered ? [] : array_slice($fields, 3, 7);

    // Chuẩn bị mảng dữ liệu JSON của toàn bộ các cụm sân cho Bản đồ Leaflet.js trong mục "Tìm Sân Theo Khu Vực"
    $allMapFields = $pdo->query("SELECT * FROM fields WHERE status = 'active' ORDER BY id ASC")->fetchAll();
    $leafletPitchesJson = [];
    foreach ($allMapFields as $fpItem) {
        $latVal = (float)($fpItem['latitude'] ?? 10.7751200) ?: 10.7751200;
        $lngVal = (float)($fpItem['longitude'] ?? 106.6928500) ?: 106.6928500;
        $stopBadge = ($fpItem['id'] >= 1 && $fpItem['id'] <= 6) ? ('Điểm dừng ' . (int)$fpItem['id']) : $fpItem['district'];
        $leafletPitchesJson[] = [
            'id'           => (int)$fpItem['id'],
            'stop_badge'   => $stopBadge,
            'name'         => $fpItem['name'],
            'district'     => $fpItem['district'],
            'address'      => $fpItem['address'],
            'lat'          => $latVal,
            'lng'          => $lngVal,
            'type'         => $fpItem['type'],
            'sub_pitches'  => $fpItem['sub_pitches'] ?? '4 sân 5 người, 2 sân 7 người',
            'normal_price' => money($fpItem['normal_price'] ?? $fpItem['price_per_hour']),
            'peak_price'   => money($fpItem['peak_price'] ?? round($fpItem['price_per_hour'] * 1.6)),
            'phone'        => $fpItem['contact_phone'] ?? '0901.234.567',
            'rating'       => $fpItem['rating'],
            'image'        => img_src($fpItem['image']),
            'detail_url'   => 'user/field-detail.php?id=' . (int)$fpItem['id'],
            'book_url'     => 'user/booking.php?field_id=' . (int)$fpItem['id'],
            'gmap_url'     => "https://www.google.com/maps/dir/?api=1&destination={$latVal},{$lngVal}",
        ];
    }
    ?>
    <script>
      const AREA_PITCHES_DATA = <?=json_encode($leafletPitchesJson, JSON_UNESCAPED_UNICODE)?>;
      let areaLeafletMap = null;
      let pitchMarkersById = {};
      let userGeoMarker = null;

      // 1. Khởi tạo Bản đồ trực tuyến Leaflet.js ngay trong cột trái của mục "Tìm Sân Theo Khu Vực"
      document.addEventListener('DOMContentLoaded', function () {
        if (typeof L === 'undefined' || !document.getElementById('areaLeafletMap')) return;

        areaLeafletMap = L.map('areaLeafletMap', { zoomControl: true }).setView([10.788, 106.685], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '© OpenStreetMap'
        }).addTo(areaLeafletMap);

        const bounds = [];
        AREA_PITCHES_DATA.forEach(function (p) {
          const isFamousStop = p.id >= 1 && p.id <= 6;
          const pinText = isFamousStop ? `ĐD ${p.id} • ${p.normal_price}` : p.normal_price;
          const markerIcon = L.divIcon({
            className: 'sb-leaflet-pin',
            html: `<div class="sb-pin-bubble ${isFamousStop ? 'famous' : ''}" id="pinBubble_${p.id}"><span>⚽</span><b>${pinText}</b></div>`,
            iconSize: [108, 32],
            iconAnchor: [54, 32],
            popupAnchor: [0, -30]
          });

          const popupHtml = `
            <div class="sb-map-popup">
              <div class="smp-head">
                <img src="${p.image}" alt="">
                <div>
                  <span class="smp-open">🟢 ${p.stop_badge} • Đang mở cửa</span>
                  <b>${p.name}</b>
                </div>
              </div>
              <div class="smp-body">
                <div>📍 <b>Địa chỉ:</b> ${p.address}</div>
                <div>🏟 <b>Quy mô:</b> ${p.sub_pitches}</div>
                <div>💰 <b>Giá thuê:</b> <span style="color:#16a34a;font-weight:800">Từ ${p.normal_price}/giờ</span> (Giờ vàng: ${p.peak_price}/h)</div>
                <div>📞 <b>Liên hệ:</b> ${p.phone} • ⭐ ${p.rating}</div>
              </div>
              <div class="smp-actions">
                <a href="${p.detail_url}" class="smp-btn outline">Xem chi tiết</a>
                <a href="${p.book_url}" class="smp-btn primary">Đặt sân →</a>
                <a href="${p.gmap_url}" target="_blank" rel="noopener" class="smp-btn dir">🧭 Chỉ đường</a>
              </div>
            </div>
          `;

          const mk = L.marker([p.lat, p.lng], { icon: markerIcon }).addTo(areaLeafletMap).bindPopup(popupHtml, { maxWidth: 300 });
          mk.on('click', function () {
            highlightStopAndAreaCard(p.id, p.name + ' (' + p.district + ')');
          });
          pitchMarkersById[p.id] = { marker: mk, data: p };
          bounds.push([p.lat, p.lng]);
        });

        if (bounds.length > 1) {
          areaLeafletMap.fitBounds(bounds, { padding: [26, 26] });
        } else if (bounds.length === 1) {
          areaLeafletMap.setView(bounds[0], 14);
        }
      });

      // 2. Đồng bộ khi người dùng rê chuột hoặc bấm vào thẻ Khu vực / Điểm dừng -> Bản đồ bay (flyTo) tới sân và mở Popup
      function focusAreaPitchOnMap(fid, labelText, openPopupNow) {
        var lbl = document.getElementById('areaMapStatusLabel');
        if (lbl && labelText) {
          lbl.textContent = '📍 ' + labelText;
        }
        highlightStopAndAreaCard(fid, labelText);

        if (!areaLeafletMap || !pitchMarkersById[fid]) return;
        const item = pitchMarkersById[fid];
        areaLeafletMap.flyTo([item.data.lat, item.data.lng], openPopupNow ? 15 : 14, {
          duration: 0.55
        });
        if (openPopupNow) {
          item.marker.openPopup();
        }
      }

      function highlightStopAndAreaCard(fid, labelText) {
        document.querySelectorAll('.stop-item-card').forEach(function (c) {
          c.classList.toggle('active', parseInt(c.dataset.fid, 10) === fid);
        });
        var lbl = document.getElementById('areaMapStatusLabel');
        if (lbl && labelText) {
          lbl.textContent = '📍 ' + labelText;
        }
      }

      function resetAreaLeafletMap() {
        if (!areaLeafletMap || AREA_PITCHES_DATA.length === 0) return;
        const b = AREA_PITCHES_DATA.map(p => [p.lat, p.lng]);
        areaLeafletMap.fitBounds(b, { padding: [26, 26] });
        var lbl = document.getElementById('areaMapStatusLabel');
        if (lbl) lbl.textContent = '📍 Toàn TP. Hồ Chí Minh (' + AREA_PITCHES_DATA.length + ' cụm sân)';
      }

      // 3. Công thức Haversine tính khoảng cách đường chim bay (km) giữa 2 tọa độ GPS
      function calcHaversineKm(lat1, lon1, lat2, lon2) {
        const R = 6371;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a =
          Math.sin(dLat / 2) * Math.sin(dLat / 2) +
          Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
          Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
      }

      // 4. Chức năng "Tìm sân gần tôi" bằng Geolocation API + Sắp xếp 6 Điểm dừng & Khu vực từ gần đến xa
      function findNearestPitches() {
        const btn = document.getElementById('btnFindNearMe');
        const banner = document.getElementById('geoStatusBanner');
        if (btn) btn.textContent = '⏳ Đang định vị GPS...';

        const applyUserCoordinates = function (userLat, userLng, isFallback) {
          if (btn) btn.textContent = '📍 Đã sắp xếp sân gần bạn';
          if (banner) {
            banner.style.display = 'flex';
            banner.innerHTML = isFallback
              ? `<span>📍 Đang tính khoảng cách từ khu vực trung tâm TP.HCM (<b>${userLat.toFixed(4)}, ${userLng.toFixed(4)}</b>) — Các khu vực & 6 Điểm dừng đã được sắp xếp từ gần đến xa!</span>`
              : `<span>📍 Đã xác định vị trí của bạn (<b>${userLat.toFixed(4)}, ${userLng.toFixed(4)}</b>) — Các khu vực & 6 Điểm dừng đã được sắp xếp từ gần đến xa!</span>`;
          }

          if (areaLeafletMap) {
            if (userGeoMarker) areaLeafletMap.removeLayer(userGeoMarker);
            const userIcon = L.divIcon({
              className: 'user-geo-pin',
              html: '<div style="width:18px;height:18px;background:#2563eb;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 6px rgba(37,99,235,0.28)"></div>',
              iconSize: [18, 18],
              iconAnchor: [9, 9]
            });
            userGeoMarker = L.marker([userLat, userLng], { icon: userIcon })
              .addTo(areaLeafletMap)
              .bindPopup('<b>📍 Vị trí hiện tại của bạn</b>');
          }

          // Sắp xếp lưới 6 thẻ Khu vực theo khoảng cách gần nhất
          const distGrid = document.getElementById('areaDistrictGrid');
          if (distGrid) {
            const dCards = Array.from(distGrid.querySelectorAll('.hp-district-card'));
            dCards.forEach(function (card) {
              const km = calcHaversineKm(userLat, userLng, parseFloat(card.dataset.lat), parseFloat(card.dataset.lng));
              card.dataset.distKm = km.toFixed(2);
              const badge = document.getElementById('areaCardKm_' + card.dataset.fid);
              if (badge) {
                badge.style.display = 'inline-block';
                badge.textContent = `📍 ${km.toFixed(1)} km`;
              }
            });
            dCards.sort((a, b) => parseFloat(a.dataset.distKm) - parseFloat(b.dataset.distKm));
            dCards.forEach(c => distGrid.appendChild(c));
          }

          // Sắp xếp dải 6 Điểm dừng sân bóng nổi tiếng từ gần đến xa
          const stopsGrid = document.getElementById('famousStopsGrid');
          if (stopsGrid) {
            const sCards = Array.from(stopsGrid.querySelectorAll('.stop-item-card'));
            sCards.forEach(function (card) {
              const km = calcHaversineKm(userLat, userLng, parseFloat(card.dataset.lat), parseFloat(card.dataset.lng));
              card.dataset.distKm = km.toFixed(2);
              const badge = document.getElementById('stopKm_' + card.dataset.fid);
              if (badge) {
                badge.style.display = 'inline-block';
                badge.textContent = `📍 Cách ${km.toFixed(1)} km`;
              }
            });
            sCards.sort((a, b) => parseFloat(a.dataset.distKm) - parseFloat(b.dataset.distKm));
            sCards.forEach(c => stopsGrid.appendChild(c));

            if (sCards[0]) {
              const nearestFid = parseInt(sCards[0].dataset.fid, 10);
              setTimeout(() => focusAreaPitchOnMap(nearestFid, 'Sân gần bạn nhất', true), 300);
            }
          }
        };

        if (!navigator.geolocation) {
          applyUserCoordinates(10.7850, 106.6850, true);
          return;
        }

        navigator.geolocation.getCurrentPosition(
          function (pos) {
            applyUserCoordinates(pos.coords.latitude, pos.coords.longitude, false);
          },
          function () {
            applyUserCoordinates(10.7850, 106.6850, true);
          },
          { enableHighAccuracy: true, timeout: 6000 }
        );
      }

      // 5. Chuyển đổi 3 Tab màn hình quản lý trong phần "Dành Cho Chủ Sân"
      function switchOwnerPreviewTab(tabNum, clickedLi) {
        [1, 2, 3].forEach(function (n) {
          var pane = document.getElementById('monTab' + n);
          var nav = document.getElementById('msNav' + n);
          if (pane) pane.style.display = (n === tabNum) ? 'block' : 'none';
          if (nav) nav.classList.toggle('on', n === tabNum);
        });
        if (clickedLi) {
          document.querySelectorAll('.owner-tab-trigger').forEach(function (el) {
            el.classList.remove('active');
          });
          clickedLi.classList.add('active');
        }
      }

      // 6. Mở / Đóng Modal Đăng ký hợp tác Chủ sân
      function openPartnerRegisterModal() {
        var m = document.getElementById('partnerRegisterModal');
        if (m) m.classList.add('open');
      }
      function closePartnerRegisterModal() {
        var m = document.getElementById('partnerRegisterModal');
        if (m) m.classList.remove('open');
      }

      // 7. Xử lý nút "Đặt gấp" trong phần Đặt Sân Nhanh Theo Giờ Vàng
      function bookGoldenSlotNow(slotIdx, dateStr, startStr, endStr) {
        var sel = document.getElementById('goldenFieldSelect_' + slotIdx);
        var fid = sel ? sel.value : 1;
        window.location.href = 'user/booking.php?field_id=' + encodeURIComponent(fid)
          + '&date=' + encodeURIComponent(dateStr)
          + '&start=' + encodeURIComponent(startStr)
          + '&end=' + encodeURIComponent(endStr)
          + '&voucher=SANBONG10';
      }
    </script>

    <!-- 7.3. Khu vực: Sân nổi bật gần bạn (3 cột chuẩn Screen 01 & 02) -->
    <section id="fields">
      <h3 class="hp-h">
        <span>🏟 <?=$filtered ? 'Kết quả tìm kiếm sân bóng' : 'Sân nổi bật gần bạn'?></span>
        <?php if ($filtered): ?>
          <a href="index.php">✕ Xóa bộ lọc</a>
        <?php else: ?>
          <a href="user/booking.php">Xem tất cả sân →</a>
        <?php endif; ?>
      </h3>

      <?php if (!$featuredFields): ?>
        <div class="empty">Không tìm thấy sân phù hợp với bộ lọc của bạn.</div>
      <?php endif; ?>

      <div class="hp-grid">
        <?php foreach ($featuredFields as $f): $b = in_array($f['id'], $busyNow); ?>
          <div class="hp-card">
            <div class="hp-img">
              <img src="<?=e(img_src($f['image']))?>" alt="<?=e($f['name'])?>">
              <div class="hp-top-badges">
                <span class="hp-badge-type">● Sân <?=$f['type']?> người</span>
                <span class="hp-badge-status <?=$b ? 'busy' : 'free'?>">
                  <?=$b ? 'Đang đá' : 'Đang trống'?>
                </span>
              </div>
              <span class="hp-rating-pill">⭐ <?=e($f['rating'])?></span>
            </div>

            <div class="hp-cb">
              <h4><?=e($f['name'])?></h4>
              <small>📍 <?=e($f['district'])?> • <?=e($f['address'])?></small>
              <div class="hp-card-foot">
                <div class="hp-price-inline">
                  <b><?=money($f['price_per_hour'])?></b><small>/giờ</small>
                </div>
                <div class="hp-card-btns">
                  <a class="btn-detail" href="user/field-detail.php?id=<?=$f['id']?>">Chi tiết</a>
                  <a class="btn sm" href="user/booking.php?field_id=<?=$f['id']?>">Đặt sân</a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 7.4. Hàng 2 Cột: Kèo giao hữu đang mở + Ưu đãi nổi bật (Chuẩn Screen 02) -->
    <div class="hp-split-row" id="matchmaking-preview">
      <!-- Cột trái: Kèo giao hữu đang mở -->
      <section class="hp-split-box">
        <h3 class="hp-h">
          <span>⚔️ Kèo giao hữu đang mở</span>
          <a href="user/matchmaking.php">Tất cả →</a>
        </h3>

        <?php if (!$openMatches): ?>
          <div class="empty" style="padding:18px">
            Chưa có đội bóng nào đăng kèo hôm nay.
            <a href="user/matchmaking.php"><b>+ Đăng kèo ngay</b></a>
          </div>
        <?php else: ?>
          <div class="hp-match-list">
            <?php foreach (array_slice($openMatches, 0, 2) as $m): ?>
              <div class="hp-match-item">
                <div>
                  <b><?=e($m['team_name'])?> <span class="match-sub-tag">• Sân <?=$m['ftype']?> người</span></b>
                  <small>
                    📍 <?=e($m['fname'])?> (<?=e($m['district'])?>) •
                    🕒 <?=substr($m['start_time'], 0, 5)?> - <?=date('d/m', strtotime($m['match_date']))?>
                  </small>
                </div>
                <a class="match-join-link" href="user/matchmaking.php?join=<?=$m['id']?>">Nhận kèo →</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Cột phải: Ưu đãi nổi bật -->
      <section class="hp-split-box">
        <h3 class="hp-h">
          <span>🎁 Ưu đãi nổi bật</span>
          <a href="user/booking.php">Áp mã →</a>
        </h3>

        <div class="hp-voucher-card">
          <div class="vc-ico">🏷️</div>
          <div class="vc-info">
            <b>GIẢM 20% ĐẶT SÂN MỚI</b>
            <small>Nhập mã <code>TANTHU20</code> • Giảm tối đa 50.000đ</small>
            <span class="vc-sub">Hoặc dùng mã <code>SANBONG10</code> giảm ngay 10% mọi khung giờ</span>
          </div>
          <a class="btn sm" href="user/booking.php">Dùng mã</a>
        </div>
      </section>
    </div>

    <!-- 7.5. Khu vực: Khám phá thêm sân bóng (Chuẩn Screen 02) -->
    <?php if ($moreFields): ?>
      <section>
        <h3 class="hp-h">
          <span>⚽ Khám phá thêm sân bóng</span>
          <a href="user/booking.php">Xem tất cả →</a>
        </h3>

        <div class="hp-grid">
          <?php foreach ($moreFields as $f): $b = in_array($f['id'], $busyNow); ?>
            <div class="hp-card">
              <div class="hp-img">
                <img src="<?=e(img_src($f['image']))?>" alt="<?=e($f['name'])?>">
                <div class="hp-top-badges">
                  <span class="hp-badge-type">● Sân <?=$f['type']?> người</span>
                  <span class="hp-badge-status <?=$b ? 'busy' : 'free'?>">
                    <?=$b ? 'Đang đá' : 'Đang trống'?>
                  </span>
                </div>
                <span class="hp-rating-pill">⭐ <?=e($f['rating'])?></span>
              </div>

              <div class="hp-cb">
                <h4><?=e($f['name'])?></h4>
                <small>📍 <?=e($f['district'])?> • <?=e($f['address'])?></small>
                <div class="hp-card-foot">
                  <div class="hp-price-inline">
                    <b><?=money($f['price_per_hour'])?></b><small>/giờ</small>
                  </div>
                  <div class="hp-card-btns">
                    <a class="btn-detail" href="user/field-detail.php?id=<?=$f['id']?>">Chi tiết</a>
                    <a class="btn sm" href="user/booking.php?field_id=<?=$f['id']?>">Đặt sân</a>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- 7.6. Khu vực: Vì sao chọn Sân Bóng Pro? (3 ô xanh mint chuẩn Screen 02) -->
    <section>
      <h3 class="hp-h">
        <span>✨ Vì sao chọn Sân Bóng Pro?</span>
      </h3>
      <div class="hp-why-mint-grid">
        <div class="why-mint-box">
          <b>⚡ Đặt lịch siêu tốc 30s</b>
          <small>Sơ đồ khung giờ 30 phút trực quan, khóa chéo tự động Sân 5 - Sân 7 - Sân 11 không lo trùng lịch.</small>
        </div>
        <div class="why-mint-box">
          <b>🎫 Vé điện tử & VietQR</b>
          <small>Thanh toán chuyển khoản QR tự động, cấp ngay mã vé QR Check-in chuyên nghiệp tại sân.</small>
        </div>
        <div class="why-mint-box">
          <b>⚔️ Cộng đồng Cáp Kèo sôi động</b>
          <small>Tìm đối thủ giao hữu cùng trình độ hoặc tuyển thêm cầu thủ phủi nhanh chóng mỗi ngày.</small>
        </div>
      </div>
    </section>

    <!-- 7.7. Bản Tin Sân Cỏ 24/7 (Tin tức Bóng đá trực tiếp) -->
    <section id="footballNewsBox">
      <div class="hp-h">
        <span>📰 Bản Tin Sân Cỏ 24/7 (Tin nóng Bóng đá)</span>
        <button
          type="button"
          id="btnRefreshNews"
          onclick="refreshFootballNews()"
          style="background:#ecfdf5;border:1px solid #86efac;color:#047857;font-size:12px;font-weight:700;padding:5px 11px;border-radius:8px;cursor:pointer"
        >
          🔄 Cập nhật tin mới
        </button>
      </div>
      <small id="newsStatusLabel" style="display:block;color:#64748b;margin:-8px 0 12px;font-size:12px">
        Tổng hợp tin tức thể thao bóng đá Việt Nam & Quốc tế mới nhất
      </small>

      <div id="footballNewsList" class="hp-news-grid-row">
        <?php foreach (array_slice($footballNews, 0, 3) as $item): ?>
          <a
            class="hp-news-card"
            href="<?=e($item['link'])?>"
            target="_blank"
            rel="noopener"
            onclick="return openFootballNewsModal(event, this)"
            data-title="<?=e($item['title'])?>"
            data-summary="<?=e($item['summary'])?>"
            data-image="<?=e($item['image'])?>"
            data-tag="<?=e($item['tag'])?>"
            data-time="<?=e($item['time_label'])?>"
            data-source="<?=e($item['source'])?>"
            data-link="<?=e($item['link'])?>"
          >
            <img
              src="<?=e($item['image'])?>"
              alt="<?=e($item['title'])?>"
              onerror="this.src='https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&q=80'"
            >
            <div style="flex:1;min-width:0">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:3px">
                <span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:800;padding:1px 6px;border-radius:4px">
                  <?=e($item['tag'])?>
                </span>
                <small style="margin:0;font-size:10.5px;color:#64748b"><?=e($item['time_label'])?></small>
              </div>
              <b style="font-size:12.5px;color:#0f172a;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                <?=e($item['title'])?>
              </b>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Modal Đọc Nhanh Bản Tin Bóng Đá -->
    <div
      id="footballNewsModal"
      onclick="if(event.target===this)closeFootballNewsModal()"
      style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:9995;place-items:center;padding:16px"
    >
      <div style="background:#fff;max-width:520px;width:100%;border-radius:18px;overflow:hidden;border:1px solid #cbd5e1;box-shadow:0 24px 50px rgba(0,0,0,0.25)">
        <div style="position:relative;height:210px;background:#0f172a">
          <img id="fnModalImg" src="" alt="" style="width:100%;height:100%;object-fit:cover">
          <span
            id="fnModalTag"
            style="position:absolute;top:14px;left:14px;background:#16a34a;color:#fff;font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:6px"
          ></span>
          <button
            type="button"
            onclick="closeFootballNewsModal()"
            style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:0;background:rgba(15,23,42,0.8);color:#fff;font-size:15px;cursor:pointer"
          >✕</button>
        </div>
        <div style="padding:20px">
          <small id="fnModalMeta" style="color:#16a34a;font-weight:700;margin:0 0 6px"></small>
          <h3 id="fnModalTitle" style="margin:0 0 10px;font-size:18px;line-height:1.35;color:#0f172a"></h3>
          <p id="fnModalSummary" style="margin:0 0 18px;color:#334155;font-size:13.5px;line-height:1.6"></p>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
            <button type="button" class="btn sm outline" onclick="closeFootballNewsModal()">Đóng</button>
            <a id="fnModalLink" href="#" target="_blank" rel="noopener" class="btn sm">
              📰 Đọc bài gốc →
            </a>
          </div>
        </div>
      </div>
    </div>

    <script>
      function openFootballNewsModal(e, el) {
        if (e) e.preventDefault();
        var modal = document.getElementById('footballNewsModal');
        if (!modal) return true;
        document.getElementById('fnModalImg').src = el.getAttribute('data-image') || '';
        document.getElementById('fnModalTag').textContent = el.getAttribute('data-tag') || '⚽ Bóng đá';
        document.getElementById('fnModalMeta').textContent =
          '🕒 ' + (el.getAttribute('data-time') || 'Vừa xong') + ' • Nguồn: ' + (el.getAttribute('data-source') || 'Thể Thao 24/7');
        document.getElementById('fnModalTitle').textContent = el.getAttribute('data-title') || '';
        document.getElementById('fnModalSummary').textContent = el.getAttribute('data-summary') || '';
        document.getElementById('fnModalLink').href = el.getAttribute('data-link') || '#';
        modal.style.display = 'grid';
        return false;
      }

      function closeFootballNewsModal() {
        var modal = document.getElementById('footballNewsModal');
        if (modal) modal.style.display = 'none';
      }

      function refreshFootballNews() {
        var btn = document.getElementById('btnRefreshNews');
        var list = document.getElementById('footballNewsList');
        var label = document.getElementById('newsStatusLabel');
        if (!list) return;
        if (btn) btn.textContent = '⏳ Đang tải...';

        fetch('api/football_news.php?refresh=1')
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (btn) btn.textContent = '🔄 Cập nhật tin mới';
            if (!res || !res.ok || !res.items) return;
            if (label) label.textContent = 'Đã cập nhật lúc ' + res.updated_at;
            var html = '';
            res.items.slice(0, 3).forEach(function (item) {
              var safeTitle = (item.title || '').replace(/"/g, '&quot;');
              var safeSum = (item.summary || '').replace(/"/g, '&quot;');
              var safeImg = (item.image || '').replace(/"/g, '&quot;');
              var safeTag = (item.tag || '').replace(/"/g, '&quot;');
              var safeTime = (item.time_label || '').replace(/"/g, '&quot;');
              var safeSrc = (item.source || '').replace(/"/g, '&quot;');
              var safeLink = (item.link || '#').replace(/"/g, '&quot;');

              html +=
                '<a class="hp-news-card" href="' + safeLink + '" target="_blank" rel="noopener" ' +
                'onclick="return openFootballNewsModal(event, this)" ' +
                'data-title="' + safeTitle + '" data-summary="' + safeSum + '" data-image="' + safeImg + '" ' +
                'data-tag="' + safeTag + '" data-time="' + safeTime + '" data-source="' + safeSrc + '" data-link="' + safeLink + '">' +
                  '<img src="' + safeImg + '" alt="" onerror="this.src=\'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&q=80\'">' +
                  '<div style="flex:1;min-width:0">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:3px">' +
                      '<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:800;padding:1px 6px;border-radius:4px">' + safeTag + '</span>' +
                      '<small style="margin:0;font-size:10.5px;color:#64748b">' + safeTime + '</small>' +
                    '</div>' +
                    '<b style="font-size:12.5px;color:#0f172a;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">' + safeTitle + '</b>' +
                  '</div>' +
                '</a>';
            });
            list.innerHTML = html;
          })
          .catch(function () {
            if (btn) btn.textContent = '🔄 Cập nhật tin mới';
          });
      }
    </script>

  </main>

</div>

<!-- ==========================================================================
     PHẦN 9: CHÂN TRANG ĐẦY ĐỦ SÂN BÓNG PRO (FULL 4-COLUMN SPORT FOOTER)
     ========================================================================== -->
<footer class="sb-pro-footer" id="contact">
  <!-- Dải băng kêu gọi đặt sân nhanh phía trên Footer -->
  <div class="sb-foot-top-strip">
    <div class="sb-foot-top-inner">
      <div class="sb-foot-cta-text">
        <span class="cta-badge">🔥 Ưu đãi sân cỏ</span>
        <span>Nhập mã <b>SANBONG20</b> giảm ngay 20% khi đặt lịch thi đấu trực tuyến hôm nay!</span>
      </div>
      <div class="sb-foot-cta-actions">
        <a class="sb-foot-cta-btn" href="user/booking.php">⚡ Đặt sân ngay</a>
        <a class="sb-foot-cta-btn outline" href="user/matchmaking.php?view=create">+ Tạo kèo giao hữu</a>
      </div>
    </div>
  </div>

  <!-- Nội dung chính 4 cột của Footer -->
  <div class="sb-foot-main">
    <!-- Cột 1: Thương hiệu & Cam kết -->
    <div class="sb-foot-col">
      <a class="sb-foot-brand" href="index.php">
        <span class="logo-ball">⚽</span>
        <span>SÂN BÓNG</span>
        <span class="pro-pill">PRO</span>
      </a>
      <p class="sb-foot-desc">
        Nền tảng quản lý, đặt sân bóng đá nhân tạo theo sơ đồ trực quan 30 phút, đăng ký lịch cố định hàng tuần và cáp kèo tìm đối giao hữu tự động.
      </p>
      <div class="sb-foot-badges">
        <span>🌱 Cỏ chuẩn FIFA</span>
        <span>⏳ Giữ chỗ HOLD 10p</span>
        <span>🎫 Vé QR Check-in</span>
      </div>
    </div>

    <!-- Cột 2: Liên kết điều hướng nhanh -->
    <div class="sb-foot-col">
      <h4>🧭 Khám phá nhanh</h4>
      <ul class="sb-foot-links">
        <li><a href="index.php">🏠 Trang chủ & Danh sách sân</a></li>
        <li><a href="user/booking.php">📅 Đặt sân theo sơ đồ mặt sân</a></li>
        <li><a href="user/matchmaking.php">⚔️ Sàn Cáp Kèo / Tìm đối giao hữu</a></li>
        <li><a href="user/my-bookings.php">🎫 Vé điện tử & Lịch đặt của tôi</a></li>
        <li><a href="user/notifications.php">🔔 Trung tâm thông báo</a></li>
      </ul>
    </div>

    <!-- Cột 3: Thông tin liên hệ & Hỗ trợ 24/7 -->
    <div class="sb-foot-col">
      <h4>📞 Liên hệ & Hỗ trợ</h4>
      <div class="sb-foot-contact-list">
        <div class="sb-foot-contact-item">
          <span>📍</span>
          <span>Hệ thống cụm sân cỏ nhân tạo tại <b>TP. Hồ Chí Minh</b></span>
        </div>
        <div class="sb-foot-contact-item">
          <span>📞</span>
          <span>Hotline đặt sân: <b><?=e($ad['phone'] ?: '0901.234.567')?></b></span>
        </div>
        <div class="sb-foot-contact-item">
          <span>✉️</span>
          <span>Email: <b><?=e($ad['email'] ?: 'admin@sanbong.vn')?></b></span>
        </div>
        <div class="sb-foot-contact-item">
          <span>🕒</span>
          <span>Giờ mở cửa: <b>05:30 – 23:30</b> (Tất cả các ngày)</span>
        </div>
      </div>
      <button type="button" class="sb-foot-ai-btn" onclick="if(typeof toggleAiChat==='function') toggleAiChat();">
        💬 Nhờ Trợ lý AI tìm sân trống
      </button>
    </div>
  </div>

  <!-- Thanh bản quyền & Phương thức thanh toán ở đáy Footer -->
  <div class="sb-foot-bottom">
    <div class="sb-foot-bottom-inner">
      <span>© <?=date('Y')?> <b>Sân Bóng Pro</b> — Hệ thống đặt sân bóng đá trực tuyến tích hợp AI & Thanh toán QR tự động.</span>
      <div class="sb-foot-pay-tags">
        <span>🏦 VietQR 24/7</span>
        <span>📱 Ví MoMo</span>
        <span>💵 Tiền mặt tại sân</span>
      </div>
    </div>
  </div>
</footer>

<?php
if (is_file(__DIR__ . '/includes/chat_widget.php')) {
    include 'includes/chat_widget.php';
}
?>
<script src="assets/js/sport-effects.js?v=<?=filemtime(__DIR__ . '/assets/js/sport-effects.js')?>"></script>
</body>
</html>
