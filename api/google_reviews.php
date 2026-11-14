<?php
/**
 * ============================================================================
 * FILE: api/google_reviews.php
 * MÔ TẢ: API lấy & đồng bộ trực tiếp đánh giá sân bóng từ Google Maps
 *        (Hỗ trợ nút "Đồng bộ mới nhất từ Google Maps" trên trang chi tiết sân).
 * ============================================================================
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google_reviews_engine.php';

header('Content-Type: application/json; charset=utf-8');

$fieldId = (int)($_GET['field_id'] ?? $_POST['field_id'] ?? 0);
$force   = !empty($_GET['force']) || !empty($_POST['force']);

if ($fieldId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Thiếu mã sân bóng (field_id).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare("SELECT * FROM fields WHERE id = ? LIMIT 1");
$st->execute([$fieldId]);
$field = $st->fetch(PDO::FETCH_ASSOC);

if (!$field) {
    echo json_encode(['ok' => false, 'message' => 'Không tìm thấy sân bóng.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = get_google_maps_reviews_for_field($pdo, $field, $force);

echo json_encode([
    'ok'   => true,
    'data' => $data,
], JSON_UNESCAPED_UNICODE);
