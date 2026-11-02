<?php
/**
 * ============================================================================
 * FILE: api/check_price_availability.php
 * MÔ TẢ: API tính giá động theo phân đoạn thời gian (Dynamic Pricing Engine)
 *        và kiểm tra khóa chéo Ghép sân - Tách sân (Hierarchical Availability).
 * ĐƯỢC GỌI TỪ: Trang đặt sân (user/booking.php) qua Fetch AJAX.
 * ============================================================================
 */

require_once '../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$field_id   = (int)($_GET['field_id'] ?? $_POST['field_id'] ?? 0);
$date       = trim($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));
$start_time = trim($_GET['start'] ?? $_POST['start'] ?? '17:30');
$end_time   = trim($_GET['end'] ?? $_POST['end'] ?? '19:00');

if ($field_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Thiếu mã sân bóng.']);
    exit;
}

// 1. Kiểm tra tính khả dụng (bao gồm khóa chéo Sân 5 <-> Sân 7 <-> Sân 11)
$avail = check_field_availability($field_id, $date, $start_time, $end_time);

// 2. Tính giá động theo từng phân đoạn 30 phút & loại ngày (Ngày thường / Cuối tuần / Lễ)
$pricing = calculate_dynamic_price($field_id, $date, $start_time, $end_time);

echo json_encode([
    'ok'        => true,
    'available' => $avail['available'],
    'conflicts' => $avail['conflicts'],
    'pricing'   => $pricing,
]);

