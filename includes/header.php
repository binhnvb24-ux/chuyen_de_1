<?php
/**
 * FILE: includes/header.php
 * MÔ TẢ: Giao diện phần đầu trang (Header + Sidebar trái) dành cho khu vực Khách hàng (user/*).
 * PHONG CÁCH: Dynamic Daylight Sport (Sáng sủa, hiện đại, tương phản cao Xanh sân cỏ - Trắng - Đen thể thao).
 * YÊU CẦU BIẾN: $u (thông tin người dùng), $active (menu đang chọn), $title (tiêu đề trang).
 */
$base = project_base();
// Đếm thông báo chưa đọc nếu có user
$unread_count = 0;
if (!empty($u['id']) && isset($pdo)) {
  $stmt_n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
  $stmt_n->execute([$u['id']]);
  $unread_count = (int)$stmt_n->fetchColumn();
}
// Đếm số kèo giao hữu đang mở
$open_matches_count = 0;
if (isset($pdo)) {
  try {
    $open_matches_count = (int)$pdo->query("SELECT COUNT(*) FROM match_requests WHERE status='open' AND match_date>=CURDATE()")->fetchColumn();
  } catch (Exception $e) {}
}
?><!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=e($title ?? 'Sân Bóng Pro - Đặt sân & Cáp kèo trực tuyến')?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Chakra+Petch:ital,wght@0,600;0,700;1,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?=$base?>assets/css/style.css?v=<?=filemtime(__DIR__ . '/../assets/css/style.css')?>">
  <link rel="stylesheet" href="<?=$base?>assets/css/effects.css?v=<?=filemtime(__DIR__ . '/../assets/css/effects.css')?>">
  <?php if (is_file(__DIR__ . '/../assets/css/partner.css')): ?>
  <link rel="stylesheet" href="<?=$base?>assets/css/partner.css?v=<?=filemtime(__DIR__ . '/../assets/css/partner.css')?>">
  <?php endif; ?>
  <!-- Thư viện Bản đồ trực tuyến Leaflet.js + OpenStreetMap (Miễn phí, hỗ trợ nhiều ghim Markers) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
</head>
<body class="user-portal">
<!-- Thanh điều hướng thể thao trên cùng (Clean White Daylight Topbar) -->
<header class="topbar">
  <div class="topbar-container">
    <a class="brand" href="<?=$base?>index.php">
      <div class="brand-icon">⚽</div>
      <div>
        <b>SÂN BÓNG <span class="sport-badge-inline">PRO</span></b>
        <small>ARENA BOOKING & MATCHMAKING</small>
      </div>
    </a>

    <nav class="topbar-nav">
      <a class="nav-item <?=($active??'')==='home'?'active':''?>" href="<?=$base?>index.php">Trang chủ</a>
      <a class="nav-item <?=($active??'')==='explore'?'active':''?>" href="<?=$base?>index.php#fields">Sân bóng</a>
      <a class="nav-item <?=($active??'')==='book'?'active':''?>" href="<?=$base?>user/booking.php">Đặt sân</a>
      <a class="nav-item <?=($active??'')==='match'?'active':''?>" href="<?=$base?>user/matchmaking.php">Cáp kèo</a>
      <a class="nav-item <?=($active??'')==='mine'?'active':''?>" href="<?=$base?>user/my-bookings.php">Vé của tôi</a>
    </nav>
    
    <div class="tb-right">
      <?php if($u && is_pitch_owner($u)): ?>
        <?php if(($active ?? '') === 'owner'): ?>
          <a class="tb-partner-portal-btn" href="<?=$base?>user/booking.php" style="display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;background:rgba(255,255,255,0.14);border:1.5px solid #6ee7b7;color:#ffffff !important;font-size:12.5px;font-weight:800;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,0.15)" title="Chuyển sang Giao diện Đặt sân Khách hàng">
            <span>⚽ Giao diện Đặt sân</span>
            <span style="padding:2px 7px;border-radius:999px;background:#22c55e;color:#052e16;font-size:10px;font-weight:800">CHUYỂN KÊNH ↔</span>
          </a>
        <?php else: ?>
          <a class="tb-partner-portal-btn" href="<?=$base?>user/owner-dashboard.php" style="display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;background:linear-gradient(135deg,#059669,#047857);border:1.5px solid #4ade80;color:#ffffff !important;font-size:12.5px;font-weight:800;text-decoration:none;box-shadow:0 6px 16px rgba(16,185,129,0.32)" title="Mở Kênh Chủ Sân (Partner Portal) — Quản lý Sân, Giá & Lịch POS">
            <span>🏟 Kênh Chủ Sân (Partner Portal)</span>
            <span style="padding:2px 7px;border-radius:999px;background:#dcfce7;color:#065f46;font-size:10px;font-weight:800">✔ ĐÃ XÁC MINH</span>
          </a>
        <?php endif; ?>
      <?php endif; ?>

      <a class="tb-create-match" href="<?=$base?>user/matchmaking.php#create-match-form">
        + Tạo kèo
      </a>

      <?php if($u): ?>
        <a class="btn-noti" href="<?=$base?>user/notifications.php" title="Thông báo">
          🔔
          <span class="noti-count"><?=$unread_count?></span>
        </a>
        <a class="user-chip" href="<?=$base?>user/profile.php" style="text-decoration:none" title="Mở Trang Hồ sơ cá nhân, Chuyển kênh & Đăng xuất">
          <?php if (!empty($u['avatar'])): ?>
            <img src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name'])?>" class="avatar" referrerpolicy="no-referrer" style="object-fit:cover;padding:0" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
            <span class="avatar" style="display:none"><?=e(mb_substr($u['full_name'],0,1))?></span>
          <?php else: ?>
            <span class="avatar"><?=e(mb_substr($u['full_name'],0,1))?></span>
          <?php endif; ?>
          <div class="user-meta">
            <span class="uname"><?=e($u['full_name'])?></span>
            <small class="urole">
              <?php if (get_user_role_code($u) === 'ADMIN'): ?>
                🛡️ Quản trị viên • Hồ sơ
              <?php elseif (is_pitch_owner($u)): ?>
                🏟 Chủ sân đối tác • Hồ sơ
              <?php else: ?>
                🛡️ <?=(int)($u['reputation_score'] ?? 100)?>đ • 💰 <?=money($u['wallet_balance'] ?? 0)?>
              <?php endif; ?>
            </small>
          </div>
        </a>
      <?php else: ?>
        <a class="btn-login" href="<?=$base?>login.php">Đăng nhập →</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Thanh Trung Tâm Trực Tiếp LIVE ARENA (Mint Daylight Broadcast Bar) -->
<div class="sport-live-ticker">
  <div class="arena-left">
    <span class="arena-live-badge">
      <i class="arena-live-dot"></i> LIVE ARENA
    </span>
    <span class="arena-clock" id="liveArenaClock">
      🕒 <?=date('H:i:s')?>
    </span>
  </div>

  <div class="ticker-track">
    <div class="ticker-content">
      <a class="arena-pill" href="<?=$base?>user/booking.php">
        <span><b style="color:#16a34a">Sân trống:</b> Sẵn sàng thi đấu</span>
      </a>
      <a class="arena-pill" href="<?=$base?>user/booking.php">
        <span>🏟 Sơ đồ chọn giờ 30 phút, ghép/tách sân</span>
      </a>
      <a class="arena-pill" href="<?=$base?>user/matchmaking.php">
        <span>⚔️ <b style="color:#16a34a">Kèo giao hữu:</b> Đang tìm đối (<?=$open_matches_count?> kèo mở)</span>
      </a>
      <a class="arena-pill" href="<?=$base?>user/my-bookings.php">
        <span>🎫 Thanh toán VietQR nhận vé điện tử QR ngay</span>
      </a>
    </div>
  </div>

  <div class="arena-right">
    <a class="arena-quick-link" href="<?=$base?>user/booking.php">⚡ ĐẶT SÂN NGAY • CÁP KÈO</a>
  </div>
</div>

<!-- Khung bố cục 2 cột: Sidebar menu trái + Nội dung chính bên phải (Tự động mở toàn màn hình khi ở Partner Workspace / Trang Hồ sơ có Sidebar riêng) -->
<div class="layout one <?=in_array($active ?? '', ['owner', 'prof'], true) ? 'partner-workspace-mode' : ''?>">
  <?php if (!in_array($active ?? '', ['owner', 'prof'], true)): ?>
  <nav class="side">
    <div class="side-user-card">
      <div class="side-avatar" style="overflow:hidden">
        <?php if (!empty($u['avatar'])): ?>
          <img src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name']??'N')?>" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover;border-radius:50%" onerror="this.style.display='none'">
        <?php else: ?>
          <?=e(mb_substr($u['full_name']??'N',0,1))?>
        <?php endif; ?>
      </div>
      <div class="side-name"><?=e($u['full_name']??'Nguyễn Văn A')?></div>
      <div class="side-status">
        <?php if ($u && is_pitch_owner($u)): ?>
          ● Chủ sân đối tác (PITCH_OWNER)
        <?php else: ?>
          ● <?=e(!empty($u['team_name']) ? $u['team_name'] : 'Đội trưởng FC')?> • 🛡️ <?=(int)($u['reputation_score'] ?? 100)?>đ
        <?php endif; ?>
      </div>
    </div>
    
    <div class="side-menu-group">ĐIỀU HƯỚNG</div>
    <?php 
    $nav_items = [
      'home'    => ['index.php', '🏟 Trang chủ'],
      'explore' => ['index.php#fields', '🔎 Khám phá sân'],
      'book'    => ['user/booking.php', '📅 Đặt sân'],
      'match'   => ['user/matchmaking.php', '⚔️ Cáp kèo / Tìm đối'],
      'mine'    => ['user/my-bookings.php', '🎫 Vé của tôi'],
      'noti'    => ['user/notifications.php', '🔔 Thông báo' . ($unread_count ? ' <span class="badge-mini green">'.$unread_count.'</span>' : '')],
      'prof'    => ['user/profile.php', '👤 Hồ sơ & Ví nội bộ'],
    ];
    if ($u && is_pitch_owner($u)) {
      $nav_items['owner'] = ['user/owner-dashboard.php', '🏟 Kênh Chủ Sân'];
    } else {
      $nav_items['partner'] = ['user/profile.php?tab=partner', '🤝 Đăng ký Mở sân'];
    }
    foreach($nav_items as $k => $v): 
    ?>
      <a class="side-link <?=($active??'')===$k?'on':''?>" href="<?=$base.$v[0]?>"><span><?=$v[1]?></span></a>
    <?php endforeach; ?>

    <div class="side-footer">
      <div class="pitch-mini-card">
        <b>🏆 GIỜ VÀNG THI ĐẤU</b>
        <small>17:00 - 21:00 thường kín sân sớm. Đặt trước để giữ lịch đẹp!</small>
      </div>
    </div>
  </nav>
  <?php endif; ?>

  <!-- Bắt đầu vùng nội dung chính của trang con & hiển thị thông báo Flash -->
  <main class="user-main">
    <?php if (!empty($_SESSION['flash'])):
        [$t, $m] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $isCancelAlert = ($t === 'cancel' || $t === 'err' || mb_stripos($m, 'hủy') !== false);
        $alertClass    = $isCancelAlert ? 'cancel' : $t;
        $alertStyle    = $isCancelAlert
            ? 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-left:4px solid #ef4444;'
            : '';
    ?>
      <div class="alert <?=$alertClass?>" style="<?=$alertStyle?>">
        <span class="alert-icon"><?=$isCancelAlert ? '❌' : ($t === 'ok' ? '✔' : '⚠️')?></span>
        <div class="alert-text" style="font-weight:600"><?=e($m)?></div>
      </div>
    <?php endif; ?>
