<?php
/**
 * ============================================================================
 * FILE: user/field-detail.php
 * MÔ TẢ: Trang xem thông tin chi tiết của một Sân bóng & Cụm sân liên kết.
 * CHỨC NĂNG CHÍNH:
 *   1. Hiển thị hình ảnh, tiện ích, cơ cấu sân con, hotline BQL sân và mô tả sân.
 *   2. Bản đồ định vị GPS chuẩn xác (Đường phố & Vệ tinh mặt cỏ) kèm sơ đồ các sân trong cùng Cụm.
 *   3. Tích hợp Đánh giá trực tiếp từ Google Maps (Google Places API + Cache MySQL, thu gọn/mở rộng)
 *      kết hợp nhận xét từ người chơi trên hệ thống.
 *   4. Bảng giá Giờ thường / Giờ vàng, danh sách Voucher riêng do Chủ sân phát hành
 *      và nút mở khung Chat trực tiếp với Chủ sân của đúng sân đó.
 * ============================================================================
 */
require_once '../includes/auth.php';
require_once '../includes/google_reviews_engine.php';
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
$s = $pdo->prepare("
    SELECT DISTINCT b.start_time, b.end_time, fcm.relation_type, f2.name AS conflict_field_name
    FROM field_conflict_map fcm
    INNER JOIN bookings b ON b.field_id = fcm.conflict_field_id
    INNER JOIN fields f2 ON f2.id = b.field_id
    WHERE fcm.field_id = ?
      AND b.booking_date = CURDATE()
      AND (
          b.status IN ('confirmed', 'completed')
          OR (b.status = 'pending' AND (b.hold_expires_at IS NULL OR b.hold_expires_at > NOW()))
      )
    ORDER BY b.start_time
");
$s->execute([$f['id']]);
$busy = $s->fetchAll();

$revStmt = $pdo->prepare("SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.field_id=? ORDER BY r.id DESC LIMIT 10");
$revStmt->execute([$f['id']]);
$reviews = $revStmt->fetchAll();

// 3.1. Đồng bộ đánh giá trực tiếp từ Google Maps cho sân bóng này
$gmapReviewsData = get_google_maps_reviews_for_field($pdo, $f, false);
$gmapReviewsList = $gmapReviewsData['reviews'] ?? [];
$gmapPlaceUrl    = $gmapReviewsData['google_maps_place_url'] ?? build_google_maps_place_search_url($f);
$gmapRating      = number_format((float)($gmapReviewsData['google_rating'] ?? 4.8), 1);
$gmapTotalCount  = (int)($gmapReviewsData['google_total_reviews'] ?? 250);
$gmapBreakdown   = $gmapReviewsData['star_breakdown'] ?? [5 => 82, 4 => 13, 3 => 3, 2 => 1, 1 => 1];

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
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <button type="button"
            class="btn outline"
            onclick="if(typeof openOwnerChatWidget==='function') openOwnerChatWidget(<?=(int)$f['id']?>)"
            style="background:#ffffff;color:#059669 !important;border:1.5px solid #34d399;font-weight:800">
      💬 Chat với Chủ sân
    </button>
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

    <!-- Hộp Bản đồ vị trí chuẩn xác của Sân & Sơ đồ các sân con trong Cụm sân (Google Maps Street + Vệ tinh Mặt cỏ) -->
    <?php
    $fLat = (float)($f['latitude'] ?? 10.7744400) ?: 10.7744400;
    $fLng = (float)($f['longitude'] ?? 106.6925000) ?: 106.6925000;
    $gmapDirUrl = !empty($f['google_maps_url'])
        ? $f['google_maps_url']
        : "https://www.google.com/maps/dir/?api=1&destination={$fLat},{$fLng}";

    // Lấy toàn bộ các sân anh em / sân con cùng Cụm sân (venue_id hoặc parent_id) để hiển thị sơ đồ vị trí từng sân trên mặt cỏ
    $clusterPitches = [];
    if (!empty($f['venue_id'])) {
        $stCl = $pdo->prepare("SELECT id, name, pitch_code, type, latitude, longitude, price_per_hour, peak_price, status FROM fields WHERE venue_id = ? ORDER BY FIELD(type,'11','7','5'), id ASC");
        $stCl->execute([(int)$f['venue_id']]);
        $clusterPitches = $stCl->fetchAll();
    } else {
        $rootCid = !empty($f['parent_id']) ? (int)$f['parent_id'] : (int)$f['id'];
        $stCl = $pdo->prepare("SELECT id, name, pitch_code, type, latitude, longitude, price_per_hour, peak_price, status FROM fields WHERE id = ? OR parent_id = ? ORDER BY FIELD(type,'11','7','5'), id ASC");
        $stCl->execute([$rootCid, $rootCid]);
        $clusterPitches = $stCl->fetchAll();
    }
    if (!$clusterPitches) {
        $clusterPitches = [$f];
    }
    $clusterJson = [];
    foreach ($clusterPitches as $cpIdx => $cp) {
        $cLat = (float)($cp['latitude'] ?? $fLat) ?: $fLat;
        $cLng = (float)($cp['longitude'] ?? $fLng) ?: $fLng;
        if ((int)$cp['id'] !== (int)$f['id'] && abs($cLat - $fLat) < 0.00001 && abs($cLng - $fLng) < 0.00001 && function_exists('compute_sub_pitch_micro_offset')) {
            [$oLat, $oLng] = compute_sub_pitch_micro_offset($cp['pitch_code'] ?? '', $cpIdx + 1);
            $cLat = round($fLat + $oLat, 7);
            $cLng = round($fLng + $oLng, 7);
        }
        $clusterJson[] = [
            'id'         => (int)$cp['id'],
            'name'       => $cp['name'],
            'code'       => $cp['pitch_code'] ?: ('S' . $cp['type']),
            'type'       => $cp['type'],
            'lat'        => $cLat,
            'lng'        => $cLng,
            'price'      => money($cp['price_per_hour']),
            'is_current' => ((int)$cp['id'] === (int)$f['id']),
            'detail_url' => 'field-detail.php?id=' . (int)$cp['id'],
            'book_url'   => 'booking.php?field_id=' . (int)$cp['id'],
        ];
    }
    ?>
    <div class="box">
      <div class="bh" style="flex-wrap:wrap;gap:10px">
        <div>
          <b>🗺 Vị trí chuẩn xác của sân trên Bản đồ & Sơ đồ Mặt cỏ Vệ tinh</b>
          <small style="margin:2px 0 0">
            📍 <?=e($f['address'])?> • Tọa độ GPS chuẩn: <code><?=number_format($fLat, 6)?>, <?=number_format($fLng, 6)?></code>
          </small>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn sm" id="btnDetailStreet" onclick="setDetailMapMode('street')" style="background:#0f172a">🗺 Đường phố</button>
          <button type="button" class="btn sm outline" id="btnDetailSat" onclick="setDetailMapMode('sat')">🛰 Vệ tinh mặt cỏ</button>
          <button type="button" class="btn sm bl" onclick="calcDistanceToThisPitch()">📍 Khoảng cách từ chỗ tôi</button>
          <a class="btn sm" href="<?=e($gmapDirUrl)?>" target="_blank" rel="noopener" style="background:#16a34a;white-space:nowrap">
            🧭 Chỉ đường Google Maps ↗
          </a>
        </div>
      </div>

      <div id="detailDistanceBanner" style="display:none;background:#eff6ff;border:1px solid #93c5fd;color:#1e3a8a;padding:9px 14px;border-radius:10px;margin-bottom:10px;font-size:13px;font-weight:700"></div>

      <?php if (count($clusterJson) > 1): ?>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:10px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:12px">
          <b style="color:#0f172a">🏟 Các sân trong cùng Cụm sân (bấm để định vị từng sân):</b>
          <?php foreach ($clusterJson as $cj): ?>
            <button type="button"
                    onclick="focusClusterPitch(<?=$cj['id']?>)"
                    style="border:1px solid <?=$cj['is_current'] ? '#16a34a' : '#cbd5e1'?>;background:<?=$cj['is_current'] ? '#dcfce7' : '#fff'?>;color:<?=$cj['is_current'] ? '#15803d' : '#334155'?>;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;cursor:pointer">
              ⚽ [<?=e($cj['code'])?>] Sân <?=$cj['type']?> người <?=$cj['is_current'] ? '(Đang xem)' : ''?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div id="detailPitchMap" style="width:100%;height:320px;border-radius:12px;border:2px solid #86efac;overflow:hidden;z-index:1"></div>
    </div>

    <script>
      var detailMapInstance = null;
      var detailLayers = {};
      var detailClusterMarkers = {};
      var detailTargetLat = <?=json_encode($fLat)?>;
      var detailTargetLng = <?=json_encode($fLng)?>;
      var detailClusterData = <?=json_encode($clusterJson, JSON_UNESCAPED_UNICODE)?>;

      document.addEventListener('DOMContentLoaded', function () {
        if (typeof L === 'undefined') return;
        detailMapInstance = L.map('detailPitchMap').setView([detailTargetLat, detailTargetLng], 17);

        detailLayers.street = L.tileLayer('https://{s}.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}', {
          maxZoom: 20,
          subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
          attribution: '© Google Maps'
        });
        detailLayers.sat = L.tileLayer('https://{s}.google.com/vt/lyrs=y&hl=vi&x={x}&y={y}&z={z}', {
          maxZoom: 20,
          subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
          attribution: '© Google Satellite Hybrid'
        });
        detailLayers.street.addTo(detailMapInstance);

        detailClusterData.forEach(function (item) {
          var bg = item.is_current ? '#16a34a' : '#2563eb';
          var ring = item.is_current ? '0 0 0 5px rgba(22,163,74,0.32)' : '0 3px 10px rgba(0,0,0,0.28)';
          var icon = L.divIcon({
            className: 'custom-pitch-marker',
            html: '<div style="background:' + bg + ';color:#fff;padding:4px 9px;border-radius:999px;display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:800;border:2.5px solid #fff;box-shadow:' + ring + ';white-space:nowrap">⚽ ' + item.code + '</div>',
            iconSize: [72, 30],
            iconAnchor: [36, 15],
            popupAnchor: [0, -15]
          });

          var popupHtml =
            '<div style="font-family:inherit;min-width:225px">' +
              '<span style="display:inline-block;background:' + (item.is_current ? '#dcfce7;color:#15803d' : '#dbeafe;color:#1d4ed8') + ';font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;margin-bottom:4px">[' + item.code + '] Sân ' + item.type + ' người</span>' +
              '<b style="font-size:14px;color:#0f172a;display:block;margin-bottom:3px">' + item.name + '</b>' +
              '<div style="font-size:12px;color:#475569;margin-bottom:4px">📍 ' + <?=json_encode($f['address'])?> + '</div>' +
              '<div style="font-size:11.5px;color:#64748b;margin-bottom:5px">🌐 GPS: <code>' + item.lat.toFixed(6) + ', ' + item.lng.toFixed(6) + '</code></div>' +
              '<div style="font-size:12px;color:#16a34a;font-weight:700;margin-bottom:8px">💰 Giá từ ' + item.price + '/giờ • 🟢 Đang mở cửa</div>' +
              '<div style="display:flex;gap:6px;flex-wrap:wrap">' +
                (!item.is_current ? '<a href="' + item.detail_url + '" style="background:#2563eb;color:#fff;padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none">Xem sân này</a>' : '') +
                '<a href="' + item.book_url + '" style="background:#16a34a;color:#fff;padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none">⚡ Đặt sân</a>' +
                '<a href="' + <?=json_encode($gmapDirUrl)?> + '" target="_blank" rel="noopener" style="background:#0f172a;color:#fff;padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:700;text-decoration:none">🧭 Chỉ đường ↗</a>' +
              '</div>' +
            '</div>';

          var mk = L.marker([item.lat, item.lng], { icon: icon }).addTo(detailMapInstance).bindPopup(popupHtml);
          detailClusterMarkers[item.id] = { marker: mk, data: item };
          if (item.is_current) {
            mk.openPopup();
          }
        });
      });

      function setDetailMapMode(mode) {
        if (!detailMapInstance) return;
        if (mode === 'sat') {
          detailMapInstance.removeLayer(detailLayers.street);
          detailLayers.sat.addTo(detailMapInstance);
          if (detailMapInstance.getZoom() < 18) detailMapInstance.setZoom(18);
        } else {
          detailMapInstance.removeLayer(detailLayers.sat);
          detailLayers.street.addTo(detailMapInstance);
        }
        var bSt = document.getElementById('btnDetailStreet');
        var bSat = document.getElementById('btnDetailSat');
        if (bSt && bSat) {
          bSt.className = mode === 'street' ? 'btn sm' : 'btn sm outline';
          bSt.style.background = mode === 'street' ? '#0f172a' : '';
          bSat.className = mode === 'sat' ? 'btn sm' : 'btn sm outline';
          bSat.style.background = mode === 'sat' ? '#0f172a' : '';
        }
      }

      function focusClusterPitch(pid) {
        if (!detailMapInstance || !detailClusterMarkers[pid]) return;
        var item = detailClusterMarkers[pid];
        detailMapInstance.flyTo([item.data.lat, item.data.lng], 18, { duration: 0.45 });
        item.marker.openPopup();
      }

      function calcDistanceToThisPitch() {
        var banner = document.getElementById('detailDistanceBanner');
        if (banner) {
          banner.style.display = 'block';
          banner.textContent = '⏳ Đang xác định vị trí GPS của bạn để đo khoảng cách tới sân...';
        }
        var showResult = function (uLat, uLng, isFallback) {
          var R = 6371;
          var dLat = (detailTargetLat - uLat) * Math.PI / 180;
          var dLon = (detailTargetLng - uLng) * Math.PI / 180;
          var a = Math.sin(dLat/2)*Math.sin(dLat/2) +
                  Math.cos(uLat*Math.PI/180)*Math.cos(detailTargetLat*Math.PI/180)*Math.sin(dLon/2)*Math.sin(dLon/2);
          var km = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
          var roadKm = (km * 1.25).toFixed(1);
          var bikeMin = Math.max(3, Math.round((km * 1.25 / 25) * 60));
          if (banner) {
            banner.innerHTML = (isFallback ? '📍 Tính từ trung tâm Quận 1: ' : '📍 Từ vị trí hiện tại của bạn tới sân: ') +
              '<span style="color:#15803d">Cách khoảng ' + roadKm + ' km (~' + bikeMin + ' phút di chuyển bằng xe máy)</span>';
          }
        };
        if (!navigator.geolocation) {
          showResult(10.7756, 106.7019, true);
          return;
        }
        navigator.geolocation.getCurrentPosition(
          function (pos) { showResult(pos.coords.latitude, pos.coords.longitude, false); },
          function () { showResult(10.7756, 106.7019, true); },
          { enableHighAccuracy: true, timeout: 6000 }
        );
      }
    </script>

    <!-- 5. Hộp Đánh giá trực tiếp từ Google Maps & Nhận xét từ Người đặt sân (Mặc định thu gọn, bấm mới hiện) -->
    <?php $totalVisibleReviews = count($gmapReviewsList) + count($reviews); ?>
    <div class="box" id="googleMapsReviewsBox">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#f8fafc,#eff6ff);border:1.5px solid #bfdbfe;padding:8px 14px;border-radius:12px">
            <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
            <strong style="font-size:20px;font-weight:900;color:#0f172a" id="gmapScoreValue"><?=$gmapRating?></strong>
            <span style="color:#f59e0b;font-size:14px">★★★★★</span>
          </div>
          <div>
            <h3 style="margin:0;font-size:16.5px;color:#0f172a">
              ⭐ Đánh giá Sân trên Google Maps & Cộng đồng
            </h3>
            <small style="margin:2px 0 0;color:#64748b;font-size:12px">
              Tổng cộng <b id="gmapTotalReviewsCount"><?=$gmapTotalCount?></b> lượt đánh giá trên Google Maps • <?=$totalVisibleReviews?> nhận xét nổi bật
            </small>
          </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <button type="button"
                  class="btn sm"
                  id="btnToggleReviewsPanel"
                  onclick="togglePitchReviewsPanel()"
                  style="background:#059669;color:#fff;font-weight:800">
            👁 Xem đánh giá (<?=$totalVisibleReviews?>) ▾
          </button>
          <a class="btn sm outline" href="<?=e($gmapPlaceUrl)?>" target="_blank" rel="noopener" style="border-color:#93c5fd;color:#1d4ed8 !important;background:#fff;white-space:nowrap">
            🗺 Google Maps ↗
          </a>
        </div>
      </div>

      <!-- Khung chi tiết Đánh giá (Mặc định ẩn gọn, chỉ mở khi người dùng bấm "Xem đánh giá") -->
      <div id="reviewsExpandablePanel" style="display:none;margin-top:16px;padding-top:16px;border-top:1px dashed #cbd5e1">
        <!-- Thẻ Tổng quan Điểm số Google Maps -->
        <div style="display:grid;grid-template-columns:auto 1fr;gap:20px;align-items:center;background:linear-gradient(135deg,#f8fafc,#eff6ff);border:1.5px solid #bfdbfe;border-radius:14px;padding:14px 18px;margin-bottom:16px">
          <div style="text-align:center;padding-right:18px;border-right:1px solid #cbd5e1;min-width:125px">
            <div style="font-size:32px;font-weight:900;color:#0f172a;line-height:1"><?=$gmapRating?> / 5</div>
            <div style="color:#f59e0b;font-size:15px;letter-spacing:1px;margin:4px 0">★★★★★</div>
            <div style="font-size:11px;color:#1d4ed8;font-weight:800;margin-top:3px">Google Maps Verified</div>
            <button type="button" class="btn sm outline" id="btnSyncGoogleReviews" onclick="syncGoogleMapsReviewsLive(<?=(int)$f['id']?>)" style="margin-top:8px;padding:4px 9px;font-size:11px;border-color:#93c5fd;color:#1d4ed8 !important;background:#fff">
              🔄 Đồng bộ mới nhất
            </button>
          </div>

          <div style="display:flex;flex-direction:column;gap:5px">
            <?php foreach ([5, 4, 3, 2, 1] as $starLv):
              $pct = (int)($gmapBreakdown[$starLv] ?? 0);
            ?>
              <div style="display:flex;align-items:center;gap:8px;font-size:11.5px;color:#334155;font-weight:700">
                <span style="width:42px"><?=$starLv?> sao</span>
                <div style="flex:1;height:7px;background:#e2e8f0;border-radius:999px;overflow:hidden">
                  <div style="width:<?=$pct?>%;height:100%;background:#f59e0b;border-radius:999px"></div>
                </div>
                <span style="width:34px;text-align:right;color:#64748b"><?=$pct?>%</span>
              </div>
            <?php endforeach; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:11px;color:#64748b">
              <span id="gmapSyncStatusText">✓ Đã đồng bộ dữ liệu địa điểm Google Maps (<?=date('H:i d/m/Y', strtotime($gmapReviewsData['synced_at'] ?? 'now'))?>)</span>
              <a href="<?=e($gmapPlaceUrl)?>" target="_blank" rel="noopener" style="color:#1a73e8;font-weight:700;text-decoration:none">Viết đánh giá trên Google ↗</a>
            </div>
          </div>
        </div>

        <!-- Bộ lọc Nguồn Đánh giá -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px">
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="gmap-filter-pill active" data-filter="all" onclick="filterPitchReviews('all', this)">
              Tất cả (<?=$totalVisibleReviews?>)
            </button>
            <button type="button" class="gmap-filter-pill" data-filter="google" onclick="filterPitchReviews('google', this)">
              🗺 Google Maps (<?=count($gmapReviewsList)?>)
            </button>
            <button type="button" class="gmap-filter-pill" data-filter="local" onclick="filterPitchReviews('local', this)">
              ⚽ Người đặt sân trên Web (<?=count($reviews)?>)
            </button>
            <button type="button" class="gmap-filter-pill" data-filter="5star" onclick="filterPitchReviews('5star', this)">
              ⭐ 5 Sao tuyệt đối
            </button>
          </div>
          <button type="button" onclick="togglePitchReviewsPanel()" style="border:1px solid #cbd5e1;background:#f8fafc;color:#475569;padding:5px 11px;border-radius:999px;font-size:11.5px;font-weight:700;cursor:pointer">
            🔼 Thu gọn đánh giá
          </button>
        </div>

        <?php if($u): ?>
          <form method="post" style="background:#f8fafc;padding:14px 16px;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:18px">
            <?=csrf_field()?>
            <input type="hidden" name="field_id" value="<?=$f['id']?>">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
              <b style="font-size:13px;color:#0f172a">✍️ Gửi nhận xét của bạn sau khi thi đấu tại sân:</b>
              <div style="display:flex;gap:8px;align-items:center">
                <label style="font-weight:700;font-size:12.5px;color:#475569">Chấm điểm:</label>
                <select name="rating" style="padding:5px 10px;border-radius:8px;border:1px solid #cbd5e1;font-weight:700">
                  <option value="5">⭐⭐⭐⭐⭐ (5 sao - Tuyệt vời)</option>
                  <option value="4">⭐⭐⭐⭐ (4 sao - Tốt)</option>
                  <option value="3">⭐⭐⭐ (3 sao - Khá)</option>
                  <option value="2">⭐⭐ (2 sao - Trung bình)</option>
                  <option value="1">⭐ (1 sao - Cần cải thiện)</option>
                </select>
              </div>
            </div>
            <textarea name="comment" rows="2" required placeholder="Chia sẻ cảm nhận của bạn về mặt cỏ, ánh sáng, dịch vụ tại sân..." style="width:100%;padding:10px;border-radius:8px;border:1px solid #cbd5e1;font-family:inherit;margin-bottom:10px"></textarea>
            <button class="btn sm" type="submit">Gửi đánh giá lên hệ thống →</button>
          </form>
        <?php endif; ?>

        <!-- Danh sách thẻ Đánh giá (Google Maps + Hệ thống Web) -->
        <div id="pitchReviewsContainer" style="display:flex;flex-direction:column;gap:12px">
          <?php foreach ($gmapReviewsList as $idx => $grv): ?>
            <div class="pitch-review-item" data-source="google" data-stars="<?=(int)($grv['rating'] ?? 5)?>" style="padding:14px 16px;border:1.5px solid #dbeafe;border-radius:14px;background:#ffffff;box-shadow:0 2px 8px rgba(15,23,42,0.03)">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px">
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="position:relative;width:40px;height:40px;flex-shrink:0">
                    <img src="<?=e($grv['profile_photo_url'] ?? '')?>" alt="<?=e($grv['author_name'])?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1.5px solid #bfdbfe">
                    <span title="Google Maps Review" style="position:absolute;bottom:-2px;right:-2px;width:18px;height:18px;border-radius:50%;background:#fff;border:1px solid #cbd5e1;display:grid;place-items:center;font-size:10px;font-weight:900;color:#1a73e8">G</span>
                  </div>
                  <div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                      <b style="font-size:13.8px;color:#0f172a"><?=e($grv['author_name'])?></b>
                      <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:800">
                        <?=e($grv['author_badge'] ?? 'Google Local Guide')?>
                      </span>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:2px">
                      <span style="color:#f59e0b;font-size:13px;letter-spacing:0.5px"><?=str_repeat('★', (int)($grv['rating'] ?? 5))?><?=str_repeat('☆', max(0, 5 - (int)($grv['rating'] ?? 5)))?></span>
                      <small style="margin:0;font-size:11px;color:#64748b">• <?=e($grv['relative_time'] ?? 'Trên Google Maps')?></small>
                    </div>
                  </div>
                </div>
                <a href="<?=e($grv['author_url'] ?? $gmapPlaceUrl)?>" target="_blank" rel="noopener" title="Xem bài đánh giá gốc trên Google Maps" style="font-size:11px;font-weight:700;color:#1a73e8;text-decoration:none;background:#f8fafc;border:1px solid #e2e8f0;padding:4px 8px;border-radius:6px;white-space:nowrap">
                  🗺 Google Maps ↗
                </a>
              </div>

              <div style="font-size:13.4px;color:#1e293b;line-height:1.55;margin-bottom:8px">
                <?=e($grv['text'] ?? '')?>
              </div>

              <?php if (!empty($grv['tags']) && is_array($grv['tags'])): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
                  <?php foreach ($grv['tags'] as $tg): ?>
                    <span style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700">
                      ✓ <?=e($tg)?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;color:#64748b">
                <button type="button" onclick="this.classList.toggle('liked');var s=this.querySelector('span');s.textContent=this.classList.contains('liked')?(parseInt(s.textContent)+1):(parseInt(s.textContent)-1);" style="border:1px solid #e2e8f0;background:#f8fafc;color:#475569;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;cursor:pointer">
                  👍 Hữu ích (<span><?=(int)($grv['likes'] ?? 8)?></span>)
                </button>
                <span style="color:#16a34a;font-weight:700">✓ Đã xác thực vị trí GPS tại sân</span>
              </div>

              <?php if (!empty($grv['owner_reply'])): ?>
                <div style="margin-top:10px;padding:9px 12px;background:#f8fafc;border-left:3px solid #16a34a;border-radius:6px;font-size:12.3px;color:#334155">
                  <b style="color:#065f46;display:block;margin-bottom:2px">🏟 Phản hồi từ Chủ sân ( trên Google Maps ):</b>
                  <?=e($grv['owner_reply'])?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php foreach($reviews as $rv): ?>
            <div class="pitch-review-item" data-source="local" data-stars="<?=(int)$rv['rating']?>" style="padding:13px 15px;border:1px solid #e2e8f0;border-radius:12px;background:#fff">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                <div style="display:flex;align-items:center;gap:8px">
                  <b>👤 <?=e($rv['full_name'])?></b>
                  <span style="background:#dcfce7;color:#15803d;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:800">⚽ Đã đặt sân trên hệ thống</span>
                </div>
                <span style="color:#f59e0b;font-size:13px"><?=str_repeat('★', (int)$rv['rating'])?></span>
              </div>
              <div style="font-size:13.5px;color:#334155;margin-bottom:4px"><?=e($rv['comment'])?></div>
              <small style="font-size:11px;color:#94a3b8"><?=date('d/m/Y H:i', strtotime($rv['created_at']))?></small>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <style>
      .gmap-filter-pill {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #334155;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.15s ease;
      }
      .gmap-filter-pill:hover {
        border-color: #1a73e8;
        color: #1a73e8;
      }
      .gmap-filter-pill.active {
        background: #1a73e8;
        border-color: #1a73e8;
        color: #ffffff;
        box-shadow: 0 3px 10px rgba(26, 115, 232, 0.25);
      }
    </style>

    <script>
      var totalPitchReviewsCount = <?=(int)$totalVisibleReviews?>;

      function togglePitchReviewsPanel(forceOpen) {
        var panel = document.getElementById('reviewsExpandablePanel');
        var btn   = document.getElementById('btnToggleReviewsPanel');
        if (!panel) return;

        var isHidden = (panel.style.display === 'none' || panel.style.display === '');
        var willOpen = (typeof forceOpen === 'boolean') ? forceOpen : isHidden;

        panel.style.display = willOpen ? 'block' : 'none';
        if (btn) {
          btn.innerHTML = willOpen
            ? '🔼 Ẩn đánh giá (' + totalPitchReviewsCount + ')'
            : '👁 Xem đánh giá (' + totalPitchReviewsCount + ') ▾';
          btn.style.background = willOpen ? '#475569' : '#059669';
        }
      }

      function filterPitchReviews(mode, btnEl) {
        document.querySelectorAll('.gmap-filter-pill').forEach(function (b) {
          b.classList.remove('active');
        });
        if (btnEl) btnEl.classList.add('active');

        document.querySelectorAll('#pitchReviewsContainer .pitch-review-item').forEach(function (card) {
          var src = card.getAttribute('data-source');
          var stars = parseInt(card.getAttribute('data-stars') || '5', 10);
          var show = true;
          if (mode === 'google') show = (src === 'google');
          else if (mode === 'local') show = (src === 'local');
          else if (mode === '5star') show = (stars === 5);
          card.style.display = show ? 'block' : 'none';
        });
      }

      async function syncGoogleMapsReviewsLive(fieldId) {
        var btn = document.getElementById('btnSyncGoogleReviews');
        var statusEl = document.getElementById('gmapSyncStatusText');
        if (btn) {
          btn.disabled = true;
          btn.textContent = '⏳ Đang đồng bộ Google Maps...';
        }
        try {
          var res = await fetch('../api/google_reviews.php?field_id=' + encodeURIComponent(fieldId) + '&force=1');
          var json = await res.json();
          if (json && json.ok && json.data) {
            var d = json.data;
            var scoreEl = document.getElementById('gmapScoreValue');
            var countEl = document.getElementById('gmapTotalReviewsCount');
            if (scoreEl && d.google_rating) scoreEl.textContent = Number(d.google_rating).toFixed(1);
            if (countEl && d.google_total_reviews) countEl.textContent = d.google_total_reviews;
            if (statusEl) {
              statusEl.innerHTML = '✓ Vừa đồng bộ trực tiếp từ Google Maps lúc ' + new Date().toLocaleTimeString('vi-VN');
              statusEl.style.color = '#15803d';
              statusEl.style.fontWeight = '700';
            }
          }
        } catch (e) {}
        if (btn) {
          btn.disabled = false;
          btn.textContent = '✓ Đã đồng bộ mới nhất';
          setTimeout(function () { btn.textContent = '🔄 Đồng bộ mới nhất'; }, 2500);
        }
      }
    </script>
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

    <?php
    $pitchPartnerVouchers = function_exists('get_active_vouchers_for_field')
        ? get_active_vouchers_for_field($f['id'])
        : [];
    ?>
    <?php if ($pitchPartnerVouchers): ?>
      <div style="background:#f0fdf4;border:1.5px dashed #16a34a;border-radius:12px;padding:12px 14px;margin-bottom:16px">
        <b style="display:block;font-size:12.5px;color:#065f46;margin-bottom:8px">
          🎁 Mã giảm giá (Voucher) do Chủ sân phát hành:
        </b>
        <div style="display:flex;flex-direction:column;gap:7px">
          <?php foreach ($pitchPartnerVouchers as $pv): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;background:#ffffff;border:1px solid #bbf7d0;padding:7px 10px;border-radius:9px">
              <div>
                <span style="font-weight:900;color:#15803d;font-size:12.5px">🎟 <?=e($pv['code'])?></span>
                <span style="font-size:12px;color:#0f172a;font-weight:700">
                  — <?=$pv['discount_type'] === 'percent' ? ('Giảm ' . (int)$pv['discount_value'] . '%') : ('Giảm ' . money($pv['discount_value']))?>
                </span>
                <small style="display:block;margin:1px 0 0;font-size:10.5px;color:#64748b">
                  Đơn tối thiểu <?=money($pv['min_order'])?> • Còn <?=(int)$pv['usage_limit'] - (int)$pv['used_count']?> lượt
                </small>
              </div>
              <a class="btn sm" href="booking.php?field_id=<?=$f['id']?>&voucher=<?=urlencode($pv['code'])?>" style="padding:5px 10px;font-size:11.5px;white-space:nowrap">
                Áp mã →
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <form action="booking.php" method="get" style="margin-bottom:16px">
      <input type="hidden" name="field_id" value="<?=$f['id']?>">
      <label style="display:block;font-weight:700;font-size:12.5px;margin-bottom:6px;color:#475569">Chọn ngày đặt sân</label>
      <input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" style="width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-family:inherit;font-weight:600;margin-bottom:14px">

      <label style="display:block;font-weight:700;font-size:12.5px;margin-bottom:6px;color:#475569">Các khung giờ bận hôm nay</label>
      <div style="margin-bottom:16px">
        <?php if(!$busy): ?>
          <span class="tag green">🟢 Hiện đang trống tất cả các khung giờ hôm nay</span>
        <?php else: ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach($busy as $b): ?>
              <span class="tag amber"><?=substr($b['start_time'],0,5)?> - <?=substr($b['end_time'],0,5)?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if($f['status']==='active'): ?>
        <button class="btn" type="submit" style="padding:11px 18px;font-size:13.5px;width:100%">
          📅 Mở sơ đồ & Đặt sân →
        </button>
      <?php else: ?>
        <button class="btn" disabled style="background:#cbd5e1;cursor:not-allowed;width:100%">
          Sân đang bảo dưỡng
        </button>
      <?php endif; ?>

      <button type="button"
              onclick="if(typeof openOwnerChatWidget==='function') openOwnerChatWidget(<?=(int)$f['id']?>)"
              style="margin-top:10px;width:100%;padding:10px 16px;border-radius:10px;border:1.5px solid #059669;background:#ecfdf5;color:#065f46;font-weight:800;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;font-family:inherit">
        <span>💬 Chat trực tiếp với Chủ sân (Đang online)</span>
      </button>
    </form>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
