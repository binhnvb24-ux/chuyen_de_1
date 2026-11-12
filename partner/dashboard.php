<?php
/**
 * Partner Dashboard Alias (`/partner/dashboard.php`)
 * Chuyển hướng liền mạch đến trang Quản lý Sân Bóng (Partner Dashboard) chính tại `/user/owner-dashboard.php`
 * đồng thời giữ nguyên toàn bộ tham số truy vấn (venue_id, tab, date, field_id...).
 */
require_once __DIR__ . '/../includes/auth.php';
require_pitch_owner();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '../user/owner-dashboard.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target);
exit;
