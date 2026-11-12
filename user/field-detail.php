<?php
/**
 * FILE: user/field-detail.php
 * MÔ TẢ: Trang xem thông tin chi tiết của một Sân bóng.
 * CHỨC NĂNG: Hiển thị ảnh sân, giá thuê, mô tả, khung giờ đã đặt trong ngày hôm nay
 *            và cho phép khách hàng gửi đánh giá (chấm sao + nhận xét).
 */
require_once '../includes/auth.php';
$u = current_user();

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

<!-- Banner đầu trang Chi tiết sân bóng -->
<div class="sport-page-head">
  <div>
    <h2><?=e($f['name'])?> <?=!empty($f['pitch_code']) ? '• Sân ' . e($f['pitch_code']) : ''?></h2>
    <small>📍 <?=e($f['address'])?> (Khu vực: <?=e($f['district'])?>, TP.HCM)</small>
  </div>
  <div>
    <a class="btn" href="booking.php?field_id=<?=$f['id']?>">+ ĐẶT SÂN NÀY</a>
  </div>
</div>

<!-- 4. Giao diện hiển thị chi tiết sân bóng (60% Trái - 40% Phải) -->
<div style="display:grid;grid-template-columns:1.45fr 1fr;gap:20px;align-items:start">
  <div>
    <div class="box">
      <div class="bh">
        <b>🖼 Hình ảnh sân bóng</b>
      </div>
      <div style="height:290px;position:relative;background:#0f172a;border-radius:14px;overflow:hidden;margin-bottom:16px">
        <img src="<?=e(img_src($f['image']))?>" alt="<?=e($f['name'])?>" style="width:100%;height:100%;object-fit:cover">
        <span style="position:absolute;bottom:14px;right:14px;background:rgba(15,23,42,0.88);backdrop-filter:blur(8px);color:#fff;padding:6px 14px;border-radius:20px;font-size:12.5px;font-weight:700">
          ★ <?=e($f['rating'])?> (<?=e($f['review_count'])?> lượt đánh giá)
        </span>
      </div>

      <div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
          <span class="tag green">⚽ Sân <?=$f['type']?> người</span>
          <span class="tag green">🌱 Cỏ chuẩn FIFA</span>
          <span class="tag blue">💡 Đèn LED chống chói</span>
          <span class="tag gray">🅿️ Bãi xe miễn phí</span>
          <span class="tag gray">🚿 Phòng thay đồ & Nước uống</span>
        </div>

        <!-- Thông tin Cơ cấu sân con, Giá Giờ thường / Giờ vàng & SĐT liên hệ thực tế -->
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:13px">
          <div>
            <small style="margin:0;color:#15803d;font-weight:700">🏟 Cơ cấu sân con bên trong:</small>
            <b style="color:#0f172a;display:block;margin-top:2px"><?=e($f['sub_pitches'] ?? "4 sân 5 người, 2 sân 7 người (Ghép được sân 11)")?></b>
          </div>
          <div>
            <small style="margin:0;color:#15803d;font-weight:700">📞 Hotline ban quản lý sân:</small>
            <b style="color:#0f172a;display:block;margin-top:2px"><?=e($f['contact_phone'] ?? '0901.234.567')?> • 🟢 Đang mở cửa (05:30 - 23:30)</b>
          </div>
        </div>

        <h4 style="margin:14px 0 8px;color:#0f172a;font-size:15px">Giới thiệu sân</h4>
        <div style="color:#334155;line-height:1.65;font-size:13.5px">
          <?=nl2br(e($f['description'] ?: 'Sân cỏ nhân tạo đạt chuẩn FIFA Quality, hệ thống đèn LED chiếu sáng chống chói, phòng thay đồ thoáng mát, gửi xe miễn phí 100%.'))?>
        </div>
      </div>
    </div>

    <!-- Hộp Bản đồ vị trí thực tế của Cụm sân (Leaflet.js + OpenStreetMap) & Nút Chỉ đường Google Maps -->
    <?php
    $fLat = (float)($f['latitude'] ?? 10.7751200) ?: 10.7751200;
    $fLng = (float)($f['longitude'] ?? 106.6928500) ?: 106.6928500;
    $gmapDirUrl = "https://www.google.com/maps/dir/?api=1&destination={$fLat},{$fLng}";
    ?>
    <div class="box">
      <div class="bh">
        <div>
          <b>🗺 Vị trí cụm sân trên Bản đồ</b>
          <small style="margin:2px 0 0">📍 <?=e($f['address'])?> (Tọa độ GPS: <?=number_format($fLat, 5)?>, <?=number_format($fLng, 5)?>)</small>
        </div>
        <a class="btn sm" href="<?=e($gmapDirUrl)?>" target="_blank" rel="noopener" style="background:#16a34a;white-space:nowrap">
          🧭 Chỉ đường bằng Google Maps ↗
        </a>
      </div>
      <div id="detailPitchMap" style="width:100%;height:260px;border-radius:12px;border:1px solid #cbd5e1;overflow:hidden;z-index:1"></div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (typeof L === 'undefined') return;
        var lat = <?=json_encode($fLat)?>;
        var lng = <?=json_encode($fLng)?>;
        var map = L.map('detailPitchMap').setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '© OpenStreetMap'
        }).addTo(map);

        var customIcon = L.divIcon({
          className: 'custom-pitch-marker',
          html: '<div style="background:#16a34a;color:#fff;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-size:18px;border:3px solid #fff;box-shadow:0 4px 12px rgba(0,0,0,0.3)">⚽</div>',
          iconSize: [36, 36],
          iconAnchor: [18, 18],
          popupAnchor: [0, -18]
        });

        var popupHtml =
          '<div style="font-family:inherit;min-width:210px">' +
            '<b style="font-size:14px;color:#0f172a;display:block;margin-bottom:3px">' + <?=json_encode($f['name'])?> + '</b>' +
            '<div style="font-size:12px;color:#475569;margin-bottom:5px">📍 ' + <?=json_encode($f['address'])?> + '</div>' +
            '<div style="font-size:12px;color:#16a34a;font-weight:700;margin-bottom:8px">💰 Giá từ ' + <?=json_encode(money($f['normal_price'] ?? $f['price_per_hour']))?> + '/giờ • 🟢 Đang mở cửa</div>' +
            '<a href="' + <?=json_encode($gmapDirUrl)?> + '" target="_blank" rel="noopener" style="display:inline-block;background:#16a34a;color:#fff;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none">🧭 Mở Google Maps chỉ đường ↗</a>' +
          '</div>';

        L.marker([lat, lng], { icon: customIcon }).addTo(map).bindPopup(popupHtml).openPopup();
      });
    </script>

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

  <!-- Cột phải (40%): Thẻ Lịch thi đấu và giá sân -->
  <div class="box" style="position:sticky;top:88px">
    <div class="bh">
      <b>📅 Lịch thi đấu và giá sân</b>
    </div>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
          <small style="margin:0;color:#64748b;font-weight:700;font-size:11.5px">☀️ GIỜ THƯỜNG (05:30 - 16:00)</small>
          <div style="font-size:24px;font-weight:800;color:#16a34a;margin-top:2px">
            <?=money($f['normal_price'] ?? $f['price_per_hour'])?> <span style="font-size:12.5px;font-weight:600;color:#64748b">/ giờ</span>
          </div>
        </div>
        <div style="text-align:right">
          <small style="margin:0;color:#d97706;font-weight:700;font-size:11.5px">🔥 GIỜ VÀNG (16:00 - 22:00)</small>
          <div style="font-size:20px;font-weight:800;color:#d97706;margin-top:2px">
            <?=money($f['peak_price'] ?? round($f['price_per_hour'] * 1.6))?> <span style="font-size:12px;font-weight:600;color:#64748b">/ giờ</span>
          </div>
        </div>
      </div>
    </div>

    <form action="booking.php" method="get" style="margin-bottom:16px">
      <input type="hidden" name="field_id" value="<?=$f['id']?>">
      <label style="display:block;font-weight:700;font-size:12.5px;margin-bottom:6px;color:#475569">Chọn ngày đặt sân</label>
      <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-family:inherit;font-weight:600;margin-bottom:14px">

      <label style="display:block;font-weight:700;font-size:12.5px;margin-bottom:6px;color:#475569">Các khung giờ bận hôm nay</label>
      <div style="margin-bottom:16px">
        <?php if(!$busy): ?>
          <span class="tag amber">18:00 - 19:30</span>
        <?php else: ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach($busy as $b): ?>
              <span class="tag amber"><?=substr($b['start_time'],0,5)?> - <?=substr($b['end_time'],0,5)?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if($f['status']==='active'): ?>
        <button class="btn" type="submit" style="padding:11px 18px;font-size:13.5px">
          📅 Mở sơ đồ & Đặt sân →
        </button>
      <?php else: ?>
        <button class="btn" disabled style="background:#cbd5e1;cursor:not-allowed">
          Sân đang bảo dưỡng
        </button>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
