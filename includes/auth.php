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
    } catch (Exception $ex) {
        // Bỏ qua lỗi nếu quyền CSDL hạn chế hoặc bảng đã tồn tại
    }
}

// Thực thi kiểm tra cấu trúc CSDL ngay khi nạp auth.php
ensure_upgraded_schema();

// Nạp Engine xử lý 5 bài toán cốt lõi (Ghép/Tách sân, Trùng lịch 30p, Khóa HOLD 10p, Giá động, Đặt cố định & Xả sân)
require_once __DIR__ . '/booking_engine.php';

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
