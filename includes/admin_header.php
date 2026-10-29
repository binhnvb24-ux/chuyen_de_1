<?php
/**
 * FILE: includes/admin_header.php
 * MÔ TẢ: Giao diện phần đầu trang (Sidebar menu trái + Topbar) cho khu vực Quản trị (admin/*).
 * YÊU CẦU BIẾN: $adm (tài khoản admin), $page (menu đang mở), $title (tiêu đề trang).
 */
$base = project_base();
$adm_user = $adm ?? $admin ?? ['username' => 'admin', 'full_name' => 'Quản trị viên'];

// Đếm số đơn chờ duyệt để hiện badge trên menu Admin
$pending_bk_count = 0;
if (isset($pdo)) {
  try {
    $pending_bk_count = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
  } catch (Exception $e) {}
}

// Danh sách các mục menu quản lý của Admin
$pages = [
  'dashboard' => ['🏠', 'Tổng quan', 0],
  'calendar'  => ['📅', 'Xem lịch trực quan', 0],
  'bookings'  => ['📋', 'Đặt sân & Thanh toán', $pending_bk_count],
  'fields'    => ['🏟', 'Quản lý sân bóng', 0],
  'services'  => ['🥤', 'Dịch vụ tiện ích', 0],
  'vouchers'  => ['🎟', 'Mã giảm giá Voucher', 0],
  'users'     => ['👥', 'Quản lý người dùng', 0],
  'revenue'   => ['📈', 'Thống kê doanh thu', 0],
  'reports'   => ['📊', 'Báo cáo hiệu suất', 0],
  'settings'  => ['⚙', 'Cài đặt AI & VietQR', 0]
];
?><!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin - <?=e($title ?? 'Quản trị hệ thống')?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?=$base?>assets/css/style.css">
  <link rel="stylesheet" href="<?=$base?>assets/css/admin.css">
</head>
<body class="adm">
<!-- Thanh điều hướng dọc bên trái (Admin Sidebar) -->
<aside class="asb" id="asb">
  <div class="abrand">
    <div class="abrand-logo">⚽</div>
    <div>
      Admin Sân Bóng
      <span class="abrand-sub">Hệ thống Quản trị Pro</span>
    </div>
  </div>

  <div class="asb-group">Menu Quản Lý</div>
  <?php foreach($pages as $k => $v): ?>
    <a class="<?=($page??'')===$k?'on':''?>" href="<?=$base?>admin/<?=$k?>.php">
      <span class="nav-ic"><?=$v[0]?></span>
      <span><?=$v[1]?></span>
      <?php if(!empty($v[2])): ?>
        <span class="nav-badge"><?=$v[2]?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>

  <div class="asb-group">Liên Kết Nhanh</div>
  <a href="<?=$base?>index.php" target="_blank">
    <span class="nav-ic">🌐</span>
    <span>Xem Trang Chủ Khách</span>
  </a>

  <a class="out" href="<?=$base?>logout.php" onclick="return confirm('Bạn có chắc chắn muốn đăng xuất khỏi trang Quản trị?')">
    <span class="nav-ic">↪</span>
    <span>Đăng xuất</span>
  </a>
</aside>

<!-- Vùng nội dung chính bên phải (Admin Main) -->
<div class="amain">
  <header class="atop">
    <div class="atop-left">
      <button class="atop-toggle" type="button" onclick="document.getElementById('asb').classList.toggle('open')">☰</button>
      <div class="atop-title-wrap">
        <h2><?=e($title ?? 'Bảng điều khiển')?></h2>
        <small>Hệ thống quản lý đặt sân, đối soát thanh toán Demo & Trợ lý AI</small>
      </div>
    </div>

    <div class="atop-right">
      <?php if($pending_bk_count > 0): ?>
        <a class="atop-btn" href="<?=$base?>admin/bookings.php?status=pending" style="border-color:#f59e0b;color:#fde68a !important">
          🔔 <?=$pending_bk_count?> đơn chờ duyệt
        </a>
      <?php endif; ?>
      <a class="atop-btn" href="<?=$base?>index.php" target="_blank">🌐 Trang chủ ↗</a>
      <div class="atop-user">
        <div class="atop-avatar"><?=e(mb_strtoupper(mb_substr($adm_user['username'] ?? 'A', 0, 1)))?></div>
        <div>
          <b style="font-size:13px;display:block;line-height:1.2;color:#fff"><?=e($adm_user['full_name'] ?? $adm_user['username'])?></b>
          <small style="font-size:10.5px;color:#34d399;margin:0">Quản trị viên</small>
        </div>
      </div>
    </div>
  </header>

  <!-- Hiển thị thông báo Flash (Thành công / Lỗi) -->
  <?php if(!empty($_SESSION['flash'])): [$t,$m]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert <?=$t?>">
      <span class="alert-icon"><?=$t==='ok'?'✔':'⚠️'?></span>
      <div class="alert-text"><?=e($m)?></div>
    </div>
  <?php endif; ?>
