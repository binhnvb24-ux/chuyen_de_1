<?php
/**
 * FILE: user/field-detail.php
 * MÔ TẢ: Trang xem thông tin chi tiết của một Sân bóng.
 * CHỨC NĂNG: Hiển thị ảnh sân, giá thuê, mô tả, khung giờ đã đặt trong ngày hôm nay
 *            và cho phép khách hàng gửi đánh giá (chấm sao + nhận xét).
 */
require_once '../includes/auth.php';
$u = current_user(); 
if($u && $u['role'] === 'admin'){ header('Location: ../admin/fields.php'); exit; }

// 1. Lấy thông tin sân bóng theo ID
$fid = (int)($_GET['id'] ?? $_POST['field_id'] ?? 0);
$s = $pdo->prepare("SELECT * FROM fields WHERE id=?"); 
$s->execute([$fid]); 
$f = $s->fetch();

if(!$f){ 
  http_response_code(404); 
  flash('err','Không tìm thấy thông tin sân bóng.'); 
  header('Location: ../index.php'); 
  exit; 
}

// 2. Xử lý khi người dùng gửi đánh giá mới cho sân bóng (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u) {
  csrf_check();
  $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
  $comment = trim($_POST['comment'] ?? '');
  if ($comment === '') {
    flash('err', 'Vui lòng nhập nội dung đánh giá.');
  } else {
    $pdo->prepare("INSERT INTO reviews(user_id, field_id, rating, comment) VALUES(?,?,?,?)")
        ->execute([$u['id'], $f['id'], $rating, $comment]);
    $avgStmt = $pdo->prepare("SELECT ROUND(AVG(rating),1) avg_r, COUNT(*) cnt FROM reviews WHERE field_id=?");
    $avgStmt->execute([$f['id']]);
    $stat = $avgStmt->fetch();
    if ($stat && $stat['cnt'] > 0) {
      $pdo->prepare("UPDATE fields SET rating=?, review_count=review_count+1 WHERE id=?")
          ->execute([$stat['avg_r'], $f['id']]);
    }
    flash('ok', 'Cảm ơn bạn đã gửi đánh giá sân bóng!');
    header("Location: field-detail.php?id={$f['id']}");
    exit;
  }
}

// 3. Truy vấn lịch bận hôm nay của sân và 10 đánh giá mới nhất
$s = $pdo->prepare("SELECT start_time,end_time FROM bookings WHERE field_id=? AND booking_date=CURDATE() AND status IN('pending','confirmed','completed') ORDER BY start_time"); 
$s->execute([$f['id']]); 
$busy = $s->fetchAll();

$revStmt = $pdo->prepare("SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.field_id=? ORDER BY r.id DESC LIMIT 10");
$revStmt->execute([$f['id']]);
$reviews = $revStmt->fetchAll();

$active = 'book'; 
$title = $f['name']; 
include '../includes/header.php'; 
?>

<!-- 4. Giao diện hiển thị chi tiết sân bóng, đánh giá và khung đặt sân nhanh -->
<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:24px;align-items:start">
  <div>
    <div class="box" style="padding:0;overflow:hidden">
      <div style="height:320px;position:relative;background:#0f172a">
        <img src="<?=e(img_src($f['image']))?>" alt="<?=e($f['name'])?>" style="width:100%;height:100%;object-fit:cover">
        <span class="hp-badge" style="position:absolute;top:16px;left:16px;font-size:12px;padding:6px 14px">
          <?=$f['status']==='active'?'🟢 Sân đang hoạt động':'⛔ Đang bảo trì'?>
        </span>
        <span style="position:absolute;bottom:16px;right:16px;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700">
          ⭐ <?=e($f['rating'])?> (<?=e($f['review_count'])?> lượt đánh giá)
        </span>
      </div>

      <div style="padding:24px">
        <h2 style="margin:0 0 8px;font-size:24px;color:#0f172a"><?=e($f['name'])?></h2>
        <p style="color:#64748b;font-size:14px;margin:0 0 16px">📍 <?=e($f['address'])?> (Khu vực: <b><?=e($f['district'])?></b>)</p>

        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px">
          <span class="tag green">⚽ Sân tiêu chuẩn <?=$f['type']?> người</span>
          <span class="tag blue">💡 Hệ thống đèn LED chiếu sáng</span>
          <span class="tag gray">🅿️ Bãi đỗ xe rộng rãi</span>
          <span class="tag gray">🚿 Phòng thay đồ</span>
        </div>

        <h4 style="margin:20px 0 8px;color:#0f172a;font-size:16px">Mô tả chi tiết</h4>
        <div style="color:#334155;line-height:1.7;font-size:14px;background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e2e8f0">
          <?=nl2br(e($f['description'] ?: 'Sân cỏ nhân tạo chất lượng cao, thường xuyên được bảo dưỡng, mặt sân êm ái chống trơn trượt.'))?>
        </div>
      </div>
    </div>

    <div class="box">
      <h3 style="margin:0 0 16px;font-size:18px;color:#0f172a">⭐ Đánh giá từ người chơi (<?=count($reviews)?>)</h3>
      <?php if($u): ?>
        <form method="post" style="background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:18px">
          <?=csrf_field()?>
          <input type="hidden" name="field_id" value="<?=$f['id']?>">
          <div style="display:flex;gap:12px;align-items:center;margin-bottom:10px">
            <label style="font-weight:700;font-size:13px">Chấm điểm:</label>
            <select name="rating" style="padding:6px 12px;border-radius:8px;border:1px solid #cbd5e1">
              <option value="5">⭐⭐⭐⭐⭐ (5 sao - Tuyệt vời)</option>
              <option value="4">⭐⭐⭐⭐ (4 sao - Tốt)</option>
              <option value="3">⭐⭐⭐ (3 sao - Khá)</option>
              <option value="2">⭐⭐ (2 sao - Trung bình)</option>
              <option value="1">⭐ (1 sao - Cần cải thiện)</option>
            </select>
          </div>
          <textarea name="comment" rows="2" required placeholder="Chia sẻ cảm nhận của bạn về mặt cỏ, ánh sáng, dịch vụ tại sân..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #cbd5e1;font-family:inherit;margin-bottom:10px"></textarea>
          <button class="btn sm" type="submit">Gửi đánh giá →</button>
        </form>
      <?php endif; ?>

      <?php if(!$reviews): ?>
        <small>Chưa có nhận xét nào. Hãy là người đầu tiên đánh giá sân bóng này!</small>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
          <?php foreach($reviews as $rv): ?>
            <div style="padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;background:#fff">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                <b>👤 <?=e($rv['full_name'])?></b>
                <span style="color:#f59e0b;font-size:13px"><?=str_repeat('★', (int)$rv['rating'])?></span>
              </div>
              <div style="font-size:13.5px;color:#334155"><?=e($rv['comment'])?></div>
              <small style="font-size:11px;color:#94a3b8"><?=date('d/m/Y H:i', strtotime($rv['created_at']))?></small>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="box" style="position:sticky;top:88px">
    <div style="border-bottom:1px solid #e2e8f0;padding-bottom:16px;margin-bottom:16px">
      <span style="color:#64748b;font-size:13px">Giá thuê sân:</span>
      <div style="font-size:28px;font-weight:800;color:#059669;margin-top:4px">
        <?=money($f['price_per_hour'])?> <span style="font-size:14px;font-weight:500;color:#64748b">/ giờ</span>
      </div>
    </div>

    <h4 style="margin:0 0 12px;font-size:15px;color:#0f172a">🕒 Lịch thi đấu hôm nay (<?=date('d/m/Y')?>):</h4>
    <div style="margin-bottom:20px">
      <?php if(!$busy): ?>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#064e3b;padding:12px;border-radius:10px;font-size:13px">
          🎉 Sân đang còn trống tất cả các khung giờ trong hôm nay!
        </div>
      <?php else: ?>
        <small style="color:#64748b;margin-bottom:8px">Các khung giờ đã có người đặt:</small>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach($busy as $b): ?>
            <span class="tag amber">⛔ <?=substr($b['start_time'],0,5)?> - <?=substr($b['end_time'],0,5)?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if($f['status']==='active'): ?>
      <a class="btn" style="width:100%;padding:14px;font-size:15px;font-weight:700" href="booking.php?field_id=<?=$f['id']?>">
        ⚡ Tiến hành đặt sân này →
      </a>
    <?php else: ?>
      <button class="btn" disabled style="width:100%;background:#cbd5e1;cursor:not-allowed">
        Sân đang bảo dưỡng
      </button>
    <?php endif; ?>

    <button type="button" class="btn bl outline" style="width:100%;margin-top:10px" onclick="toggleAiChat()">
      🤖 Hỏi AI tư vấn thêm về sân này
    </button>

    <a href="../index.php" style="display:block;text-align:center;margin-top:14px;font-size:13px;color:#64748b">
      ← Xem các sân bóng khác
    </a>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
