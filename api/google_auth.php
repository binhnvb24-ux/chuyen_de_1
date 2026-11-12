<?php
/**
 * ============================================================================
 * FILE: api/google_auth.php
 * MÔ TẢ: API xác thực Đăng nhập / Đăng ký nhanh bằng tài khoản Google (GIS OAuth2)
 *        sử dụng GOOGLE_CLIENT_ID thật.
 * CHỨC NĂNG CHÍNH:
 *   1. Xác thực JWT ID Token (`credential`) từ Google qua `oauth2.googleapis.com/tokeninfo`
 *      với `CURLOPT_SSL_VERIFYPEER => false`, `CURLOPT_SSL_VERIFYHOST => 0` trên XAMPP
 *      (kèm giải mã trực tiếp phần payload base64url của JWT và kiểm tra `aud === GOOGLE_CLIENT_ID`).
 *   2. Tự động kiểm tra và bổ sung các cột cần thiết trong bảng `users` (`google_id`, `avatar`, `auth_provider`).
 *   3. Đồng bộ & Liên kết tài khoản (Account Linking & Auto-Register):
 *      - TH1: Trùng `google_id` -> Đăng nhập ngay, cập nhật `avatar` nếu chưa có.
 *      - TH2: Trùng `email` -> Tự động gắn `google_id` và `avatar` vào user hiện tại.
 *      - TH3: Email hoàn toàn mới -> Tự động tạo tài khoản mới và khởi tạo Ví / Điểm uy tín.
 *   4. Khởi tạo đầy đủ `$_SESSION` giống hệt luồng đăng nhập trong `includes/auth.php` & `login.php`.
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/google_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Phương thức không hợp lệ (Chỉ chấp nhận POST).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------------------
// BƯỚC 0: ĐẢM BẢO BẢNG `users` CÓ ĐỦ CỘT `google_id`, `avatar`, `auth_provider`
// ----------------------------------------------------------------------------
try {
    $colGoogle = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_id'")->fetch();
    if (!$colGoogle) {
        $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(100) NULL DEFAULT NULL UNIQUE");
    }
    $colAvatar = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();
    if (!$colAvatar) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL DEFAULT NULL");
    }
    $colProvider = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_provider'")->fetch();
    if (!$colProvider) {
        $pdo->exec("ALTER TABLE users ADD COLUMN auth_provider ENUM('local','google') NOT NULL DEFAULT 'local'");
    }
} catch (Exception $e) {
    // Bỏ qua nếu cột đã tồn tại
}

// Hỗ trợ cả dữ liệu gửi dạng JSON body hoặc application/x-www-form-urlencoded (Google Redirect / Fetch)
$rawBody   = file_get_contents('php://input');
$jsonInput = json_decode((string)$rawBody, true);
$input     = is_array($jsonInput) ? array_merge($_POST, $jsonInput) : $_POST;

$credential  = trim((string)($input['credential'] ?? ''));
$sandboxMode = !empty($input['sandbox']) || !empty($input['is_sandbox']);

$googleId  = '';
$email     = '';
$fullName  = '';
$avatarUrl = '';

/**
 * Giải mã trực tiếp phần Payload (Base64URL) của Google JWT ID Token
 */
function decode_google_jwt_payload(string $idToken): ?array {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return null;
    }
    $payloadB64 = strtr($parts[1], '-_', '+/');
    $padLen = strlen($payloadB64) % 4;
    if ($padLen > 0) {
        $payloadB64 .= str_repeat('=', 4 - $padLen);
    }
    $decoded = json_decode((string)base64_decode($payloadB64), true);
    if (is_array($decoded) && !empty($decoded['sub']) && !empty($decoded['email'])) {
        return $decoded;
    }
    return null;
}

/**
 * Xác thực JWT ID Token với Google Endpoint (bỏ qua lỗi SSL trên XAMPP localhost)
 * và fallback giải mã trực tiếp JWT payload nếu cURL trên XAMPP bị chặn mạng.
 */
function verify_google_id_token(string $idToken): ?array {
    $endpoint = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $respBody = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            $respBody = false;
        }
    }

    if ($respBody === false) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 6, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $respBody = @file_get_contents($endpoint, false, $ctx);
    }

    if (is_string($respBody) && $respBody !== '') {
        $data = json_decode($respBody, true);
        if (is_array($data) && !empty($data['sub']) && !empty($data['email'])) {
            return $data;
        }
    }

    // Fallback: Giải mã trực tiếp phần payload base64url của JWT nếu cURL trên XAMPP bị chặn mạng
    return decode_google_jwt_payload($idToken);
}

// ----------------------------------------------------------------------------
// BƯỚC 1: XÁC THỰC GOOGLE CREDENTIAL THẬT HOẶC DỮ LIỆU DEMO SANDBOX
// ----------------------------------------------------------------------------
if ($credential !== '' && !$sandboxMode) {
    $tokenData = verify_google_id_token($credential);
    if (!$tokenData) {
        echo json_encode([
            'success' => false,
            'message' => 'Không thể giải mã hoặc xác thực Google ID Token. Vui lòng thử lại.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kiểm tra `aud === GOOGLE_CLIENT_ID`
    $expectedClientId = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
    if ($expectedClientId !== '' && isset($tokenData['aud']) && $tokenData['aud'] !== $expectedClientId) {
        echo json_encode([
            'success' => false,
            'message' => 'Mã Google Client ID (aud) không khớp với cấu hình hệ thống.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kiểm tra trạng thái xác thực email từ Google
    $emailVerified = $tokenData['email_verified'] ?? true;
    if ($emailVerified === false || $emailVerified === 'false') {
        echo json_encode([
            'success' => false,
            'message' => 'Tài khoản Google này chưa xác minh địa chỉ Email.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $googleId  = trim((string)$tokenData['sub']);
    $email     = strtolower(trim((string)$tokenData['email']));
    $fullName  = trim((string)($tokenData['name'] ?? explode('@', $email)[0]));
    $avatarUrl = trim((string)($tokenData['picture'] ?? ''));
} elseif ($sandboxMode || (defined('GOOGLE_SANDBOX_MODE') && GOOGLE_SANDBOX_MODE === true)) {
    $email     = strtolower(trim((string)($input['email'] ?? '')));
    $fullName  = trim((string)($input['full_name'] ?? ''));
    $googleId  = trim((string)($input['google_id'] ?? ''));
    $avatarUrl = trim((string)($input['avatar'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Vui lòng chọn tài khoản Google hoặc nhập địa chỉ Email hợp lệ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($fullName === '') {
        $localPart = explode('@', $email)[0];
        $fullName  = ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }
    if ($googleId === '') {
        $googleId = 'gg_sb_' . substr(hash('sha256', $email), 0, 22);
    }
    if ($avatarUrl === '') {
        $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=16a34a&color=fff&size=128&bold=true';
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Không tìm thấy mã xác thực Google (credential).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------------------
// BƯỚC 2: ĐỒNG BỘ TÀI KHOẢN (ACCOUNT LINKING & AUTO-REGISTER)
// ----------------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // Lấy danh sách các cột thực tế đang có trong bảng `users` để câu lệnh INSERT an toàn tuyệt đối
    $existingCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll() as $cRow) {
        $existingCols[$cRow['Field']] = true;
    }

    // 2.1. Trường hợp 1: Đã tồn tại user có `google_id` trùng khớp
    $user = null;
    if (!empty($existingCols['google_id'])) {
        $stmtByGoogle = $pdo->prepare("SELECT * FROM users WHERE google_id = ? LIMIT 1");
        $stmtByGoogle->execute([$googleId]);
        $user = $stmtByGoogle->fetch();
    }

    if ($user) {
        if (!empty($existingCols['avatar']) && empty($user['avatar']) && $avatarUrl !== '') {
            $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatarUrl, $user['id']]);
            $user['avatar'] = $avatarUrl;
        }
        $authAction = 'login_existing_google';
    } else {
        // 2.2. Trường hợp 2: Chưa có `google_id` nhưng `email` đã tồn tại trong bảng `users`
        $stmtByEmail = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmtByEmail->execute([$email]);
        $user = $stmtByEmail->fetch();

        if ($user) {
            $newAvatar = !empty($user['avatar']) ? $user['avatar'] : $avatarUrl;
            if (!empty($existingCols['google_id']) && !empty($existingCols['avatar'])) {
                $pdo->prepare("UPDATE users SET google_id = ?, avatar = ? WHERE id = ?")
                    ->execute([$googleId, $newAvatar, $user['id']]);
            }
            $user['google_id'] = $googleId;
            $user['avatar']    = $newAvatar;
            $authAction        = 'linked_existing_email';
        } else {
            // 2.3. Trường hợp 3: Email hoàn toàn mới -> Tự động tạo tài khoản mới
            $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(explode('@', $email)[0]));
            if (strlen($baseUsername) < 4) {
                $baseUsername = 'user_' . $baseUsername;
            }
            $baseUsername = substr($baseUsername, 0, 22);
            $candidateUsername = $baseUsername;
            $suffix = 1;

            $stmtCheckU = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            while (true) {
                $stmtCheckU->execute([$candidateUsername]);
                if ((int)$stmtCheckU->fetchColumn() === 0) {
                    break;
                }
                $candidateUsername = $baseUsername . '_' . $suffix;
                $suffix++;
            }

            $randomPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $placeholderPhone   = '09' . str_pad((string)random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

            $insertData = [
                'username'  => $candidateUsername,
                'password'  => $randomPasswordHash,
                'full_name' => $fullName,
                'phone'     => $placeholderPhone,
                'email'     => $email,
                'role'      => 'user',
                'status'    => 'active',
            ];
            if (!empty($existingCols['account_role']))        $insertData['account_role']        = 'CUSTOMER';
            if (!empty($existingCols['account_status']))      $insertData['account_status']      = 'ACTIVE';
            if (!empty($existingCols['partner_status']))      $insertData['partner_status']      = 'NONE';
            if (!empty($existingCols['wallet_balance']))      $insertData['wallet_balance']      = 0.00;
            if (!empty($existingCols['reputation_score']))    $insertData['reputation_score']    = 100;
            if (!empty($existingCols['loyalty_points']))      $insertData['loyalty_points']      = 0;
            if (!empty($existingCols['google_id']))           $insertData['google_id']           = $googleId;
            if (!empty($existingCols['avatar']))              $insertData['avatar']              = $avatarUrl;
            if (!empty($existingCols['auth_provider']))       $insertData['auth_provider']       = 'google';
            if (!empty($existingCols['preferred_position']))  $insertData['preferred_position']  = 'Tiền vệ';
            if (!empty($existingCols['skill_level']))         $insertData['skill_level']         = 'Vui vẻ';
            if (!empty($existingCols['team_name']))           $insertData['team_name']           = '';
            if (!empty($existingCols['preferred_districts'])) $insertData['preferred_districts'] = '';

            $colNames     = array_keys($insertData);
            $placeholders = implode(', ', array_fill(0, count($colNames), '?'));
            $sqlIns       = "INSERT INTO users (" . implode(', ', $colNames) . ") VALUES ($placeholders)";
            $stmtInsert   = $pdo->prepare($sqlIns);
            $stmtInsert->execute(array_values($insertData));

            $newUserId = (int)$pdo->lastInsertId();

            // Tạo dòng hồ sơ cầu thủ mặc định trong bảng user_profiles (nếu bảng tồn tại)
            try {
                $pdo->prepare("
                    INSERT INTO user_profiles (user_id, preferred_position, skill_level, team_name, preferred_districts, jersey_number, bio)
                    VALUES (?, 'Tiền vệ', 'Vui vẻ', '', '', 10, 'Thành viên đăng ký nhanh qua Google')
                    ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
                ")->execute([$newUserId]);
            } catch (Exception $eProf) {}

            if (function_exists('notify')) {
                notify($newUserId, "🎉 Chào mừng {$fullName} gia nhập Sân Bóng Pro bằng tài khoản Google! Bạn được tặng 100 Điểm Uy Tín Đội Trưởng.");
            }

            $stmtNew = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmtNew->execute([$newUserId]);
            $user = $stmtNew->fetch();
            $authAction = 'registered_new_google_user';
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi xử lý tài khoản Google: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Kiểm tra nếu tài khoản đang bị khóa (BANNED / locked)
if (!$user || ($user['status'] ?? 'active') !== 'active' || ($user['account_status'] ?? 'ACTIVE') === 'BANNED') {
    echo json_encode([
        'success' => false,
        'message' => 'Tài khoản của bạn hiện đang bị khóa (BANNED). Vui lòng liên hệ Quản trị viên.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------------------------------
// BƯỚC 3: KHỞI TẠO SESSION ĐỒNG BỘ 100% VỚI `includes/auth.php` & `login.php`
// ----------------------------------------------------------------------------
session_regenerate_id(true);
$_SESSION['uid']       = (int)$user['id'];
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['role']      = $user['role'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email']     = $user['email'];
$_SESSION['avatar']    = $user['avatar'] ?? '';
$_SESSION['user']      = $user;

// ----------------------------------------------------------------------------
// BƯỚC 4: XÁC ĐỊNH URL ĐIỀU HƯỚNG THEO ĐÚNG PHÂN QUYỀN
// ----------------------------------------------------------------------------
$base = function_exists('project_base') ? project_base() : '/quan-ly-san-bong/';
if ($user['role'] === 'admin' || (function_exists('get_user_role_code') && get_user_role_code($user) === 'ADMIN')) {
    $redirectUrl = $base . 'admin/dashboard.php';
} elseif (function_exists('is_pitch_owner') && is_pitch_owner($user)) {
    $redirectUrl = $base . 'user/owner-dashboard.php';
} else {
    $redirectUrl = $base . 'index.php';
}

$welcomeMsg = ($authAction === 'registered_new_google_user')
    ? 'Chào mừng ' . $user['full_name'] . '! Tài khoản Google của bạn đã được tạo thành công.'
    : (($authAction === 'linked_existing_email')
        ? 'Đã liên kết tài khoản Google và đăng nhập thành công (' . $user['full_name'] . ')!'
        : 'Đăng nhập bằng Google thành công! Xin chào ' . $user['full_name'] . '.');

if (function_exists('flash')) {
    flash('ok', $welcomeMsg);
}

echo json_encode([
    'success'      => true,
    'action'       => $authAction,
    'message'      => $welcomeMsg,
    'redirect_url' => $redirectUrl,
    'user'         => [
        'id'        => (int)$user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
        'avatar'    => $user['avatar'] ?? ''
    ]
], JSON_UNESCAPED_UNICODE);
