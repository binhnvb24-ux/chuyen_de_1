<?php
/**
 * FILE: includes/admin_header.php
 * MÔ TẢ: Giao diện phần đầu trang (Sidebar menu trái + Topbar) cho khu vực Quản trị (admin/*).
 * YÊU CẦU BIẾN: $adm (tài khoản admin), $page (menu đang mở), $title (tiêu đề trang).
 */
$base = project_base();
$adm_user = $adm ?? $admin ?? ['username' => 'admin', 'full_name' => 'Quản trị viên'];

// Đếm số đơn chờ duyệt & hồ sơ đối tác mở sân chờ duyệt để hiện badge trên menu Admin
$pending_bk_count = 0;
$pending_partner_count = 0;
if (isset($pdo)) {
  try {
    $pending_bk_count = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
    $pending_partner_count = (int)$pdo->query("SELECT COUNT(*) FROM partner_applications WHERE status='PENDING'")->fetchColumn();
  } catch (Exception $e) {}
}

// Danh sách các mục menu quản lý của Admin (Voucher do từng Tài khoản Đối tác Chủ sân tự thiết lập)
$pages = [
  'dashboard' => ['🏠', 'Tổng quan', 0],
  'calendar'  => ['📅', 'Xem lịch trực quan', 0],
  'bookings'  => ['📋', 'Đặt sân & Thanh toán', $pending_bk_count],
  'partners'  => ['🤝', 'Duyệt Chủ sân Đối tác', $pending_partner_count],
  'fields'    => ['🏟', 'Quản lý sân & Chủ sân', 0],
  'users'     => ['👥', 'Tài khoản & Điểm uy tín', 0],
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
  <link rel="stylesheet" href="<?=$base?>assets/css/style.css?v=<?= @filemtime(__DIR__.'/../assets/css/style.css') ?: time() ?>">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
  <link rel="stylesheet" href="<?=$base?>assets/css/admin.css?v=<?= @filemtime(__DIR__.'/../assets/css/admin.css') ?: time() ?>">
  <style>
    /* Nút icon tròn chuyển chế độ Sáng / Tối trên thanh Topbar */
    .adm .atop button.atop-icon-btn {
      width: 38px !important;
      height: 38px !important;
      padding: 0 !important;
      border-radius: 50% !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-size: 18px !important;
      line-height: 1 !important;
      cursor: pointer;
      background: var(--adm-surface, #131f35) !important;
      border: 1px solid var(--adm-border, #233452) !important;
      transition: all 0.2s ease;
    }
    .adm .atop button.atop-icon-btn:hover {
      border-color: #10b981 !important;
      transform: scale(1.06);
    }
    /* Đảm bảo chế độ Sáng (Light Mode) hoạt động tức thì trên mọi trình duyệt */
    body.adm.light-mode {
      --adm-bg: #f1f5f3 !important;
      --adm-sidebar: #ffffff !important;
      --adm-surface: #ffffff !important;
      --adm-surface-hover: #f8fafc !important;
      --adm-border: #d8e4dd !important;
      --adm-green: #059669 !important;
      --adm-neon: #10b981 !important;
      --adm-text: #0f172a !important;
      --adm-muted: #64748b !important;
      --admin-bg: #f1f5f3 !important;
      --admin-panel: #ffffff !important;
      --admin-border: #d8e4dd !important;
      --admin-green: #059669 !important;
      --admin-text: #0f172a !important;
      --admin-muted: #64748b !important;
      background: #f1f5f3 !important;
      color: #0f172a !important;
    }
    body.adm.light-mode .asb {
      background: linear-gradient(180deg, #ffffff 0%, #f8faf9 100%) !important;
      border-right: 1px solid #d8e4dd !important;
      box-shadow: 4px 0 20px rgba(15, 23, 42, 0.05) !important;
    }
    body.adm.light-mode .abrand { color: #0f172a !important; border-bottom: 1px solid #e2e8f0 !important; }
    body.adm.light-mode .abrand-sub { color: #059669 !important; }
    body.adm.light-mode .asb a { color: #334155 !important; }
    body.adm.light-mode .asb a:hover { color: #047857 !important; background: #ecfdf5 !important; border-color: #a7f3d0 !important; }
    body.adm.light-mode .asb a.on {
      color: #065f46 !important;
      background: linear-gradient(90deg, #d1fae5 0%, #ecfdf5 100%) !important;
      border-color: #6ee7b7 !important;
      box-shadow: inset 3px 0 0 #059669 !important;
    }
    body.adm.light-mode .amain { background: #f1f5f3 !important; color: #0f172a !important; }
    body.adm.light-mode .atop { background: rgba(255, 255, 255, 0.92) !important; border-bottom: 1px solid #d8e4dd !important; }
    body.adm.light-mode .atop-title-wrap h2,
    body.adm.light-mode .amain h1,
    body.adm.light-mode .amain h2,
    body.adm.light-mode .amain h3,
    body.adm.light-mode .amain b { color: #0f172a !important; }
    body.adm.light-mode .atop-btn,
    body.adm.light-mode .atop-icon-btn,
    body.adm.light-mode .atop-user {
      background: #ffffff !important;
      border-color: #cbd5e1 !important;
      color: #1e293b !important;
    }
    body.adm.light-mode .stat {
      background: #ffffff !important;
      border: 1px solid #d8e4dd !important;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05) !important;
    }
    body.adm.light-mode .stat b { color: #059669 !important; }
    body.adm.light-mode .box,
    body.adm.light-mode .amain .box,
    body.adm.light-mode .amain .sbar {
      background: #ffffff !important;
      color: #1e293b !important;
      border: 1px solid #d8e4dd !important;
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04) !important;
    }
    body.adm.light-mode .bh { border-bottom: 1px solid #e2e8f0 !important; }
    body.adm.light-mode .tw,
    body.adm.light-mode .amain .tw { background: #ffffff !important; border-color: #d8e4dd !important; }
    body.adm.light-mode table th,
    body.adm.light-mode .amain table th {
      background: #f1f5f9 !important;
      color: #334155 !important;
      border-bottom: 1px solid #cbd5e1 !important;
    }
    body.adm.light-mode table td,
    body.adm.light-mode .amain table td {
      color: #1e293b !important;
      border-bottom: 1px solid #e2e8f0 !important;
    }
    body.adm.light-mode .amain input:not([type="checkbox"]):not([type="radio"]):not([type="file"]),
    body.adm.light-mode .amain select,
    body.adm.light-mode .amain textarea {
      background: #ffffff !important;
      color: #0f172a !important;
      border: 1px solid #cbd5e1 !important;
    }
    body.adm.light-mode .amain .form label { color: #334155 !important; }
  </style>
</head>
<body class="adm">
<script>
  // Khôi phục giao diện Sáng / Tối đã lưu trong trình duyệt ngay khi tải trang
  (function() {
    if (localStorage.getItem('admin_theme') === 'light') {
      document.body.classList.add('light-mode');
    }
  })();
</script>
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
  <a href="<?=$base?>index.php">
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
      <?php if($pending_partner_count > 0): ?>
        <a class="atop-btn" href="<?=$base?>admin/partners.php?status=PENDING" style="border-color:#10b981;color:#059669 !important;background:rgba(16,185,129,0.12)">
          🤝 <?=$pending_partner_count?> hồ sơ Chủ sân chờ duyệt
        </a>
      <?php endif; ?>
      <?php if($pending_bk_count > 0): ?>
        <a class="atop-btn" href="<?=$base?>admin/bookings.php?status=pending" style="border-color:#f59e0b;color:#d97706 !important;background:rgba(245,158,11,0.12)">
          🔔 <?=$pending_bk_count?> đơn chờ duyệt
        </a>
      <?php endif; ?>
      <!-- Nút chỉ hiển thị biểu tượng (☀️ / 🌙) chuyển đổi giao diện Sáng / Tối -->
      <button type="button" class="atop-icon-btn" id="topThemeBtn" onclick="toggleAdminTheme()" title="Đổi giao diện Sáng / Tối">☀️</button>
      <!-- Nút chuyển về Trang chủ -->
      <a class="atop-btn" href="<?=$base?>index.php">🌐 Trang chủ</a>
      <div class="atop-user">
        <div class="atop-avatar"><?=e(mb_strtoupper(mb_substr($adm_user['username'] ?? 'A', 0, 1)))?></div>
        <div>
          <b class="atop-user-name" style="font-size:13px;display:block;line-height:1.2"><?=e($adm_user['full_name'] ?? $adm_user['username'])?></b>
          <small class="atop-user-role" style="font-size:10.5px;color:#10b981;margin:0">Quản trị viên</small>
        </div>
      </div>
    </div>
  </header>

  <script>
    // Hàm cập nhật biểu tượng nút Sáng / Tối (chỉ hiện icon ☀️ hoặc 🌙)
    function syncAdminThemeUI() {
      var isLight = document.body.classList.contains('light-mode');
      var topBtn = document.getElementById('topThemeBtn');
      if (topBtn) {
        topBtn.textContent = isLight ? '🌙' : '☀️';
        topBtn.title = isLight ? 'Chuyển sang chế độ Tối' : 'Chuyển sang chế độ Sáng';
      }
    }
    // Hàm xử lý khi nhấn nút biểu tượng Sáng / Tối
    function toggleAdminTheme() {
      var isLight = document.body.classList.toggle('light-mode');
      localStorage.setItem('admin_theme', isLight ? 'light' : 'dark');
      syncAdminThemeUI();
    }
    syncAdminThemeUI();
  </script>

  <!-- Hiển thị thông báo Flash (Thành công / Lỗi) -->
  <?php if(!empty($_SESSION['flash'])): [$t,$m]=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert <?=$t?>">
      <span class="alert-icon"><?=$t==='ok'?'✔':'⚠️'?></span>
      <div class="alert-text"><?=e($m)?></div>
    </div>
  <?php endif; ?>
