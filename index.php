<?php
/**
 * ============================================================================
 * FILE: index.php
 * MÔ TẢ: Trang chủ của hệ thống Đặt Sân Bóng Trực Tuyến (Dynamic Daylight Sport Pro).
 * CẤU TRÚC BỐ CỤC CHÍNH:
 *   - Phần 1: Kiểm tra tài khoản đăng nhập hiện tại.
 *   - Phần 2: Xử lý bộ lọc tìm kiếm sân bóng (Từ khóa, Loại sân, Khu vực, Giá, Giờ trống).
 *   - Phần 3: Truy vấn danh sách sân, trạng thái sân bận/trống, Sàn Cáp Kèo & Voucher.
 *   - Phần 4: Truy vấn lịch đặt sân gần đây & thông báo của người dùng.
 *   - Phần 5: Giao diện Thanh điều hướng trên cùng (Header Topbar).
 *   - Phần 6: Cột 1 - Menu điều hướng nhanh bên trái (Sidebar Left).
 *   - Phần 7: Cột 2 - Nội dung chính (Hero Banner, Bảng tỉ số Live, Danh sách sân,
 *                     Sàn Cáp Kèo, Danh mục sân 5/7/11, Lịch đặt gần đây, Ưu đãi).
 *   - Phần 8: Cột 3 - Tiện ích bên phải (Vé của tôi, Bản tin sân cỏ, Hotline).
 *   - Phần 9: Chân trang (Footer) và Tiện ích Trợ lý AI (Chat Widget).
 * ============================================================================
 */

require_once 'includes/auth.php';
require_once 'includes/football_news_feed.php';

// ----------------------------------------------------------------------------
// PHẦN 1: KIỂM TRA NGƯỜI DÙNG ĐANG ĐĂNG NHẬP
// (Cho phép cả Khách hàng và Admin xem giao diện Trang chủ)
// ----------------------------------------------------------------------------
$u = current_user();

// ----------------------------------------------------------------------------
// PHẦN 2: XỬ LÝ THAM SỐ TÌM KIẾM & BỘ LỌC SÂN BÓNG TỪ URL ($_GET)
// ----------------------------------------------------------------------------
$q     = trim($_GET['q'] ?? '');
$type  = in_array($_GET['type'] ?? '', ['5', '7', '11']) ? $_GET['type'] : '';
$dist  = trim($_GET['district'] ?? '');
$max   = (int)($_GET['max'] ?? 0);
$date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
$start = preg_match('/^\d{2}:00$/', $_GET['start'] ?? '') ? $_GET['start'] : '';

// Khởi tạo câu truy vấn cơ sở lấy các sân đang hoạt động (active)
$sql = "SELECT * FROM fields f WHERE f.status = 'active' AND (f.name LIKE ? OR f.address LIKE ?)";
$p   = ["%$q%", "%$q%"];

// Nối thêm điều kiện lọc theo Loại sân (5, 7, 11 người)
if ($type) {
    $sql .= " AND f.type = ?";
    $p[]  = $type;
}

// Nối thêm điều kiện lọc theo Quận / Huyện
if ($dist) {
    $sql .= " AND f.district = ?";
    $p[]  = $dist;
}

// Nối thêm điều kiện lọc theo Mức giá tối đa mỗi giờ
if ($max) {
    $sql .= " AND f.price_per_hour <= ?";
    $p[]  = $max;
}

// Nối thêm điều kiện kiểm tra sân còn trống trong Ngày & Khung giờ người dùng chọn
if ($date && $start) {
    $end  = sprintf('%02d:00', (int)$start + 1);
    $sql .= " AND NOT EXISTS (
                SELECT 1 FROM bookings b
                WHERE b.field_id = f.id
                  AND b.booking_date = ?
                  AND b.status IN ('pending', 'confirmed', 'completed')
                  AND b.start_time < ?
                  AND b.end_time > ?
              )";
    array_push($p, $date, $end, $start);
}

// ----------------------------------------------------------------------------
// PHẦN 3: TRUY VẤN DANH SÁCH SÂN, QUẬN/HUYỆN, SÂN ĐANG BẬN & SÀN CÁP KÈO
// ----------------------------------------------------------------------------

// 3.1. Lấy tối đa 12 sân bóng sắp xếp theo điểm đánh giá (rating) cao nhất
$s = $pdo->prepare($sql . " ORDER BY f.rating DESC LIMIT 12");
$s->execute($p);
$fields = $s->fetchAll();

// 3.2. Lấy danh sách các Quận/Huyện hiện có để đổ vào ô <select> bộ lọc
$districts = $pdo->query("
    SELECT DISTINCT district
    FROM fields
    WHERE status = 'active' AND district <> ''
    ORDER BY district
")->fetchAll(PDO::FETCH_COLUMN);

// 3.3. Lấy danh sách ID các sân đang có trận đấu diễn ra ngay tại thời điểm hiện tại
$busyNow = $pdo->query("
    SELECT DISTINCT field_id
    FROM bookings
    WHERE booking_date = CURDATE()
      AND status IN ('pending', 'confirmed')
      AND start_time <= CURTIME()
      AND end_time > CURTIME()
")->fetchAll(PDO::FETCH_COLUMN);

// 3.4. Thống kê số lượng sân và giá thấp nhất theo từng thể thức (Sân 5, Sân 7, Sân 11)
$cats = $pdo->query("
    SELECT type, COUNT(*) AS n, MIN(price_per_hour) AS p, MIN(image) AS img
    FROM fields
    WHERE status = 'active'
    GROUP BY type
    ORDER BY type
")->fetchAll();

// 3.5. Lấy 3 kèo giao hữu đang mở gần nhất và tổng số mã giảm giá Voucher đang có hiệu lực
$openMatches  = [];
$voucherCount = 0;
try {
    $openMatches = $pdo->query("
        SELECT m.*, f.name AS fname, f.type AS ftype, f.district
        FROM match_requests m
        JOIN fields f ON f.id = m.field_id
        WHERE m.status = 'open' AND m.match_date >= CURDATE()
        ORDER BY m.match_date ASC, m.start_time ASC
        LIMIT 3
    ")->fetchAll();

    $voucherCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM vouchers
        WHERE status = 'active' AND expired_at >= CURDATE()
    ")->fetchColumn();
} catch (Exception $e) {}

// 3.6. Lấy 5 bản tin thể thao bóng đá nóng hổi hiện nay (RSS VnExpress Thể Thao + Cache 15p)
$footballNews = get_latest_football_news(5);

// ----------------------------------------------------------------------------
// PHẦN 4: TRUY VẤN LỊCH ĐẶT GẦN ĐÂY & THÔNG BÁO CỦA NGƯỜI DÙNG (NẾU ĐÃ LOGIN)
// ----------------------------------------------------------------------------
$my     = [];
$unread = 0;

if ($u) {
    // Lấy 3 đơn đặt sân gần nhất của người dùng
    $s = $pdo->prepare("
        SELECT b.*, f.name, f.image
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT 3
    ");
    $s->execute([$u['id']]);
    $my = $s->fetchAll();

    // Đếm số thông báo chưa đọc
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$u['id']]);
    $unread = (int)$s->fetchColumn();
}

// Lấy thông tin số điện thoại và email của Quản trị viên để hiển thị ở phần Liên hệ
$ad = $pdo->query("
    SELECT phone, email
    FROM users
    WHERE role = 'admin' AND status = 'active'
    LIMIT 1
")->fetch() ?: ['phone' => '', 'email' => ''];

// Bảng ánh xạ trạng thái đơn đặt sân sang nhãn tiếng Việt và màu sắc thẻ tag
$st = [
    'pending'   => ['Chờ xác nhận', 'blue'],
    'confirmed' => ['Đã xác nhận', 'green'],
    'cancelled' => ['Đã hủy', 'gray'],
    'completed' => ['Hoàn thành', 'green']
];

// Kiểm tra xem người dùng có đang sử dụng bộ lọc tìm kiếm hay không
$filtered          = ($q !== '' || $type || $dist || $max || ($date && $start));
$totalActiveFields = count($fields);
$freeNowCount      = max(0, $totalActiveFields - count($busyNow));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sân Bóng Pro - Đặt sân trực tuyến & Sàn cáp kèo thể thao</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Chakra+Petch:ital,wght@0,600;0,700;1,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__ . '/assets/css/style.css')?>">
  <link rel="stylesheet" href="assets/css/home.css?v=<?=filemtime(__DIR__ . '/assets/css/home.css')?>">
  <link rel="stylesheet" href="assets/css/effects.css?v=<?=filemtime(__DIR__ . '/assets/css/effects.css')?>">
</head>
<body class="hp">

<!-- ==========================================================================
     PHẦN 5: THANH HEADER ĐIỀU HƯỚNG TRÊN CÙNG (TOPBAR)
     ========================================================================== -->
<header class="hp-top">
  <!-- Logo thương hiệu Sân Bóng Pro -->
  <a class="hp-brand" href="index.php">
    ⚽
    <div>
      <b>SÂN BÓNG <span class="sport-badge-inline">PRO</span></b>
      <small>ARENA BOOKING & MATCHMAKING</small>
    </div>
  </a>

  <!-- Menu điều hướng chính -->
  <nav class="hp-nav">
    <a class="on" href="index.php">🏟 Trang chủ</a>
    <a href="#fields">⚽ Sân bóng</a>
    <a href="user/booking.php">⚡ Đặt sân</a>
    <a href="user/matchmaking.php">⚔️ Cáp kèo / Tìm đối</a>
    <a href="#contact">📞 Liên hệ</a>
  </nav>

  <!-- Ô tìm kiếm nhanh theo tên sân hoặc địa chỉ -->
  <form class="hp-search" method="get">
    <input name="q" placeholder="Tìm tên sân, đường, quận huyện..." value="<?=e($q)?>">
    <button type="submit">🔍</button>
  </form>

  <!-- Khu vực tài khoản người dùng & chuông thông báo -->
  <div class="hp-user">
    <?php if ($u && $u['role'] === 'admin'): ?>
      <a class="lnk" href="admin/dashboard.php" style="background:#0f172a;color:#34d399;border:1px solid #10b981">
        ⚙️ Quản trị Admin
      </a>
    <?php endif; ?>

    <a href="user/notifications.php" title="Thông báo">
      🔔
      <?php if ($unread): ?>
        <i class="dot"><?=$unread?></i>
      <?php endif; ?>
    </a>

    <?php if ($u): ?>
      <span class="avatar"><?=e(mb_substr($u['full_name'], 0, 1))?></span>
      <a href="<?=($u['role'] === 'admin') ? 'admin/dashboard.php' : 'user/profile.php'?>">
        <?=e($u['full_name'])?>
      </a>
    <?php else: ?>
      <a class="lnk" href="login.php">Đăng nhập</a>
    <?php endif; ?>
  </div>
</header>

<!-- Thanh Trung Tâm Trực Tiếp LIVE ARENA (Matchday Broadcast Center Bar) -->
<div class="sport-live-ticker">
  <!-- Cụm trái: Huy hiệu LIVE đỏ phát sáng & Đồng hồ điện tử sân vận động -->
  <div class="arena-left">
    <span class="arena-live-badge">
      <i class="arena-live-dot"></i> LIVE ARENA
    </span>
    <span class="arena-clock" id="liveArenaClock">
      🕒 <?=date('H:i:s')?>
    </span>
  </div>

  <!-- Cụm giữa: Dải Thẻ Trạng Thái Sân & Kèo Đấu Trực Tiếp (Clickable Matchday Pills) -->
  <div class="ticker-track">
    <div class="ticker-content">
      <a class="arena-pill" href="user/booking.php">
        <span class="pill-tag green">🟢 SÂN TRỐNG</span>
        <span>Hiện có <b style="color:#4ade80"><?=$freeNowCount?>/<?=$totalActiveFields?> sân</b> sẵn sàng thi đấu</span>
      </a>

      <a class="arena-pill" href="user/matchmaking.php">
        <span class="pill-tag amber">⚔️ CÁP KÈO</span>
        <span>Có <b style="color:#fbbf24"><?=count($openMatches)?> đội bóng</b> đang chờ bắt đối giao hữu</span>
      </a>

      <?php foreach ($openMatches as $tm): ?>
        <a class="arena-pill" href="user/matchmaking.php">
          <span class="pill-tag blue">🔥 KÈO MỞ</span>
          <span>
            🛡️ <b style="color:#fff"><?=e($tm['team_name'])?></b>
            <b style="color:#ef4444;margin:0 3px">VS</b>
            <i style="color:#86efac">Chờ đối...</i>
            • <?=e($tm['fname'])?> (<?=substr($tm['start_time'], 0, 5)?> - <?=date('d/m', strtotime($tm['match_date']))?>)
          </span>
        </a>
      <?php endforeach; ?>

      <a class="arena-pill" href="user/booking.php">
        <span class="pill-tag green">🏟 SƠ ĐỒ 2D</span>
        <span>Ghép/Tách sân tự động: <b style="color:#4ade80">5A, 5B, 5C ↔ 7A ↔ 11A</b></span>
      </a>

      <?php if (!empty($footballNews[0])): ?>
        <a class="arena-pill" href="#footballNewsBox">
          <span class="pill-tag amber">📰 TIN NÓNG</span>
          <span><?=e($footballNews[0]['title'])?></span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cụm phải: Nút thao tác nhanh -->
  <div class="arena-right">
    <a class="arena-quick-btn" href="user/booking.php">⚡ Đặt sân ngay</a>
    <a class="arena-quick-btn" href="user/matchmaking.php" style="border-color:#38bdf8;color:#7dd3fc !important;background:rgba(56,189,248,0.14)">⚔️ Sàn Cáp Kèo</a>
  </div>
</div>

<!-- ==========================================================================
     KHUNG BỐ CỤC 3 CỘT TRANG CHỦ: SIDEBAR TRÁI + NỘI DUNG CHÍNH + SIDEBAR PHẢI
     ========================================================================== -->
<div class="hp-wrap">

  <!-- ========================================================================
       PHẦN 6: CỘT 1 - MENU ĐIỀU HƯỚNG NHANH BÊN TRÁI (LEFT SIDEBAR)
       ======================================================================== -->
  <nav class="hp-side">
    <!-- Thẻ số áo cầu thủ (Player Locker Room Card) -->
    <div class="side-user-card" style="padding:14px;border-radius:12px;margin-bottom:14px;text-align:center">
      <div class="side-jersey-num">#<?=!empty($u['id']) ? str_pad((string)($u['id'] % 99), 2, '0', STR_PAD_LEFT) : '10'?></div>
      <div class="side-avatar" style="margin:0 auto 6px"><?=e(mb_substr($u['full_name'] ?? 'P', 0, 1))?></div>
      <div class="side-name"><?=e($u['full_name'] ?? 'Cầu Thủ Tự Do')?></div>
      <div class="side-status" style="justify-content:center"><i class="radar-dot"></i> Sẵn sàng ra sân</div>
    </div>

    <a class="on" href="index.php">🏟 Trang chủ Sân Bóng</a>

    <?php if ($u && $u['role'] === 'admin'): ?>
      <a href="admin/dashboard.php" style="background:rgba(34,197,94,0.18);color:#4ade80 !important;font-weight:800;border:1px solid #22c55e">
        ⚙️ Về trang Quản trị Admin
      </a>
    <?php endif; ?>

    <h6>THI ĐẤU & ĐẶT SÂN</h6>
    <a href="user/booking.php">⚡ Đặt sân theo sơ đồ</a>
    <a href="user/matchmaking.php">⚔️ Sàn Cáp Kèo / Tìm Đối</a>
    <a href="user/my-bookings.php">🎫 Vé & Lịch đặt của tôi</a>

    <h6>KHÁM PHÁ</h6>
    <a href="#fields">⚽ Tất cả sân bóng</a>
    <a href="#matchmaking-preview">🔥 Kèo giao hữu hôm nay</a>

    <h6>TÀI KHOẢN</h6>
    <a href="user/profile.php">👤 Hồ sơ cầu thủ</a>
    <a href="user/notifications.php">
      🔔 Thông báo
      <?php if ($unread): ?>
        <i class="dot"><?=$unread?></i>
      <?php endif; ?>
    </a>

    <?php if ($u): ?>
      <a href="logout.php" onclick="return confirm('Bạn có chắc chắn muốn đăng xuất?')">↪ Đăng xuất</a>
    <?php else: ?>
      <a href="login.php">🔑 Đăng nhập</a>
      <a href="register.php">📝 Đăng ký</a>
    <?php endif; ?>
  </nav>

  <!-- ========================================================================
       PHẦN 7: CỘT 2 - NỘI DUNG CHÍNH TRANG CHỦ (MAIN CONTENT)
       ======================================================================== -->
  <main class="hp-main">

    <!-- 7.1. Phần Hero Banner & Bộ lọc tìm kiếm sân trống trực tuyến -->
    <section class="hp-hero">
      <small class="k">⚽ HỆ THỐNG SÂN BÓNG CHUẨN THI ĐẤU FIFA QUALITY</small>
      <h1>
        CHÁY HẾT MÌNH TRÊN SÂN CỎ
        <span>Đặt sân siêu tốc • Ghép kèo giao hữu • Vé điện tử QR</span>
      </h1>

      <div class="hp-chips">
        <span><i>✔</i> Sơ đồ chọn giờ trực quan</span>
        <span><i>✔</i> Sàn cáp kèo 5v5 / 7v7 / 11v11</span>
        <span><i>✔</i> VietQR & MoMo tự động</span>
        <span><i>✔</i> Vé QR Check-in tại sân</span>
      </div>

      <!-- Biểu mẫu lọc sân theo Khu vực, Loại sân, Mức giá, Ngày và Khung giờ -->
      <form class="hp-form" method="get">
        <input type="hidden" name="q" value="<?=e($q)?>">

        <select name="district">
          <option value="">📍 Tất cả Khu vực</option>
          <?php foreach ($districts as $d): ?>
            <option <?=$dist === $d ? 'selected' : ''?>><?=e($d)?></option>
          <?php endforeach; ?>
        </select>

        <select name="type">
          <option value="">⚽ Mọi loại sân</option>
          <?php foreach (['5', '7', '11'] as $tp): ?>
            <option value="<?=$tp?>" <?=$type === $tp ? 'selected' : ''?>>Sân <?=$tp?> người</option>
          <?php endforeach; ?>
        </select>

        <select name="max">
          <option value="">💲 Mọi mức giá</option>
          <?php foreach ([120000, 150000, 220000] as $m): ?>
            <option value="<?=$m?>" <?=$max == $m ? 'selected' : ''?>>Đến <?=money($m)?></option>
          <?php endforeach; ?>
        </select>

        <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($date)?>">

        <select name="start">
          <option value="">🕒 Khung giờ</option>
          <?php for ($h = 6; $h <= 22; $h++): $v = sprintf('%02d:00', $h); ?>
            <option <?=$start === $v ? 'selected' : ''?>><?=$v?></option>
          <?php endfor; ?>
        </select>

        <button class="btn" type="submit">🔍 Tìm Sân Trống</button>
      </form>
    </section>

    <!-- 7.2. Bảng điện tử trực tiếp (Live Stadium Scoreboard Strip) -->
    <div class="hp-scoreboard-bar">
      <div class="hp-score-item">
        <div class="hp-score-num"><?=str_pad((string)$totalActiveFields, 2, '0', STR_PAD_LEFT)?></div>
        <div class="hp-score-label">
          <b>SÂN ĐANG MỞ</b><br>Sân 5 • Sân 7 • Sân 11
        </div>
      </div>

      <div class="hp-score-item">
        <div class="hp-score-num"><?=$freeNowCount?>/<?=$totalActiveFields?></div>
        <div class="hp-score-label">
          <b>TRỐNG GIỜ NÀY</b><br>Cập nhật <?=date('H:i')?>
        </div>
      </div>

      <div class="hp-score-item">
        <div class="hp-score-num"><?=str_pad((string)count($openMatches), 2, '0', STR_PAD_LEFT)?></div>
        <div class="hp-score-label">
          <b>KÈO CHỜ ĐỐI</b><br>
          <a href="user/matchmaking.php" style="color:#4ade80;font-weight:700">Bắt đối ngay →</a>
        </div>
      </div>

      <div class="hp-score-item">
        <div class="hp-score-num"><?=str_pad((string)$voucherCount, 2, '0', STR_PAD_LEFT)?></div>
        <div class="hp-score-label">
          <b>VOUCHER HOT</b><br>Giảm đến 20% tiền sân
        </div>
      </div>
    </div>

    <!-- 7.3. Danh sách các sân bóng tiêu chuẩn -->
    <section id="fields">
      <h3 class="hp-h">
        <span>🏟 <?=$filtered ? 'KẾT QUẢ TÌM KIẾM SÂN ĐẤU' : 'DANH SÁCH SÂN BÓNG TIÊU CHUẨN'?></span>
        <?php if ($filtered): ?>
          <a href="index.php">✕ Xóa bộ lọc</a>
        <?php endif; ?>
      </h3>

      <?php if (!$fields): ?>
        <div class="empty">Không tìm thấy sân phù hợp với bộ lọc của bạn.</div>
      <?php endif; ?>

      <div class="hp-grid">
        <?php foreach ($fields as $f): $b = in_array($f['id'], $busyNow); ?>
          <div class="hp-card">
            <!-- Ảnh sân và nhãn trạng thái trực tiếp -->
            <div class="hp-img">
              <img src="<?=e(img_src($f['image']))?>" alt="<?=e($f['name'])?>">
              <span class="hp-badge <?=$b ? 'b' : ''?>">
                <?=$b ? '🔴 Đang có trận' : '🟢 Đang trống sân'?>
              </span>
              <span style="position:absolute;bottom:10px;right:10px;background:rgba(15,23,42,0.88);color:#4ade80;font-family:var(--font-sport);font-weight:700;font-size:12px;padding:3px 10px;border-radius:6px;border:1px solid #22c55e">
                SÂN <?=$f['type']?> NGƯỜI
              </span>
            </div>

            <!-- Thông tin tên sân, địa chỉ, đánh giá và nút đặt sân -->
            <div class="hp-cb">
              <h4><?=e($f['name'])?></h4>
              <small>📍 <?=e($f['address'])?></small>
              <small>
                ⭐ <b><?=e($f['rating'])?></b> (<?=e($f['review_count'])?> đánh giá)
                • Khu vực: <b><?=e($f['district'])?></b>
              </small>
              <p class="hp-pr">
                <?=money($f['price_per_hour'])?>
                <span style="font-size:12px;color:#64748b;font-weight:500">/ giờ</span>
              </p>
              <div class="hp-row">
                <a href="user/field-detail.php?id=<?=$f['id']?>">Chi tiết</a>
                <a class="btn" href="user/booking.php?field_id=<?=$f['id']?>">⚡ Chọn giờ đặt sân →</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 7.4. Sàn Cáp Kèo & Tìm Đối Giao Hữu nổi bật -->
    <section id="matchmaking-preview">
      <h3 class="hp-h">
        <span>⚔️ SÀN CÁP KÈO & TÌM ĐỐI GIAO HỮU ĐANG MỞ</span>
        <a href="user/matchmaking.php">Vào Sàn Cáp Kèo (Tất cả) →</a>
      </h3>

      <?php if (!$openMatches): ?>
        <div class="hp-booking-empty">
          <span>⚔️</span>
          <div>
            <b>Chưa có đội bóng nào đăng kèo hôm nay</b>
            <small>Đội của bạn muốn tìm đối thủ đá giao hữu 50/50 hoặc tuyển thêm người đá phủi?</small>
          </div>
          <a class="btn" href="user/matchmaking.php">+ Đăng kèo tìm đối ngay →</a>
        </div>
      <?php else: ?>
        <div class="match-grid">
          <?php
          $lvlMap = [
              'vui_ve'     => ['Vui vẻ / Rèn sức khỏe', 'green'],
              'phong_trao' => ['Phong trào', 'blue'],
              'ban_chuyen' => ['Bán chuyên / Cứng', 'amber']
          ];
          foreach ($openMatches as $m):
              [$lvlTxt, $lvlColor] = $lvlMap[$m['skill_level']] ?? ['Phong trào', 'blue'];
          ?>
            <div class="match-card">
              <div class="match-card-top">
                <span style="font-family:var(--font-sport);font-weight:700;font-size:13px;color:#4ade80">
                  <?=$m['match_type'] === 'tim_doi' ? '⚔️ KÈO TÌM ĐỐI THỦ' : '🏃 TUYỂN THÊM ' . $m['spots_needed'] . ' CẦU THỦ'?>
                </span>
                <span class="tag <?=$lvlColor?>" style="font-size:11px"><?=$lvlTxt?></span>
              </div>

              <div class="match-vs-box">
                <div class="match-team">
                  <div class="match-team-shield">🛡️</div>
                  <b style="font-size:13.5px;color:#0f172a;display:block"><?=e($m['team_name'])?></b>
                  <small style="margin:0">Đội chủ nhà</small>
                </div>
                <div class="vs-circle">VS</div>
                <div class="match-team">
                  <div class="match-team-shield wait">❓</div>
                  <b style="font-size:13.5px;color:#d97706;display:block">Đang chờ đối...</b>
                  <small style="margin:0"><?=e($m['fee_share'])?></small>
                </div>
              </div>

              <div class="match-body">
                <div style="font-size:13px">
                  <b>🏟 Sân:</b> <?=e($m['fname'])?> (Sân <?=$m['ftype']?> - <?=e($m['district'])?>)
                </div>
                <div style="font-size:13px">
                  <b>🕒 Thời gian:</b> <?=date('d/m/Y', strtotime($m['match_date']))?>
                  • <span style="color:#16a34a;font-weight:700"><?=substr($m['start_time'], 0, 5)?> - <?=substr($m['end_time'], 0, 5)?></span>
                </div>
                <?php if (!empty($m['note'])): ?>
                  <small style="background:#f8fafc;padding:8px 10px;border-radius:8px;border:1px solid #e2e8f0;color:#334155">
                    💬 "<?=e($m['note'])?>"
                  </small>
                <?php endif; ?>
                <div style="margin-top:auto;padding-top:10px;display:flex;justify-content:space-between;align-items:center">
                  <small style="margin:0;font-weight:700;color:#0f172a">📞 <?=e($m['contact_phone'])?></small>
                  <a class="btn sm" href="user/matchmaking.php?join=<?=$m['id']?>">🤝 Nhận kèo này →</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- 7.5. Danh mục sân theo quy mô thi đấu (Sân 5, Sân 7, Sân 11 người) -->
    <?php if ($cats): ?>
      <section>
        <h3 class="hp-h">
          <span>⚽ HỆ THỐNG SÂN THEO THỂ THỨC THI ĐẤU</span>
        </h3>
        <div class="hp-grid">
          <?php foreach ($cats as $c): ?>
            <div class="hp-card hp-cat">
              <div class="hp-img">
                <img src="<?=e(img_src($c['img']))?>" alt="Sân <?=e($c['type'])?> người">
              </div>
              <div class="hp-cb">
                <h4>Sân <?=e($c['type'])?> người (<?=$c['type']?>v<?=$c['type']?>)</h4>
                <small><?=$c['n']?> sân tiêu chuẩn đang mở</small>
                <p class="hp-pr">Từ <?=money($c['p'])?> / giờ</p>
                <a class="btn" style="padding:8px;font-size:13px" href="?type=<?=e($c['type'])?>#fields">
                  Lọc sân <?=$c['type']?> người →
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- 7.6. Bảng xem nhanh lịch thi đấu gần đây của người dùng -->
    <section class="hp-booking-table" id="booking-preview">
      <h3 class="hp-h">
        <span>🗓️ LỊCH THI ĐẤU GẦN ĐÂY CỦA BẠN</span>
        <a href="user/my-bookings.php">Quản lý & Xem vé QR →</a>
      </h3>

      <?php if ($u && $my): ?>
        <div class="hp-table-wrap">
          <table class="hp-table">
            <thead>
              <tr>
                <th>Sân bóng</th>
                <th>Ngày đá</th>
                <th>Khung giờ</th>
                <th>Trạng thái</th>
                <th>Vé điện tử</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($my as $bk): [$lb, $cl] = $st[$bk['status']] ?? ['Đang xử lý', 'blue']; ?>
                <tr>
                  <td><b><?=e($bk['name'])?></b></td>
                  <td><?=date('d/m/Y', strtotime($bk['booking_date']))?></td>
                  <td><b><?=substr($bk['start_time'], 0, 5)?>–<?=substr($bk['end_time'], 0, 5)?></b></td>
                  <td><span class="tag <?=$cl?>"><?=$lb?></span></td>
                  <td>
                    <a href="user/payment-demo.php?booking_id=<?=$bk['id']?>" style="font-weight:700">
                      🎫 Mở vé QR →
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="hp-booking-empty">
          <span>📅</span>
          <div>
            <b>Lên lịch cho trận cầu tiếp theo của đội bạn</b>
            <small>Chọn khung giờ trống trên sơ đồ trực quan, nhận vé QR và bật tìm đối thủ giao hữu ngay!</small>
          </div>
          <a class="btn" href="user/booking.php">Tìm giờ trống →</a>
        </div>
      <?php endif; ?>
    </section>

    <!-- 7.7. Khu vực chương trình ưu đãi & Mã giảm giá nổi bật -->
    <section class="hp-deals">
      <h3 class="hp-h">
        <span>🎁 ĐẶC QUYỀN DÀNH CHO CẦU THỦ</span>
        <a href="user/booking.php">Đặt sân áp mã →</a>
      </h3>

      <div class="hp-deal-grid">
        <article class="hp-deal deal-green">
          <small>MÃ: SANBONG10</small>
          <b>Giảm ngay 10% tiền sân</b>
          <span>Áp dụng cho mọi khung giờ khi đặt trực tuyến trên hệ thống.</span>
          <a href="user/booking.php">Dùng mã ngay ↗</a>
        </article>

        <article class="hp-deal deal-blue">
          <small>SÀN CÁP KÈO 24/7</small>
          <b>Ghép đối giao hữu nhanh</b>
          <span>Đăng kèo tìm đối 50/50 hoặc tuyển thêm cầu thủ phủi trong 30 giây.</span>
          <a href="user/matchmaking.php">Vào sàn cáp kèo ↗</a>
        </article>

        <article class="hp-deal deal-purple">
          <small>MÃ: TANTHU20</small>
          <b>Ưu đãi 20% Đội bóng mới</b>
          <span>Tặng ngay ưu đãi giảm tới 60.000đ cho lần đầu đặt sân.</span>
          <a href="user/booking.php">Đặt lịch ngay ↗</a>
        </article>
      </div>
    </section>

  </main>

  <!-- ========================================================================
       PHẦN 8: CỘT 3 - SIDEBAR TIỆN ÍCH BÊN PHẢI (RIGHT SIDEBAR)
       ======================================================================== -->
  <aside class="hp-right">
    <!-- 8.1. Hộp tóm tắt Vé đặt sân của tôi -->
    <div>
      <div class="bh">
        <b>🎫 Vé đặt sân của tôi</b>
        <a href="user/my-bookings.php">Tất cả →</a>
      </div>

      <?php if (!$u): ?>
        <div class="empty">
          <a href="login.php"><b>Đăng nhập</b></a> để xem vé QR & lịch thi đấu.
        </div>
      <?php elseif (!$my): ?>
        <div class="empty">Bạn chưa có lịch đặt nào.</div>
      <?php endif; ?>

      <?php foreach ($my as $bk): [$lb, $cl] = $st[$bk['status']]; ?>
        <div class="bk">
          <img src="<?=e(img_src($bk['image']))?>" alt="<?=e($bk['name'])?>">
          <div>
            <b><?=e($bk['name'])?></b>
            <small>
              📅 <?=date('d/m/Y', strtotime($bk['booking_date']))?>
              | <?=substr($bk['start_time'], 0, 5)?>-<?=substr($bk['end_time'], 0, 5)?>
            </small>
            <span class="tag <?=$cl?>"><?=$lb?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- 8.2. Thẻ quảng bá Sàn Cáp Kèo -->
    <div class="hp-promo">
      <b>⚔️ Đội bạn chưa có đối?</b>
      <small>Tham gia Sàn Cáp Kèo Giao Hữu để bắt đối 5v5, 7v7, 11v11 cùng trình độ ngay hôm nay!</small>
      <a class="btn" href="user/matchmaking.php">Vào Sàn Cáp Kèo →</a>
    </div>

    <!-- 8.3. Bộ lọc nhanh theo thể thức sân -->
    <div>
      <b>Chọn sân theo thể thức</b>
      <div class="hp-need" style="margin-top:10px">
        <a href="?type=5#fields">⚽ Sân 5v5</a>
        <a href="?type=7#fields">⚽ Sân 7v7</a>
        <a href="?type=11#fields">⚽ Sân 11v11</a>
        <a href="index.php#fields">🏟 Tất cả sân</a>
      </div>
    </div>

    <!-- 8.4. Bản Tin Sân Cỏ - Tin tức Thể thao Bóng đá 24/7 hiện nay -->
    <div class="hp-right-news" id="footballNewsBox">
      <div class="bh" style="margin-bottom:10px">
        <b>📰 Bản Tin Sân Cỏ 24/7</b>
        <button
          type="button"
          id="btnRefreshNews"
          onclick="refreshFootballNews()"
          style="background:#ecfdf5;border:1px solid #86efac;color:#047857;font-size:11.5px;font-weight:700;padding:4px 9px;border-radius:6px;cursor:pointer"
          title="Cập nhật tin bóng đá mới nhất"
        >
          🔄 Cập nhật
        </button>
      </div>

      <small style="display:flex;align-items:center;gap:6px;color:#15803d;font-weight:600;font-size:11.5px;margin-bottom:10px">
        <i class="radar-dot"></i>
        <span id="newsStatusLabel">Tin nóng Bóng đá Việt Nam & Quốc tế</span>
      </small>

      <div id="footballNewsList" style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($footballNews as $idx => $item): ?>
          <a
            class="hp-news-card"
            href="<?=e($item['link'])?>"
            target="_blank"
            rel="noopener"
            onclick="return openFootballNewsModal(event, this)"
            data-title="<?=e($item['title'])?>"
            data-summary="<?=e($item['summary'])?>"
            data-image="<?=e($item['image'])?>"
            data-tag="<?=e($item['tag'])?>"
            data-time="<?=e($item['time_label'])?>"
            data-source="<?=e($item['source'])?>"
            data-link="<?=e($item['link'])?>"
            style="display:flex;gap:11px;padding:10px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;text-decoration:none;transition:all 0.2s ease;align-items:flex-start"
          >
            <img
              src="<?=e($item['image'])?>"
              alt="<?=e($item['title'])?>"
              onerror="this.src='https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&q=80'"
              style="width:68px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;border:1px solid #cbd5e1"
            >
            <div style="flex:1;min-width:0">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:3px">
                <span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:800;padding:1px 6px;border-radius:4px">
                  <?=e($item['tag'])?>
                </span>
                <small style="margin:0;font-size:10.5px;color:#64748b"><?=e($item['time_label'])?></small>
              </div>
              <b style="font-size:12.5px;color:#0f172a;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                <?=e($item['title'])?>
              </b>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Modal Đọc Nhanh Bản Tin Bóng Đá ngay trên Trang chủ -->
    <div
      id="footballNewsModal"
      onclick="if(event.target===this)closeFootballNewsModal()"
      style="display:none;position:fixed;inset:0;background:rgba(7,13,25,0.78);backdrop-filter:blur(5px);z-index:9995;place-items:center;padding:16px"
    >
      <div style="background:#fff;max-width:540px;width:100%;border-radius:18px;overflow:hidden;border:2px solid #22c55e;box-shadow:0 24px 50px rgba(0,0,0,0.45)">
        <div style="position:relative;height:220px;background:#0f172a">
          <img id="fnModalImg" src="" alt="" style="width:100%;height:100%;object-fit:cover">
          <span
            id="fnModalTag"
            style="position:absolute;top:14px;left:14px;background:#16a34a;color:#fff;font-family:var(--font-sport);font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:6px"
          ></span>
          <button
            type="button"
            onclick="closeFootballNewsModal()"
            style="position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:50%;border:0;background:rgba(15,23,42,0.85);color:#fff;font-size:16px;cursor:pointer"
          >✕</button>
        </div>
        <div style="padding:22px">
          <small id="fnModalMeta" style="color:#16a34a;font-weight:700;margin:0 0 6px"></small>
          <h3 id="fnModalTitle" style="margin:0 0 12px;font-family:var(--font-sport);font-size:19px;line-height:1.35;color:#0f172a"></h3>
          <p id="fnModalSummary" style="margin:0 0 20px;color:#334155;font-size:14px;line-height:1.6"></p>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
            <button type="button" class="btn sm outline" onclick="closeFootballNewsModal()">Đóng</button>
            <a id="fnModalLink" href="#" target="_blank" rel="noopener" class="btn sm">
              📰 Đọc bài chi tiết trên báo →
            </a>
          </div>
        </div>
      </div>
    </div>

    <script>
      // Mở hộp thoại đọc nhanh bản tin bóng đá khi người dùng bấm vào thẻ tin
      function openFootballNewsModal(e, el) {
        if (e) e.preventDefault();
        var modal = document.getElementById('footballNewsModal');
        if (!modal) return true;
        document.getElementById('fnModalImg').src = el.getAttribute('data-image') || '';
        document.getElementById('fnModalTag').textContent = el.getAttribute('data-tag') || '⚽ Bóng đá';
        document.getElementById('fnModalMeta').textContent =
          '🕒 ' + (el.getAttribute('data-time') || 'Vừa xong') + ' • Nguồn: ' + (el.getAttribute('data-source') || 'Thể Thao 24/7');
        document.getElementById('fnModalTitle').textContent = el.getAttribute('data-title') || '';
        document.getElementById('fnModalSummary').textContent = el.getAttribute('data-summary') || '';
        document.getElementById('fnModalLink').href = el.getAttribute('data-link') || '#';
        modal.style.display = 'grid';
        return false;
      }

      function closeFootballNewsModal() {
        var modal = document.getElementById('footballNewsModal');
        if (modal) modal.style.display = 'none';
      }

      // Làm mới danh sách bản tin bóng đá trực tiếp qua AJAX
      function refreshFootballNews() {
        var btn = document.getElementById('btnRefreshNews');
        var list = document.getElementById('footballNewsList');
        var label = document.getElementById('newsStatusLabel');
        if (!list) return;
        if (btn) btn.textContent = '⏳ Đang tải...';

        fetch('api/football_news.php?refresh=1')
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (btn) btn.textContent = '🔄 Cập nhật';
            if (!res || !res.ok || !res.items) return;
            if (label) label.textContent = 'Đã cập nhật lúc ' + res.updated_at;
            var html = '';
            res.items.forEach(function (item) {
              var safeTitle = (item.title || '').replace(/"/g, '&quot;');
              var safeSum = (item.summary || '').replace(/"/g, '&quot;');
              var safeImg = (item.image || '').replace(/"/g, '&quot;');
              var safeTag = (item.tag || '').replace(/"/g, '&quot;');
              var safeTime = (item.time_label || '').replace(/"/g, '&quot;');
              var safeSrc = (item.source || '').replace(/"/g, '&quot;');
              var safeLink = (item.link || '#').replace(/"/g, '&quot;');

              html +=
                '<a class="hp-news-card" href="' + safeLink + '" target="_blank" rel="noopener" ' +
                'onclick="return openFootballNewsModal(event, this)" ' +
                'data-title="' + safeTitle + '" data-summary="' + safeSum + '" data-image="' + safeImg + '" ' +
                'data-tag="' + safeTag + '" data-time="' + safeTime + '" data-source="' + safeSrc + '" data-link="' + safeLink + '" ' +
                'style="display:flex;gap:11px;padding:10px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;text-decoration:none;transition:all 0.2s ease;align-items:flex-start">' +
                  '<img src="' + safeImg + '" alt="" onerror="this.src=\'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=400&q=80\'" style="width:68px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;border:1px solid #cbd5e1">' +
                  '<div style="flex:1;min-width:0">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:3px">' +
                      '<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:800;padding:1px 6px;border-radius:4px">' + safeTag + '</span>' +
                      '<small style="margin:0;font-size:10.5px;color:#64748b">' + safeTime + '</small>' +
                    '</div>' +
                    '<b style="font-size:12.5px;color:#0f172a;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">' + safeTitle + '</b>' +
                  '</div>' +
                '</a>';
            });
            list.innerHTML = html;
          })
          .catch(function () {
            if (btn) btn.textContent = '🔄 Cập nhật';
          });
      }
    </script>

    <!-- 8.5. Thẻ Hotline liên hệ Ban Quản Lý Sân -->
    <div class="hp-right-help">
      <span>🎧</span>
      <div>
        <b>Hotline Quản Lý Sân</b>
        <small>Hỗ trợ đặt giải đấu & hợp đồng cố định tháng.</small>
        <a href="mailto:<?=e($ad['email'])?>">📞 <?=e($ad['phone'] ?: '0901.234.567')?> →</a>
      </div>
    </div>
  </aside>

</div>

<!-- ==========================================================================
     PHẦN 9: CHÂN TRANG (FOOTER) & TIỆN ÍCH TRỢ LÝ AI (CHAT WIDGET)
     ========================================================================== -->
<footer class="hp-foot" id="contact">
  <div class="foot-brand">
    <b>⚽ SÂN BÓNG PRO</b>
    <small>Nền tảng Đặt Sân & Cáp Kèo Thể Thao Trực Tuyến</small>
    <p>Kết nối đam mê sân cỏ với sơ đồ đặt giờ thông minh, thanh toán VietQR tự động và sàn tìm đối giao hữu.</p>
    <div class="hp-social">
      <span>f</span>
      <span>▶</span>
      <span>◎</span>
      <span>♪</span>
    </div>
  </div>

  <div>
    <b>Ban Quản Lý Sân</b>
    <small>📍 TP. Hồ Chí Minh, Việt Nam</small>
    <small>📞 <?=e($ad['phone'] ?: '0901234567')?></small>
    <small>✉ <?=e($ad['email'] ?: 'admin@sanbong.vn')?></small>
    <small>🕒 Giờ mở cửa: 06:00 – 23:00</small>
  </div>

  <div>
    <b>Tiện ích cầu thủ</b>
    <a href="user/booking.php">Đặt sân theo sơ đồ giờ</a>
    <a href="user/matchmaking.php">Sàn cáp kèo giao hữu</a>
    <a href="user/my-bookings.php">Tra cứu vé điện tử QR</a>
  </div>

  <div>
    <b>Chính sách sân đấu</b>
    <a href="#contact">Quy định hủy sân (Trước 2h)</a>
    <a href="#contact">Quy tắc Fair-play khi cáp kèo</a>
    <a href="user/notifications.php">Trung tâm thông báo</a>
  </div>

  <div class="hp-newsletter">
    <b>Nhận thông báo Kèo Thơm & Voucher</b>
    <small>Cập nhật khung giờ giảm giá và giải phong trào.</small>
    <form onsubmit="event.preventDefault();this.querySelector('.newsletter-msg').textContent='✔ Đã đăng ký nhận bản tin Sân Bóng Pro!';">
      <input type="email" required placeholder="Nhập email đội trưởng...">
      <button type="submit">Đăng ký ngay</button>
      <small class="newsletter-msg"></small>
    </form>
  </div>

  <div class="hp-copy">
    © <?=date('Y')?> Sân Bóng Pro - Dynamic Daylight Sport Edition. Bảo lưu mọi quyền.
  </div>
</footer>

<?php
// Nhúng Widget Trợ lý AI tư vấn trực tuyến ở góc phải dưới màn hình
if (is_file(__DIR__ . '/includes/chat_widget.php')) {
    include 'includes/chat_widget.php';
}
?>
<!-- Nhúng Engine Hiệu Ứng Tương Tác Thể Thao (Confetti, 3D Tilt, Scroll Reveal, Animated Counter) -->
<script src="assets/js/sport-effects.js?v=<?=filemtime(__DIR__ . '/assets/js/sport-effects.js')?>"></script>
</body>
</html>
