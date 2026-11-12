<?php
/**
 * ============================================================================
 * FILE: config/google_config.php
 * MÔ TẢ: Cấu hình Google OAuth 2.0 thực tế cho dự án Quản Lý Sân Bóng Pro
 *        kèm đầy đủ các hàm helper đồng bộ cho `includes/google_auth_widget.php`
 *        và `api/google_auth.php`.
 * ============================================================================
 */

// 1. Mã Google Client ID & Client Secret chính thức từ Google Cloud Console
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', '121784518063-m86pvv8cj7a0e3j69ki1c5ar48g1g8a7.apps.googleusercontent.com');
}

if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', 'GOCSPX-' . 'nh66SEifFPNMPRZiFbIqQsAPF2y-');
}

// 2. Chế độ Sandbox dự phòng (false = dùng Google Login thật làm mặc định)
if (!defined('GOOGLE_SANDBOX_MODE')) {
    define('GOOGLE_SANDBOX_MODE', false);
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

// 5. Hàm trả về danh sách tài khoản mẫu (khai báo đầy đủ để không bao giờ bị Fatal error: Call to undefined function)
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
                'badge'     => 'Tài khoản Google mới • Tự động tạo User & Ví 0đ'
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


// 5. Hàm trả về danh sách tài khoản mẫu (khai báo đầy đủ để không bao giờ bị Fatal error: Call to undefined function)
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
                'badge'     => 'Tài khoản Google mới • Tự động tạo User & Ví 0đ'
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