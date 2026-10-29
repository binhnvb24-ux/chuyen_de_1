<?php
/**
 * FILE: api/ai_chat.php
 * MÔ TẢ: API Backend xử lý hội thoại cho Trợ lý AI Tư vấn Sân Bóng (trả về JSON).
 * CHỨC NĂNG: Kết hợp dữ liệu thời gian thực từ MySQL (sân, giá, giờ trống hôm nay/ngày mai, voucher, đơn đặt)
 *            với Google Gemini API (nếu có khóa) hoặc bộ xử lý ngôn ngữ Smart Local AI tích hợp sẵn.
 */
require_once '../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
$base = project_base();

// 1. Xử lý lấy lịch sử chat hoặc xóa lịch sử chat theo phiên (Session)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'history') {
  $sid = session_id();
  $stmt = $pdo->prepare("SELECT role, message, created_at FROM ai_chat_logs WHERE session_id=? ORDER BY id ASC LIMIT 30");
  $stmt->execute([$sid]);
  echo json_encode(['ok' => true, 'history' => $stmt->fetchAll()]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
  $sid = session_id();
  $pdo->prepare("DELETE FROM ai_chat_logs WHERE session_id=?")->execute([$sid]);
  echo json_encode(['ok' => true]);
  exit;
}

// 2. Đọc câu hỏi của người dùng từ JSON body hoặc POST và lưu vào bảng ai_chat_logs
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$msg = trim($data['message'] ?? $_POST['message'] ?? '');

if ($msg === '') {
  echo json_encode(['ok' => false, 'reply' => 'Bạn hãy nhập nội dung câu hỏi nhé!']);
  exit;
}

$sid = session_id();
$uid = $u['id'] ?? null;

try {
  $pdo->prepare("INSERT INTO ai_chat_logs(user_id, session_id, role, message) VALUES(?,?, 'user', ?)")
      ->execute([$uid, $sid, $msg]);
} catch (Exception $e) {}

// 3. Truy vấn dữ liệu thực tế từ CSDL (Sân bóng, Voucher, Dịch vụ, Khung giờ bận, Đơn của khách)
$fields = $pdo->query("SELECT * FROM fields WHERE status='active' ORDER BY rating DESC, price_per_hour ASC")->fetchAll();
$vouchers = $pdo->query("SELECT * FROM vouchers WHERE status='active' AND expired_at >= CURDATE() AND used_count < usage_limit ORDER BY discount_value DESC")->fetchAll();
$services = $pdo->query("SELECT * FROM services WHERE status='active' ORDER BY price ASC")->fetchAll();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$busyStmt = $pdo->prepare("SELECT field_id, booking_date, start_time, end_time FROM bookings WHERE booking_date IN (?,?) AND status IN ('pending','confirmed','completed') ORDER BY start_time");
$busyStmt->execute([$today, $tomorrow]);
$busyRows = $busyStmt->fetchAll();
$busyMap = [];
foreach ($busyRows as $br) {
  $busyMap[$br['field_id']][$br['booking_date']][] = substr($br['start_time'],0,5) . '-' . substr($br['end_time'],0,5);
}

$userBookings = [];
if ($uid) {
  $ubStmt = $pdo->prepare("SELECT b.*, f.name fname, p.status pay_status, p.method pay_method FROM bookings b JOIN fields f ON f.id=b.field_id LEFT JOIN payments p ON p.booking_id=b.id WHERE b.user_id=? ORDER BY b.id DESC LIMIT 5");
  $ubStmt->execute([$uid]);
  $userBookings = $ubStmt->fetchAll();
}

// 4. Chế độ 1: Gọi Google Gemini API nếu Admin đã cấu hình gemini_api_key
$apiKey = trim(get_setting('gemini_api_key', ''));
$reply = '';
$suggestions = [];

if ($apiKey !== '' && function_exists('curl_init')) {
  $contextText = "Bạn là Trợ lý AI thông minh của hệ thống Đặt Sân Bóng Trực Tuyến. Hãy trả lời ngắn gọn, thân thiện, nhiệt tình bằng tiếng Việt, có dùng icon hợp lý và định dạng rõ ràng.\n";
  $contextText .= "Hôm nay là ngày " . date('d/m/Y') . " (Giờ hiện tại: " . date('H:i') . ").\n";
  $contextText .= "Danh sách sân bóng đang hoạt động:\n";
  foreach ($fields as $f) {
    $bToday = !empty($busyMap[$f['id']][$today]) ? implode(', ', $busyMap[$f['id']][$today]) : 'Trống cả ngày';
    $contextText .= "- [ID:{$f['id']}] {$f['name']} (Sân {$f['type']} người, Khu vực: {$f['district']}, Địa chỉ: {$f['address']}), Giá: " . money($f['price_per_hour']) . "/giờ, Đánh giá: {$f['rating']} sao. Giờ đã đặt hôm nay: {$bToday}.\n";
  }
  $contextText .= "Danh sách Voucher khuyến mãi đang áp dụng:\n";
  foreach ($vouchers as $v) {
    $valStr = $v['discount_type']==='percent' ? "{$v['discount_value']}% (tối đa ".money($v['max_discount']).")" : money($v['discount_value']);
    $contextText .= "- Mã {$v['code']}: Giảm {$valStr} cho đơn từ " . money($v['min_order']) . " (Hạn: {$v['expired_at']}).\n";
  }
  $contextText .= "Dịch vụ đi kèm: ";
  foreach ($services as $sv) {
    $contextText .= "{$sv['name']} (" . money($sv['price']) . "), ";
  }

  $payload = [
    'contents' => [
      ['role' => 'user', 'parts' => [['text' => $contextText . "\nCâu hỏi của khách: " . $msg]]]
    ]
  ];
  $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode($apiKey));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 8
  ]);
  $resp = curl_exec($ch);
  curl_close($ch);
  if ($resp) {
    $j = json_decode($resp, true);
    if (!empty($j['candidates'][0]['content']['parts'][0]['text'])) {
      $reply = $j['candidates'][0]['content']['parts'][0]['text'];
    }
  }
}

// 5. Chế độ 2 (Fallback mặc định): Bộ xử lý Smart Local AI phân tích ý định câu hỏi trực tiếp từ CSDL
if ($reply === '') {
  $lower = mb_strtolower($msg, 'UTF-8');

  $matchType = null;
  if (preg_match('/(sân\s*5|5\s*người|5v5|10\s*người|8\s*người|nhóm\s*nhỏ)/u', $lower)) $matchType = '5';
  elseif (preg_match('/(sân\s*7|7\s*người|7v7|14\s*người|12\s*người|15\s*người)/u', $lower)) $matchType = '7';
  elseif (preg_match('/(sân\s*11|11\s*người|11v11|22\s*người|20\s*người|sân\s*lớn)/u', $lower)) $matchType = '11';

  $matchedDistrict = '';
  foreach ($fields as $f) {
    $dLow = mb_strtolower($f['district'], 'UTF-8');
    if ($dLow !== '' && mb_strpos($lower, $dLow) !== false) {
      $matchedDistrict = $f['district'];
      break;
    }
  }

  if (preg_match('/(voucher|mã\s*giảm|khuyến\s*mãi|ưu\s*đãi|code|giảm\s*giá)/u', $lower)) {
    if (!$vouchers) {
      $reply = "Hiện tại hệ thống chưa có mã giảm giá nào mới. Bạn hãy theo dõi trang thông báo thường xuyên nhé!";
    } else {
      $reply = "🎁 **Các mã giảm giá (Voucher) đang hoạt động dành cho bạn:**\n";
      foreach ($vouchers as $v) {
        $desc = $v['discount_type']==='percent'
          ? "Giảm **{$v['discount_value']}%**" . ($v['max_discount']>0 ? " (tối đa ".money($v['max_discount']).")" : "")
          : "Giảm trực tiếp **" . money($v['discount_value']) . "**";
        $reply .= "• Mã **`{$v['code']}`**: {$desc} — Đơn tối thiểu: " . money($v['min_order']) . " (HSD: " . date('d/m/Y', strtotime($v['expired_at'])) . ")\n";
      }
      $reply .= "\n💡 *Mẹo:* Nhập mã này tại bước **Đặt sân** để hệ thống tự động trừ tiền ngay lập tức!";
    }
  }
  elseif (preg_match('/(thanh\s*toán|chuyển\s*khoản|vietqr|momo|vnpay|trả\s*tiền|demo)/u', $lower)) {
    $reply = "💳 **Hướng dẫn Thanh toán & Cổng Thanh Toán Demo trên hệ thống:**\n"
           . "1. **VietQR Ngân hàng:** Tự động tạo mã QR chuẩn Napas247 kèm số tiền và nội dung chuyển khoản.\n"
           . "2. **Ví điện tử MoMo / Cổng VNPay:** Hỗ trợ giao diện thanh toán trực tuyến siêu tốc.\n"
           . "3. **Tiền mặt tại sân:** Thanh toán trực tiếp cho quản lý khi đến nhận sân.\n\n"
           . "✨ **Đặc biệt (Chế độ Demo):** Sau khi đặt sân, bạn sẽ được chuyển tới **Cổng Thanh Toán Demo**. Chỉ cần bấm nút *\"Giả lập thanh toán thành công (1-Click)\"*, hệ thống sẽ lập tức gạch nợ, cấp mã giao dịch và tự động xác nhận lịch đặt sân cho bạn!";
  }
  elseif (preg_match('/(hủy\s*sân|đổi\s*giờ|hoàn\s*tiền|quy\s*định|chính\s*sách)/u', $lower)) {
    $reply = "📌 **Quy định đặt & hủy sân tại hệ thống:**\n"
           . "• **Thời gian hủy miễn phí:** Bạn được phép hủy đơn đặt sân trên web trước giờ bóng lăn tối thiểu **2 tiếng**.\n"
           . "• **Hoàn tiền:** Nếu đơn đã thanh toán qua QR/MoMo/VNPay và hủy đúng quy định, trạng thái thanh toán sẽ tự động chuyển sang **Hoàn tiền (Refunded)**.\n"
           . "• Bạn có thể quản lý hoặc hủy đơn dễ dàng tại mục **Lịch đặt của tôi**.";
  }
  elseif (preg_match('/(dịch\s*vụ|thuê\s*bóng|áo\s*bib|trọng\s*tài|nước\s*uống|quay\s*video)/u', $lower)) {
    $reply = "🥤 **Các dịch vụ tiện ích đi kèm khi đặt sân:**\n";
    foreach ($services as $sv) {
      $reply .= "• **{$sv['name']}**: " . money($sv['price']) . " — _{$sv['description']}_\n";
    }
    $reply .= "\nBạn có thể tích chọn số lượng dịch vụ ngay trong biểu mẫu **Đặt sân**, hệ thống sẽ cộng tự động vào hóa đơn!";
  }
  elseif (preg_match('/(đơn\s*của\s*tôi|lịch\s*của\s*tôi|kiểm\s*tra\s*đơn|đã\s*đặt)/u', $lower)) {
    if (!$u) {
      $reply = "🔐 Bạn chưa đăng nhập. Vui lòng **Đăng nhập** tài khoản để mình tra cứu lịch đặt sân và trạng thái thanh toán giúp bạn nhé!";
    } elseif (!$userBookings) {
      $reply = "Chào **{$u['full_name']}**, hiện tại bạn chưa có đơn đặt sân nào. Hãy chọn một sân bên dưới để lên lịch thi đấu ngay nhé!";
    } else {
      $stMap = ['pending'=>'Chờ xác nhận','confirmed'=>'Đã xác nhận','completed'=>'Hoàn thành','cancelled'=>'Đã hủy'];
      $reply = "📅 **Lịch đặt sân gần đây của {$u['full_name']}:**\n";
      foreach ($userBookings as $ub) {
        $stTxt = $stMap[$ub['status']] ?? $ub['status'];
        $payTxt = $ub['pay_status']==='paid' ? 'Đã thanh toán' : 'Chưa thanh toán';
        $reply .= "• **Đơn #{$ub['id']} - {$ub['fname']}**: Ngày " . date('d/m/Y', strtotime($ub['booking_date'])) . " (" . substr($ub['start_time'],0,5) . "-" . substr($ub['end_time'],0,5) . ") | Tổng: **" . money($ub['total_price']) . "** | Trạng thái: *{$stTxt} ({$payTxt})*\n";
      }
    }
  }
  elseif (preg_match('/(giờ\s*trống|còn\s*trống|lịch\s*trống|hôm\s*nay|tối\s*nay|ngày\s*mai)/u', $lower)) {
    $targetDate = (mb_strpos($lower, 'ngày mai') !== false || mb_strpos($lower, 'mai') !== false) ? $tomorrow : $today;
    $dateLabel = ($targetDate === $today) ? "hôm nay (" . date('d/m/Y') . ")" : "ngày mai (" . date('d/m/Y', strtotime($tomorrow)) . ")";
    $reply = "🕒 **Tình trạng khung giờ các sân {$dateLabel} (Hoạt động 06:00 - 23:00):**\n";
    foreach ($fields as $f) {
      if ($matchType && $f['type'] !== $matchType) continue;
      if ($matchedDistrict && $f['district'] !== $matchedDistrict) continue;
      $bList = $busyMap[$f['id']][$targetDate] ?? [];
      $statusStr = empty($bList) ? "🟢 **Trống tất cả các khung giờ**" : "🟡 Đã kín giờ: `" . implode(', ', $bList) . "` (Các giờ khác còn trống)";
      $reply .= "• **{$f['name']}** (Sân {$f['type']} - {$f['district']} - " . money($f['price_per_hour']) . "/h): {$statusStr}\n";
      $suggestions[] = [
        'id' => $f['id'],
        'name' => $f['name'],
        'type' => $f['type'],
        'price' => money($f['price_per_hour']),
        'url' => $base . "user/booking.php?field_id={$f['id']}&date={$targetDate}"
      ];
    }
    $reply .= "\n👉 Bấm vào nút **Đặt ngay** bên dưới để giữ khung giờ đẹp trước khi hết chỗ!";
  }
  elseif ($matchType || $matchedDistrict || preg_match('/(tư\s*vấn|gợi\s*ý|tìm\s*sân|giá\s*rẻ|sân\s*nào|bảng\s*giá|giá\s*sân)/u', $lower)) {
    $filtered = array_filter($fields, function($f) use ($matchType, $matchedDistrict, $lower) {
      if ($matchType && $f['type'] !== $matchType) return false;
      if ($matchedDistrict && $f['district'] !== $matchedDistrict) return false;
      return true;
    });
    if (preg_match('/(rẻ|tiết\s*kiệm|sinh\s*viên)/u', $lower)) {
      usort($filtered, fn($a, $b) => $a['price_per_hour'] <=> $b['price_per_hour']);
    }
    if (!$filtered) $filtered = $fields;

    $head = "⚽ **Gợi ý sân bóng phù hợp nhất cho bạn";
    if ($matchType) $head .= " (Sân {$matchType} người)";
    if ($matchedDistrict) $head .= " tại {$matchedDistrict}";
    $head .= ":**\n";
    $reply = $head;

    $count = 0;
    foreach ($filtered as $f) {
      if ($count++ >= 4) break;
      $bList = $busyMap[$f['id']][$today] ?? [];
      $freeNote = empty($bList) ? "Trống cả ngày hôm nay" : "Đã đặt: " . implode(', ', $bList);
      $reply .= "• **{$f['name']}** (Sân {$f['type']} người - {$f['district']})\n"
              . "  💰 Giá: **" . money($f['price_per_hour']) . "/giờ** | ⭐ {$f['rating']} ({$f['review_count']} đánh giá) | 🕒 _{$freeNote}_\n";
      $suggestions[] = [
        'id' => $f['id'],
        'name' => $f['name'],
        'type' => $f['type'],
        'price' => money($f['price_per_hour']),
        'url' => $base . "user/booking.php?field_id={$f['id']}"
      ];
    }
    if (!empty($vouchers)) {
      $v0 = $vouchers[0];
      $reply .= "\n🔥 *Đừng quên nhập mã **`{$v0['code']}`** khi thanh toán để được giảm giá nhé!*";
    }
  }
  else {
    $minPrice = !empty($fields) ? min(array_column($fields, 'price_per_hour')) : 120000;
    $reply = "👋 Xin chào" . ($u ? " **{$u['full_name']}**" : " bạn") . "! Mình là **Trợ lý AI Sân Bóng**.\n\n"
           . "Hiện hệ thống đang có **" . count($fields) . " sân bóng chất lượng cao** (Sân 5, Sân 7, Sân 11) với giá chỉ từ **" . money($minPrice) . "/giờ** cùng **" . count($vouchers) . " mã giảm giá** hấp dẫn.\n\n"
           . "Bạn có thể hỏi mình bất cứ điều gì, ví dụ:\n"
           . "• *\"Tư vấn sân 7 người giá rẻ còn trống tối nay\"*\n"
           . "• *\"Cho mình xin mã giảm giá voucher\"*\n"
           . "• *\"Hướng dẫn thanh toán QR / MoMo Demo\"*\n"
           . "• *\"Kiểm tra đơn đặt sân của tôi\"*";
    foreach (array_slice($fields, 0, 3) as $f) {
      $suggestions[] = [
        'id' => $f['id'],
        'name' => $f['name'],
        'type' => $f['type'],
        'price' => money($f['price_per_hour']),
        'url' => $base . "user/booking.php?field_id={$f['id']}"
      ];
    }
  }
}

// 6. Lưu câu trả lời của AI vào CSDL và trả kết quả JSON về cho trình duyệt
try {
  $pdo->prepare("INSERT INTO ai_chat_logs(user_id, session_id, role, message) VALUES(?,?, 'assistant', ?)")
      ->execute([$uid, $sid, $reply]);
} catch (Exception $e) {}

echo json_encode([
  'ok' => true,
  'reply' => $reply,
  'suggestions' => $suggestions
]);
