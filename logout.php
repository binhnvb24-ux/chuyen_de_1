<?php
/**
 * ============================================================================
 * FILE: logout.php
 * MÔ TẢ: Xử lý đăng xuất tài khoản khỏi hệ thống Đặt Sân Bóng (hỗ trợ cả AJAX & Redirect).
 * CHỨC NĂNG:
 *   1. Xóa sạch Session, hủy Cookie phiên làm việc và các Cookie ghi nhớ đăng nhập.
 *   2. Khởi tạo Session mới chỉ chứa thông báo Flash đăng xuất thành công.
 *   3. Trả về JSON (nếu gọi qua API/AJAX) hoặc chuyển hướng về trang Đăng nhập / Trang chủ.
 * ============================================================================
 */

require_once __DIR__ . '/includes/auth.php';

perform_logout();

$redirectTo = ($_GET['redirect'] ?? 'login') === 'home'
    ? project_base() . 'index.php'
    : project_base() . 'login.php';

if (!empty($_GET['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'       => true,
        'message'  => 'Đã đăng xuất khỏi tài khoản thành công.',
        'redirect' => $redirectTo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Location: ' . $redirectTo);
exit;
