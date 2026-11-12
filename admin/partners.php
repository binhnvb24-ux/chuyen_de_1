<?php
/**
 * ============================================================================
 * FILE: admin/partners.php
 * MÔ TẢ: Module 2 - Xét duyệt Hồ sơ Đăng ký Đối tác Mở sân (Partner Approval).
 * CHỨC NĂNG:
 *   - Hiển thị danh sách hồ sơ theo trạng thái (PENDING / APPROVED / REJECTED).
 *   - Xem chi tiết hồ sơ (Ảnh sân, CCCD 2 mặt, Giấy phép KD, Ngân hàng đối soát
 *     và Bản đồ nhỏ Leaflet.js + Google Maps Tiles ghim đúng tọa độ sân).
 *   - DUYỆT HỒ SƠ (APPROVE) bằng Database Transaction (`approve_partner_application()`):
 *       1. Cập nhật `partner_applications.status = 'APPROVED'`
 *       2. Nâng cấp user lên `PITCH_OWNER` (`partner_status = 'APPROVED'`)
 *       3. Tự động tạo Cụm sân mới (+ sân con & khóa chéo Cha-Con) gắn `owner_id = user_id`
 *   - TỪ CHỐI HỒ SƠ (REJECT): Bắt buộc nhập `rejection_reason` để phản hồi cho đối tác.
 * ============================================================================
 */

require_once '../includes/auth.php';
$adm = require_admin();

// ----------------------------------------------------------------------------
// 1. XỬ LÝ DUYỆT (APPROVE) HOẶC TỪ CHỐI (REJECT) HỒ SƠ ĐỐI TÁC (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $appId  = (int)($_POST['app_id'] ?? 0);

    if ($action === 'approve') {
        $res = approve_partner_application($appId, (int)$adm['id']);
        flash($res['ok'] ? 'ok' : 'err', $res['message']);
        header('Location: partners.php?view=' . $appId);
        exit;
    }

    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($appId <= 0 || $reason === '') {
            flash('err', 'Vui lòng nhập rõ lý do từ chối hồ sơ để đối tác nắm thông tin chỉnh sửa.');
            header('Location: partners.php?view=' . $appId);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM partner_applications WHERE id = ?");
        $stmt->execute([$appId]);
        $app = $stmt->fetch();

        if (!$app || $app['status'] !== 'PENDING') {
            flash('err', 'Hồ sơ không tồn tại hoặc đã được xử lý trước đó.');
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    UPDATE partner_applications
                    SET status = 'REJECTED',
                        rejection_reason = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW()
                    WHERE id = ?
                ")->execute([$reason, (int)$adm['id'], $appId]);

                $pdo->prepare("UPDATE users SET partner_status = 'REJECTED' WHERE id = ?")
                    ->execute([(int)$app['user_id']]);

                notify(
                    (int)$app['user_id'],
                    "⚠️ Hồ sơ đăng ký mở sân '{$app['venue_name']}' chưa được duyệt. Lý do: {$reason}. Bạn có thể bổ sung thông tin và gửi lại."
                );

                $pdo->commit();
                flash('ok', "Đã từ chối hồ sơ #{$appId} ({$app['venue_name']}) và gửi thông báo lý do cho người nộp.");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('err', 'Lỗi xử lý: ' . $e->getMessage());
            }
        }
        header('Location: partners.php?view=' . $appId);
        exit;
    }
}

// ----------------------------------------------------------------------------
// 2. TRUY VẤN DANH SÁCH HỒ SƠ ĐỐI TÁC & THỐNG KÊ KPI
// ----------------------------------------------------------------------------
$statusFilter = strtoupper(trim($_GET['status'] ?? 'ALL'));
$q            = trim($_GET['q'] ?? '');

$sql = "
    SELECT pa.*,
           u.username, u.full_name, u.email, u.phone AS user_phone,
           u.account_role, u.partner_status,
           f.name AS created_field_name
    FROM partner_applications pa
    JOIN users u ON u.id = pa.user_id
    LEFT JOIN fields f ON f.id = pa.approved_field_id
    WHERE 1=1
";
$params = [];

if (in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
    $sql .= " AND pa.status = ?";
    $params[] = $statusFilter;
}
if ($q !== '') {
    $sql .= " AND (pa.venue_name LIKE ? OR pa.venue_address LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= " ORDER BY FIELD(pa.status, 'PENDING', 'APPROVED', 'REJECTED'), pa.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apps = $stmt->fetchAll();

// Đếm số lượng theo từng trạng thái
$counts = ['ALL' => 0, 'PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
$stCnt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM partner_applications GROUP BY status")->fetchAll();
foreach ($stCnt as $cRow) {
    $stKey = strtoupper($cRow['status']);
    if (isset($counts[$stKey])) {
        $counts[$stKey] = (int)$cRow['cnt'];
        $counts['ALL'] += (int)$cRow['cnt'];
    }
}

// Hồ sơ đang mở xem chi tiết (?view=ID)
$detailApp = null;
if (isset($_GET['view'])) {
    $vId = (int)$_GET['view'];
    $stD = $pdo->prepare("
        SELECT pa.*,
               u.username, u.full_name, u.email, u.phone AS user_phone, u.reputation_score,
               rv.full_name AS reviewer_name,
               f.name AS created_field_name
        FROM partner_applications pa
        JOIN users u ON u.id = pa.user_id
        LEFT JOIN users rv ON rv.id = pa.reviewed_by
        LEFT JOIN fields f ON f.id = pa.approved_field_id
        WHERE pa.id = ?
    ");
    $stD->execute([$vId]);
    $detailApp = $stD->fetch();
}

$page  = 'partners';
$title = 'Xét duyệt Đối tác Mở sân (Partner Approval)';
include '../includes/admin_header.php';
?>

<!-- Nhúng Leaflet.js để hiển thị bản đồ vị trí cụm sân trong Modal Xem Chi Tiết -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- 3. HÀNG THẺ KPI TỔNG QUAN HỒ SƠ ĐỐI TÁC -->
<div class="stats" style="margin-bottom:18px">
  <div class="stat">
    <small>Tổng hồ sơ đăng ký</small>
    <b><?=$counts['ALL']?></b>
  </div>
  <div class="stat">
    <small>⏳ Chờ xét duyệt (PENDING)</small>
    <b style="color:#f59e0b"><?=$counts['PENDING']?></b>
  </div>
  <div class="stat">
    <small>✅ Đã duyệt mở sân (APPROVED)</small>
    <b style="color:#10b981"><?=$counts['APPROVED']?></b>
  </div>
  <div class="stat">
    <small>❌ Đã từ chối (REJECTED)</small>
    <b style="color:#ef4444"><?=$counts['REJECTED']?></b>
  </div>
</div>

<!-- 4. THANH LỌC TRẠNG THÁI & TÌM KIẾM HỒ SƠ -->
<div class="box" style="margin-bottom:18px;padding:16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php
      $tabs = [
          'ALL'      => ['Tất cả hồ sơ', $counts['ALL'], 'blue'],
          'PENDING'  => ['⏳ Chờ duyệt', $counts['PENDING'], 'amber'],
          'APPROVED' => ['✅ Đã duyệt', $counts['APPROVED'], 'green'],
          'REJECTED' => ['❌ Đã từ chối', $counts['REJECTED'], 'red']
      ];
      foreach ($tabs as $tk => [$tLabel, $tCount, $tColor]):
          $activeTab = ($statusFilter === $tk);
      ?>
        <a
          href="?status=<?=$tk?>&q=<?=urlencode($q)?>"
          class="btn sm <?=$activeTab ? '' : 'gray'?>"
          style="border-radius:999px"
        >
          <?=$tLabel?> (<?=$tCount?>)
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" style="display:flex;gap:8px;flex:1;max-width:420px">
      <input type="hidden" name="status" value="<?=e($statusFilter)?>">
      <input name="q" value="<?=e($q)?>" placeholder="Tìm tên sân, địa chỉ, tên chủ sân, SĐT..." style="margin:0;flex:1">
      <button class="btn sm" type="submit">🔍 Tìm</button>
    </form>
  </div>
</div>

<!-- 5. HỘP XEM CHI TIẾT HỒ SƠ ĐỐI TÁC + BẢN ĐỒ LEAFLET + NÚT DUYỆT TRANSACTION / TỪ CHỐI -->
<?php if ($detailApp):
    $venueImgs = json_decode($detailApp['venue_images'] ?? '[]', true);
    if (!is_array($venueImgs) || !$venueImgs) {
        $venueImgs = ['https://images.unsplash.com/photo-1529900748604-07564a03e7a6?w=900&auto=format&fit=crop&q=80'];
    }
    $dLat = (float)$detailApp['latitude'];
    $dLng = (float)$detailApp['longitude'];
?>
  <div class="box" style="margin-bottom:22px;border:2px solid #10b981">
    <div class="bh">
      <div>
        <span class="tag <?=$detailApp['status'] === 'APPROVED' ? 'green' : ($detailApp['status'] === 'REJECTED' ? 'red' : 'amber')?>">
          <?=e($detailApp['status'])?>
        </span>
        <b style="font-size:17px;margin-left:8px">
          HỒ SƠ ĐỐI TÁC #<?=$detailApp['id']?>: <?=e($detailApp['venue_name'])?>
        </b>
      </div>
      <a href="partners.php?status=<?=urlencode($statusFilter)?>" class="btn sm red">✕ Đóng chi tiết</a>
    </div>

    <div style="display:grid;grid-template-columns:1.25fr 1fr;gap:20px;align-items:start">
      <!-- Cột trái: Thông tin Cụm sân, Pháp lý & Ngân hàng Đối soát -->
      <div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
          <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.28);border-radius:12px;padding:14px">
            <b style="display:block;margin-bottom:6px;color:#10b981">👤 Thông tin Người nộp hồ sơ</b>
            <div style="font-size:13.5px;line-height:1.6">
              <div>Họ tên: <b><?=e($detailApp['full_name'])?></b> (@<?=e($detailApp['username'])?>)</div>
              <div>SĐT Hotline: <b><?=e($detailApp['contact_phone'] ?: $detailApp['user_phone'])?></b></div>
              <div>Email: <b><?=e($detailApp['email'])?></b></div>
              <div>Điểm uy tín hiện tại: <b><?=(int)$detailApp['reputation_score']?>/100</b></div>
            </div>
          </div>

          <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.28);border-radius:12px;padding:14px">
            <b style="display:block;margin-bottom:6px;color:#3b82f6">🏦 Tài khoản Ngân hàng Đối soát</b>
            <div style="font-size:13.5px;line-height:1.6">
              <div>Ngân hàng: <b><?=e($detailApp['bank_name'])?></b></div>
              <div>Số tài khoản: <b style="color:#10b981;font-size:15px"><?=e($detailApp['bank_account_number'])?></b></div>
              <div>Chủ tài khoản: <b><?=e($detailApp['bank_account_holder'])?></b></div>
              <div>Mã số thuế / GPKD: <b><?=e($detailApp['tax_code'] ?: 'Cá nhân / Hộ KD')?></b></div>
            </div>
          </div>
        </div>

        <div style="background:rgba(148,163,184,0.08);border:1px solid var(--adm-border,#233452);border-radius:12px;padding:14px;margin-bottom:16px">
          <b style="display:block;margin-bottom:8px">🏟 Quy mô Cụm sân & Bảng giá đăng ký</b>
          <div style="font-size:13.5px;line-height:1.65">
            <div>• Tên cụm sân: <b><?=e($detailApp['venue_name'])?></b></div>
            <div>• Địa chỉ thực tế: <b><?=e($detailApp['venue_address'])?></b> (<?=e($detailApp['district'])?>, <?=e($detailApp['city'])?>)</div>
            <div>• Tọa độ GPS: <code><?=number_format($dLat, 6)?>, <?=number_format($dLng, 6)?></code>
              <a href="https://www.google.com/maps?q=<?=$dLat?>,<?=$dLng?>" target="_blank" style="margin-left:8px;color:#10b981;font-weight:700">↗ Mở Google Maps</a>
            </div>
            <div>• Cơ cấu mặt sân: <b><?=e($detailApp['pitch_summary'])?></b> (Sân 5: <b><?=(int)$detailApp['cnt_pitch_5']?></b> • Sân 7: <b><?=(int)$detailApp['cnt_pitch_7']?></b> • Sân 11: <b><?=(int)$detailApp['cnt_pitch_11']?></b>)</div>
            <div>• Giá thuê dự kiến: Giờ thường <b><?=money($detailApp['price_normal'])?>/h</b> — Giờ vàng <b><?=money($detailApp['price_peak'])?>/h</b></div>
          </div>
        </div>

        <!-- Hình ảnh thực tế sân & Hồ sơ pháp lý (CCCD / Giấy phép KD) -->
        <b style="display:block;margin-bottom:8px">📸 Hình ảnh Thực tế Sân & Hồ sơ Pháp lý đính kèm</b>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:10px;margin-bottom:16px">
          <?php foreach ($venueImgs as $vImg): ?>
            <a href="<?=e(img_src($vImg))?>" target="_blank" style="display:block;border:1px solid var(--adm-border,#334155);border-radius:10px;overflow:hidden;text-decoration:none">
              <img src="<?=e(img_src($vImg))?>" alt="Ảnh sân" style="width:100%;height:96px;object-fit:cover;display:block">
              <small style="display:block;padding:4px 8px;font-size:11px;text-align:center">🏟 Ảnh thực tế sân</small>
            </a>
          <?php endforeach; ?>

          <?php if (!empty($detailApp['id_card_front_url'])): ?>
            <a href="<?=e(img_src($detailApp['id_card_front_url']))?>" target="_blank" style="display:block;border:1px solid var(--adm-border,#334155);border-radius:10px;overflow:hidden;text-decoration:none">
              <img src="<?=e(img_src($detailApp['id_card_front_url']))?>" alt="CCCD Mặt trước" style="width:100%;height:96px;object-fit:cover;display:block">
              <small style="display:block;padding:4px 8px;font-size:11px;text-align:center">🪪 CCCD Mặt trước</small>
            </a>
          <?php endif; ?>

          <?php if (!empty($detailApp['id_card_back_url'])): ?>
            <a href="<?=e(img_src($detailApp['id_card_back_url']))?>" target="_blank" style="display:block;border:1px solid var(--adm-border,#334155);border-radius:10px;overflow:hidden;text-decoration:none">
              <img src="<?=e(img_src($detailApp['id_card_back_url']))?>" alt="CCCD Mặt sau" style="width:100%;height:96px;object-fit:cover;display:block">
              <small style="display:block;padding:4px 8px;font-size:11px;text-align:center">🪪 CCCD Mặt sau</small>
            </a>
          <?php endif; ?>

          <?php if (!empty($detailApp['business_license_url'])): ?>
            <a href="<?=e(img_src($detailApp['business_license_url']))?>" target="_blank" style="display:block;border:1px solid var(--adm-border,#334155);border-radius:10px;overflow:hidden;text-decoration:none">
              <img src="<?=e(img_src($detailApp['business_license_url']))?>" alt="Giấy phép KD" style="width:100%;height:96px;object-fit:cover;display:block">
              <small style="display:block;padding:4px 8px;font-size:11px;text-align:center">📄 Giấy phép KD</small>
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Cột phải: Bản đồ nhỏ kiểm tra vị trí sân + Hành động Duyệt Transaction / Từ chối -->
      <div>
        <b style="display:block;margin-bottom:8px">🗺 Bản đồ Kiểm định Vị trí Cụm sân (Google Maps Hybrid)</b>
        <div id="adminPartnerMiniMap" style="width:100%;height:255px;border-radius:14px;border:2px solid #10b981;margin-bottom:16px;overflow:hidden"></div>

        <?php if ($detailApp['status'] === 'PENDING'): ?>
          <div style="background:rgba(16,185,129,0.1);border:1.5px solid #10b981;border-radius:14px;padding:16px;margin-bottom:14px">
            <b style="display:block;color:#10b981;margin-bottom:6px">✅ DUYỆT HỒ SƠ & TỰ ĐỘNG KHỞI TẠO CỤM SÂN (DB TRANSACTION)</b>
            <small style="display:block;margin-bottom:12px;line-height:1.5">
              Khi bấm Duyệt, hệ thống sẽ thực thi <b>Database Transaction</b> đồng bộ 3 bước:<br>
              1. Chuyển hồ sơ sang <code>APPROVED</code>.<br>
              2. Nâng cấp tài khoản <b><?=e($detailApp['full_name'])?></b> lên <code>PITCH_OWNER</code>.<br>
              3. Tự động tạo Cụm sân <b><?=e($detailApp['venue_name'])?></b> vào bảng <code>fields</code> gắn <code>owner_id = <?=$detailApp['user_id']?></code>.
            </small>
            <form method="post" onsubmit="return confirm('Xác nhận DUYỆT hồ sơ đối tác này, nâng cấp quyền PITCH_OWNER và tự động tạo Cụm sân trên hệ thống?')">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="app_id" value="<?=$detailApp['id']?>">
              <button type="submit" class="btn" style="width:100%;padding:12px;font-weight:800">
                ✅ XÁC NHẬN DUYỆT ĐỐI TÁC & MỞ CỤM SÂN NGAY
              </button>
            </form>
          </div>

          <div style="background:rgba(239,68,68,0.08);border:1.5px solid #ef4444;border-radius:14px;padding:16px">
            <b style="display:block;color:#ef4444;margin-bottom:8px">❌ TỪ CHỐI HỒ SƠ (Bắt buộc nhập lý do)</b>
            <form method="post" onsubmit="return confirm('Xác nhận từ chối hồ sơ đối tác này?')">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="app_id" value="<?=$detailApp['id']?>">
              <textarea
                name="rejection_reason"
                rows="2"
                required
                placeholder="Nhập lý do từ chối (VD: Ảnh CCCD bị mờ, tọa độ sân chưa khớp địa chỉ thực tế...)"
                style="width:100%;margin-bottom:10px"
              ></textarea>
              <button type="submit" class="btn red sm" style="width:100%;padding:10px">
                ❌ Từ chối hồ sơ & Gửi phản hồi
              </button>
            </form>
          </div>
        <?php elseif ($detailApp['status'] === 'APPROVED'): ?>
          <div style="background:rgba(16,185,129,0.12);border:1.5px solid #10b981;border-radius:14px;padding:16px">
            <b style="color:#10b981;display:block;margin-bottom:6px">🎉 HỒ SƠ ĐÃ ĐƯỢC PHÊ DUYỆT</b>
            <div style="font-size:13px;line-height:1.6">
              <div>• Thời gian duyệt: <b><?=e($detailApp['reviewed_at'])?></b></div>
              <div>• Sân đã khởi tạo: <b><?=e($detailApp['created_field_name'] ?: ('Field #' . $detailApp['approved_field_id']))?></b></div>
              <div style="margin-top:10px">
                <a href="fields.php?edit=<?=(int)$detailApp['approved_field_id']?>" class="btn sm">🏟 Xem / Chỉnh sửa Cụm sân</a>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div style="background:rgba(239,68,68,0.12);border:1.5px solid #ef4444;border-radius:14px;padding:16px">
            <b style="color:#ef4444;display:block;margin-bottom:6px">❌ HỒ SƠ ĐÃ BỊ TỪ CHỐI</b>
            <div style="font-size:13px;line-height:1.6">
              <div>• Lý do từ chối: <b><?=e($detailApp['rejection_reason'])?></b></div>
              <div>• Thời gian xử lý: <b><?=e($detailApp['reviewed_at'])?></b></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    (function() {
      var lat = <?=json_encode($dLat)?>;
      var lng = <?=json_encode($dLng)?>;
      var mapEl = document.getElementById('adminPartnerMiniMap');
      if (!mapEl || typeof L === 'undefined') return;
      var map = L.map(mapEl).setView([lat, lng], 16);
      L.tileLayer('https://mt1.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}', {
        maxZoom: 20,
        attribution: '&copy; Google Maps'
      }).addTo(map);
      L.marker([lat, lng]).addTo(map)
        .bindPopup(<?=json_encode('<b>' . e($detailApp['venue_name']) . '</b><br>' . e($detailApp['venue_address']))?>)
        .openPopup();
    })();
  </script>
<?php endif; ?>

<!-- 6. BẢNG DANH SÁCH TOÀN BỘ HỒ SƠ ĐĂNG KÝ CHỦ SÂN -->
<div class="box">
  <div class="bh">
    <b>📋 Danh sách Hồ sơ Đăng ký Mở sân (<?=count($apps)?> hồ sơ)</b>
  </div>

  <?php if (!$apps): ?>
    <div class="empty">Không có hồ sơ đối tác nào trong mục này.</div>
  <?php else: ?>
    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Mã HS</th>
            <th>Cụm sân đăng ký & Địa chỉ</th>
            <th>Chủ sân / Liên hệ</th>
            <th>Cơ cấu sân & Giá</th>
            <th>Ngân hàng đối soát</th>
            <th>Ngày gửi</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($apps as $a): ?>
            <tr>
              <td><b>#<?=$a['id']?></b></td>
              <td>
                <b><?=e($a['venue_name'])?></b>
                <small style="display:block">📍 <?=e($a['venue_address'])?> (<?=e($a['district'])?>)</small>
                <small style="color:#10b981">🌐 GPS: <?=number_format((float)$a['latitude'], 4)?>, <?=number_format((float)$a['longitude'], 4)?></small>
              </td>
              <td>
                <b><?=e($a['full_name'])?></b>
                <small style="display:block">📞 <?=e($a['contact_phone'] ?: $a['user_phone'])?></small>
                <small><?=e($a['email'])?></small>
              </td>
              <td>
                <b><?=e($a['pitch_summary'])?></b>
                <small style="display:block">Thường: <?=money($a['price_normal'])?>/h • Vàng: <?=money($a['price_peak'])?>/h</small>
              </td>
              <td>
                <b><?=e($a['bank_name'])?></b>
                <small style="display:block">STK: <?=e($a['bank_account_number'])?></small>
                <small><?=e($a['bank_account_holder'])?></small>
              </td>
              <td><?=date('d/m/Y H:i', strtotime($a['created_at']))?></td>
              <td>
                <?php if ($a['status'] === 'APPROVED'): ?>
                  <span class="tag green">✅ APPROVED</span>
                <?php elseif ($a['status'] === 'REJECTED'): ?>
                  <span class="tag red">❌ REJECTED</span>
                <?php else: ?>
                  <span class="tag amber">⏳ PENDING</span>
                <?php endif; ?>
              </td>
              <td>
                <a class="btn sm <?=$a['status'] === 'PENDING' ? '' : 'bl'?>" href="?status=<?=urlencode($statusFilter)?>&view=<?=$a['id']?>">
                  <?=$a['status'] === 'PENDING' ? '🔍 Kiểm duyệt ngay' : '👁 Xem hồ sơ'?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include '../includes/admin_footer.php'; ?>
