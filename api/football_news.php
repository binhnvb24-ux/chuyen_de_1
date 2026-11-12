<?php
/**
 * ============================================================================
 * FILE: api/football_news.php
 * MÔ TẢ: API trả về danh sách Bản Tin Bóng Đá 24/7 mới nhất dưới dạng JSON
 *        phục vụ nút "🔄 Làm mới tin" trên Trang chủ (index.php).
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/football_news_feed.php';

$force = !empty($_GET['refresh']);
$items = get_latest_football_news(5, $force);

echo json_encode([
    'ok'         => true,
    'updated_at' => date('H:i:s d/m/Y'),
    'items'      => $items
], JSON_UNESCAPED_UNICODE);

