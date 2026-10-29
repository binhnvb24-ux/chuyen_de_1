<?php
/**
 * FILE: user/notifications.php
 * MÔ TẢ: Trang Trung tâm Thông báo của Khách hàng.
 * CHỨC NĂNG: Hiển thị danh sách thông báo về trạng thái đơn đặt sân, thanh toán và đánh dấu đã đọc.
 */
require_once '../includes/auth.php';
$u = require_login(); 
if($u['role'] === 'admin'){ header('Location: ../admin/dashboard.php'); exit; }

// Xử lý đánh dấu tất cả thông báo là đã đọc (POST)
if($_SERVER['REQUEST_METHOD'] === 'POST'){ 
  csrf_check(); 
  $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$u['id']]); 
  flash('ok', 'Đã đánh dấu tất cả thông báo là đã đọc.'); 
  header('Location: notifications.php'); 
  exit; 
}

// Lấy 50 thông báo gần nhất của người dùng
$s = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50"); 
$s->execute([$u['id']]); 
$rows = $s->fetchAll();

$has_unread = (bool)array_filter($rows, fn($r) => !$r['is_read']);
$active = 'noti'; 
$title = 'Thông báo của bạn'; 
include '../includes/header.php'; 
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#0f172a">🔔 Trung Tâm Thông Báo</h2>
    <small style="font-size:13.5px;color:#64748b">Cập nhật lịch đặt sân, nhắc nhở trận đấu và ưu đãi</small>
  </div>
  <?php if($has_unread): ?>
    <form method="post">
      <?=csrf_field()?>
      <button class="btn sm outline" type="submit">✔ Đánh dấu tất cả đã đọc</button>
    </form>
  <?php endif; ?>
</div>

<div class="box">
  <?php if(!$rows): ?>
    <div style="text-align:center;padding:40px 16px">
      <div style="font-size:40px;margin-bottom:8px">📭</div>
      <b style="color:#0f172a">Bạn chưa có thông báo nào</b>
      <small style="margin-top:4px">Khi bạn đặt sân hoặc có thay đổi trạng thái, thông báo sẽ hiển thị ở đây.</small>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach($rows as $r): ?>
        <div class="bk" style="<?=$r['is_read']?'border-color:#e2e8f0;':'background:#ecfdf5;border-color:#a7f3d0;'?>">
          <span style="width:38px;height:38px;border-radius:10px;background:<?=$r['is_read']?'#f1f5f9':'#d1fae5'?>;display:grid;place-items:center;font-size:18px;flex-shrink:0">
            <?=$r['is_read']?'📨':'🔔'?>
          </span>
          <div style="flex:1">
            <div style="font-size:14px;color:<?=$r['is_read']?'#334155':'#065f46'?>;font-weight:<?=$r['is_read']?'500':'700'?>">
              <?=e($r['message'])?>
            </div>
            <small style="color:#94a3b8;font-size:11.5px;margin-top:3px">
              🕒 <?=date('H:i - d/m/Y', strtotime($r['created_at']))?>
            </small>
          </div>
          <?php if(!$r['is_read']): ?>
            <span class="tag green" style="font-size:11px">Mới</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
