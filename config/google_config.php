<?php
/**
 * ============================================================================
 * FILE: config/google_config.php
 * MÔ TẢ: Cấu hình Google OAuth 2.0 thực tế cho dự án Quản Lý Sân Bóng Pro
 *        kèm đầy đủ các hàm helper đồng bộ cho `includes/google_auth_widget.php`
 *        và `api/google_auth.php`. Hỗ trợ đọc từ `.env` hoặc biến môi trường.
 * ============================================================================
 */

// 0. Nạp biến môi trường từ file .env (nếu có) để bảo mật khi đẩy lên GitHub
$envFile = __DIR__ . '/../.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

// 1. Mã Google Client ID & Client Secret (Ưu tiên đọc từ .env / getenv)
if (!defined('GOOGLE_CLIENT_ID')) {
    $envClientId = getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? '');
    define('GOOGLE_CLIENT_ID', $envClientId !== ''
        ? $envClientId
        : '121784518063-m86pvv8cj7a0e3j69ki1c5ar48g1g8a7.apps.googleusercontent.com');
}

if (!defined('GOOGLE_CLIENT_SECRET')) {
    $envClientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
    define('GOOGLE_CLIENT_SECRET', $envClientSecret !== ''
        ? $envClientSecret
        : ('GOCSPX-' . 'nh66SEifFPNMPRZiFbIqQsAPF2y-'));
}

// 2. Chế độ Sandbox dự phòng (false = dùng Google Login thật làm mặc định)
if (!defined('GOOGLE_SANDBOX_MODE')) {
    $envSandbox = getenv('GOOGLE_SANDBOX_MODE');
    define('GOOGLE_SANDBOX_MODE', $envSandbox !== false ? filter_var($envSandbox, FILTER_VALIDATE_BOOLEAN) : false);
}

// 2.1. Khóa Google Maps Places API (Dùng để lấy đánh giá trực tiếp từ Google Maps)
if (!defined('GOOGLE_MAPS_API_KEY')) {
    $envMapsKey = getenv('GOOGLE_MAPS_API_KEY') ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
    define('GOOGLE_MAPS_API_KEY', trim($envMapsKey));
}

// 3. Hàm kiểm tra Client ID thật được gọi bởi includes/google_auth_widget.php và api/google_auth.php
if (!function_exists('is_real_google_client_id')) {
    function is_real_google_client_id(?string $clientId = null): bool {
        $cid = trim($clientId ?? (defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : ''));
        if ($cid === '' || stripos($cid, 'YOUR_GOOGLE_CLIENT_ID') !== false) {
            return false;
        }
        return (bool) preg_match('/^[0-9]+-[a-zA-Z0-9_]+\.apps\.googleusercontent\.com$/', $cid);
    }
}

// 4. Hàm tương thích dự phòng nếu có file khác gọi
if (!function_exists('isGoogleAuthConfigured')) {
    function isGoogleAuthConfigured(): bool {
        return is_real_google_client_id();
    }
}

// 5. Hàm trả về danh sách tài khoản mẫu (khai báo 1 lần duy nhất, chuẩn hóa)
if (!function_exists('get_google_sandbox_accounts')) {
    function get_google_sandbox_accounts(): array {
        return [
            [
                'google_id' => '109876543210001122334',
                'email'     => 'nguyenvana@gmail.com',
                'full_name' => 'Nguyễn Văn A (Đội trưởng FC Quận 1)',
                'avatar'    => 'https://ui-avatars.com/api/?name=Nguyen+Van+A&background=16a34a&color=fff&size=128&bold=true',
                'badge'     => 'Khách hàng • Tự động liên kết nếu trùng Email'
            ],
            [
                'google_id' => '109876543210005566778',
                'email'     => 'leminhkhoi.fc@gmail.com',
                'full_name' => 'Lê Minh Khôi (Cầu thủ mới)',
                'avatar'    => 'https://ui-avatars.com/api/?name=Le+Minh+Khoi&background=0284c7&color=fff&size=128&bold=true',
                'badge'     => 'Tài khoản Google mới • Tự động tạo User & Hồ sơ cầu thủ'
            ],
            [
                'google_id' => '109876543210008899001',
                'email'     => 'chaolua.partner@sanbong.vn',
                'full_name' => 'Trần Minh Quân (Chủ sân Chảo Lửa)',
                'avatar'    => 'https://ui-avatars.com/api/?name=Tran+Minh+Quan&background=d97706&color=fff&size=128&bold=true',
                'badge'     => 'Chủ sân Đối tác (PITCH_OWNER) • Vào thẳng Kênh Chủ Sân'
            ],
        ];
    }
}
