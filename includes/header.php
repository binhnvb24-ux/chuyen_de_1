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
  <link rel="stylesheet" href="<?=$base?>assets/css/style.css">
</head>
<body class="user-portal">
<!-- Thanh điều hướng thể thao trên cùng (Athletic Daylight Topbar) -->
<header class="topbar">
  <div class="topbar-container">
    <a class="brand" href="<?=$base?>index.php">
      <div class="brand-icon">⚽</div>
      <div>
        <b>SÂN BÓNG <span class="sport-badge-inline">PRO</span></b>
        <small>ARENA BOOKING & MATCHMAKING</small>
      </div>
    </a>
    
    <div class="tb-right">
      <a class="nav-item" href="<?=$base?>index.php">🏟 Trang chủ</a>
      <a class="nav-item <?=($active??'')==='book'?'active':''?>" href="<?=$base?>user/booking.php">⚡ Đặt sân</a>
      <a class="nav-item <?=($active??'')==='match'?'active':''?>" href="<?=$base?>user/matchmaking.php">
        ⚔️ Cáp kèo / Tìm đối
        <?php if($open_matches_count > 0): ?>
          <span class="live-pill"><?=$open_matches_count?> kèo</span>
        <?php endif; ?>
      </a>
      <a class="nav-item <?=($active??'')==='mine'?'active':''?>" href="<?=$base?>user/my-bookings.php">🎫 Vé của tôi</a>

      <?php if($u): ?>
        <a class="btn-noti" href="<?=$base?>user/notifications.php" title="Thông báo">
          🔔
          <?php if($unread_count > 0): ?>
            <span class="dot"><?=$unread_count?></span>
          <?php endif; ?>
        </a>
        <div class="user-chip">
          <span class="avatar"><?=e(mb_substr($u['full_name'],0,1))?></span>
          <div class="user-meta">
            <span class="uname"><?=e($u['full_name'])?></span>
            <small class="urole">⚽ Cầu thủ</small>
          </div>
        </div>
        <a class="lnk-logout" href="<?=$base?>logout.php" onclick="return confirm('Bạn chắc chắn muốn đăng xuất?')">Đăng xuất</a>
      <?php else: ?>
        <a class="btn-login" href="<?=$base?>login.php">Đăng nhập →</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Khung bố cục 2 cột: Sidebar menu trái + Nội dung chính bên phải -->
<div class="layout one">
  <nav class="side">
    <div class="side-user-card">
      <div class="side-jersey-num">#<?=!empty($u['id']) ? str_pad((string)($u['id'] % 99), 2, '0', STR_PAD_LEFT) : '10'?></div>
      <div class="side-avatar"><?=e(mb_substr($u['full_name']??'U',0,1))?></div>
      <div class="side-name"><?=e($u['full_name']??'Khách thăm sân')?></div>
      <div class="side-status"><span class="pulse-dot"></span> Sẵn sàng ra sân</div>
    </div>
    
    <div class="side-menu-group">ĐIỀU HƯỚNG SÂN ĐẤU</div>
    <?php 
    $nav_items = [
      'home'  => ['index.php', '🏟 Trang chủ Sân Bóng'],
      'book'  => ['user/booking.php', '⚡ Đặt sân theo sơ đồ giờ'],
      'match' => ['user/matchmaking.php', '⚔️ Sàn Cáp Kèo / Tìm Đối' . ($open_matches_count ? ' <span class="badge-mini green">'.$open_matches_count.'</span>' : '')],
      'mine'  => ['user/my-bookings.php', '🎫 Lịch đặt & Vé QR'],
      'noti'  => ['user/notifications.php', '🔔 Thông báo' . ($unread_count ? ' <span class="badge-mini">'.$unread_count.'</span>' : '')],
      'prof'  => ['user/profile.php', '👤 Hồ sơ cầu thủ']
    ];
    foreach($nav_items as $k => $v): 
    ?>
      <a class="side-link <?=($active??'')===$k?'on':''?>" href="<?=$base.$v[0]?>"><span><?=$v[1]?></span></a>
    <?php endforeach; ?>

    <div class="side-footer">
      <div class="pitch-mini-card">
        <b>🏆 GIỜ VÀNG THI ĐẤU</b>
        <small>Khung 17:00 - 21:00 thường kín sân sớm. Hãy đặt trước để giữ sân đẹp!</small>
      </div>
      <a class="side-support-btn" href="<?=$base?>index.php#contact">📞 Liên hệ Ban Quản Lý Sân</a>
    </div>
  </nav>

  <!-- Bắt đầu vùng nội dung chính của trang con & hiển thị thông báo Flash -->
  <main class="user-main">
    <?php if(!empty($_SESSION['flash'])): [$t,$m]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert <?=$t?>">
        <span class="alert-icon"><?=$t==='ok'?'✔':'⚠️'?></span>
        <div class="alert-text"><?=e($m)?></div>
      </div>
    <?php endif; ?>
