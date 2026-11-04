<?php
/**
 * ============================================================================
 * FILE: includes/football_news_feed.php
 * MÔ TẢ: Bộ thu thập & cung cấp Tin Tức Bóng Đá Hiện Nay (Live RSS + Offline Fallback).
 * CƠ CHẾ HOẠT ĐỘNG:
 *   1. Tự động lấy RSS Thể Thao / Bóng Đá trực tiếp từ VnExpress (vnexpress.net/rss/the-thao.rss)
 *      với cơ chế lưu bộ nhớ đệm (Cache 15 phút) giúp trang chủ tải siêu nhanh.
 *   2. Lọc ưu tiên các tin tức về Bóng đá (Ngoại Hạng Anh, Champions League, ĐT Việt Nam, V-League...).
 *   3. Nếu máy chủ không có kết nối mạng (Offline), tự động hiển thị danh sách Bản tin Bóng đá
 *      tiêu điểm được biên tập sẵn kèm hình ảnh chất lượng cao.
 * ============================================================================
 */

if (!function_exists('get_latest_football_news')) {
    /**
     * Lấy danh sách bản tin bóng đá mới nhất
     *
     * @param int  $limit        Số lượng bài viết tối đa cần lấy (mặc định: 6)
     * @param bool $forceRefresh Bỏ qua cache để cập nhật tin mới ngay lập tức
     * @return array Danh sách bài viết gồm: title, summary, image, link, tag, time_label, source
     */
    function get_latest_football_news(int $limit = 6, bool $forceRefresh = false): array
    {
        $cacheDir  = __DIR__ . '/../uploads';
        $cacheFile = $cacheDir . '/football_news_cache.json';
        $cacheTtl  = 900; // Lưu cache 15 phút (900 giây)

        // 1. Kiểm tra nếu có file cache còn hạn thì đọc ngay để tối ưu tốc độ
        if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && count($cached) > 0) {
                return array_slice($cached, 0, $limit);
            }
        }

        $newsItems = [];

        // 2. Thử tải RSS trực tiếp từ chuyên mục Thể Thao - Bóng Đá
        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout'    => 2.5,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false
                ]
            ]);

            $rssRaw = @file_get_contents('https://vnexpress.net/rss/the-thao.rss', false, $ctx);
            if ($rssRaw) {
                $xml = @simplexml_load_string($rssRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml && isset($xml->channel->item)) {
                    $footballKeywords = [
                        'bóng đá', 'cầu thủ', 'hlv', 'clb', 'ngoại hạng anh', 'premier league',
                        'champions league', 'cúp c1', 'real madrid', 'barca', 'barcelona',
                        'man utd', 'mu', 'man city', 'arsenal', 'liverpool', 'chelsea',
                        'v-league', 'việt nam', 'tuyển', 'bàn thắng', 'trận', 'sân', 'vòng',
                        'fifa', 'aff', 'quả bóng vàng', 'messi', 'ronaldo', 'mbappe', 'haaland'
                    ];

                    foreach ($xml->channel->item as $item) {
                        $title   = trim((string)$item->title);
                        $link    = trim((string)$item->link);
                        $descRaw = (string)$item->description;
                        $pubDate = (string)$item->pubDate;

                        // Tách ảnh thumbnail từ thẻ <img> trong description RSS
                        $imgUrl = '';
                        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $descRaw, $mImg)) {
                            $imgUrl = html_entity_decode($mImg[1], ENT_QUOTES, 'UTF-8');
                        }

                        // Tách nội dung tóm tắt sạch (bỏ thẻ HTML)
                        $summary = trim(strip_tags(html_entity_decode($descRaw, ENT_QUOTES, 'UTF-8')));

                        // Kiểm tra từ khóa bóng đá
                        $haystack   = mb_strtolower($title . ' ' . $summary, 'UTF-8');
                        $isFootball = false;
                        foreach ($footballKeywords as $kw) {
                            if (mb_strpos($haystack, $kw) !== false) {
                                $isFootball = true;
                                break;
                            }
                        }

                        if (!$isFootball) {
                            continue;
                        }

                        // Phân loại nhãn giải đấu (Tag)
                        $tag = '⚽ Bóng đá 24h';
                        if (preg_match('/(việt nam|v-league|vff|hà nội fc|công an hà nội|nam định|hoàng anh gia lai|kim sang)/iu', $haystack)) {
                            $tag = '🇻🇳 Bóng đá Việt Nam';
                        } elseif (preg_match('/(champions league|cúp c1|châu âu|real|barca|bayern)/iu', $haystack)) {
                            $tag = '🏆 Champions League';
                        } elseif (preg_match('/(ngoại hạng anh|premier league|man city|arsenal|liverpool|man utd|chelsea)/iu', $haystack)) {
                            $tag = '🦁 Ngoại Hạng Anh';
                        }

                        // Tính thời gian đăng tương đối
                        $ts        = strtotime($pubDate) ?: time();
                        $diffMin   = max(1, (int)round((time() - $ts) / 60));
                        $timeLabel = $diffMin < 60
                            ? ($diffMin . ' phút trước')
                            : ($diffMin < 1440 ? ((int)round($diffMin / 60) . ' giờ trước') : date('d/m H:i', $ts));

                        $newsItems[] = [
                            'title'      => $title,
                            'summary'    => $summary ?: 'Cập nhật diễn biến mới nhất trên sân cỏ trong nước và quốc tế.',
                            'image'      => $imgUrl ?: 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&q=80',
                            'link'       => $link ?: '#fields',
                            'tag'        => $tag,
                            'time_label' => $timeLabel,
                            'source'     => 'VnExpress Thể Thao'
                        ];

                        if (count($newsItems) >= 8) {
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Chuyển sang danh sách dự phòng nếu không lấy được RSS
        }

        // 3. Nếu không có mạng hoặc RSS trống -> Dùng Bản tin Bóng đá Tiêu điểm hiện nay
        if (empty($newsItems)) {
            $newsItems = [
                [
                    'title'      => 'Cuộc đua vô địch Ngoại Hạng Anh: Liverpool, Arsenal và Man City bám đuổi nghẹt thở từng điểm số',
                    'summary'    => 'Giải Ngoại Hạng Anh đang chứng kiến màn đua tam mã kịch tính nhất thập kỷ với các trận cầu tâm điểm cuối tuần này.',
                    'image'      => 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=400&q=80',
                    'link'       => 'https://vnexpress.net/the-thao/bong-da',
                    'tag'        => '🦁 Ngoại Hạng Anh',
                    'time_label' => '18 phút trước',
                    'source'     => 'Bản Tin Bóng Đá Quốc Tế'
                ],
                [
                    'title'      => 'UEFA Champions League thể thức mới League Phase: Những siêu kinh điển châu Âu bùng nổ bàn thắng',
                    'summary'    => 'Thể thức vòng phân hạng 36 đội tại Cúp C1 Châu Âu mang đến hàng loạt cặp đấu đỉnh cao ngay từ giai đoạn đầu mùa giải.',
                    'image'      => 'https://images.unsplash.com/photo-1518091043644-c1d4457512c6?w=400&q=80',
                    'link'       => 'https://vnexpress.net/the-thao/bong-da',
                    'tag'        => '🏆 Champions League',
                    'time_label' => '42 phút trước',
                    'source'     => 'UEFA Match Center'
                ],
                [
                    'title'      => 'Đội tuyển Việt Nam & V-League tăng tốc: Dàn sao nội binh và cầu thủ Việt kiều tỏa sáng rực rỡ',
                    'summary'    => 'Các vòng đấu V-League diễn ra sôi nổi với chất lượng chuyên môn cao, tạo bước đệm quan trọng cho Đội tuyển Quốc gia.',
                    'image'      => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018?w=400&q=80',
                    'link'       => 'https://vnexpress.net/the-thao/bong-da',
                    'tag'        => '🇻🇳 Bóng đá Việt Nam',
                    'time_label' => '1 giờ trước',
                    'source'     => 'Bóng Đá Việt Nam 24/7'
                ],
                [
                    'title'      => 'Bóng đá phủi 7 người (VPL / SPL): Sức hút cuồng nhiệt từ những chảo lửa sân cỏ nhân tạo',
                    'summary'    => 'Hệ thống giải bóng đá 7 người phong trào tiếp tục thu hút hàng nghìn khán giả đến sân mỗi dịp cuối tuần.',
                    'image'      => 'https://images.unsplash.com/photo-1431324155629-1a6deb1dec8d?w=400&q=80',
                    'link'       => 'https://vnexpress.net/the-thao/bong-da',
                    'tag'        => '🔥 Bóng Đá Phủi 7v7',
                    'time_label' => '2 giờ trước',
                    'source'     => 'Nhịp Đập Sân Phủi'
                ],
                [
                    'title'      => 'Bí quyết chọn giày đá sân cỏ nhân tạo (Đinh TF) và khởi động chống chấn thương cổ chân',
                    'summary'    => 'Hướng dẫn bài tập làm nóng cơ khớp 10 phút trước khi ra sân giúp anh em cầu thủ phủi bứt tốc an toàn.',
                    'image'      => 'https://images.unsplash.com/photo-1511886929837-354d827aae26?w=400&q=80',
                    'link'       => 'https://vnexpress.net/the-thao/bong-da',
                    'tag'        => '👟 Kỹ Chiến Thuật',
                    'time_label' => '3 giờ trước',
                    'source'     => 'Cẩm Nang Cầu Thủ'
                ]
            ];
        }

        // Lưu lại vào file cache
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents($cacheFile, json_encode($newsItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return array_slice($newsItems, 0, $limit);
    }
}

