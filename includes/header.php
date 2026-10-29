<?php
/**
 * FILE: includes/header.php
 * MÔ TẢ: Giao diện phần đầu trang (Header + Sidebar trái) dành cho khu vực Khách hàng (user/*).
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
?><!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=e($title ?? 'Sân Bóng - Đặt sân trực tuyến')?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?=$base?>assets/css/style.css">
</head>
<body class="user-portal">
<!-- Thanh điều hướng trên cùng (Topbar) -->
<header class="topbar">
  <div class="topbar-container">
    <a class="brand" href="<?=$base?>index.php">
      <div class="brand-icon">⚽</div>
      <div>
        <b>Sân Bóng</b>
        <small>Hệ thống đặt sân thông minh</small>
      </div>
    </a>
    
    <div class="tb-right">
      <a class="nav-item" href="<?=$base?>index.php">Trang chủ</a>
      <a class="nav-item <?=($active??'')==='book'?'active':''?>" href="<?=$base?>user/booking.php">Đặt sân</a>
      <a class="nav-item <?=($active??'')==='mine'?'active':''?>" href="<?=$base?>user/my-bookings.php">Lịch đặt</a>

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
            <small class="urole">Thành viên</small>
          </div>
        </div>
        <a class="lnk-logout" href="<?=$base?>logout.php" onclick="return confirm('Bạn chắc chắn muốn đăng xuất?')">Đăng xuất</a>
      <?php else: ?>
        <a class="btn-login" href="<?=$base?>login.php">Đăng nhập</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Khung bố cục 2 cột: Sidebar menu trái + Nội dung chính bên phải -->
<div class="layout one">
  <nav class="side">
    <div class="side-user-card">
      <div class="side-avatar"><?=e(mb_substr($u['full_name']??'U',0,1))?></div>
      <div class="side-name"><?=e($u['full_name']??'Khách')?></div>
      <div class="side-status"><span class="pulse-dot"></span> Đang hoạt động</div>
    </div>
    
    <div class="side-menu-group">MENU CHÍNH</div>
    <?php 
    $nav_items = [
      'home' => ['index.php', '🏠 Trang chủ'],
      'book' => ['user/booking.php', '⚡ Đặt sân mới'],
      'mine' => ['user/my-bookings.php', '📅 Lịch đặt của tôi'],
      'noti' => ['user/notifications.php', '🔔 Thông báo' . ($unread_count ? ' <span class="badge-mini">'.$unread_count.'</span>' : '')],
      'prof' => ['user/profile.php', '👤 Tài khoản cá nhân']
    ];
    foreach($nav_items as $k => $v): 
    ?>
      <a class="side-link <?=($active??'')===$k?'on':''?>" href="<?=$base.$v[0]?>"><?=$v[1]?></a>
    <?php endforeach; ?>

    <div class="side-footer">
      <small>Cần trợ giúp?</small>
      <a class="side-support-btn" href="<?=$base?>index.php#contact">📞 Hỗ trợ khách hàng</a>
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
