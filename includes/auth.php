<?php
/**
 * ============================================================================
 * FILE: includes/auth.php
 * MÔ TẢ: Thư viện lõi (Core Helper & Authentication) dùng chung cho toàn dự án.
 * CHỨC NĂNG CHÍNH:
 *   - Phần 1: Khởi tạo Session và nạp kết nối CSDL (config/database.php).
 *   - Phần 2: Các hàm bảo mật chống XSS, định dạng tiền tệ VNĐ và chống CSRF.
 *   - Phần 3: Hàm xác định đường dẫn gốc (Base URL) của dự án.
 *   - Phần 4: Tự động kiểm tra và nâng cấp cấu trúc bảng CSDL (Auto-Migration).
 *   - Phần 5: Đọc và ghi cấu hình hệ thống (bảng system_settings).
 *   - Phần 6: Xác thực người dùng đang đăng nhập và phân quyền (User / Admin).
 *   - Phần 7: Quản lý thông báo Flash, thông báo CSDL và đường dẫn hình ảnh.
 *   - Phần 8: Bộ lọc khoảng thời gian (Từ ngày - Đến ngày) cho báo cáo Admin.
 * ============================================================================
 */

// ----------------------------------------------------------------------------
// PHẦN 1: KHỞI TẠO SESSION VÀ NẠP KẾT NỐI CƠ SỞ DỮ LIỆU
// ----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// ----------------------------------------------------------------------------
// PHẦN 2: CÁC HÀM TIỆN ÍCH ĐỊNH DẠNG & BẢO MẬT (XSS, TIỀN TỆ, CSRF TOKEN)
// ----------------------------------------------------------------------------

/**
 * Hàm e(): Mã hóa ký tự đặc biệt sang HTML Entities để chống lỗ hổng XSS
 * khi in dữ liệu ra giao diện HTML.
 */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Hàm money(): Định dạng số nguyên thành chuỗi tiền tệ Việt Nam Đồng (VD: 150.000đ).
 */
function money($n) {
    return number_format((int)$n, 0, ',', '.') . 'đ';
}

/**
 * Hàm csrf_token(): Sinh hoặc lấy mã CSRF Token ngẫu nhiên lưu trong Session.
 */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Hàm csrf_field(): Tạo thẻ <input type="hidden"> chứa mã CSRF để nhúng vào các biểu mẫu <form>.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/**
 * Hàm csrf_check(): Kiểm tra tính hợp lệ của mã CSRF khi người dùng gửi biểu mẫu POST.
 */
function csrf_check() {
    $session_token = $_SESSION['csrf'] ?? '';
    $post_token    = $_POST['csrf'] ?? '';

    if (!hash_equals($session_token, $post_token)) {
        http_response_code(419);
        die('CSRF token không hợp lệ. Vui lòng tải lại trang và thử lại.');
    }
}

// ----------------------------------------------------------------------------
// PHẦN 3: HÀM LẤY ĐƯỜNG DẪN GỐC CỦA DỰ ÁN (PROJECT BASE URL)
// ----------------------------------------------------------------------------

/**
 * Hàm project_base(): Trả về đường dẫn thư mục gốc của website (VD: "/quan-ly-san-bong/").
 */
function project_base() {
    static $base = null;

    if ($base === null) {
        $folder = basename(dirname(__DIR__));
        $base   = '/' . $folder . '/';
    }

    return $base;
}

// ----------------------------------------------------------------------------
// PHẦN 4: TỰ ĐỘNG KIỂM TRA VÀ NÂNG CẤP CẤU TRÚC CSDL (AUTO-MIGRATION)
// ----------------------------------------------------------------------------

/**
 * Hàm ensure_upgraded_schema(): Tự động tạo các bảng và cột bổ sung nếu CSDL
 * hiện tại chưa có (system_settings, ai_chat_logs, reviews, match_requests).
 */
function ensure_upgraded_schema() {
    global $pdo;
    static $checked = false;

    // Chỉ kiểm tra duy nhất 1 lần trong mỗi vòng đời request
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        // 4.1. Bảng lưu cấu hình hệ thống (API Key AI, tài khoản VietQR...)
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // 4.2. Bảng lưu lịch sử hội thoại với Trợ lý AI
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_chat_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            session_id VARCHAR(100) NOT NULL DEFAULT '',
            role ENUM('user','assistant') NOT NULL DEFAULT 'user',
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_session(session_id)
        ) ENGINE=InnoDB");

        // 4.3. Bảng lưu đánh giá và chấm sao sân bóng của khách hàng
        $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            field_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
            comment TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reviews_field(field_id)
        ) ENGINE=InnoDB");

        // 4.4. Bảng lưu danh sách kèo giao hữu / tìm đối trên Sàn Cáp Kèo
        $pdo->exec("CREATE TABLE IF NOT EXISTS match_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NULL,
            creator_id INT UNSIGNED NOT NULL,
            field_id INT UNSIGNED NOT NULL,
            team_name VARCHAR(120) NOT NULL,
            match_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            skill_level ENUM('vui_ve','phong_trao','ban_chuyen') NOT NULL DEFAULT 'phong_trao',
            match_type ENUM('tim_doi','tuyen_nguoi') NOT NULL DEFAULT 'tim_doi',
            spots_needed TINYINT UNSIGNED NOT NULL DEFAULT 1,
            fee_share VARCHAR(80) NOT NULL DEFAULT 'Chia đôi 50/50',
            contact_phone VARCHAR(30) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            opponent_id INT UNSIGNED NULL,
            opponent_name VARCHAR(120) NULL,
            opponent_phone VARCHAR(30) NULL,
            status ENUM('open','matched','cancelled') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_match_date_status(match_date, status)
        ) ENGINE=InnoDB");

        // 4.5. Tự động thêm dữ liệu mẫu cho Sàn Cáp Kèo nếu bảng vừa tạo đang trống
        $mc = (int)$pdo->query("SELECT COUNT(*) FROM match_requests")->fetchColumn();
        if ($mc === 0) {
            $pdo->exec("INSERT INTO match_requests (
                creator_id, field_id, team_name, match_date, start_time, end_time,
                skill_level, match_type, spots_needed, fee_share, contact_phone, note, status
            ) VALUES
            (2, 1, 'FC Anh Em Quận 1', CURDATE(), '18:00:00', '19:00:00', 'phong_trao', 'tim_doi', 1, 'Chia đôi 50/50', '0912345678', 'Tìm đối văn minh, đá vui vẻ rèn luyện sức khỏe sau giờ làm!', 'open'),
            (3, 2, 'Thủ Đức United', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '19:00:00', '21:00:00', 'ban_chuyen', 'tim_doi', 1, 'Thua trả 70 / Thắng 30', '0987654321', 'Đội trẻ chạy khỏe, cần tìm đối cứng giao lưu cọ xát sân 7.', 'open'),
            (2, 3, 'FC Văn Phòng Bình Thạnh', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '17:00:00', '18:00:00', 'vui_ve', 'tuyen_nguoi', 3, 'Góp 35k/người (Free trà đá)', '0912345678', 'Đội thiếu 3 anh em đá vị trí tiền vệ/hậu vệ, hoan nghênh anh em phủi ghép đội!', 'open')");
        }

        // 4.6. Bổ sung cột transaction_code và các phương thức thanh toán mới vào bảng payments
        $cols = $pdo->query("SHOW COLUMNS FROM payments LIKE 'transaction_code'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN transaction_code VARCHAR(80) NULL AFTER amount");
            $pdo->exec("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank','transfer','qr','momo','vnpay') NOT NULL DEFAULT 'cash'");
        }

        // 4.7. Bổ sung cột note (ghi chú đơn đặt sân) vào bảng bookings
        $bcols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'note'")->fetch();
        if (!$bcols) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT '' AFTER voucher_id");
        }

        // 4.8. Bổ sung các cột Bản đồ GPS & Cơ cấu cụm sân thực tế vào bảng fields
        $fLatCol = $pdo->query("SHOW COLUMNS FROM fields LIKE 'latitude'")->fetch();
        if (!$fLatCol) {
            $pdo->exec("ALTER TABLE fields
                ADD COLUMN latitude DECIMAL(10,7) NULL DEFAULT 10.7768890 AFTER district,
                ADD COLUMN longitude DECIMAL(10,7) NULL DEFAULT 106.7008060 AFTER latitude,
                ADD COLUMN sub_pitches VARCHAR(255) NOT NULL DEFAULT '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)' AFTER price_per_hour,
                ADD COLUMN normal_price INT UNSIGNED NOT NULL DEFAULT 150000 AFTER sub_pitches,
                ADD COLUMN peak_price INT UNSIGNED NOT NULL DEFAULT 280000 AFTER normal_price,
                ADD COLUMN contact_phone VARCHAR(30) NOT NULL DEFAULT '0901.234.567' AFTER peak_price,
                ADD COLUMN google_maps_url VARCHAR(255) NOT NULL DEFAULT '' AFTER contact_phone
            ");
        }

        // 4.9. Đồng bộ dữ liệu 6 Điểm Dừng Sân Bóng Nổi Tiếng + 4 Cụm Sân Thực Tế tại TP.HCM (v2)
        $hasRealSeed = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='real_pitches_seeded_v2'")->fetchColumn();
        if ($hasRealSeed !== '1') {
            $realPitches = [
                1 => [
                    'name' => 'Sân Banh Chảo Lửa',
                    'type' => '5',
                    'address' => '38 Đ. Phan Thúc Duyện, Tân Sơn Nhất, Quận Tân Bình, TP.HCM',
                    'district' => 'Tân Bình',
                    'lat' => 10.8016800,
                    'lng' => 106.6626100,
                    'price' => 180000,
                    'normal_price' => 180000,
                    'peak_price' => 320000,
                    'sub_pitches' => 'Nhiều sân 5 người và 7 người liên hoàn (Ghép thành sân 11 người tiêu chuẩn)',
                    'phone' => '0777 700 000',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8016800,106.6626100',
                    'desc' => 'Một trong những "thánh địa" bóng đá phong trào lớn và sôi động nhất thành phố. Cụm sân có quy mô lớn gồm nhiều sân 5 người và 7 người liên hoàn, có thể ghép thành sân 11 người tiêu chuẩn.',
                    'image' => 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=900',
                    'rating' => 4.3,
                    'reviews' => 3600
                ],
                2 => [
                    'name' => 'Sân Bóng Đá K34',
                    'type' => '7',
                    'address' => 'Bạch Đằng, Tân Sơn Hòa, Quận Tân Bình, TP.HCM',
                    'district' => 'Tân Bình',
                    'lat' => 10.8132500,
                    'lng' => 106.6694200,
                    'price' => 160000,
                    'normal_price' => 160000,
                    'peak_price' => 300000,
                    'sub_pitches' => 'Hệ thống sân cỏ nhân tạo 5 - 7 người có mái che thoáng mát',
                    'phone' => '0908 787 798',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8132500,106.6694200',
                    'desc' => 'Nổi tiếng với hệ thống sân cỏ nhân tạo có mái che cực kỳ thoáng mát, giúp các đội bóng thi đấu bất chấp thời tiết nắng mưa.',
                    'image' => 'https://images.unsplash.com/photo-1575361204480-aadea25e6e68?w=900',
                    'rating' => 4.2,
                    'reviews' => 850
                ],
                3 => [
                    'name' => 'Sân vận động Gia Định',
                    'type' => '11',
                    'address' => '2A Phan Chu Trinh, Phường 12, Quận Bình Thạnh, TP.HCM',
                    'district' => 'Bình Thạnh',
                    'lat' => 10.8087500,
                    'lng' => 106.6961500,
                    'price' => 170000,
                    'normal_price' => 170000,
                    'peak_price' => 310000,
                    'sub_pitches' => 'Sân 11 người chuẩn thi đấu + Cụm các sân 5 - 7 người vệ tinh xung quanh (Mở cửa: 05:30 – 22:00)',
                    'phone' => '028 3841 2635',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8087500,106.6961500',
                    'desc' => 'Tổ hợp sân vận động lớn có cả mặt sân 11 người chuẩn thi đấu và cụm các sân 5 - 7 người vệ tinh xung quanh, khuôn viên rộng rãi. Giờ mở cửa: 05:30 – 22:00.',
                    'image' => 'https://images.unsplash.com/photo-1518604666860-9ed391f76460?w=900',
                    'rating' => 4.3,
                    'reviews' => 1240
                ],
                4 => [
                    'name' => 'Sân bóng đá mini Nhà Tập Luyện Phú Thọ',
                    'type' => '5',
                    'address' => '219 Đ. Lý Thường Kiệt, Phường 15, Quận 11, TP.HCM',
                    'district' => 'Quận 11',
                    'lat' => 10.7694500,
                    'lng' => 106.6579200,
                    'price' => 150000,
                    'normal_price' => 150000,
                    'peak_price' => 280000,
                    'sub_pitches' => 'Khu liên hợp thể thao cụm sân 5 - 7 người đạt chuẩn (Mở cửa: 05:00 – 23:00)',
                    'phone' => '028 3865 0930',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.7694500,106.6579200',
                    'desc' => 'Nằm trong khu liên hợp thể thao Sân vận động Phú Thọ, mặt cỏ đạt chuẩn, dễ dàng kết nối giao thông từ các khu vực trung tâm. Giờ mở cửa: 05:00 – 23:00.',
                    'image' => 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900',
                    'rating' => 4.4,
                    'reviews' => 960
                ],
                5 => [
                    'name' => 'Sân banh Tao Đàn',
                    'type' => '7',
                    'address' => 'Huyền Trân Công Chúa, Phường Bến Thành, Quận 1, TP.HCM',
                    'district' => 'Quận 1',
                    'lat' => 10.7751200,
                    'lng' => 106.6928500,
                    'price' => 200000,
                    'normal_price' => 200000,
                    'peak_price' => 360000,
                    'sub_pitches' => 'Mặt sân 11 người chia tách linh hoạt thành các sân 7 người và 5 người',
                    'phone' => '028 3822 4158',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.7751200,106.6928500',
                    'desc' => 'Nằm ngay "lá phổi xanh" trung tâm thành phố, bao quanh bởi cây cổ thụ mát mẻ, có mặt sân 11 người chia tách linh hoạt thành các sân 7 người và 5 người.',
                    'image' => 'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=900',
                    'rating' => 4.2,
                    'reviews' => 1450
                ],
                6 => [
                    'name' => 'Sân vận động Trường Đại học Tôn Đức Thắng',
                    'type' => '11',
                    'address' => 'Đường D6 (19 Nguyễn Hữu Thọ), Phường Tân Hưng, Quận 7, TP.HCM',
                    'district' => 'Quận 7',
                    'lat' => 10.7326500,
                    'lng' => 106.6997800,
                    'price' => 190000,
                    'normal_price' => 190000,
                    'peak_price' => 350000,
                    'sub_pitches' => 'Sân vận động chuẩn FIFA 2 sao (Sân 11 người + Cụm sân 5 - 7 người & Khán đài lớn)',
                    'phone' => '028 3775 5035',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.7326500,106.6997800',
                    'desc' => 'Sân vận động đạt chuẩn FIFA 2 sao với mặt cỏ cực đẹp, khán đài lớn và đường chạy điền kinh bao quanh, thường xuyên tổ chức các giải sinh viên và phong trào lớn.',
                    'image' => 'https://images.unsplash.com/photo-1431324155629-1a6deb1dec8d?w=900',
                    'rating' => 4.7,
                    'reviews' => 2150
                ],
                7 => [
                    'name' => 'Sân bóng Phú Lợi - Phạm Văn Đồng',
                    'type' => '7',
                    'address' => '88 Phạm Văn Đồng, Phường Hiệp Bình Chánh, TP. Thủ Đức, TP.HCM',
                    'district' => 'TP. Thủ Đức',
                    'lat' => 10.8294000,
                    'lng' => 106.7231000,
                    'price' => 150000,
                    'normal_price' => 150000,
                    'peak_price' => 270000,
                    'sub_pitches' => '6 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
                    'phone' => '0933 088 088',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8294000,106.7231000',
                    'desc' => 'Cụm sân lớn mặt tiền đại lộ Phạm Văn Đồng, mặt cỏ mới thay êm ái, thoát nước nhanh, bãi đỗ ô tô & xe máy rộng rãi.',
                    'image' => 'https://images.unsplash.com/photo-1551958219-acbc608c6377?w=900',
                    'rating' => 4.6,
                    'reviews' => 420
                ],
                8 => [
                    'name' => 'Sân bóng Bình Quới - Thanh Đa',
                    'type' => '5',
                    'address' => '406 Bình Quới, Phường 28, Quận Bình Thạnh, TP.HCM',
                    'district' => 'Bình Thạnh',
                    'lat' => 10.8226000,
                    'lng' => 106.7318000,
                    'price' => 130000,
                    'normal_price' => 130000,
                    'peak_price' => 240000,
                    'sub_pitches' => '6 sân 5 người, 3 sân 7 người (Ghép được 1 sân 11 người)',
                    'phone' => '0938 406 406',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8226000,106.7318000',
                    'desc' => 'Cụm sân ven sông mát mẻ tại bán đảo Thanh Đa - Bình Thạnh, giá thuê cực kỳ hợp lý cho sinh viên và đội bóng văn phòng.',
                    'image' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018?w=900',
                    'rating' => 4.5,
                    'reviews' => 310
                ],
                9 => [
                    'name' => 'Sân bóng Vạn Phúc City (TP. Thủ Đức)',
                    'type' => '7',
                    'address' => 'Khu đô thị Vạn Phúc, Quốc lộ 13, Phường Hiệp Bình Phước, TP. Thủ Đức, TP.HCM',
                    'district' => 'TP. Thủ Đức',
                    'lat' => 10.8408500,
                    'lng' => 106.7114200,
                    'price' => 160000,
                    'normal_price' => 160000,
                    'peak_price' => 290000,
                    'sub_pitches' => '6 sân 5 người, 3 sân 7 người, 1 sân 11 người ven sông',
                    'phone' => '0902 713 713',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8408500,106.7114200',
                    'desc' => 'Tổ hợp sân bóng hiện đại bậc nhất TP. Thủ Đức nằm trong KĐT Vạn Phúc, hệ thống đèn LED chống lóa và phòng tắm nước nóng.',
                    'image' => 'https://images.unsplash.com/photo-1518604666860-9ed391f76460?w=900',
                    'rating' => 4.6,
                    'reviews' => 520
                ],
                10 => [
                    'name' => 'Sân bóng Đạt Đức (Thống Nhất - Gò Vấp)',
                    'type' => '5',
                    'address' => '5A Nguyễn Văn Lượng, Phường 16, Quận Gò Vấp, TP.HCM',
                    'district' => 'Gò Vấp',
                    'lat' => 10.8382000,
                    'lng' => 106.6665000,
                    'price' => 140000,
                    'normal_price' => 140000,
                    'peak_price' => 250000,
                    'sub_pitches' => '8 sân 5 người, 4 sân 7 người (Ghép được 2 sân 11 người)',
                    'phone' => '0919 516 516',
                    'gmap' => 'https://www.google.com/maps/dir/?api=1&destination=10.8382000,106.6665000',
                    'desc' => 'Cụm sân trung tâm Gò Vấp đối diện Lotte Mart Nguyễn Văn Lượng, mặt cỏ chuẩn thi đấu, dịch vụ trọng tài & nước uống chu đáo.',
                    'image' => 'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=900',
                    'rating' => 4.5,
                    'reviews' => 640
                ],
            ];

            $stmtCheckId = $pdo->prepare("SELECT id FROM fields WHERE id = ?");
            $stmtUpd = $pdo->prepare("
                UPDATE fields
                SET name=?, type=?, address=?, district=?, latitude=?, longitude=?,
                    price_per_hour=?, sub_pitches=?, normal_price=?, peak_price=?,
                    contact_phone=?, google_maps_url=?, description=?, image=?, rating=?, review_count=?
                WHERE id=?
            ");
            $stmtIns = $pdo->prepare("
                INSERT INTO fields (
                    id, name, type, address, district, latitude, longitude,
                    price_per_hour, sub_pitches, normal_price, peak_price,
                    contact_phone, google_maps_url, description, status, image, rating, review_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
            ");

            foreach ($realPitches as $pid => $rp) {
                $stmtCheckId->execute([$pid]);
                if ($stmtCheckId->fetchColumn()) {
                    $stmtUpd->execute([
                        $rp['name'], $rp['type'], $rp['address'], $rp['district'], $rp['lat'], $rp['lng'],
                        $rp['price'], $rp['sub_pitches'], $rp['normal_price'], $rp['peak_price'],
                        $rp['phone'], $rp['gmap'], $rp['desc'], $rp['image'], $rp['rating'], $rp['reviews'],
                        $pid
                    ]);
                } else {
                    $stmtIns->execute([
                        $pid, $rp['name'], $rp['type'], $rp['address'], $rp['district'], $rp['lat'], $rp['lng'],
                        $rp['price'], $rp['sub_pitches'], $rp['normal_price'], $rp['peak_price'],
                        $rp['phone'], $rp['gmap'], $rp['desc'], $rp['image'], $rp['rating'], $rp['reviews']
                    ]);
                }
            }

            $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('real_pitches_seeded_v2', '1')")->execute();
        }

        // 4.10. NÂNG CẤP HỆ THỐNG TÀI KHOẢN ĐA VAI TRÒ (CUSTOMER - PITCH_OWNER - ADMIN),
        //       VÍ NỘI BỘ, ĐIỂM UY TÍN, HỒ SƠ CẦU THỦ & LUỒNG ĐĂNG KÝ CHỦ SÂN (PARTNER APPLICATIONS)
        $hasAccountRole = $pdo->query("SHOW COLUMNS FROM users LIKE 'account_role'")->fetch();
        if (!$hasAccountRole) {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user','pitch_owner','admin') NOT NULL DEFAULT 'user'");
            $pdo->exec("ALTER TABLE users
                ADD COLUMN account_role ENUM('CUSTOMER','PITCH_OWNER','ADMIN') NOT NULL DEFAULT 'CUSTOMER' AFTER role,
                ADD COLUMN account_status ENUM('ACTIVE','WARNING','BANNED') NOT NULL DEFAULT 'ACTIVE' AFTER status,
                ADD COLUMN partner_status ENUM('NONE','PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'NONE' AFTER account_status,
                ADD COLUMN wallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER partner_status,
                ADD COLUMN reputation_score INT NOT NULL DEFAULT 100 AFTER wallet_balance,
                ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER reputation_score,
                ADD COLUMN avatar VARCHAR(255) NOT NULL DEFAULT '' AFTER loyalty_points,
                ADD COLUMN preferred_position VARCHAR(60) NOT NULL DEFAULT 'Tiền vệ' AFTER avatar,
                ADD COLUMN skill_level VARCHAR(60) NOT NULL DEFAULT 'Vui vẻ' AFTER preferred_position,
                ADD COLUMN team_name VARCHAR(120) NOT NULL DEFAULT '' AFTER skill_level,
                ADD COLUMN preferred_districts VARCHAR(255) NOT NULL DEFAULT '' AFTER team_name
            ");

            // Đồng bộ cột account_role và account_status cho các tài khoản hiện có
            $pdo->exec("UPDATE users SET account_role = 'ADMIN' WHERE role = 'admin'");
            $pdo->exec("UPDATE users SET account_role = 'PITCH_OWNER', partner_status = 'APPROVED' WHERE role = 'pitch_owner'");
            $pdo->exec("UPDATE users SET account_status = 'BANNED' WHERE status = 'locked'");
            $pdo->exec("UPDATE users SET team_name = 'FC Anh Em Quận 1', preferred_position = 'Tiền đạo', skill_level = 'Bán chuyên', preferred_districts = 'Quận 1, Tân Bình, Bình Thạnh', wallet_balance = 150000, loyalty_points = 120 WHERE id = 2");
        }

        // 4.10B. Bổ sung các cột hỗ trợ Đăng nhập / Đăng ký bằng Google (Google Sign-In / OAuth2)
        $hasGoogleIdCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_id'")->fetch();
        if (!$hasGoogleIdCol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(100) NULL DEFAULT NULL UNIQUE AFTER loyalty_points");
        }
        $hasAvatarCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();
        if (!$hasAvatarCol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL DEFAULT NULL AFTER google_id");
        }
        $hasAuthProviderCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_provider'")->fetch();
        if (!$hasAuthProviderCol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN auth_provider ENUM('local','google') NOT NULL DEFAULT 'local' AFTER avatar");
            $pdo->exec("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL DEFAULT NULL");
        }

        // Bảng user_profiles (Hồ sơ cầu thủ / Đội bóng mở rộng)
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (
            user_id INT UNSIGNED PRIMARY KEY,
            preferred_position VARCHAR(60) NOT NULL DEFAULT 'Tiền vệ',
            skill_level VARCHAR(60) NOT NULL DEFAULT 'Vui vẻ',
            team_name VARCHAR(120) NOT NULL DEFAULT '',
            preferred_districts VARCHAR(255) NOT NULL DEFAULT '',
            jersey_number TINYINT UNSIGNED NULL DEFAULT 10,
            bio VARCHAR(255) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Bảng wallet_transactions (Lịch sử biến động số dư Ví nội bộ & Điểm uy tín)
        $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            booking_id INT UNSIGNED NULL DEFAULT NULL,
            tx_type ENUM('REFUND','PAYMENT','DEPOSIT','REPUTATION','LOYALTY','ADMIN_ADJUST') NOT NULL DEFAULT 'REFUND',
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            reputation_delta INT NOT NULL DEFAULT 0,
            reputation_after INT NOT NULL DEFAULT 100,
            loyalty_delta INT NOT NULL DEFAULT 0,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            created_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wt_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Bảng user_logs (Lịch sử cộng/trừ Điểm uy tín & Ví nội bộ)
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            action_type VARCHAR(60) NOT NULL DEFAULT 'REPUTATION_CHANGE',
            reputation_delta INT NOT NULL DEFAULT 0,
            wallet_delta DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            performed_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ulogs_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Bảng partner_applications (Hồ sơ đăng ký Hợp tác mở sân của Chủ sân)
        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_applications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            venue_name VARCHAR(180) NOT NULL,
            venue_address VARCHAR(255) NOT NULL,
            district VARCHAR(100) NOT NULL DEFAULT 'Quận 1',
            city VARCHAR(100) NOT NULL DEFAULT 'TP. Hồ Chí Minh',
            latitude DECIMAL(10,7) NOT NULL DEFAULT 10.7768890,
            longitude DECIMAL(10,7) NOT NULL DEFAULT 106.7008060,
            pitch_summary VARCHAR(255) NOT NULL DEFAULT '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
            cnt_pitch_5 TINYINT UNSIGNED NOT NULL DEFAULT 4,
            cnt_pitch_7 TINYINT UNSIGNED NOT NULL DEFAULT 2,
            cnt_pitch_11 TINYINT UNSIGNED NOT NULL DEFAULT 1,
            price_normal INT UNSIGNED NOT NULL DEFAULT 160000,
            price_peak INT UNSIGNED NOT NULL DEFAULT 290000,
            contact_phone VARCHAR(30) NOT NULL DEFAULT '',
            venue_images TEXT NULL,
            tax_code VARCHAR(60) NOT NULL DEFAULT '',
            business_license_url VARCHAR(255) NOT NULL DEFAULT '',
            id_card_front_url VARCHAR(255) NOT NULL DEFAULT '',
            id_card_back_url VARCHAR(255) NOT NULL DEFAULT '',
            bank_name VARCHAR(80) NOT NULL DEFAULT 'MB Bank',
            bank_account_number VARCHAR(60) NOT NULL DEFAULT '',
            bank_account_holder VARCHAR(120) NOT NULL DEFAULT '',
            status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
            rejection_reason VARCHAR(255) NULL DEFAULT NULL,
            reviewed_by INT UNSIGNED NULL DEFAULT NULL,
            reviewed_at DATETIME NULL DEFAULT NULL,
            approved_field_id INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pa_user (user_id),
            INDEX idx_pa_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Bổ sung cột owner_id vào bảng fields (liên kết với tài khoản Chủ sân)
        $hasOwnerId = $pdo->query("SHOW COLUMNS FROM fields LIKE 'owner_id'")->fetch();
        if (!$hasOwnerId) {
            $pdo->exec("ALTER TABLE fields ADD COLUMN owner_id INT UNSIGNED NULL DEFAULT NULL AFTER id");
            $pdo->exec("ALTER TABLE fields ADD INDEX idx_fields_owner (owner_id)");
        }

        // Khởi tạo dữ liệu mẫu ban đầu cho Ví nội bộ, Điểm uy tín & Đơn đăng ký Chủ sân (1 lần)
        $hasMultiRoleSeed = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='multirole_seeded_v1'")->fetchColumn();
        if ($hasMultiRoleSeed !== '1') {
            $wtCount = (int)$pdo->query("SELECT COUNT(*) FROM wallet_transactions")->fetchColumn();
            if ($wtCount === 0) {
                $pdo->exec("INSERT INTO wallet_transactions (user_id, tx_type, amount, balance_after, reputation_delta, reputation_after, loyalty_delta, reason) VALUES
                    (2, 'REFUND', 150000, 150000, 0, 100, 0, 'Hoàn tiền cọc 100% do hủy lịch hợp lệ trước 2 giờ (Sân Banh Chảo Lửa)'),
                    (2, 'REPUTATION', 0, 150000, 5, 100, 50, 'Hoàn tất trận đấu đúng giờ & thanh toán đầy đủ (+5 điểm uy tín, +50 điểm thưởng)'),
                    (3, 'REPUTATION', 0, 0, -25, 75, 0, 'Hủy sân sát giờ (< 2 tiếng trước giờ bóng lăn) - Cảnh báo hệ thống')");
                $pdo->exec("UPDATE users SET reputation_score = 75, account_status = 'WARNING' WHERE id = 3");
            }
            $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('multirole_seeded_v1', '1')")->execute();
        }

        // 4.11. KHỞI TẠO 6 TÀI KHOẢN ĐỐI TÁC CHỦ SÂN (PITCH_OWNER) CHO CÁC CỤM SÂN HIỆN HỮU
        $hasSixPartners = (string)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='six_partners_seeded_v1'")->fetchColumn();
        if ($hasSixPartners !== '1') {
            $defaultPassHash = password_hash('123456', PASSWORD_DEFAULT);

            $sixPartners = [
                [
                    'username'      => 'chusan_chaolua',
                    'full_name'     => 'Trần Minh Quân (Chủ sân Chảo Lửa)',
                    'email'         => 'chaolua.partner@sanbong.vn',
                    'phone'         => '0777700000',
                    'wallet'        => 2850000,
                    'reputation'    => 100,
                    'loyalty'       => 450,
                    'team_name'     => 'BQL Sân Banh Chảo Lửa',
                    'districts'     => 'Tân Bình, Phú Nhuận',
                    'field_ids'     => [1],
                    'main_field_id' => 1,
                    'venue_name'    => 'Sân Banh Chảo Lửa',
                    'venue_address' => '38 Đ. Phan Thúc Duyện, Tân Sơn Nhất, Quận Tân Bình, TP.HCM',
                    'district'      => 'Tân Bình',
                    'lat'           => 10.8016800,
                    'lng'           => 106.6626100,
                    'pitch_summary' => '6 sân 5 người, 2 sân 7 người (Ghép thành 1 sân 11 người)',
                    'cnt5'          => 6,
                    'cnt7'          => 2,
                    'cnt11'         => 1,
                    'price_normal'  => 180000,
                    'price_peak'    => 320000,
                    'tax_code'      => '0316889101',
                    'bank_name'     => 'Vietcombank',
                    'bank_no'       => '0071001238888',
                    'bank_holder'   => 'TRAN MINH QUAN',
                    'image'         => 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=900'
                ],
                [
                    'username'      => 'chusan_k34',
                    'full_name'     => 'Lê Hoàng Nam (Chủ sân K34)',
                    'email'         => 'k34.partner@sanbong.vn',
                    'phone'         => '0908787798',
                    'wallet'        => 1920000,
                    'reputation'    => 100,
                    'loyalty'       => 320,
                    'team_name'     => 'BQL Cụm Sân K34 & Đạt Đức',
                    'districts'     => 'Tân Bình, Gò Vấp',
                    'field_ids'     => [2, 10],
                    'main_field_id' => 2,
                    'venue_name'    => 'Sân Bóng Đá K34',
                    'venue_address' => 'Bạch Đằng, Tân Sơn Hòa, Quận Tân Bình, TP.HCM',
                    'district'      => 'Tân Bình',
                    'lat'           => 10.8132500,
                    'lng'           => 106.6694200,
                    'pitch_summary' => '4 sân 5 người, 2 sân 7 người có mái che thoáng mát',
                    'cnt5'          => 4,
                    'cnt7'          => 2,
                    'cnt11'         => 0,
                    'price_normal'  => 160000,
                    'price_peak'    => 300000,
                    'tax_code'      => '0316889102',
                    'bank_name'     => 'MB Bank',
                    'bank_no'       => '090878779888',
                    'bank_holder'   => 'LE HOANG NAM',
                    'image'         => 'https://images.unsplash.com/photo-1575361204480-aadea25e6e68?w=900'
                ],
                [
                    'username'      => 'chusan_giadinh',
                    'full_name'     => 'Nguyễn Quốc Tuấn (Chủ sân Gia Định)',
                    'email'         => 'giadinh.partner@sanbong.vn',
                    'phone'         => '0918412635',
                    'wallet'        => 3400000,
                    'reputation'    => 100,
                    'loyalty'       => 510,
                    'team_name'     => 'BQL Sân Vận Động Gia Định',
                    'districts'     => 'Bình Thạnh, Gò Vấp',
                    'field_ids'     => [3, 8],
                    'main_field_id' => 3,
                    'venue_name'    => 'Sân vận động Gia Định',
                    'venue_address' => '2A Phan Chu Trinh, Phường 12, Quận Bình Thạnh, TP.HCM',
                    'district'      => 'Bình Thạnh',
                    'lat'           => 10.8087500,
                    'lng'           => 106.6961500,
                    'pitch_summary' => '4 sân 5 người, 2 sân 7 người, 1 sân 11 người chuẩn thi đấu',
                    'cnt5'          => 4,
                    'cnt7'          => 2,
                    'cnt11'         => 1,
                    'price_normal'  => 170000,
                    'price_peak'    => 310000,
                    'tax_code'      => '0316889103',
                    'bank_name'     => 'Techcombank',
                    'bank_no'       => '1903688899901',
                    'bank_holder'   => 'NGUYEN QUOC TUAN',
                    'image'         => 'https://images.unsplash.com/photo-1518604666860-9ed391f76460?w=900'
                ],
                [
                    'username'      => 'chusan_phutho',
                    'full_name'     => 'Phạm Gia Huy (Chủ sân Phú Thọ)',
                    'email'         => 'phutho.partner@sanbong.vn',
                    'phone'         => '0903865093',
                    'wallet'        => 1650000,
                    'reputation'    => 100,
                    'loyalty'       => 280,
                    'team_name'     => 'BQL Sân Bóng Nhà Tập Luyện Phú Thọ',
                    'districts'     => 'Quận 11, Quận 10',
                    'field_ids'     => [4],
                    'main_field_id' => 4,
                    'venue_name'    => 'Sân bóng đá mini Nhà Tập Luyện Phú Thọ',
                    'venue_address' => '219 Đ. Lý Thường Kiệt, Phường 15, Quận 11, TP.HCM',
                    'district'      => 'Quận 11',
                    'lat'           => 10.7694500,
                    'lng'           => 106.6579200,
                    'pitch_summary' => '5 sân 5 người, 2 sân 7 người liên hợp thể thao Phú Thọ',
                    'cnt5'          => 5,
                    'cnt7'          => 2,
                    'cnt11'         => 0,
                    'price_normal'  => 150000,
                    'price_peak'    => 280000,
                    'tax_code'      => '0316889104',
                    'bank_name'     => 'ACB',
                    'bank_no'       => '8899665588',
                    'bank_holder'   => 'PHAM GIA HUY',
                    'image'         => 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900'
                ],
                [
                    'username'      => 'chusan_taodan',
                    'full_name'     => 'Võ Thành Đạt (Chủ sân Tao Đàn)',
                    'email'         => 'taodan.partner@sanbong.vn',
                    'phone'         => '0908224158',
                    'wallet'        => 4100000,
                    'reputation'    => 100,
                    'loyalty'       => 620,
                    'team_name'     => 'BQL Sân Banh Tao Đàn Quận 1',
                    'districts'     => 'Quận 1, Quận 3',
                    'field_ids'     => [5],
                    'main_field_id' => 5,
                    'venue_name'    => 'Sân banh Tao Đàn',
                    'venue_address' => 'Huyền Trân Công Chúa, Phường Bến Thành, Quận 1, TP.HCM',
                    'district'      => 'Quận 1',
                    'lat'           => 10.7751200,
                    'lng'           => 106.6928500,
                    'pitch_summary' => '4 sân 5 người, 2 sân 7 người, 1 sân 11 người trung tâm Quận 1',
                    'cnt5'          => 4,
                    'cnt7'          => 2,
                    'cnt11'         => 1,
                    'price_normal'  => 200000,
                    'price_peak'    => 360000,
                    'tax_code'      => '0316889105',
                    'bank_name'     => 'BIDV',
                    'bank_no'       => '31410008899777',
                    'bank_holder'   => 'VO THANH DAT',
                    'image'         => 'https://images.unsplash.com/photo-1459865264687-595d652de67e?w=900'
                ],
                [
                    'username'      => 'chusan_tdtu',
                    'full_name'     => 'Đặng Đình Khôi (Chủ sân Tôn Đức Thắng & Thủ Đức)',
                    'email'         => 'tdtu.partner@sanbong.vn',
                    'phone'         => '0903775503',
                    'wallet'        => 3750000,
                    'reputation'    => 100,
                    'loyalty'       => 590,
                    'team_name'     => 'BQL Cụm Sân Nam Sài Gòn & Thủ Đức',
                    'districts'     => 'Quận 7, TP. Thủ Đức',
                    'field_ids'     => [6, 7, 9],
                    'main_field_id' => 6,
                    'venue_name'    => 'Sân vận động Trường Đại học Tôn Đức Thắng',
                    'venue_address' => 'Đường D6 (19 Nguyễn Hữu Thọ), Phường Tân Hưng, Quận 7, TP.HCM',
                    'district'      => 'Quận 7',
                    'lat'           => 10.7326500,
                    'lng'           => 106.6997800,
                    'pitch_summary' => 'Sân vận động chuẩn FIFA 2 sao (4 sân 5, 2 sân 7, 1 sân 11 người)',
                    'cnt5'          => 4,
                    'cnt7'          => 2,
                    'cnt11'         => 1,
                    'price_normal'  => 190000,
                    'price_peak'    => 350000,
                    'tax_code'      => '0316889106',
                    'bank_name'     => 'VPBank',
                    'bank_no'       => '6688997755',
                    'bank_holder'   => 'DANG DINH KHOI',
                    'image'         => 'https://images.unsplash.com/photo-1431324155629-1a6deb1dec8d?w=900'
                ],
            ];

            $stFindUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stInsUser  = $pdo->prepare("
                INSERT INTO users (
                    username, password, full_name, email, phone,
                    role, account_role, status, account_status, partner_status,
                    wallet_balance, reputation_score, loyalty_points,
                    preferred_position, skill_level, team_name, preferred_districts
                ) VALUES (?, ?, ?, ?, ?, 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', ?, ?, ?, 'Tiền vệ', 'Bán chuyên', ?, ?)
            ");
            $stUpdUser  = $pdo->prepare("
                UPDATE users
                SET full_name=?, email=?, phone=?, role='pitch_owner', account_role='PITCH_OWNER',
                    status='active', account_status='ACTIVE', partner_status='APPROVED',
                    wallet_balance=?, reputation_score=?, loyalty_points=?, team_name=?, preferred_districts=?
                WHERE id=?
            ");

            $stCheckApp = $pdo->prepare("SELECT id FROM partner_applications WHERE user_id = ?");
            $stInsApp   = $pdo->prepare("
                INSERT INTO partner_applications (
                    user_id, venue_name, venue_address, district, city,
                    latitude, longitude, pitch_summary, cnt_pitch_5, cnt_pitch_7, cnt_pitch_11,
                    price_normal, price_peak, contact_phone, venue_images,
                    tax_code, business_license_url, id_card_front_url, id_card_back_url,
                    bank_name, bank_account_number, bank_account_holder,
                    status, reviewed_by, reviewed_at, approved_field_id
                ) VALUES (
                    ?, ?, ?, ?, 'TP. Hồ Chí Minh',
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    'APPROVED', 1, NOW(), ?
                )
            ");

            foreach ($sixPartners as $sp) {
                $stFindUser->execute([$sp['username']]);
                $uid = (int)$stFindUser->fetchColumn();

                if ($uid > 0) {
                    $stUpdUser->execute([
                        $sp['full_name'], $sp['email'], $sp['phone'],
                        $sp['wallet'], $sp['reputation'], $sp['loyalty'],
                        $sp['team_name'], $sp['districts'], $uid
                    ]);
                } else {
                    $stInsUser->execute([
                        $sp['username'], $defaultPassHash, $sp['full_name'], $sp['email'], $sp['phone'],
                        $sp['wallet'], $sp['reputation'], $sp['loyalty'],
                        $sp['team_name'], $sp['districts']
                    ]);
                    $uid = (int)$pdo->lastInsertId();
                }

                // Đồng bộ bảng user_profiles
                $pdo->prepare("
                    INSERT INTO user_profiles (user_id, preferred_position, skill_level, team_name, preferred_districts, jersey_number, bio)
                    VALUES (?, 'Tiền vệ', 'Bán chuyên', ?, ?, 10, ?)
                    ON DUPLICATE KEY UPDATE team_name = VALUES(team_name), preferred_districts = VALUES(preferred_districts)
                ")->execute([$uid, $sp['team_name'], $sp['districts'], 'Đối tác Chủ sân chính thức trên hệ thống Sân Bóng Pro']);

                // Gán owner_id cho các sân chính thuộc Chủ sân này
                foreach ($sp['field_ids'] as $fidItem) {
                    $pdo->prepare("UPDATE fields SET owner_id = ? WHERE id = ?")->execute([$uid, (int)$fidItem]);
                }

                // Tạo hồ sơ đối tác đã duyệt (APPROVED) nếu chưa có
                $stCheckApp->execute([$uid]);
                if (!$stCheckApp->fetchColumn()) {
                    $imgsJson = json_encode([$sp['image']], JSON_UNESCAPED_SLASHES);
                    $stInsApp->execute([
                        $uid,
                        $sp['venue_name'],
                        $sp['venue_address'],
                        $sp['district'],
                        $sp['lat'],
                        $sp['lng'],
                        $sp['pitch_summary'],
                        $sp['cnt5'],
                        $sp['cnt7'],
                        $sp['cnt11'],
                        $sp['price_normal'],
                        $sp['price_peak'],
                        $sp['phone'],
                        $imgsJson,
                        $sp['tax_code'],
                        $sp['image'],
                        $sp['image'],
                        $sp['image'],
                        $sp['bank_name'],
                        $sp['bank_no'],
                        $sp['bank_holder'],
                        $sp['main_field_id']
                    ]);
                }
            }

            // Đồng bộ owner_id cho toàn bộ các sân con (Sân 5/7 thành phần) theo owner_id của sân Cha (2 tầng phân cấp)
            $pdo->exec("
                UPDATE fields c
                INNER JOIN fields p ON c.parent_id = p.id
                SET c.owner_id = p.owner_id
                WHERE p.owner_id IS NOT NULL
            ");
            $pdo->exec("
                UPDATE fields c
                INNER JOIN fields p ON c.parent_id = p.id
                SET c.owner_id = p.owner_id
                WHERE p.owner_id IS NOT NULL
            ");

            $pdo->prepare("REPLACE INTO system_settings(setting_key, setting_value) VALUES('six_partners_seeded_v1', '1')")->execute();
        }
    } catch (Exception $ex) {
        // Bỏ qua lỗi nếu quyền CSDL hạn chế hoặc bảng đã tồn tại
    }
}

// Thực thi kiểm tra cấu trúc CSDL ngay khi nạp auth.php
ensure_upgraded_schema();

// Nạp Engine xử lý 5 bài toán cốt lõi (Ghép/Tách sân, Trùng lịch 30p, Khóa HOLD 10p, Giá động, Đặt cố định & Xả sân)
require_once __DIR__ . '/booking_engine.php';

// Nạp Engine Kênh Đối tác Chủ sân (Venues, Pitches, Pitch Pricing, POS Check-in/No-show/Walk-in & Middleware)
require_once __DIR__ . '/partner_engine.php';

// ----------------------------------------------------------------------------
// PHẦN 5: HÀM ĐỌC & LƯU THÔNG SỐ CẤU HÌNH HỆ THỐNG (SYSTEM SETTINGS)
// ----------------------------------------------------------------------------

/**
 * Hàm get_setting(): Lấy giá trị cấu hình theo khóa $key, nếu chưa có trả về $default.
 */
function get_setting($key, $default = '') {
    global $pdo;
    static $cache = null;

    // Nạp toàn bộ cài đặt vào bộ nhớ đệm (cache) trong lần gọi đầu tiên
    if ($cache === null) {
        $cache = [];
        try {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Exception $e) {}
    }

    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/**
 * Hàm set_setting(): Lưu hoặc cập nhật một thông số cấu hình vào bảng system_settings.
 */
function set_setting($key, $val) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $val]);
}

// ----------------------------------------------------------------------------
// PHẦN 6: XÁC THỰC ĐĂNG NHẬP VÀ KIỂM TRA PHÂN QUYỀN (USER / ADMIN)
// ----------------------------------------------------------------------------

/**
 * Hàm current_user(): Lấy thông tin tài khoản đang đăng nhập từ Session.
 * Trả về mảng thông tin user hoặc null nếu chưa đăng nhập / tài khoản bị khóa.
 */
function current_user() {
    global $pdo;

    if (empty($_SESSION['uid']) && !empty($_SESSION['user_id'])) {
        $_SESSION['uid'] = (int)$_SESSION['user_id'];
    }

    if (empty($_SESSION['uid'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch();

    // Nếu tài khoản không còn tồn tại hoặc vừa bị khóa thì hủy Session
    if (!$user) {
        session_destroy();
        return null;
    }

    // Đồng bộ đầy đủ các khóa Session chuẩn để dùng chung giữa đăng nhập thường và Google Sign-In
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['avatar']    = $user['avatar'] ?? '';
    $_SESSION['user']      = $user;

    return $user;
}

/**
 * Hàm require_login(): Bắt buộc người dùng phải đăng nhập mới được truy cập trang.
 * Nếu chưa đăng nhập sẽ tự động chuyển hướng sang trang login.php.
 */
function require_login() {
    $user = current_user();

    if (!$user) {
        header('Location: ' . project_base() . 'login.php');
        exit;
    }

    return $user;
}

/**
 * Hàm require_admin(): Bắt buộc người dùng phải có quyền Quản trị viên (role = 'admin').
 * Nếu là khách hàng thường sẽ trả về mã 403 và đẩy về trang chủ.
 */
function require_admin() {
    $user = require_login();

    if ($user['role'] !== 'admin') {
        http_response_code(403);
        header('Location: ' . project_base() . 'index.php');
        exit;
    }

    return $user;
}

// ----------------------------------------------------------------------------
// PHẦN 7: QUẢN LÝ THÔNG BÁO FLASH, THÔNG BÁO DB & ĐƯỜNG DẪN HÌNH ẢNH
// ----------------------------------------------------------------------------

/**
 * Hàm flash(): Lưu thông báo tạm thời vào Session để hiển thị ở lần tải trang kế tiếp.
 * @param string $type 'ok' (thành công) hoặc 'err' (báo lỗi)
 * @param string $msg  Nội dung thông báo
 */
function flash($type, $msg) {
    $_SESSION['flash'] = [$type, $msg];
}

/**
 * Hàm notify(): Ghi một thông báo mới vào bảng notifications cho người dùng $uid.
 */
function notify($uid, $msg) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->execute([$uid, $msg]);
}

/**
 * Hàm img_src(): Chuẩn hóa đường dẫn ảnh (giữ nguyên nếu là link http/https,
 * hoặc nối thêm đường dẫn gốc project_base() nếu là ảnh lưu trong thư mục dự án).
 */
function img_src($path) {
    if (preg_match('#^https?://#', (string)$path)) {
        return $path;
    }
    return project_base() . ltrim((string)$path, '/');
}

// ----------------------------------------------------------------------------
// PHẦN 8: HÀM HỖ TRỢ LỌC KHOẢNG NGÀY CHO TRANG THỐNG KÊ & BÁO CÁO ADMIN
// ----------------------------------------------------------------------------

/**
 * Hàm get_range(): Đọc tham số $_GET['from'] và $_GET['to'] (Mặc định: 30 ngày gần nhất).
 */
function get_range() {
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';

    // Hàm ẩn danh kiểm tra định dạng ngày YYYY-MM-DD hợp lệ
    $is_valid_date = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);

    if (!$is_valid_date($from)) {
        $from = date('Y-m-d', strtotime('-29 day'));
    }
    if (!$is_valid_date($to)) {
        $to = date('Y-m-d');
    }

    // Nếu ngày bắt đầu lớn hơn ngày kết thúc thì hoán đổi lại
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to];
}

/**
 * Hàm range_form(): Sinh biểu mẫu HTML chọn khoảng ngày "Từ ngày - Đến ngày" cho Admin.
 */
function range_form($extra = '') {
    [$from, $to] = get_range();

    return '
    <form class="sbar" method="get" style="margin:0 0 14px">
        <label>Từ <input type="date" name="from" value="' . $from . '"></label>
        <label>Đến <input type="date" name="to" value="' . $to . '"></label>
        ' . $extra . '
        <button class="btn">🔍 Lọc dữ liệu</button>
    </form>';
}

// ----------------------------------------------------------------------------
// PHẦN 9: CÁC HÀM XỬ LÝ TÀI KHOẢN ĐA VAI TRÒ, ĐIỂM UY TÍN, VÍ NỘI BỘ & ĐỐI TÁC
// ----------------------------------------------------------------------------

/**
 * Hàm get_user_role_code(): Chuẩn hóa mã vai trò tài khoản ('CUSTOMER' | 'PITCH_OWNER' | 'ADMIN').
 */
function get_user_role_code($user) {
    if (!$user) {
        return 'CUSTOMER';
    }
    $role = strtolower((string)($user['role'] ?? ''));
    $accRole = strtoupper((string)($user['account_role'] ?? ''));
    if ($role === 'admin' || $accRole === 'ADMIN') {
        return 'ADMIN';
    }
    if ($role === 'pitch_owner' || $role === 'partner' || $accRole === 'PITCH_OWNER' || strtoupper((string)($user['partner_status'] ?? '')) === 'APPROVED') {
        return 'PITCH_OWNER';
    }
    return 'CUSTOMER';
}

/**
 * Hàm is_pitch_owner(): Kiểm tra người dùng đã được duyệt trở thành Chủ sân hay chưa.
 */
function is_pitch_owner($user) {
    if (!$user) {
        return false;
    }
    return get_user_role_code($user) === 'PITCH_OWNER'
        || strtoupper((string)($user['partner_status'] ?? '')) === 'APPROVED';
}

/**
 * Hàm get_reputation_tier(): Phân loại hạng Điểm Uy Tín (Reputation Score) của Khách hàng.
 *   - >= 80 điểm: Màu xanh lá (Uy tín tốt: Được đặt cọc & giữ chỗ tiêu chuẩn).
 *   - 60 - 79 điểm: Màu vàng (Cảnh báo: Bắt buộc thanh toán trước 100%).
 *   - < 60 điểm: Màu đỏ (Hạn chế: Khóa tính năng đặt sân giữ chỗ).
 */
function get_reputation_tier($score) {
    $s = max(0, min(100, (int)$score));
    if ($s >= 80) {
        $tier = [
            'score'               => $s,
            'code'                => 'GOOD',
            'color'               => '#16a34a',
            'bg'                  => '#f0fdf4',
            'border'              => '#86efac',
            'badge_text'          => '🟢 Uy tín Tốt (' . $s . '/100)',
            'title'               => 'Hạng Uy Tín Tốt — Đội Trưởng Văn Minh',
            'policy'              => 'Được hưởng đầy đủ quyền lợi: Đặt giữ chỗ HOLD 10 phút, đặt cọc tiêu chuẩn và nhận ưu đãi điểm thưởng.',
            'can_hold'            => true,
            'require_full_prepay' => false,
            'badge'               => 'green',
            'label'               => 'Uy tín Tốt',
        ];
    } elseif ($s >= 60) {
        $tier = [
            'score'               => $s,
            'code'                => 'WARNING',
            'color'               => '#d97706',
            'bg'                  => '#fffbeb',
            'border'              => '#fcd34d',
            'badge_text'          => '🟡 Cảnh báo (' . $s . '/100)',
            'title'               => 'Mức Cảnh Báo — Cần Cải Thiện Điểm Uy Tín',
            'policy'              => 'Tài khoản có lịch sử hủy sân sát giờ. Bắt buộc thanh toán trước 100% khi đặt sân để giữ lịch.',
            'can_hold'            => true,
            'require_full_prepay' => true,
            'badge'               => 'amber',
            'label'               => 'Cảnh báo',
        ];
    } else {
        $tier = [
            'score'               => $s,
            'code'                => 'RESTRICTED',
            'color'               => '#dc2626',
            'bg'                  => '#fef2f2',
            'border'              => '#fca5a5',
            'badge_text'          => '🔴 Hạn chế (' . $s . '/100)',
            'title'               => 'Hạn Chế Đặt Sân — Điểm Uy Tín Thấp (< 60)',
            'policy'              => 'Tài khoản bị khóa tính năng đặt sân giữ chỗ tự động do nhiều lần hủy sát giờ / bùng kèo. Vui lòng liên hệ Admin.',
            'can_hold'            => false,
            'require_full_prepay' => true,
            'badge'               => 'red',
            'label'               => 'Hạn chế',
        ];
    }
    // Alias 'desc' để tương thích các trang hiển thị mô tả chính sách
    $tier['desc'] = $tier['policy'];
    return $tier;
}

/**
 * Hàm record_wallet_and_reputation():
 * Cập nhật số dư Ví nội bộ (wallet_balance), Điểm uy tín (reputation_score), Điểm thưởng (loyalty_points)
 * và ghi lịch sử vào bảng `wallet_transactions` & `user_logs`.
 */
function record_wallet_and_reputation(
    $userId,
    $txType,
    $amountDelta,
    $repDelta,
    $loyaltyDelta,
    $reason,
    $bookingId = null,
    $createdBy = null
) {
    global $pdo;
    $stmtU = $pdo->prepare("SELECT wallet_balance, reputation_score, loyalty_points, account_status FROM users WHERE id = ?");
    $stmtU->execute([(int)$userId]);
    $cur = $stmtU->fetch();
    if (!$cur) {
        return false;
    }

    $newBalance = max(0, (float)$cur['wallet_balance'] + (float)$amountDelta);
    $newRep     = max(0, min(100, (int)$cur['reputation_score'] + (int)$repDelta));
    $newLoyalty = max(0, (int)$cur['loyalty_points'] + (int)$loyaltyDelta);

    // Tự động điều chỉnh account_status theo ngưỡng điểm uy tín (nếu không đang bị BANNED)
    $newAccStatus = $cur['account_status'] ?? 'ACTIVE';
    if ($newAccStatus !== 'BANNED') {
        $newAccStatus = ($newRep < 80) ? 'WARNING' : 'ACTIVE';
    }

    $stmtUpd = $pdo->prepare("
        UPDATE users
        SET wallet_balance = ?,
            reputation_score = ?,
            loyalty_points = ?,
            account_status = ?
        WHERE id = ?
    ");
    $stmtUpd->execute([$newBalance, $newRep, $newLoyalty, $newAccStatus, (int)$userId]);

    $stmtLog = $pdo->prepare("
        INSERT INTO wallet_transactions (
            user_id, booking_id, tx_type, amount, balance_after,
            reputation_delta, reputation_after, loyalty_delta, reason, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtLog->execute([
        (int)$userId,
        $bookingId ? (int)$bookingId : null,
        $txType,
        (float)$amountDelta,
        $newBalance,
        (int)$repDelta,
        $newRep,
        (int)$loyaltyDelta,
        (string)$reason,
        $createdBy ? (int)$createdBy : null
    ]);

    $stmtUL = $pdo->prepare("
        INSERT INTO user_logs (user_id, action_type, reputation_delta, wallet_delta, reason, performed_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtUL->execute([
        (int)$userId,
        $txType,
        (int)$repDelta,
        (float)$amountDelta,
        (string)$reason,
        $createdBy ? (int)$createdBy : null
    ]);

    return [
        'wallet_balance'   => $newBalance,
        'reputation_score' => $newRep,
        'loyalty_points'   => $newLoyalty,
        'account_status'   => $newAccStatus,
    ];
}

/**
 * Hàm save_uploaded_asset(): Upload file ảnh (Avatar, Ảnh sân, CCCD, Giấy phép KD) vào thư mục uploads.
 */
function save_uploaded_asset($inputKey, $prefix = 'asset') {
    if (empty($_FILES[$inputKey]['name']) || !is_uploaded_file($_FILES[$inputKey]['tmp_name'])) {
        return null;
    }
    $f = $_FILES[$inputKey];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (empty($allowed[$mime])) {
        return null;
    }
    $uploadDir = __DIR__ . '/../assets/images/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }
    $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $relPath  = 'assets/images/uploads/' . $filename;
    if (move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $relPath)) {
        return $relPath;
    }
    return null;
}

/**
 * Hàm approve_partner_application():
 * Xử lý Phê duyệt Hồ sơ Chủ sân bằng Database Transaction:
 *   1. Cập nhật đơn trong `partner_applications` thành `APPROVED`.
 *   2. Nâng cấp quyền user thành `PITCH_OWNER` (`role = 'pitch_owner'`, `account_role = 'PITCH_OWNER'`, `partner_status = 'APPROVED'`).
 *   3. Tự động khởi tạo Cụm sân mới vào bảng `fields` (lấy Tên, Địa chỉ, Quận, Tọa độ Lat/Lng, Cơ cấu sân, Giá thuê và gán `owner_id = user_id`),
 *      đồng thời tạo liên kết sân cha-con và cập nhật `field_conflict_map`.
 */
function approve_partner_application($appId, $adminId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM partner_applications WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$appId]);
    $app = $stmt->fetch();
    if (!$app) {
        return ['ok' => false, 'message' => 'Không tìm thấy hồ sơ đăng ký đối tác.'];
    }
    if ($app['status'] === 'APPROVED') {
        return ['ok' => false, 'message' => 'Hồ sơ này đã được phê duyệt trước đó.'];
    }

    try {
        $pdo->beginTransaction();

        // 1. Xác định loại sân chính của cụm sân mới (ưu tiên sân 11 nếu có ghép sân, hoặc sân 7 / sân 5)
        $mainType = '5';
        if ((int)$app['cnt_pitch_11'] > 0) {
            $mainType = '11';
        } elseif ((int)$app['cnt_pitch_7'] > 0) {
            $mainType = '7';
        }

        $venueImg = trim((string)$app['venue_images']);
        if ($venueImg === '') {
            $venueImg = 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900';
        }
        $gmapUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . $app['latitude'] . ',' . $app['longitude'];
        $desc = "Cụm sân đối tác chính thức trên Sân Bóng Pro. Quy mô: {$app['pitch_summary']}. "
              . "Hỗ trợ đặt lịch trực tuyến 30 phút, ghép/tách sân linh hoạt và thanh toán VietQR.";

        // 1B. Khởi tạo bản ghi Cụm sân mới trong bảng `venues` gắn `owner_id = user_id`
        $stmtInsVenue = $pdo->prepare("
            INSERT INTO venues (
                owner_id, name, address, district, city, latitude, longitude,
                open_time, close_time, amenities, images, contact_phone, description, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, '05:30:00', '23:00:00', 'wifi,parking,canteen,shoes,light', ?, ?, ?, 'ACTIVE')
        ");
        $stmtInsVenue->execute([
            (int)$app['user_id'],
            $app['venue_name'],
            $app['venue_address'],
            $app['district'],
            $app['city'] ?? 'TP. Hồ Chí Minh',
            $app['latitude'],
            $app['longitude'],
            json_encode([$venueImg], JSON_UNESCAPED_SLASHES),
            $app['contact_phone'],
            $desc
        ]);
        $newVenueId = (int)$pdo->lastInsertId();

        // 2. Khởi tạo Cụm sân chính vào bảng `fields` và gán `venue_id = newVenueId`, `owner_id = user_id`
        $stmtInsField = $pdo->prepare("
            INSERT INTO fields (
                venue_id, owner_id, parent_id, pitch_code, name, type, address, district,
                latitude, longitude, price_per_hour, sub_pitches, normal_price, peak_price,
                contact_phone, google_maps_url, description, status, image, rating, review_count
            ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, 5.0, 1)
        ");
        $mainPitchCode = $mainType . 'A';
        $stmtInsField->execute([
            $newVenueId,
            (int)$app['user_id'],
            $mainPitchCode,
            $app['venue_name'],
            $mainType,
            $app['venue_address'],
            $app['district'],
            $app['latitude'],
            $app['longitude'],
            (int)$app['price_normal'],
            $app['pitch_summary'],
            (int)$app['price_normal'],
            (int)$app['price_peak'],
            $app['contact_phone'],
            $gmapUrl,
            $desc,
            $venueImg
        ]);
        $newFieldId = (int)$pdo->lastInsertId();
        $createdFieldIds = [$newFieldId];

        // Nếu cụm sân chính là Sân 11 hoặc Sân 7 và có sân 5 người con, tự động tạo các sân 5 con liên kết parent_id
        // để bảo toàn tính năng khóa chéo Sân Cha - Sân Con của hệ thống
        if ($mainType !== '5' && (int)$app['cnt_pitch_5'] > 0) {
            $childCount = min(4, max(1, (int)$app['cnt_pitch_5']));
            $letters = ['A', 'B', 'C', 'D'];
            $childPrice = max(100000, (int)round($app['price_normal'] * 0.75));
            $childPeak  = max(150000, (int)round($app['price_peak'] * 0.75));
            for ($ci = 0; $ci < $childCount; $ci++) {
                $cCode = '5' . $letters[$ci];
                $stmtInsField->execute([
                    $newVenueId,
                    (int)$app['user_id'],
                    $cCode,
                    $app['venue_name'] . ' (Sân ' . $cCode . ')',
                    '5',
                    $app['venue_address'],
                    $app['district'],
                    $app['latitude'],
                    $app['longitude'],
                    $childPrice,
                    $app['pitch_summary'],
                    $childPrice,
                    $childPeak,
                    $app['contact_phone'],
                    $gmapUrl,
                    $desc . ' (Sân 5 người con ' . $cCode . ' thuộc tổ hợp ' . $app['venue_name'] . ')',
                    $venueImg
                ]);
                $childId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE fields SET parent_id = ? WHERE id = ?")->execute([$newFieldId, $childId]);
                $createdFieldIds[] = $childId;
            }
        }

        // Khởi tạo khung giá chuẩn (Giờ thường / Giờ vàng / Cuối tuần) cho các sân vừa tạo
        $stmtPr = $pdo->prepare("
            INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($createdFieldIds as $cfid) {
            $fBase = ($cfid === $newFieldId) ? (int)$app['price_normal'] : max(100000, (int)round($app['price_normal'] * 0.75));
            $fPeak = ($cfid === $newFieldId) ? (int)$app['price_peak']   : max(150000, (int)round($app['price_peak'] * 0.75));
            $stmtPr->execute([$cfid, 'WEEKDAY', '05:00:00', '16:00:00', $fBase, 'Giờ thường ban ngày']);
            $stmtPr->execute([$cfid, 'WEEKDAY', '16:00:00', '20:30:00', $fPeak, '🔥 Giờ Vàng cao điểm']);
            $stmtPr->execute([$cfid, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($fBase + $fPeak) / 2), 'Giờ tối muộn']);
            $stmtPr->execute([$cfid, 'WEEKEND', '05:00:00', '16:00:00', (int)round($fBase * 1.15), 'Cuối tuần ban ngày']);
            $stmtPr->execute([$cfid, 'WEEKEND', '16:00:00', '23:00:00', (int)round($fPeak * 1.1), '🔥 Cuối tuần Giờ Vàng']);
        }

        // 3. Cập nhật trạng thái đơn đăng ký trong `partner_applications` thành APPROVED
        $stmtUpdApp = $pdo->prepare("
            UPDATE partner_applications
            SET status = 'APPROVED',
                rejection_reason = NULL,
                reviewed_by = ?,
                reviewed_at = NOW(),
                approved_field_id = ?,
                approved_venue_id = ?
            WHERE id = ?
        ");
        $stmtUpdApp->execute([(int)$adminId, $newFieldId, $newVenueId, (int)$appId]);

        // 4. Nâng cấp quyền user lên PITCH_OWNER (giữ nguyên nếu user đó đang là ADMIN)
        $stmtUpdUser = $pdo->prepare("
            UPDATE users
            SET role = IF(role = 'admin', 'admin', 'pitch_owner'),
                account_role = IF(account_role = 'ADMIN', 'ADMIN', 'PITCH_OWNER'),
                partner_status = 'APPROVED'
            WHERE id = ?
        ");
        $stmtUpdUser->execute([(int)$app['user_id']]);

        $pdo->commit();

        // Cập nhật lại bảng khóa chéo Sân Cha - Sân Con sau khi thêm sân mới
        if (function_exists('rebuild_field_conflict_map')) {
            rebuild_field_conflict_map();
        }

        // Cộng 100 điểm thưởng Loyalty chào mừng Chủ sân đối tác mới & gửi thông báo
        record_wallet_and_reputation(
            (int)$app['user_id'],
            'LOYALTY',
            0,
            5,
            100,
            "Chúc mừng hồ sơ '{$app['venue_name']}' được duyệt! Tài khoản nâng cấp lên Chủ Sân (PITCH_OWNER).",
            null,
            (int)$adminId
        );
        notify(
            (int)$app['user_id'],
            "🎉 Chúc mừng! Hồ sơ đăng ký mở sân '{$app['venue_name']}' của bạn đã được Admin phê duyệt. Cụm sân đã được kích hoạt trên bản đồ và bạn có thể bấm nút '🏟 Kênh Chủ Sân' trên thanh Header để quản lý!"
        );

        return [
            'ok'       => true,
            'field_id' => $newFieldId,
            'message'  => "Đã phê duyệt hồ sơ '{$app['venue_name']}', nâng cấp tài khoản lên PITCH_OWNER và khởi tạo cụm sân (ID #{$newFieldId}) thành công!"
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Lỗi Transaction khi phê duyệt hồ sơ: ' . $e->getMessage()];
    }
}
