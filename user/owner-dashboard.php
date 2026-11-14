<?php
/**
 * ============================================================================
 * FILE: user/owner-dashboard.php
 * MÔ TẢ: Kênh Đối tác Chủ sân (Partner / Pitch Owner Dashboard) - 8 Phân hệ:
 *   1. overview : Tổng quan, 4 KPI, Biểu đồ doanh thu 7 ngày & Bảng đối soát Ngân hàng.
 *   2. venue    : Quản lý Thông tin Cụm sân (`venues`), Tiện ích, Giờ mở/đóng, Bản đồ Leaflet.
 *   3. pitches  : Quản lý Sơ đồ Sân con 5/7/11 (`fields`/`pitches`), Liên kết Cha-Con (`parent_id`), Bảo trì.
 *   4. pricing  : Cấu hình Bảng giá linh hoạt theo Khung giờ (`pricing_rules`/`pitch_pricing`: Ngày thường / Giờ vàng / Cuối tuần).
 *   5. vouchers : Phát hành & Quản lý Mã giảm giá (Voucher) riêng cho Cụm sân / Sân con của Đối tác (`vouchers.owner_id`).
 *   6. calendar : Lịch Đặt sân Trực quan (Timeline Grid) & POS (Check-in, Báo bùng kèo No-show trừ Uy tín, Đặt tại quầy Walk-in).
 *   7. revenue  : Thống kê Doanh thu chi tiết, Khấu trừ Phí vận hành sàn & Lịch sử đối soát.
 *   8. messages : Trung tâm Tin nhắn Chủ sân (Split-Pane 2 cột) & Gửi Thẻ Báo Giá Sân Nhanh (`pitch_card_json`).
 * ============================================================================
 */

require_once '../includes/auth.php';

// Middleware kiểm tra quyền PITCH_OWNER (hoặc ADMIN)
$u       = require_pitch_owner();
$ownerId = (int)$u['id'];
$isAdmin = (get_user_role_code($u) === 'ADMIN');
if (function_exists('ensure_partner_vouchers_schema')) {
    ensure_partner_vouchers_schema();
}

$tab = $_GET['tab'] ?? 'overview';
if ($tab === 'wallet') {
    $tab = 'revenue';
}
if (!in_array($tab, ['overview', 'venue', 'pitches', 'pricing', 'vouchers', 'calendar', 'revenue', 'messages'], true)) {
    $tab = 'overview';
}

// Tải danh sách Cụm sân (`venues`) của Chủ sân
$myVenues = get_owner_venues($u);

// Đảm bảo mọi sân gốc (parent_id IS NULL) thuộc sở hữu của Chủ sân đều có bản ghi Cụm sân (`venues`) và gán đúng `venue_id`
$stOrphanRoots = $pdo->prepare("SELECT * FROM fields WHERE owner_id = ? AND (venue_id IS NULL OR venue_id = 0) AND parent_id IS NULL ORDER BY id ASC");
$stOrphanRoots->execute([$ownerId]);
$orphanRoots = $stOrphanRoots->fetchAll();
if ($orphanRoots) {
    foreach ($orphanRoots as $firstF) {
        $pdo->prepare("
            INSERT INTO venues (owner_id, name, address, district, latitude, longitude, open_time, close_time, amenities, images, contact_phone, description, status)
            VALUES (?, ?, ?, ?, ?, ?, '05:30:00', '23:00:00', 'wifi,parking,canteen,shoes,light', ?, ?, ?, 'ACTIVE')
        ")->execute([
            $ownerId,
            $firstF['name'],
            $firstF['address'],
            $firstF['district'],
            $firstF['latitude'] ?? 10.7768890,
            $firstF['longitude'] ?? 106.7008060,
            json_encode([$firstF['image']], JSON_UNESCAPED_SLASHES),
            $firstF['contact_phone'] ?? $u['phone'],
            $firstF['description'] ?? ''
        ]);
        $newVid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE fields SET venue_id = ? WHERE id = ? OR parent_id = ?")->execute([$newVid, $firstF['id'], $firstF['id']]);
    }
    $myVenues = get_owner_venues($u);
}

$selectedVenueId = (int)($_GET['venue_id'] ?? $_POST['venue_id'] ?? ($myVenues[0]['id'] ?? 0));
$curVenue = null;
foreach ($myVenues as $vItem) {
    if ((int)$vItem['id'] === $selectedVenueId) {
        $curVenue = $vItem;
        break;
    }
}
if (!$curVenue && $myVenues) {
    $curVenue = $myVenues[0];
    $selectedVenueId = (int)$curVenue['id'];
}

// ----------------------------------------------------------------------------
// XỬ LÝ CÁC THAO TÁC QUẢN LÝ CỦA CHỦ SÂN (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // 1. CẬP NHẬT THÔNG TIN CỤM SÂN & VỊ TRÍ BẢN ĐỒ (Tab 2: Venue Settings)
    if ($action === 'save_venue_settings') {
        $vId = (int)($_POST['venue_id'] ?? 0);
        $vRow = owner_get_venue($u, $vId);
        if (!$vRow) {
            flash('err', 'Bạn không có quyền chỉnh sửa Cụm sân này.');
        } else {
            $vName     = trim($_POST['name'] ?? '');
            $vAddr     = trim($_POST['address'] ?? '');
            $vDist     = trim($_POST['district'] ?? '');
            $vPhone    = trim($_POST['contact_phone'] ?? '');
            $vOpen     = trim($_POST['open_time'] ?? '05:30');
            $vClose    = trim($_POST['close_time'] ?? '23:00');
            $vLat      = (float)($_POST['latitude'] ?? $vRow['latitude']);
            $vLng      = (float)($_POST['longitude'] ?? $vRow['longitude']);
            $vDesc     = trim($_POST['description'] ?? '');
            $vStatus   = in_array($_POST['status'] ?? '', ['ACTIVE', 'INACTIVE', 'DRAFT'], true) ? $_POST['status'] : 'ACTIVE';
            $amenities = isset($_POST['amenities']) && is_array($_POST['amenities'])
                ? implode(',', array_map('trim', $_POST['amenities']))
                : 'wifi,parking';

            $uploadedImg = save_uploaded_asset('venue_image_file', 'venue_' . $vId);
            $imgUrlInput = trim($_POST['venue_image_url'] ?? '');
            $curImgs     = json_decode((string)$vRow['images'], true) ?: [];
            if ($uploadedImg) {
                array_unshift($curImgs, $uploadedImg);
            } elseif ($imgUrlInput !== '') {
                $curImgs[0] = $imgUrlInput;
            }
            $imgsJson = json_encode(array_values(array_unique(array_filter($curImgs))), JSON_UNESCAPED_SLASHES);

            $pdo->prepare("
                UPDATE venues
                SET name=?, address=?, district=?, latitude=?, longitude=?,
                    open_time=?, close_time=?, amenities=?, images=?,
                    contact_phone=?, description=?, status=?
                WHERE id=?
            ")->execute([
                $vName ?: $vRow['name'],
                $vAddr ?: $vRow['address'],
                $vDist ?: $vRow['district'],
                $vLat,
                $vLng,
                strlen($vOpen) === 5 ? $vOpen . ':00' : $vOpen,
                strlen($vClose) === 5 ? $vClose . ':00' : $vClose,
                $amenities,
                $imgsJson,
                $vPhone,
                $vDesc,
                $vStatus,
                $vId
            ]);

            // Nếu có ảnh đại diện mới, đồng bộ ảnh cho sân gốc
            if (!empty($curImgs[0])) {
                $pdo->prepare("UPDATE fields SET image = ? WHERE venue_id = ? AND parent_id IS NULL")
                    ->execute([$curImgs[0], $vId]);
            }

            sync_venue_to_fields($vId);
            flash('ok', "✔ Đã lưu cài đặt Cụm sân '{$vName}' và đồng bộ vị trí GPS / trạng thái mở bán!");
        }
        header('Location: owner-dashboard.php?tab=venue&venue_id=' . $selectedVenueId);
        exit;
    }

    // 2. BẬT / TẮT NHANH TRẠNG THÁI MỞ BÁN CỤM SÂN (ACTIVE <-> INACTIVE)
    if ($action === 'toggle_venue_status') {
        $vId  = (int)($_POST['venue_id'] ?? 0);
        $vRow = owner_get_venue($u, $vId);
        if ($vRow) {
            $nextSt = ($vRow['status'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
            $pdo->prepare("UPDATE venues SET status = ? WHERE id = ?")->execute([$nextSt, $vId]);
            sync_venue_to_fields($vId);
            if ($nextSt === 'ACTIVE') {
                $pdo->prepare("UPDATE fields SET status = 'active' WHERE venue_id = ?")->execute([$vId]);
            }
            flash('ok', $nextSt === 'ACTIVE'
                ? "🟢 Đã MỞ BÁN trở lại Cụm sân '{$vRow['name']}'!"
                : "🟠 Đã TẠM NGƯNG nhận khách trực tuyến cho Cụm sân '{$vRow['name']}'.");
        }
        header('Location: owner-dashboard.php?tab=venue&venue_id=' . $vId);
        exit;
    }

    // 2B. TẠO THÊM CỤM SÂN / CHI NHÁNH MỚI CHO CHỦ SÂN
    if ($action === 'create_new_venue') {
        $vName  = trim($_POST['name'] ?? '');
        $vAddr  = trim($_POST['address'] ?? '');
        $vDist  = trim($_POST['district'] ?? 'Tân Bình');
        $vPhone = trim($_POST['contact_phone'] ?? $u['phone']);
        $vLat   = (float)($_POST['latitude'] ?? 10.7768890) ?: 10.7768890;
        $vLng   = (float)($_POST['longitude'] ?? 106.7008060) ?: 106.7008060;
        $vPrice = max(80000, (int)($_POST['price_per_hour'] ?? 180000));
        $vPeak  = max($vPrice, (int)($_POST['peak_price'] ?? 300000));
        $vDesc  = trim($_POST['description'] ?? 'Cụm sân cỏ nhân tạo đạt chuẩn, đèn LED chống chói, bãi đỗ xe miễn phí.');
        $upImg  = save_uploaded_asset('venue_image_file', 'venue_new_' . $ownerId);
        $imgUrl = $upImg ?: (trim($_POST['venue_image_url'] ?? '') ?: 'assets/images/field-1.jpg');

        if ($vName === '' || $vAddr === '') {
            flash('err', 'Vui lòng nhập đầy đủ Tên cụm sân mới và Địa chỉ.');
            header('Location: owner-dashboard.php?tab=venue&venue_id=' . $selectedVenueId);
            exit;
        }

        $pdo->prepare("
            INSERT INTO venues (owner_id, name, address, district, latitude, longitude, open_time, close_time, amenities, images, contact_phone, description, status)
            VALUES (?, ?, ?, ?, ?, ?, '05:30:00', '23:00:00', 'wifi,parking,canteen,shoes,light', ?, ?, ?, 'ACTIVE')
        ")->execute([
            $ownerId, $vName, $vAddr, $vDist, $vLat, $vLng,
            json_encode([$imgUrl], JSON_UNESCAPED_SLASHES), $vPhone, $vDesc
        ]);
        $newVid = (int)$pdo->lastInsertId();

        $gmapUrl = "https://www.google.com/maps/dir/?api=1&destination={$vLat},{$vLng}";
        $pdo->prepare("
            INSERT INTO fields (
                venue_id, owner_id, parent_id, pitch_code, name, type, address, district,
                latitude, longitude, price_per_hour, sub_pitches, normal_price, peak_price,
                contact_phone, google_maps_url, description, status, image
            ) VALUES (?, ?, NULL, '7A', ?, '7', ?, ?, ?, ?, ?, '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)', ?, ?, ?, ?, ?, 'active', ?)
        ")->execute([
            $newVid, $ownerId, $vName, $vAddr, $vDist, $vLat, $vLng,
            $vPrice, $vPrice, $vPeak, $vPhone, $gmapUrl, $vDesc, $imgUrl
        ]);
        $newFid = (int)$pdo->lastInsertId();

        $stPr = $pdo->prepare("INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label) VALUES (?, ?, ?, ?, ?, ?)");
        $stPr->execute([$newFid, 'WEEKDAY', '05:00:00', '16:00:00', $vPrice, 'Giờ thường ban ngày']);
        $stPr->execute([$newFid, 'WEEKDAY', '16:00:00', '20:30:00', $vPeak, '🔥 Giờ Vàng cao điểm']);
        $stPr->execute([$newFid, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($vPrice + $vPeak) / 2), 'Giờ tối muộn']);
        $stPr->execute([$newFid, 'WEEKEND', '05:00:00', '16:00:00', (int)round($vPrice * 1.15), 'Cuối tuần ban ngày']);
        $stPr->execute([$newFid, 'WEEKEND', '16:00:00', '23:00:00', (int)round($vPeak * 1.1), '🔥 Cuối tuần Giờ Vàng']);

        rebuild_field_conflict_map();
        flash('ok', "🎉 Đã tạo Cụm sân mới '{$vName}' thành công! Bạn có thể tùy chỉnh chi tiết từng sân ngay dưới đây.");
        header('Location: owner-dashboard.php?tab=pitches&venue_id=' . $newVid);
        exit;
    }

    // 3. THÊM / TÙY CHỈNH CHI TIẾT SÂN BÓNG & SƠ ĐỒ GHÉP/TÁCH SÂN CHA-CON (Tab 3: Pitch Customization Studio)
    if ($action === 'save_sub_pitch') {
        $pitchId     = (int)($_POST['pitch_id'] ?? 0);
        $vId         = (int)($_POST['venue_id'] ?? $selectedVenueId);
        $vRow        = owner_get_venue($u, $vId);
        $pName       = trim($_POST['name'] ?? '');
        $pCode       = strtoupper(trim($_POST['pitch_code'] ?? '5A'));
        $pType       = in_array($_POST['type'] ?? '', ['5', '7', '11'], true) ? $_POST['type'] : '5';
        $parentId    = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $basePrice   = max(50000, (int)($_POST['price_per_hour'] ?? 180000));
        $peakPrice   = max($basePrice, (int)($_POST['peak_price'] ?? ($basePrice + 120000)));
        $pStatus     = in_array($_POST['status'] ?? '', ['active', 'maintenance'], true) ? $_POST['status'] : 'active';
        $subPitches  = trim($_POST['sub_pitches'] ?? '');
        $pAddress    = trim($_POST['address'] ?? ($vRow['address'] ?? ''));
        $pDistrict   = trim($_POST['district'] ?? ($vRow['district'] ?? ''));
        $pPhone      = trim($_POST['contact_phone'] ?? ($vRow['contact_phone'] ?? $u['phone']));
        $pLat        = (float)($_POST['latitude'] ?? ($vRow['latitude'] ?? 10.7768890)) ?: 10.7768890;
        $pLng        = (float)($_POST['longitude'] ?? ($vRow['longitude'] ?? 106.7008060)) ?: 106.7008060;
        $pDesc       = trim($_POST['description'] ?? '');
        $pGmap       = trim($_POST['google_maps_url'] ?? '') ?: "https://www.google.com/maps/dir/?api=1&destination={$pLat},{$pLng}";
        $syncPricing = !empty($_POST['sync_pricing_rules']);

        // Xử lý upload ảnh sân mới hoặc URL ảnh
        $upPitchImg  = save_uploaded_asset('pitch_image_file', 'pitch_' . ($pitchId ?: 'new'));
        $imgUrlInput = trim($_POST['pitch_image_url'] ?? '');

        if (!$vRow || $pName === '') {
            flash('err', 'Vui lòng chọn Cụm sân hợp lệ và nhập tên sân bóng.');
        } elseif ($pitchId > 0 && ($parentId === $pitchId || field_parent_creates_cycle($pitchId, $parentId))) {
            flash('err', 'Liên kết Sân Cha không hợp lệ (không thể chọn chính nó hoặc tạo vòng lặp Cha-Con).');
        } else {
            if ($pitchId > 0) {
                $fCheck = owner_get_field($u, $pitchId);
                if (!$fCheck) {
                    flash('err', 'Bạn không có quyền chỉnh sửa sân này.');
                } else {
                    $finalImg = $upPitchImg ?: ($imgUrlInput !== '' ? $imgUrlInput : $fCheck['image']);
                    $finalSub = $subPitches !== '' ? $subPitches : ($fCheck['sub_pitches'] ?? "Sân {$pType} người ({$pCode})");
                    $finalDesc = $pDesc !== '' ? $pDesc : ($fCheck['description'] ?? '');

                    $pdo->prepare("
                        UPDATE fields
                        SET venue_id=?, parent_id=?, pitch_code=?, name=?, type=?,
                            address=?, district=?, latitude=?, longitude=?,
                            price_per_hour=?, normal_price=?, peak_price=?, sub_pitches=?,
                            contact_phone=?, google_maps_url=?, description=?, image=?, status=?
                        WHERE id=?
                    ")->execute([
                        $vId, $parentId, $pCode, $pName, $pType,
                        $pAddress, $pDistrict, $pLat, $pLng,
                        $basePrice, $basePrice, $peakPrice, $finalSub,
                        $pPhone, $pGmap, $finalDesc, $finalImg, $pStatus, $pitchId
                    ]);

                    // Nếu là sân gốc của cụm sân, đồng bộ ảnh/địa chỉ/tọa độ lên bảng venues
                    if ($parentId === null) {
                        $pdo->prepare("
                            UPDATE venues
                            SET address=?, district=?, latitude=?, longitude=?, contact_phone=?, description=?
                            WHERE id=?
                        ")->execute([$pAddress, $pDistrict, $pLat, $pLng, $pPhone, $finalDesc, $vId]);
                    }

                    // Nếu tích chọn đồng bộ lại bảng khung giá động (pricing_rules)
                    if ($syncPricing) {
                        $pdo->prepare("DELETE FROM pricing_rules WHERE field_id = ?")->execute([$pitchId]);
                        $stPr = $pdo->prepare("INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label) VALUES (?, ?, ?, ?, ?, ?)");
                        $stPr->execute([$pitchId, 'WEEKDAY', '05:00:00', '16:00:00', $basePrice, 'Giờ thường ban ngày']);
                        $stPr->execute([$pitchId, 'WEEKDAY', '16:00:00', '20:30:00', $peakPrice, '🔥 Giờ Vàng cao điểm']);
                        $stPr->execute([$pitchId, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($basePrice + $peakPrice) / 2), 'Giờ tối muộn']);
                        $stPr->execute([$pitchId, 'WEEKEND', '05:00:00', '16:00:00', (int)round($basePrice * 1.15), 'Cuối tuần ban ngày']);
                        $stPr->execute([$pitchId, 'WEEKEND', '16:00:00', '23:00:00', (int)round($peakPrice * 1.1), '🔥 Cuối tuần Giờ Vàng']);
                    }

                    rebuild_field_conflict_map();
                    flash('ok', "✔ Đã lưu toàn bộ tùy chỉnh chi tiết cho sân '{$pName}' ({$pCode}) thành công!");
                }
            } else {
                $vImgs    = json_decode((string)$vRow['images'], true) ?: [];
                $defImg   = $upPitchImg ?: ($imgUrlInput !== '' ? $imgUrlInput : ($vImgs[0] ?? 'assets/images/field-1.jpg'));
                $finalSub = $subPitches !== '' ? $subPitches : "Sân {$pType} người ({$pCode}) thuộc {$vRow['name']}";
                $finalDesc = $pDesc !== '' ? $pDesc : "Sân cỏ nhân tạo tiêu chuẩn thuộc {$vRow['name']}, đèn LED chống lóa, hỗ trợ đặt lịch 30 phút.";

                $pdo->prepare("
                    INSERT INTO fields (
                        venue_id, owner_id, parent_id, pitch_code, name, type,
                        address, district, latitude, longitude,
                        price_per_hour, sub_pitches, normal_price, peak_price,
                        contact_phone, google_maps_url, description, status, image
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $vId,
                    (int)$vRow['owner_id'] ?: $ownerId,
                    $parentId,
                    $pCode,
                    $pName,
                    $pType,
                    $pAddress,
                    $pDistrict,
                    $pLat,
                    $pLng,
                    $basePrice,
                    $finalSub,
                    $basePrice,
                    $peakPrice,
                    $pPhone,
                    $pGmap,
                    $finalDesc,
                    $pStatus,
                    $defImg
                ]);
                $newFid = (int)$pdo->lastInsertId();

                $stPr = $pdo->prepare("INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label) VALUES (?, ?, ?, ?, ?, ?)");
                $stPr->execute([$newFid, 'WEEKDAY', '05:00:00', '16:00:00', $basePrice, 'Giờ thường ban ngày']);
                $stPr->execute([$newFid, 'WEEKDAY', '16:00:00', '20:30:00', $peakPrice, '🔥 Giờ Vàng cao điểm']);
                $stPr->execute([$newFid, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($basePrice + $peakPrice) / 2), 'Giờ tối muộn']);
                $stPr->execute([$newFid, 'WEEKEND', '05:00:00', '16:00:00', (int)round($basePrice * 1.15), 'Cuối tuần ban ngày']);
                $stPr->execute([$newFid, 'WEEKEND', '16:00:00', '23:00:00', (int)round($peakPrice * 1.1), '🔥 Cuối tuần Giờ Vàng']);

                rebuild_field_conflict_map();
                flash('ok', "🏟 Đã thêm sân mới '{$pName}' ({$pCode}) vào Cụm sân và kích hoạt khóa lịch chéo!");
            }
        }
        header('Location: owner-dashboard.php?tab=pitches&venue_id=' . $vId);
        exit;
    }

    // 3B. KHỞI TẠO NHANH TỔ HỢP SÂN CON MẪU (5A, 5B, 7A) LIÊN KẾT KHÓA CHÉO TRONG CỤM SÂN (1-CLICK)
    if ($action === 'quick_generate_cluster_pitches') {
        $vId  = (int)($_POST['venue_id'] ?? $selectedVenueId);
        $vRow = owner_get_venue($u, $vId);
        if ($vRow) {
            $existing = get_venue_fields($vId);
            $rootField = $existing[0] ?? null;
            if ($rootField) {
                $rootId   = (int)$rootField['id'];
                $baseP    = max(120000, (int)($rootField['price_per_hour'] ?: 180000));
                $peakP    = max($baseP + 80000, (int)($rootField['peak_price'] ?: 300000));
                $existingCodes = array_map(fn($x) => strtoupper((string)$x['pitch_code']), $existing);

                $templates = [
                    ['code' => '7A', 'type' => '7', 'name' => $vRow['name'] . ' - Sân 7A (Ghép 5A+5B)', 'mult' => 1.0],
                    ['code' => '5A', 'type' => '5', 'name' => $vRow['name'] . ' - Sân 5A', 'mult' => 0.75],
                    ['code' => '5B', 'type' => '5', 'name' => $vRow['name'] . ' - Sân 5B', 'mult' => 0.75],
                    ['code' => '5C', 'type' => '5', 'name' => $vRow['name'] . ' - Sân 5C', 'mult' => 0.75],
                ];

                $stInsF = $pdo->prepare("
                    INSERT INTO fields (
                        venue_id, owner_id, parent_id, pitch_code, name, type,
                        address, district, latitude, longitude,
                        price_per_hour, sub_pitches, normal_price, peak_price,
                        contact_phone, google_maps_url, description, status, image
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
                ");
                $stPr = $pdo->prepare("INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label) VALUES (?, ?, ?, ?, ?, ?)");

                $createdCount = 0;
                $parent7Id = ($rootField['type'] === '7') ? $rootId : null;

                foreach ($templates as $tpl) {
                    if (in_array($tpl['code'], $existingCodes, true)) {
                        continue;
                    }
                    if ($tpl['type'] === '7' && $rootField['type'] === '7') {
                        continue;
                    }
                    $pParent = ($tpl['type'] === '5' && $parent7Id) ? $parent7Id : $rootId;
                    $cBase   = (int)(round(($baseP * $tpl['mult']) / 10000) * 10000);
                    $cPeak   = (int)(round(($peakP * $tpl['mult']) / 10000) * 10000);
                    [$offLat, $offLng] = function_exists('compute_sub_pitch_micro_offset')
                        ? compute_sub_pitch_micro_offset($tpl['code'], $createdCount + 1)
                        : [0.0, 0.0];
                    $subLat  = round((float)$rootField['latitude'] + $offLat, 7);
                    $subLng  = round((float)$rootField['longitude'] + $offLng, 7);
                    $gmapUrl = "https://www.google.com/maps/dir/?api=1&destination={$subLat},{$subLng}";

                    $stInsF->execute([
                        $vId,
                        (int)$vRow['owner_id'] ?: $ownerId,
                        $pParent,
                        $tpl['code'],
                        $tpl['name'],
                        $tpl['type'],
                        $rootField['address'],
                        $rootField['district'],
                        $subLat,
                        $subLng,
                        $cBase,
                        "Sân {$tpl['type']} người ({$tpl['code']}) thuộc {$vRow['name']}",
                        $cBase,
                        $cPeak,
                        $rootField['contact_phone'],
                        $gmapUrl,
                        "Sân {$tpl['code']} thành phần thuộc cụm {$vRow['name']}, tích hợp khóa lịch chéo tự động.",
                        $rootField['image']
                    ]);
                    $newChildId = (int)$pdo->lastInsertId();
                    if ($tpl['code'] === '7A') {
                        $parent7Id = $newChildId;
                    }

                    $stPr->execute([$newChildId, 'WEEKDAY', '05:00:00', '16:00:00', $cBase, 'Giờ thường ban ngày']);
                    $stPr->execute([$newChildId, 'WEEKDAY', '16:00:00', '20:30:00', $cPeak, '🔥 Giờ Vàng cao điểm']);
                    $stPr->execute([$newChildId, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($cBase + $cPeak) / 2), 'Giờ tối muộn']);
                    $stPr->execute([$newChildId, 'WEEKEND', '05:00:00', '16:00:00', (int)round($cBase * 1.15), 'Cuối tuần ban ngày']);
                    $stPr->execute([$newChildId, 'WEEKEND', '16:00:00', '23:00:00', (int)round($cPeak * 1.1), '🔥 Cuối tuần Giờ Vàng']);
                    $createdCount++;
                }

                rebuild_field_conflict_map();
                flash('ok', $createdCount > 0
                    ? "⚡ Đã tự động tạo {$createdCount} sân thành phần (5A, 5B, 5C / 7A) và kích hoạt khóa lịch chéo Cha-Con cho Cụm sân!"
                    : "ℹ️ Cụm sân của bạn đã có đầy đủ các mã sân 5A, 5B, 5C, 7A.");
            }
        }
        header('Location: owner-dashboard.php?tab=pitches&venue_id=' . $vId);
        exit;
    }

    // 4. ĐỔI NHANH TRẠNG THÁI BẢO TRÌ SÂN CON (`ACTIVE` <-> `MAINTENANCE`) HOẶC XÓA SÂN CON
    if ($action === 'toggle_pitch_maintenance') {
        $fid  = (int)($_POST['field_id'] ?? 0);
        $fRow = owner_get_field($u, $fid);
        if ($fRow) {
            $next = ($fRow['status'] === 'active') ? 'maintenance' : 'active';
            $pdo->prepare("UPDATE fields SET status = ? WHERE id = ?")->execute([$next, $fid]);
            flash('ok', $next === 'maintenance'
                ? "🛠️ Đã chuyển sân '{$fRow['name']}' sang trạng thái BẢO TRÌ (MAINTENANCE)."
                : "🟢 Đã mở hoạt động trở lại cho sân '{$fRow['name']}'.");
        }
        header('Location: owner-dashboard.php?tab=pitches&venue_id=' . $selectedVenueId);
        exit;
    }

    if ($action === 'delete_sub_pitch') {
        $fid  = (int)($_POST['field_id'] ?? 0);
        $fRow = owner_get_field($u, $fid);
        if ($fRow) {
            $stBkCnt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE field_id = ?");
            $stBkCnt->execute([$fid]);
            if ((int)$stBkCnt->fetchColumn() > 0) {
                flash('err', 'Sân này đã có lịch sử đặt sân nên không thể xóa. Hãy chuyển sang chế độ BẢO TRÌ (MAINTENANCE).');
            } else {
                $pdo->prepare("UPDATE fields SET parent_id = NULL WHERE parent_id = ?")->execute([$fid]);
                $pdo->prepare("DELETE FROM pricing_rules WHERE field_id = ?")->execute([$fid]);
                $pdo->prepare("DELETE FROM fields WHERE id = ?")->execute([$fid]);
                rebuild_field_conflict_map();
                flash('ok', "🗑️ Đã xóa sân con '{$fRow['name']}' thành công.");
            }
        }
        header('Location: owner-dashboard.php?tab=pitches&venue_id=' . $selectedVenueId);
        exit;
    }

    // 4.5. QUẢN LÝ MÃ GIẢM GIÁ VOUCHER CỦA RIÊNG TÀI KHOẢN ĐỐI TÁC CHỦ SÂN (Tab: vouchers)
    if ($action === 'save_partner_voucher') {
        $vidEdit   = (int)($_POST['voucher_id'] ?? 0);
        $vCode     = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', trim($_POST['code'] ?? '')));
        $vDesc     = trim($_POST['description'] ?? '');
        $vScope    = trim($_POST['scope_target'] ?? 'venue'); // 'all_my_venues' | 'venue' | 'field_ID'
        $vType     = ($_POST['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $vVal      = max(1, (int)($_POST['discount_value'] ?? 10));
        $vMinOrder = max(0, (int)($_POST['min_order'] ?? 150000));
        $vMaxDisc  = max(0, (int)($_POST['max_discount'] ?? 50000));
        $vLimit    = max(1, (int)($_POST['usage_limit'] ?? 100));
        $vExp      = trim($_POST['expired_at'] ?? date('Y-12-31'));

        $targetVenueId = $selectedVenueId ?: null;
        $targetFieldId = null;
        if ($vScope === 'all_my_venues') {
            $targetVenueId = null;
            $targetFieldId = null;
        } elseif (strpos($vScope, 'field_') === 0) {
            $fCandidate = (int)substr($vScope, 6);
            $fCheckRow  = owner_get_field($u, $fCandidate);
            if ($fCheckRow) {
                $targetFieldId = (int)$fCheckRow['id'];
                $targetVenueId = (int)($fCheckRow['venue_id'] ?? $selectedVenueId) ?: null;
            }
        }

        if ($vDesc === '') {
            $vDesc = ($vType === 'percent')
                ? "Ưu đãi giảm {$vVal}% (tối đa " . number_format($vMaxDisc, 0, ',', '.') . "đ)"
                : "Giảm trực tiếp " . number_format($vVal, 0, ',', '.') . "đ khi đặt sân";
        }

        if ($vCode === '' || $vVal <= 0 || !$vExp) {
            flash('err', 'Vui lòng nhập đầy đủ Mã Voucher, Giá trị giảm và Hạn sử dụng.');
        } else {
            // Kiểm tra trùng mã Voucher với đối tác khác
            $stDup = $pdo->prepare("SELECT id, owner_id FROM vouchers WHERE code = ? AND id != ? LIMIT 1");
            $stDup->execute([$vCode, $vidEdit]);
            $dupRow = $stDup->fetch(PDO::FETCH_ASSOC);
            if ($dupRow) {
                flash('err', "Mã Voucher '{$vCode}' đã tồn tại trên hệ thống. Vui lòng chọn mã khác (VD: thêm tên sân của bạn).");
            } elseif ($vidEdit > 0) {
                $stOwn = $pdo->prepare("SELECT * FROM vouchers WHERE id = ? AND (owner_id = ? OR ? = 1) LIMIT 1");
                $stOwn->execute([$vidEdit, $ownerId, $isAdmin ? 1 : 0]);
                if ($stOwn->fetch()) {
                    $pdo->prepare("
                        UPDATE vouchers
                        SET venue_id = ?, field_id = ?, code = ?, description = ?, discount_type = ?, discount_value = ?,
                            min_order = ?, max_discount = ?, usage_limit = ?, expired_at = ?
                        WHERE id = ?
                    ")->execute([$targetVenueId, $targetFieldId, $vCode, $vDesc, $vType, $vVal, $vMinOrder, $vMaxDisc, $vLimit, $vExp, $vidEdit]);
                    flash('ok', "✔ Đã cập nhật mã giảm giá '{$vCode}' cho sân của bạn!");
                }
            } else {
                $pdo->prepare("
                    INSERT INTO vouchers
                        (owner_id, venue_id, field_id, code, description, discount_type, discount_value, min_order, max_discount, usage_limit, expired_at, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ")->execute([$ownerId, $targetVenueId, $targetFieldId, $vCode, $vDesc, $vType, $vVal, $vMinOrder, $vMaxDisc, $vLimit, $vExp]);
                flash('ok', "🎉 Đã phát hành mã giảm giá mới '{$vCode}' cho Cụm sân của bạn!");
            }
        }
        header('Location: owner-dashboard.php?tab=vouchers&venue_id=' . $selectedVenueId);
        exit;
    }

    if ($action === 'quick_extend_voucher') {
        $vidExt = (int)($_POST['voucher_id'] ?? 0);
        $pdo->prepare("
            UPDATE vouchers
            SET usage_limit = usage_limit + 50,
                expired_at = DATE_ADD(GREATEST(expired_at, CURDATE()), INTERVAL 30 DAY),
                status = 'active'
            WHERE id = ? AND (owner_id = ? OR ? = 1)
        ")->execute([$vidExt, $ownerId, $isAdmin ? 1 : 0]);
        flash('ok', '➕ Đã cộng thêm 50 lượt dùng và gia hạn thêm 30 ngày cho mã Voucher!');
        header('Location: owner-dashboard.php?tab=vouchers&venue_id=' . $selectedVenueId);
        exit;
    }

    if ($action === 'toggle_partner_voucher') {
        $vidToggle = (int)($_POST['voucher_id'] ?? 0);
        $pdo->prepare("
            UPDATE vouchers
            SET status = IF(status = 'active', 'inactive', 'active')
            WHERE id = ? AND (owner_id = ? OR ? = 1)
        ")->execute([$vidToggle, $ownerId, $isAdmin ? 1 : 0]);
        flash('ok', '✔ Đã thay đổi trạng thái hoạt động của mã Voucher.');
        header('Location: owner-dashboard.php?tab=vouchers&venue_id=' . $selectedVenueId);
        exit;
    }

    if ($action === 'delete_partner_voucher') {
        $vidDel = (int)($_POST['voucher_id'] ?? 0);
        $pdo->prepare("DELETE FROM vouchers WHERE id = ? AND (owner_id = ? OR ? = 1)")
            ->execute([$vidDel, $ownerId, $isAdmin ? 1 : 0]);
        flash('ok', '🗑️ Đã xóa mã Voucher khỏi Cụm sân.');
        header('Location: owner-dashboard.php?tab=vouchers&venue_id=' . $selectedVenueId);
        exit;
    }

    // 5. CẤU HÌNH BẢNG GIÁ LINH HOẠT THEO KHUNG GIỜ (Tab 4: Dynamic Pricing)
    if ($action === 'apply_standard_pricing') {
        $fid          = (int)($_POST['field_id'] ?? 0);
        $applyAllVenue = !empty($_POST['apply_all_venue']);
        $wdMorning    = max(50000, (int)($_POST['wd_morning'] ?? 150000));
        $wdGolden     = max(50000, (int)($_POST['wd_golden'] ?? 280000));
        $wdNight      = max(50000, (int)($_POST['wd_night'] ?? 200000));
        $weMorning    = max(50000, (int)($_POST['we_morning'] ?? 180000));
        $weGolden     = max(50000, (int)($_POST['we_golden'] ?? 320000));

        $targetFields = [];
        if ($applyAllVenue && $curVenue) {
            foreach (get_venue_fields($curVenue['id']) as $vf) {
                $targetFields[] = (int)$vf['id'];
            }
        } else {
            $fCheck = owner_get_field($u, $fid);
            if ($fCheck) {
                $targetFields[] = (int)$fCheck['id'];
            }
        }

        if (!$targetFields) {
            flash('err', 'Không tìm thấy sân hợp lệ để áp dụng khung giá.');
        } else {
            $pdo->beginTransaction();
            try {
                $stDel = $pdo->prepare("DELETE FROM pricing_rules WHERE field_id = ?");
                $stIns = $pdo->prepare("
                    INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stUpdField = $pdo->prepare("UPDATE fields SET price_per_hour = ?, normal_price = ?, peak_price = ? WHERE id = ?");

                foreach ($targetFields as $tfId) {
                    $stDel->execute([$tfId]);
                    $stIns->execute([$tfId, 'WEEKDAY', '05:00:00', '16:00:00', $wdMorning, 'Giờ thường (05:00 - 16:00)']);
                    $stIns->execute([$tfId, 'WEEKDAY', '16:00:00', '20:30:00', $wdGolden,  '🔥 Giờ Vàng (16:00 - 20:30)']);
                    $stIns->execute([$tfId, 'WEEKDAY', '20:30:00', '23:00:00', $wdNight,   'Giờ tối (20:30 - 23:00)']);
                    $stIns->execute([$tfId, 'WEEKEND', '05:00:00', '16:00:00', $weMorning, 'Cuối tuần ban ngày (05:00 - 16:00)']);
                    $stIns->execute([$tfId, 'WEEKEND', '16:00:00', '23:00:00', $weGolden,  '🔥 Cuối tuần Giờ Vàng (16:00 - 23:00)']);
                    $stUpdField->execute([$wdMorning, $wdMorning, $wdGolden, $tfId]);
                }
                $pdo->commit();
                flash('ok', '💰 Đã cập nhật Bảng giá theo khung giờ (Ngày thường / Giờ vàng / Cuối tuần) cho ' . count($targetFields) . ' sân!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('err', 'Lỗi lưu bảng giá: ' . $e->getMessage());
            }
        }
        header('Location: owner-dashboard.php?tab=pricing&venue_id=' . $selectedVenueId . '&field_id=' . $fid);
        exit;
    }

    if ($action === 'add_custom_pricing_rule') {
        $fid   = (int)($_POST['field_id'] ?? 0);
        $fRow  = owner_get_field($u, $fid);
        $dType = in_array($_POST['day_type'] ?? '', ['WEEKDAY', 'WEEKEND', 'HOLIDAY'], true) ? $_POST['day_type'] : 'WEEKDAY';
        $st    = trim($_POST['start_time'] ?? '17:00');
        $en    = trim($_POST['end_time'] ?? '20:30');
        $price = max(50000, (int)($_POST['price_per_hour'] ?? 250000));
        $label = trim($_POST['label'] ?? 'Khung giờ tùy chỉnh');

        if ($fRow && strtotime($en) > strtotime($st)) {
            $pdo->prepare("
                INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $fid,
                $dType,
                strlen($st) === 5 ? $st . ':00' : $st,
                strlen($en) === 5 ? $en . ':00' : $en,
                $price,
                $label
            ]);
            flash('ok', "Đã thêm khung giá '{$label}' cho sân {$fRow['name']}.");
        } else {
            flash('err', 'Khung giờ không hợp lệ (Giờ kết thúc phải lớn hơn giờ bắt đầu).');
        }
        header('Location: owner-dashboard.php?tab=pricing&venue_id=' . $selectedVenueId . '&field_id=' . $fid);
        exit;
    }

    if ($action === 'delete_pricing_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $fid    = (int)($_POST['field_id'] ?? 0);
        if (owner_get_field($u, $fid)) {
            $pdo->prepare("DELETE FROM pricing_rules WHERE id = ? AND field_id = ?")->execute([$ruleId, $fid]);
            flash('ok', 'Đã xóa khung giá.');
        }
        header('Location: owner-dashboard.php?tab=pricing&venue_id=' . $selectedVenueId . '&field_id=' . $fid);
        exit;
    }

    // 6. NGHIỆP VỤ POS & LỊCH ĐẶT SÂN: CHECK-IN / NO-SHOW / WALK-IN BOOKING / CONFIRM (Tab 5)
    if ($action === 'pos_check_in') {
        $bid = (int)($_POST['booking_id'] ?? 0);
        $res = owner_check_in_booking($u, $bid);
        flash($res['ok'] ? 'ok' : 'err', $res['message']);
        header('Location: owner-dashboard.php?tab=calendar&venue_id=' . $selectedVenueId . '&date=' . urlencode($_POST['cal_date'] ?? date('Y-m-d')));
        exit;
    }

    if ($action === 'pos_no_show') {
        $bid = (int)($_POST['booking_id'] ?? 0);
        $res = owner_mark_no_show($u, $bid);
        flash($res['ok'] ? 'ok' : 'err', $res['message']);
        header('Location: owner-dashboard.php?tab=calendar&venue_id=' . $selectedVenueId . '&date=' . urlencode($_POST['cal_date'] ?? date('Y-m-d')));
        exit;
    }

    if ($action === 'pos_confirm_booking') {
        $bid  = (int)($_POST['booking_id'] ?? 0);
        $bRow = owner_get_booking($u, $bid);
        if ($bRow && $bRow['status'] === 'pending') {
            $pdo->prepare("UPDATE bookings SET status = 'confirmed', hold_expires_at = NULL WHERE id = ?")->execute([$bid]);
            notify((int)$bRow['user_id'], "✔ Chủ sân đã xác nhận giữ sân cho đơn #{$bid} ({$bRow['fname']}).");
            flash('ok', "Đã xác nhận giữ sân cho đơn #{$bid}.");
        }
        header('Location: owner-dashboard.php?tab=calendar&venue_id=' . $selectedVenueId . '&date=' . urlencode($_POST['cal_date'] ?? date('Y-m-d')));
        exit;
    }

    if ($action === 'create_walkin_booking') {
        $wFieldId = (int)($_POST['field_id'] ?? 0);
        $wDate    = $_POST['booking_date'] ?? date('Y-m-d');
        $wStart   = $_POST['start_time'] ?? '17:30';
        $wEnd     = $_POST['end_time'] ?? '19:00';
        $wName    = trim($_POST['walkin_name'] ?? 'Khách đặt qua SĐT');
        $wPhone   = trim($_POST['walkin_phone'] ?? '');
        $wPaid    = !empty($_POST['paid_now']);
        $wNote    = trim($_POST['note'] ?? '');

        $res = owner_create_walkin_booking($u, $wFieldId, $wDate, $wStart, $wEnd, $wName, $wPhone, $wPaid, $wNote);
        flash($res['ok'] ? 'ok' : 'err', $res['message']);
        header('Location: owner-dashboard.php?tab=calendar&venue_id=' . $selectedVenueId . '&date=' . urlencode($wDate));
        exit;
    }

    // 7. VÍ ĐỐI TÁC & RÚT TIỀN ĐỐI SOÁT VỀ NGÂN HÀNG (Tab 6: Partner Wallet & Payout)
    if ($action === 'request_partner_payout') {
        $reqAmt     = max(0, (float)($_POST['payout_amount'] ?? 0));
        $bankName   = trim($_POST['bank_name'] ?? 'MB Bank');
        $bankAccNo  = trim($_POST['bank_account_number'] ?? '');
        $bankHolder = mb_strtoupper(trim($_POST['bank_account_holder'] ?? $u['full_name']));
        $note       = trim($_POST['note'] ?? 'Rút tiền doanh thu đối soát đặt sân');

        $curWal = (float)($u['wallet_balance'] ?? 0);
        if ($reqAmt < 50000) {
            flash('err', 'Số tiền rút tối thiểu là 50.000đ.');
        } elseif ($reqAmt > $curWal) {
            flash('err', 'Số tiền yêu cầu rút vượt quá số dư khả dụng trong Ví Đối tác (' . money($curWal) . ').');
        } elseif ($bankAccNo === '' || $bankHolder === '') {
            flash('err', 'Vui lòng nhập đầy đủ Số tài khoản và Tên chủ tài khoản Ngân hàng thụ hưởng.');
        } else {
            record_wallet_and_reputation(
                $ownerId,
                'PAYMENT',
                -$reqAmt,
                0,
                0,
                "Rút tiền doanh thu Đối tác về {$bankName} - STK {$bankAccNo} ({$bankHolder})",
                null,
                $ownerId
            );
            $pdo->prepare("
                INSERT INTO partner_payout_requests (user_id, amount, bank_name, bank_account_number, bank_account_holder, status, note)
                VALUES (?, ?, ?, ?, ?, 'COMPLETED', ?)
            ")->execute([$ownerId, $reqAmt, $bankName, $bankAccNo, $bankHolder, $note]);

            // Đồng bộ tài khoản ngân hàng thụ hưởng vào hồ sơ đối tác
            $pdo->prepare("
                UPDATE partner_applications
                SET bank_name = ?, bank_account_number = ?, bank_account_holder = ?
                WHERE user_id = ?
            ")->execute([$bankName, $bankAccNo, $bankHolder, $ownerId]);

            notify($ownerId, "🏦 Đã giải ngân thành công " . money($reqAmt) . " từ Ví Đối tác về tài khoản {$bankName} ({$bankAccNo}).");
            flash('ok', "🏦 Đã lập lệnh rút " . money($reqAmt) . " về tài khoản {$bankName} ({$bankAccNo} - {$bankHolder}) thành công!");
        }
        header('Location: owner-dashboard.php?tab=revenue&venue_id=' . $selectedVenueId);
        exit;
    }

    if ($action === 'update_partner_bank_info') {
        $bankName   = trim($_POST['bank_name'] ?? 'MB Bank');
        $bankAccNo  = trim($_POST['bank_account_number'] ?? '');
        $bankHolder = mb_strtoupper(trim($_POST['bank_account_holder'] ?? $u['full_name']));
        $taxCode    = trim($_POST['tax_code'] ?? '');
        if ($bankAccNo === '' || $bankHolder === '') {
            flash('err', 'Vui lòng nhập đầy đủ Số tài khoản và Chủ tài khoản.');
        } else {
            $pdo->prepare("
                UPDATE partner_applications
                SET bank_name = ?, bank_account_number = ?, bank_account_holder = ?, tax_code = IF(? = '', tax_code, ?)
                WHERE user_id = ?
            ")->execute([$bankName, $bankAccNo, $bankHolder, $taxCode, $taxCode, $ownerId]);
            flash('ok', '✔ Đã cập nhật thông tin Tài khoản Ngân hàng nhận tiền đối soát!');
        }
        header('Location: owner-dashboard.php?tab=revenue&venue_id=' . $selectedVenueId);
        exit;
    }
}

// ----------------------------------------------------------------------------
// CHUẨN BỊ DỮ LIỆU THỐNG KÊ KPI, DOANH THU, SÂN CON & LỊCH TIMELINE
// ----------------------------------------------------------------------------
$myVenues    = get_owner_venues($u);
$curVenue    = $selectedVenueId ? owner_get_venue($u, $selectedVenueId) : ($myVenues[0] ?? null);
$venueFields = $curVenue ? get_venue_fields($curVenue['id']) : [];
$allOwnerFieldIds = get_owner_field_ids($u);

$kpiTodayRev     = 0;
$kpiMonthRev     = 0;
$kpiTotalBks     = 0;
$kpiOccupancyPct = 0;
$dailyRevSeries  = [];
$ownerBookings   = [];
$onlineReconcileTotal = 0;
$cashCounterTotal     = 0;
$escrowHoldTotal      = 0;
$pendingOwnerBkCount  = 0;

$todayStr = date('Y-m-d');
$monthPrefix = date('Y-m-');

// Khởi tạo mảng doanh thu 7 ngày gần nhất
for ($d = 6; $d >= 0; $d--) {
    $dayKey = date('Y-m-d', strtotime("-$d days"));
    $dailyRevSeries[$dayKey] = [
        'label'   => date('d/m', strtotime($dayKey)),
        'revenue' => 0,
        'count'   => 0,
    ];
}

if ($allOwnerFieldIds) {
    $inClause = implode(',', array_fill(0, count($allOwnerFieldIds), '?'));
    $stmtBks = $pdo->prepare("
        SELECT b.*, f.name AS fname, f.type AS ftype, f.pitch_code,
               u.full_name AS customer_name, u.phone AS customer_phone, u.reputation_score,
               p.status AS pay_status, p.method AS pay_method
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        JOIN users u ON u.id = b.user_id
        LEFT JOIN payments p ON p.booking_id = b.id
        WHERE b.field_id IN ($inClause)
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT 120
    ");
    $stmtBks->execute($allOwnerFieldIds);
    $ownerBookings = $stmtBks->fetchAll();

    $bookedHoursToday = 0.0;
    foreach ($ownerBookings as $ob) {
        if ($ob['status'] !== 'cancelled') {
            $kpiTotalBks++;
        }
        $isRevValid = in_array($ob['status'], ['confirmed', 'completed'], true);
        $priceVal   = (float)$ob['total_price'];

        if ($isRevValid) {
            if ($ob['booking_date'] === $todayStr) {
                $kpiTodayRev += $priceVal;
            }
            if (strpos($ob['booking_date'], $monthPrefix) === 0) {
                $kpiMonthRev += $priceVal;
            }
            if (isset($dailyRevSeries[$ob['booking_date']])) {
                $dailyRevSeries[$ob['booking_date']]['revenue'] += $priceVal;
                $dailyRevSeries[$ob['booking_date']]['count']++;
            }
            if (in_array($ob['pay_method'] ?? '', ['bank', 'momo', 'vnpay', 'wallet', 'qr', 'transfer'], true)) {
                $onlineReconcileTotal += $priceVal;
            } else {
                $cashCounterTotal += $priceVal;
            }
        }

        if ($ob['status'] === 'pending') {
            $pendingOwnerBkCount++;
            $escrowHoldTotal += (float)$ob['total_price'];
        } elseif ($ob['status'] === 'confirmed' && $ob['booking_date'] >= $todayStr) {
            $escrowHoldTotal += (float)$ob['total_price'];
        }

        if ($ob['booking_date'] === $todayStr && in_array($ob['status'], ['pending', 'confirmed', 'completed'], true)) {
            $durH = max(0, (strtotime($ob['end_time']) - strtotime($ob['start_time'])) / 3600);
            $bookedHoursToday += $durH;
        }
    }

    // Tính tỷ lệ lấp đầy sân hôm nay (trên tổng quỹ giờ hoạt động ~12h/sân)
    $totalAvailableHours = max(1, count($allOwnerFieldIds) * 12);
    $kpiOccupancyPct     = min(100, (int)round(($bookedHoursToday / $totalAvailableHours) * 100));
}

// Thông tin tài khoản ngân hàng đối soát & lịch sử giao dịch Ví Đối tác
$stmtPartner = $pdo->prepare("SELECT * FROM partner_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmtPartner->execute([$ownerId]);
$partnerAppInfo = $stmtPartner->fetch();

$stmtPartnerWal = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 25");
$stmtPartnerWal->execute([$ownerId]);
$partnerWalletLogs = $stmtPartnerWal->fetchAll();

$stmtPayouts = $pdo->prepare("SELECT * FROM partner_payout_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20");
$stmtPayouts->execute([$ownerId]);
$partnerPayoutList = $stmtPayouts->fetchAll();

$calDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$bkStatusFilter = $_GET['bk_status'] ?? 'all';
$selectedPricingFieldId = (int)($_GET['field_id'] ?? ($venueFields[0]['id'] ?? 0));
$ownerUnreadChatCount = function_exists('get_owner_unread_chat_count') ? get_owner_unread_chat_count($u) : 0;

$active = 'owner';
$title  = 'Kênh Chủ Sân (Partner Portal) - Quản lý Cụm sân, Khung giá & Lịch đặt POS';
include '../includes/header.php';
?>

<div class="partner-shell">
  <!-- ========================================================================
       SIDEBAR TRÁI CỦA KÊNH ĐỐI TÁC CHỦ SÂN (CHUẨN PARTNER PORTAL DESIGN SYSTEM)
       ======================================================================== -->
  <aside class="partner-sidebar">
    <div class="partner-sb-profile">
      <div class="partner-sb-avatar">
        <?php if (!empty($u['avatar'])): ?>
          <img src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name'])?>" referrerpolicy="no-referrer" onerror="this.style.display='none';this.parentElement.textContent=<?=json_encode(mb_substr($u['full_name'], 0, 1), JSON_UNESCAPED_UNICODE)?>">
        <?php else: ?>
          <?=e(mb_substr($u['full_name'], 0, 1))?>
        <?php endif; ?>
      </div>

      <span class="partner-verified-pill <?=(($u['partner_status'] ?? 'APPROVED') === 'APPROVED' || is_pitch_owner($u)) ? 'verified' : 'pending'?>">
        <?=(($u['partner_status'] ?? 'APPROVED') === 'APPROVED' || is_pitch_owner($u)) ? '✔ ĐÃ XÁC MINH CHỦ SÂN' : '⏳ CHỜ DUYỆT HỒ SƠ'?>
      </span>

      <b style="font-size:16px;display:block;color:#ffffff"><?=e($u['full_name'])?></b>
      <small style="color:#a7f3d0;margin:2px 0 8px;display:block">📞 <?=e($u['phone'])?> • <?=count($myVenues)?> Cụm sân</small>

      <!-- Khung chọn Cụm sân của Đối tác -->
      <div class="partner-venue-switcher">
        <small style="color:#6ee7b7;font-size:10.5px;font-weight:800;text-transform:uppercase;margin:0 0 4px">🏟 Cụm sân đang quản lý:</small>
        <?php if (count($myVenues) > 1): ?>
          <form method="get" style="margin:0">
            <input type="hidden" name="tab" value="<?=e($tab)?>">
            <select name="venue_id" onchange="this.form.submit()">
              <?php foreach ($myVenues as $mv): ?>
                <option value="<?=$mv['id']?>" <?=(int)$mv['id'] === $selectedVenueId ? 'selected' : ''?>>
                  <?=e($mv['name'])?> (<?=e($mv['district'])?>)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php elseif ($curVenue): ?>
          <b style="font-size:13px;color:#ffffff;display:block"><?=e($curVenue['name'])?></b>
          <small style="color:#a7f3d0;margin:2px 0 0;font-size:11.5px">
            📍 <?=e($curVenue['district'])?> • <?=$curVenue['status'] === 'ACTIVE' ? '🟢 Đang mở bán' : '🟠 Tạm ngưng'?>
          </small>
        <?php endif; ?>
      </div>
    </div>

    <div class="ps-nav-group-label">MENU QUẢN LÝ CHỦ SÂN</div>

    <a class="ps-menu-link <?=$tab === 'overview' ? 'active' : ''?>" href="?tab=overview&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">📊</span><span>Tổng quan & Doanh thu</span></span>
      <span>›</span>
    </a>

    <a class="ps-menu-link <?=$tab === 'pitches' ? 'active' : ''?>" href="?tab=pitches&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">🏟</span><span>Quản lý Sân bóng (5/7/11)</span></span>
      <span class="ps-menu-badge"><?=count($venueFields)?></span>
    </a>

    <a class="ps-menu-link <?=$tab === 'pricing' ? 'active' : ''?>" href="?tab=pricing&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">💰</span><span>Cấu hình Giá Khung giờ</span></span>
      <span>›</span>
    </a>

    <a class="ps-menu-link <?=$tab === 'vouchers' ? 'active' : ''?>" href="?tab=vouchers&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">🎟</span><span>Mã giảm giá Voucher Sân</span></span>
      <span>›</span>
    </a>

    <a class="ps-menu-link <?=$tab === 'calendar' ? 'active' : ''?>" href="?tab=calendar&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">📅</span><span>Quản lý Lịch đặt sân</span></span>
      <?php if ($pendingOwnerBkCount > 0): ?>
        <span class="ps-menu-badge" style="background:#ef4444;color:#fff"><?=$pendingOwnerBkCount?></span>
      <?php else: ?>
        <span>›</span>
      <?php endif; ?>
    </a>

    <a class="ps-menu-link <?=$tab === 'messages' ? 'active' : ''?>" href="?tab=messages&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">💬</span><span>Trung tâm Tin nhắn</span></span>
      <span class="ps-menu-badge" id="sbOwnerChatBadge" style="<?=$ownerUnreadChatCount > 0 ? 'background:#ef4444;color:#fff;display:inline-flex' : 'display:none'?>"><?=$ownerUnreadChatCount?></span>
    </a>

    <a class="ps-menu-link <?=$tab === 'revenue' ? 'active' : ''?>" href="?tab=revenue&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">📈</span><span>Doanh thu & Trừ phí sàn</span></span>
      <span>›</span>
    </a>

    <a class="ps-menu-link <?=$tab === 'venue' ? 'active' : ''?>" href="?tab=venue&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">📍</span><span>Thiết lập Cụm sân & GPS</span></span>
      <span>›</span>
    </a>

    <div class="ps-nav-group-label">TÀI KHOẢN & ĐIỀU HƯỚNG</div>

    <a href="profile.php?tab=partner" class="ps-menu-link">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">👤</span><span>Hồ sơ Đối tác & Cá nhân</span></span>
      <span>↩</span>
    </a>

    <a href="../index.php" class="ps-menu-link">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">🌐</span><span>Về Trang chủ Đặt sân</span></span>
      <span>↗</span>
    </a>

    <button
      type="button"
      onclick="document.getElementById('partnerLogoutModal').style.display='flex'"
      style="width:100%;margin-top:10px;padding:11px 13px;border-radius:11px;border:1px solid rgba(248,113,113,0.55);background:rgba(220,38,38,0.18);color:#fecaca;font-weight:800;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.18s"
    >
      <span>🚪 Đăng xuất tài khoản</span>
    </button>
  </aside>

  <!-- ========================================================================
       KHUNG NỘI DUNG PHẢI CỦA KÊNH ĐỐI TÁC CHỦ SÂN (.partner-main-area)
       ======================================================================== -->
  <div class="partner-main-area">

    <!-- Thanh Sub-nav Ngang đồng bộ 8 mục -->
    <div class="partner-subnav-pills">
      <a class="partner-subnav-pill <?=$tab === 'overview' ? 'active' : ''?>" href="?tab=overview&venue_id=<?=$selectedVenueId?>">📊 Tổng quan & Doanh thu</a>
      <a class="partner-subnav-pill <?=$tab === 'pitches' ? 'active' : ''?>" href="?tab=pitches&venue_id=<?=$selectedVenueId?>">🏟 Sân bóng (5/7/11)</a>
      <a class="partner-subnav-pill <?=$tab === 'pricing' ? 'active' : ''?>" href="?tab=pricing&venue_id=<?=$selectedVenueId?>">💰 Giá theo Khung giờ</a>
      <a class="partner-subnav-pill <?=$tab === 'vouchers' ? 'active' : ''?>" href="?tab=vouchers&venue_id=<?=$selectedVenueId?>">🎟 Voucher Khuyến mãi</a>
      <a class="partner-subnav-pill <?=$tab === 'calendar' ? 'active' : ''?>" href="?tab=calendar&venue_id=<?=$selectedVenueId?>">📅 Lịch đặt sân (Bookings)</a>
      <a class="partner-subnav-pill <?=$tab === 'messages' ? 'active' : ''?>" href="?tab=messages&venue_id=<?=$selectedVenueId?>" style="display:inline-flex;align-items:center;gap:6px">
        <span>💬 Trung tâm Tin nhắn</span>
        <span id="pillOwnerChatBadge" style="background:#ef4444;color:#fff;font-size:11px;font-weight:800;padding:1px 7px;border-radius:999px;<?=$ownerUnreadChatCount > 0 ? 'display:inline-block' : 'display:none'?>"><?=$ownerUnreadChatCount?></span>
      </a>
      <a class="partner-subnav-pill <?=$tab === 'revenue' ? 'active' : ''?>" href="?tab=revenue&venue_id=<?=$selectedVenueId?>">📈 Thống kê Doanh thu & Phí sàn</a>
      <a class="partner-subnav-pill <?=$tab === 'venue' ? 'active' : ''?>" href="?tab=venue&venue_id=<?=$selectedVenueId?>">📍 Cài đặt Cụm sân</a>
    </div>

    <?php if ($tab === 'overview'): ?>
      <!-- ====================================================================
           MODULE 1: TỔNG QUAN, 4 THẺ KPI, BIỂU ĐỒ DOANH THU & ĐỐI SOÁT NGÂN HÀNG
           ==================================================================== -->
      <div class="partner-hero-banner">
        <div>
          <h2>📊 Tổng quan Vận hành & Thống kê Doanh thu</h2>
          <small>Cụm sân: <b><?=e($curVenue['name'] ?? $u['full_name'])?></b> • Dữ liệu đối soát thời gian thực</small>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a href="?tab=calendar&venue_id=<?=$selectedVenueId?>" class="btn sm" style="background:#fde047;color:#052e16 !important;font-weight:800">📅 Mở Lịch POS & Check-in</a>
          <a href="?tab=revenue&venue_id=<?=$selectedVenueId?>" class="btn sm outline" style="color:#fff !important;border-color:#86efac">📈 Xem Bảng Doanh thu (-<?=get_platform_fee_percent()?>% Phí sàn) →</a>
        </div>
      </div>

      <!-- 4 Thẻ Stat Cards chuẩn Design System -->
      <div class="partner-kpi-grid">
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon green">💵</div>
          <div class="partner-kpi-meta">
            <small>Doanh thu hôm nay (<?=date('d/m')?>)</small>
            <b style="color:#15803d"><?=money($kpiTodayRev)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon blue">📈</div>
          <div class="partner-kpi-meta">
            <small>Doanh thu tháng <?=date('m/Y')?></small>
            <b style="color:#1d4ed8"><?=money($kpiMonthRev)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon purple">🎫</div>
          <div class="partner-kpi-meta">
            <small>Tổng lượt đặt sân</small>
            <b style="color:#6d28d9"><?=$kpiTotalBks?> đơn</b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon amber">⚡</div>
          <div class="partner-kpi-meta">
            <small>Tỷ lệ lấp đầy hôm nay</small>
            <b style="color:#d97706"><?=$kpiOccupancyPct?>%</b>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:20px;align-items:start;margin-bottom:20px">
        <!-- Biểu đồ doanh thu 7 ngày gần nhất -->
        <div class="box" style="margin:0">
          <div class="bh">
            <b>📈 Biểu đồ Doanh thu 7 ngày gần nhất</b>
            <small style="margin:0;color:#64748b">Đơn đã xác nhận & hoàn thành</small>
          </div>
          <?php
          $maxDayRev = 1;
          foreach ($dailyRevSeries as $dItem) {
              if ($dItem['revenue'] > $maxDayRev) {
                  $maxDayRev = $dItem['revenue'];
              }
          }
          ?>
          <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:10px;align-items:end;height:190px;padding:16px 8px 6px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0">
            <?php foreach ($dailyRevSeries as $dKey => $dVal):
                $barH = $dVal['revenue'] > 0 ? max(16, (int)round(($dVal['revenue'] / $maxDayRev) * 135)) : 8;
            ?>
              <div style="text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
                <small style="font-size:10px;font-weight:800;color:#15803d;margin-bottom:4px">
                  <?=$dVal['revenue'] > 0 ? round($dVal['revenue'] / 1000) . 'k' : '0'?>
                </small>
                <div style="width:100%;max-width:36px;height:<?=$barH?>px;background:<?=$dKey === $todayStr ? 'linear-gradient(180deg,#22c55e,#15803d)' : 'linear-gradient(180deg,#86efac,#16a34a)'?>;border-radius:8px 8px 4px 4px"></div>
                <small style="font-size:11px;font-weight:700;color:#334155;margin-top:6px"><?=$dVal['label']?></small>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Bảng đối soát dòng tiền Admin thu hộ & trừ phí sàn -->
        <div class="box" style="margin:0;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #86efac">
          <h4 style="margin:0 0 12px;color:#065f46;font-family:var(--font-sport);font-size:17px">
            🏦 ĐỐI SOÁT DÒNG TIỀN ADMIN THU HỘ & TRỪ PHÍ SÀN
          </h4>
          <?php
          $feePctOverview     = get_platform_fee_percent();
          $grossTotalOverview = $onlineReconcileTotal + $cashCounterTotal;
          $platformFee        = round($grossTotalOverview * ($feePctOverview / 100));
          $netPayoutToOwner   = max(0, $grossTotalOverview - $platformFee);
          ?>
          <div style="background:#ffffff;border-radius:12px;padding:14px;border:1px solid #bbf7d0;font-size:13px;display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
            <div style="display:flex;justify-content:space-between">
              <span style="color:#475569">Tổng doanh thu gốc (Về TK Admin 100%):</span>
              <b><?=money($grossTotalOverview)?></b>
            </div>
            <div style="display:flex;justify-content:space-between;color:#dc2626">
              <span>Khấu trừ Phí vận hành sàn (-<?=$feePctOverview?>%):</span>
              <b>-<?=money($platformFee)?></b>
            </div>
            <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1.5px dashed #86efac;font-size:14.5px">
              <b style="color:#065f46">Doanh thu Chủ sân thực nhận (<?=(100 - $feePctOverview)?>%):</b>
              <b style="color:#16a34a;font-size:17px"><?=money($netPayoutToOwner)?></b>
            </div>
          </div>

          <?php if ($partnerAppInfo): ?>
            <div style="font-size:12.5px;color:#064e3b;line-height:1.6;margin-bottom:10px">
              <div>• Ngân hàng nhận đối soát: <b><?=e($partnerAppInfo['bank_name'])?></b></div>
              <div>• Số tài khoản: <b><?=e($partnerAppInfo['bank_account_number'])?></b> (<b><?=e($partnerAppInfo['bank_account_holder'])?></b>)</div>
            </div>
          <?php endif; ?>
          <a href="?tab=revenue&venue_id=<?=$selectedVenueId?>" class="btn sm" style="width:100%">
            📊 Mở Bảng Thống Kê Doanh Thu Chi Tiết →
          </a>
        </div>
      </div>

    <?php elseif ($tab === 'venue'): ?>
      <!-- ====================================================================
           MODULE 2: QUẢN LÝ THÔNG TIN CỤM SÂN, TIỆN ÍCH & BẢN ĐỒ LEAFLET
           ==================================================================== -->
      <?php if (!$curVenue): ?>
        <div class="box"><div class="empty">Chưa có Cụm sân nào được khởi tạo.</div></div>
      <?php else:
          $curAmenities = array_filter(array_map('trim', explode(',', (string)$curVenue['amenities'])));
          $curVenueImgs = json_decode((string)$curVenue['images'], true) ?: [];
          $firstVenueImg = $curVenueImgs[0] ?? '';
      ?>
        <div class="box" style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-left:5px solid <?=$curVenue['status'] === 'ACTIVE' ? '#16a34a' : '#f59e0b'?>">
          <div>
            <span class="tag <?=$curVenue['status'] === 'ACTIVE' ? 'green' : 'amber'?>">
              <?=$curVenue['status'] === 'ACTIVE' ? '🟢 ĐANG MỞ BÁN (ACTIVE)' : '🟠 ĐANG TẠM NGƯNG (INACTIVE)'?>
            </span>
            <h3 style="margin:6px 0 2px;font-size:19px;color:#0f172a"><?=e($curVenue['name'])?></h3>
            <small style="margin:0;color:#64748b">📍 <?=e($curVenue['address'])?> (<?=e($curVenue['district'])?>)</small>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn bl" onclick="var b=document.getElementById('newVenueFormBox');if(b)b.style.display=(b.style.display==='none'?'block':'none')">
              ➕ Mở thêm Cụm sân / Chi nhánh mới
            </button>
            <form method="post" class="inl">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="toggle_venue_status">
              <input type="hidden" name="venue_id" value="<?=$curVenue['id']?>">
              <button type="submit" class="btn <?=$curVenue['status'] === 'ACTIVE' ? 'red' : ''?>">
                <?=$curVenue['status'] === 'ACTIVE' ? '⏸ Tạm ngưng mở bán Cụm sân' : '▶️ Kích hoạt Mở bán Cụm sân'?>
              </button>
            </form>
          </div>
        </div>

        <!-- Khung Tạo Cụm Sân / Chi Nhánh Mới (Ẩn mặc định, bấm nút + Mở thêm Cụm sân để hiện) -->
        <div class="box" id="newVenueFormBox" style="display:none;margin-bottom:18px;border:2px dashed #2563eb;background:#f8fafc">
          <div class="bh">
            <b>➕ Khởi Tạo Cụm Sân / Chi Nhánh Sân Bóng Mới</b>
            <button type="button" class="btn sm gray" onclick="document.getElementById('newVenueFormBox').style.display='none'">✕ Đóng</button>
          </div>
          <form method="post" enctype="multipart/form-data" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="create_new_venue">
            <div class="two" style="margin:0">
              <div>
                <label>Tên Cụm sân mới *</label>
                <input name="name" required placeholder="VD: Cụm Sân Bóng Đá Chảo Lửa Cơ Sở 2">
              </div>
              <div>
                <label>Quận / Khu vực *</label>
                <input name="district" required value="<?=e($curVenue['district'])?>">
              </div>
            </div>
            <div class="two" style="margin:0">
              <div>
                <label>Địa chỉ chi tiết *</label>
                <input name="address" required placeholder="Nhập địa chỉ cụm sân mới">
              </div>
              <div>
                <label>SĐT Hotline Cụm sân *</label>
                <input name="contact_phone" required value="<?=e($u['phone'])?>">
              </div>
            </div>
            <div class="two" style="margin:0">
              <div>
                <label>Giá thuê Giờ thường mặc định (VNĐ/giờ)</label>
                <input type="number" name="price_per_hour" step="10000" value="180000" required>
              </div>
              <div>
                <label>Giá thuê Giờ vàng mặc định (VNĐ/giờ)</label>
                <input type="number" name="peak_price" step="10000" value="300000" required>
              </div>
            </div>
            <label>Ảnh đại diện Cụm sân mới (Tải file hoặc dán URL)</label>
            <input type="file" name="venue_image_file" accept="image/*">
            <input name="venue_image_url" placeholder="Hoặc dán URL ảnh sân bóng...">
            <label>Mô tả giới thiệu Cụm sân</label>
            <textarea name="description" rows="2">Cụm sân cỏ nhân tạo tiêu chuẩn FIFA, đèn LED chống chói, bãi giữ xe miễn phí.</textarea>
            <button type="submit" class="btn bl" style="margin-top:12px;width:100%">🚀 TẠO CỤM SÂN MỚI & MỞ TRÌNH TÙY CHỈNH SÂN</button>
          </form>
        </div>

        <div class="box">
          <h3 style="margin:0 0 16px;font-size:18px;color:#0f172a">📍 Cài đặt Thông tin Cụm sân, Tiện ích & Vị trí Bản đồ</h3>
          <form method="post" enctype="multipart/form-data" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="save_venue_settings">
            <input type="hidden" name="venue_id" value="<?=$curVenue['id']?>">

            <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:20px;align-items:start">
              <div>
                <label>Tên Cụm sân bóng *</label>
                <input name="name" required value="<?=e($curVenue['name'])?>">

                <div class="two" style="margin:0">
                  <div>
                    <label>Quận / Khu vực *</label>
                    <input name="district" required value="<?=e($curVenue['district'])?>">
                  </div>
                  <div>
                    <label>SĐT Hotline Cụm sân *</label>
                    <input name="contact_phone" required value="<?=e($curVenue['contact_phone'] ?: $u['phone'])?>">
                  </div>
                </div>

                <label>Địa chỉ chi tiết *</label>
                <input name="address" required value="<?=e($curVenue['address'])?>">

                <div class="two" style="margin:0">
                  <div>
                    <label>Giờ mở cửa (Open Time)</label>
                    <input type="time" name="open_time" value="<?=substr($curVenue['open_time'], 0, 5)?>">
                  </div>
                  <div>
                    <label>Giờ đóng cửa (Close Time)</label>
                    <input type="time" name="close_time" value="<?=substr($curVenue['close_time'], 0, 5)?>">
                  </div>
                </div>

                <label>Trạng thái Cụm sân</label>
                <select name="status">
                  <option value="ACTIVE" <?=$curVenue['status'] === 'ACTIVE' ? 'selected' : ''?>>🟢 ACTIVE — Đang mở bán trực tuyến</option>
                  <option value="INACTIVE" <?=$curVenue['status'] === 'INACTIVE' ? 'selected' : ''?>>🟠 INACTIVE — Tạm ngưng nhận khách</option>
                  <option value="DRAFT" <?=$curVenue['status'] === 'DRAFT' ? 'selected' : ''?>>⚪ DRAFT — Bản nháp</option>
                </select>

                <label>Tiện ích tại Cụm sân (Amenities)</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:12px">
                  <?php foreach (venue_amenity_catalog() as $amKey => $amLabel): ?>
                    <label style="display:flex;align-items:center;gap:8px;margin:0;font-weight:600;font-size:13px;cursor:pointer">
                      <input type="checkbox" name="amenities[]" value="<?=$amKey?>" <?=in_array($amKey, $curAmenities, true) ? 'checked' : ''?> style="width:16px;height:16px;margin:0">
                      <span><?=$amLabel?></span>
                    </label>
                  <?php endforeach; ?>
                </div>

                <label>Ảnh thực tế Cụm sân (Tải file mới hoặc dán URL)</label>
                <input type="file" name="venue_image_file" accept="image/*">
                <input name="venue_image_url" value="<?=e($firstVenueImg)?>" placeholder="Hoặc URL ảnh thực tế sân bóng...">

                <label>Mô tả giới thiệu Cụm sân</label>
                <textarea name="description" rows="3"><?=e($curVenue['description'])?></textarea>
              </div>

              <!-- Cột phải: Bản đồ Leaflet kéo thả ghim cập nhật Latitude / Longitude + Chế độ Vệ tinh Mặt cỏ -->
              <div>
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                  <label style="margin:0">🗺 Vị trí Chuẩn Cụm sân (Kéo thả ghim hoặc chọn chuẩn)</label>
                  <div style="display:flex;gap:5px">
                    <button type="button" class="btn sm" id="btnVenueMapSat" onclick="setOwnerVenueMapMode('sat')" style="padding:4px 9px;font-size:11px;background:#0f172a">🛰 Vệ tinh sân cỏ</button>
                    <button type="button" class="btn sm outline" id="btnVenueMapStreet" onclick="setOwnerVenueMapMode('street')" style="padding:4px 9px;font-size:11px">🗺 Đường phố</button>
                    <button type="button" class="btn sm bl" onclick="locateOwnerVenueCurrentGps()" style="padding:4px 9px;font-size:11px">📍 GPS của tôi</button>
                  </div>
                </div>

                <?php $presetCatalogVen = function_exists('get_verified_hcmc_pitches_catalog') ? get_verified_hcmc_pitches_catalog() : []; ?>
                <select onchange="applyVenuePresetLocation(this)" style="margin-bottom:8px;padding:7px 10px;border-radius:8px;border:1px solid #86efac;background:#f0fdf4;font-size:12px;font-weight:700;color:#15803d;width:100%">
                  <option value="">📌 Chọn nhanh tọa độ chuẩn từ 10 Cụm Sân Thực Tế TP.HCM...</option>
                  <?php foreach ($presetCatalogVen as $pcv): ?>
                    <option value="<?=e(json_encode(['lat'=>$pcv['lat'],'lng'=>$pcv['lng'],'addr'=>$pcv['address'],'dist'=>$pcv['district']], JSON_UNESCAPED_UNICODE))?>">
                      ⚽ <?=e($pcv['name'])?> — <?=e($pcv['district'])?> (<?=number_format($pcv['lat'], 5)?>, <?=number_format($pcv['lng'], 5)?>)
                    </option>
                  <?php endforeach; ?>
                </select>

                <div id="ownerVenueMap" style="width:100%;height:330px;border-radius:14px;border:2px solid #16a34a;margin-bottom:12px"></div>
                <div class="two" style="margin:0">
                  <div>
                    <label>Vĩ độ (Latitude)</label>
                    <input name="latitude" id="inpOwnerLat" value="<?=e($curVenue['latitude'])?>" required oninput="syncVenuePinFromInputs()">
                  </div>
                  <div>
                    <label>Kinh độ (Longitude)</label>
                    <input name="longitude" id="inpOwnerLng" value="<?=e($curVenue['longitude'])?>" required oninput="syncVenuePinFromInputs()">
                  </div>
                </div>
              </div>
            </div>

            <button type="submit" class="btn" style="margin-top:14px;width:100%;padding:13px;font-weight:800">
              💾 LƯU CÀI ĐẶT CỤM SÂN & ĐỒNG BỘ TỌA ĐỘ BẢN ĐỒ
            </button>
          </form>
        </div>

        <script>
          var ownerVenueMapInstance = null;
          var ownerVenueMarker = null;
          var ownerVenueLayers = {};

          document.addEventListener('DOMContentLoaded', function () {
            var mapEl = document.getElementById('ownerVenueMap');
            if (!mapEl || typeof L === 'undefined') return;
            var lat = parseFloat(document.getElementById('inpOwnerLat').value) || 10.8058333;
            var lng = parseFloat(document.getElementById('inpOwnerLng').value) || 106.6583333;
            ownerVenueMapInstance = L.map('ownerVenueMap').setView([lat, lng], 18);
            ownerVenueLayers.sat = L.tileLayer('https://{s}.google.com/vt/lyrs=y&hl=vi&x={x}&y={y}&z={z}', {
              maxZoom: 20,
              subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
              attribution: '© Google Satellite Hybrid'
            });
            ownerVenueLayers.street = L.tileLayer('https://{s}.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}', {
              maxZoom: 20,
              subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
              attribution: '© Google Maps'
            });
            ownerVenueLayers.sat.addTo(ownerVenueMapInstance);

            ownerVenueMarker = L.marker([lat, lng], { draggable: true }).addTo(ownerVenueMapInstance)
              .bindPopup('<b>📍 Tâm Cụm sân của bạn</b><br>Kéo thả ghim hoặc click lên thảm cỏ vệ tinh để đặt tọa độ chuẩn').openPopup();
            ownerVenueMarker.on('dragend', function (e) {
              var p = e.target.getLatLng();
              document.getElementById('inpOwnerLat').value = p.lat.toFixed(7);
              document.getElementById('inpOwnerLng').value = p.lng.toFixed(7);
            });
            ownerVenueMapInstance.on('click', function (e) {
              ownerVenueMarker.setLatLng(e.latlng);
              document.getElementById('inpOwnerLat').value = e.latlng.lat.toFixed(7);
              document.getElementById('inpOwnerLng').value = e.latlng.lng.toFixed(7);
            });
          });

          function setOwnerVenueMapMode(mode) {
            if (!ownerVenueMapInstance) return;
            if (mode === 'sat') {
              ownerVenueMapInstance.removeLayer(ownerVenueLayers.street);
              ownerVenueLayers.sat.addTo(ownerVenueMapInstance);
            } else {
              ownerVenueMapInstance.removeLayer(ownerVenueLayers.sat);
              ownerVenueLayers.street.addTo(ownerVenueMapInstance);
            }
          }

          function syncVenuePinFromInputs() {
            if (!ownerVenueMapInstance || !ownerVenueMarker) return;
            var lat = parseFloat(document.getElementById('inpOwnerLat').value);
            var lng = parseFloat(document.getElementById('inpOwnerLng').value);
            if (!isNaN(lat) && !isNaN(lng)) {
              ownerVenueMarker.setLatLng([lat, lng]);
              ownerVenueMapInstance.panTo([lat, lng]);
            }
          }

          function applyVenuePresetLocation(sel) {
            if (!sel || !sel.value) return;
            try {
              var d = JSON.parse(sel.value);
              document.getElementById('inpOwnerLat').value = Number(d.lat).toFixed(7);
              document.getElementById('inpOwnerLng').value = Number(d.lng).toFixed(7);
              if (ownerVenueMarker && ownerVenueMapInstance) {
                ownerVenueMarker.setLatLng([d.lat, d.lng]);
                ownerVenueMapInstance.flyTo([d.lat, d.lng], 18, { duration: 0.45 });
              }
            } catch (e) {}
          }

          function locateOwnerVenueCurrentGps() {
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition(function (pos) {
              var lat = pos.coords.latitude;
              var lng = pos.coords.longitude;
              document.getElementById('inpOwnerLat').value = lat.toFixed(7);
              document.getElementById('inpOwnerLng').value = lng.toFixed(7);
              if (ownerVenueMarker && ownerVenueMapInstance) {
                ownerVenueMarker.setLatLng([lat, lng]);
                ownerVenueMapInstance.flyTo([lat, lng], 18);
              }
            });
          }
        </script>
      <?php endif; ?>

    <?php elseif ($tab === 'pitches'): ?>
      <!-- ====================================================================
           MODULE 3: STUDIO QUẢN LÝ & TÙY CHỈNH CHI TIẾT SÂN BÓNG CỦA CHỦ SÂN
           (Tùy chỉnh Ảnh mặt sân, Tên, Mã sân, Loại 5/7/11, Khóa chéo Cha-Con,
            Giá thường/vàng, Cơ cấu sân, Địa chỉ, SĐT, Tọa độ GPS & Mô tả chi tiết)
           ==================================================================== -->
      <?php
      $editPitchId  = (int)($_GET['edit_pitch'] ?? 0);
      $isNewPitch   = !empty($_GET['new_pitch']);
      $editPitchRow = $editPitchId ? owner_get_field($u, $editPitchId) : null;
      if (!$editPitchRow && !$isNewPitch && !empty($venueFields)) {
          // Mặc định nạp sân đầu tiên trong cụm vào khung Tùy chỉnh chi tiết để Chủ sân sửa được ngay!
          $editPitchRow = $venueFields[0];
          $editPitchId  = (int)$editPitchRow['id'];
      }
      ?>

      <div class="partner-hero-banner" style="margin-bottom:18px">
        <div>
          <h2>🏟 Quản Lý & Tùy Chỉnh Chi Tiết Sân Bóng Của Bạn</h2>
          <small>
            Đang chọn Cụm sân: <b><?=e($curVenue['name'] ?? '')?></b> (<?=count($venueFields)?> sân) •
            Mọi thay đổi về <b>Tên sân, Ảnh mặt cỏ, Giá giờ thường/vàng, Cơ cấu sân, Địa chỉ & Mô tả</b> sẽ cập nhật tức thì trên Trang chủ & Trang Đặt sân!
          </small>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a href="?tab=pitches&venue_id=<?=$selectedVenueId?>&new_pitch=1#pitchEditorBox" class="btn sm" style="background:#fde047;color:#052e16 !important;font-weight:800">
            ➕ Thêm Sân Mới Vào Cụm
          </a>
          <form method="post" class="inl" onsubmit="return confirm('Tự động tạo bộ sân thành phần (5A, 5B, 5C / 7A) liên kết khóa chéo với sân chính của Cụm sân này?')">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="quick_generate_cluster_pitches">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
            <button type="submit" class="btn sm outline" style="color:#fff !important;border-color:#86efac">
              ⚡ Tạo nhanh Sơ đồ Sân con (5A, 5B, 5C)
            </button>
          </form>
        </div>
      </div>

      <!-- PHẦN 1: DANH SÁCH THẺ SÂN BÓNG TRỰC QUAN TRONG CỤM SÂN -->
      <div class="box" style="margin-bottom:20px">
        <div class="bh" style="flex-wrap:wrap;gap:10px">
          <div>
            <b>⚽ Danh Sách Các Sân Trong Cụm (<?=count($venueFields)?> sân) — Bấm "✏️ Tùy chỉnh sân" để sửa chi tiết</b>
            <small style="margin:2px 0 0;color:#64748b">
              🔗 Hỗ trợ khóa lịch chéo tự động: Khi khách đặt Sân 11 hoặc Sân 7 Cha, các Sân 5 Con thuộc sân đó sẽ tự động khóa lịch và ngược lại.
            </small>
          </div>
          <?php if (count($myVenues) > 1): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php foreach ($myVenues as $mvBtn): ?>
                <a href="?tab=pitches&venue_id=<?=$mvBtn['id']?>"
                   class="btn sm <?=(int)$mvBtn['id'] === $selectedVenueId ? '' : 'outline'?>">
                  🏟 <?=e($mvBtn['name'])?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$venueFields): ?>
          <div class="empty">Chưa có sân nào trong cụm sân này. Hãy sử dụng biểu mẫu bên dưới để thêm sân!</div>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px">
            <?php foreach ($venueFields as $vf):
                $isEditingThis = ($editPitchRow && (int)$editPitchRow['id'] === (int)$vf['id'] && !$isNewPitch);
            ?>
              <div style="background:#ffffff;border:2px solid <?=$isEditingThis ? '#16a34a' : '#e2e8f0'?>;border-radius:16px;overflow:hidden;box-shadow:<?=$isEditingThis ? '0 10px 26px rgba(22,163,74,0.16)' : '0 4px 12px rgba(15,23,42,0.04)'?>;display:flex;flex-direction:column">
                <div style="position:relative;height:148px;background:#0f172a">
                  <img src="<?=e(img_src($vf['image']))?>" alt="<?=e($vf['name'])?>" style="width:100%;height:100%;object-fit:cover">
                  <div style="position:absolute;top:10px;left:10px;display:flex;gap:6px;flex-wrap:wrap">
                    <span style="background:#0f172a;color:#4ade80;font-family:var(--font-sport);font-size:11.5px;font-weight:800;padding:3px 9px;border-radius:6px">
                      [<?=e($vf['pitch_code'] ?: ('S' . $vf['type']))?>] SÂN <?=$vf['type']?> NGƯỜI
                    </span>
                    <?php if (!empty($vf['parent_id'])): ?>
                      <span style="background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;border:1px solid #93c5fd">
                        🔗 Thuộc <?=e($vf['parent_code'] ?: '')?> <?=e($vf['parent_name'])?>
                      </span>
                    <?php else: ?>
                      <span style="background:#ecfdf5;color:#065f46;font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;border:1px solid #6ee7b7">
                        🏟 Sân chính / Sân gốc
                      </span>
                    <?php endif; ?>
                  </div>

                  <form method="post" style="position:absolute;top:10px;right:10px;margin:0">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="toggle_pitch_maintenance">
                    <input type="hidden" name="field_id" value="<?=$vf['id']?>">
                    <button type="submit" title="Bấm để đổi nhanh trạng thái Mở bán / Bảo trì" style="border:0;cursor:pointer;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:800;background:<?=$vf['status'] === 'active' ? '#16a34a' : '#f59e0b'?>;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.25)">
                      <?=$vf['status'] === 'active' ? '🟢 Đang mở' : '🛠️ Bảo trì'?>
                    </button>
                  </form>
                </div>

                <div style="padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:6px">
                  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                    <b style="font-size:15.5px;color:#0f172a;line-height:1.3"><?=e($vf['name'])?></b>
                    <?php if ($isEditingThis): ?>
                      <span class="tag green" style="font-size:10.5px;white-space:nowrap">Đang chọn sửa</span>
                    <?php endif; ?>
                  </div>

                  <small style="margin:0;color:#475569;font-size:12px">📍 <?=e($vf['address'])?> (<?=e($vf['district'])?>)</small>
                  <small style="margin:0;color:#15803d;font-weight:700;font-size:12px">🏟 <?=e($vf['sub_pitches'] ?? 'Sân tiêu chuẩn FIFA')?></small>

                  <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;margin-top:4px;font-size:12.5px">
                    <div>
                      <span style="color:#64748b">☀️ Giờ thường:</span>
                      <b style="color:#16a34a"><?=money($vf['price_per_hour'])?>/h</b>
                    </div>
                    <div>
                      <span style="color:#64748b">🔥 Giờ vàng:</span>
                      <b style="color:#d97706"><?=money($vf['peak_price'] ?? ($vf['price_per_hour'] + 120000))?>/h</b>
                    </div>
                  </div>

                  <div style="margin-top:auto;padding-top:10px;display:flex;gap:6px;flex-wrap:wrap">
                    <a class="btn sm" style="flex:1" href="?tab=pitches&venue_id=<?=$selectedVenueId?>&edit_pitch=<?=$vf['id']?>#pitchEditorBox">
                      ✏️ Tùy chỉnh sân
                    </a>
                    <a class="btn sm bl" href="?tab=pricing&venue_id=<?=$selectedVenueId?>&field_id=<?=$vf['id']?>">
                      💰 Khung giá
                    </a>
                    <a class="btn sm outline" href="field-detail.php?id=<?=$vf['id']?>" target="_blank" title="Xem giao diện chi tiết sân như khách hàng">
                      🌐 Xem
                    </a>
                    <form method="post" class="inl" onsubmit="return confirm('Xác nhận xóa sân này khỏi cụm sân?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="delete_sub_pitch">
                      <input type="hidden" name="field_id" value="<?=$vf['id']?>">
                      <button type="submit" class="btn sm red" title="Xóa sân">🗑️</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- PHẦN 2: STUDIO TÙY CHỈNH CHI TIẾT SÂN BÓNG (ĐẦY ĐỦ 4 NHÓM THUỘC TÍNH + XEM TRƯỚC ẢNH) -->
      <div class="box" id="pitchEditorBox" style="border-top:4px solid #16a34a">
        <div class="bh" style="border-bottom:1.5px solid #f1f5f9;padding-bottom:12px;margin-bottom:18px">
          <div>
            <b style="font-size:18px;color:#0f172a">
              <?=$editPitchRow ? '🛠️ Tùy Chỉnh Chi Tiết Sân: ' . e($editPitchRow['name']) . ' (Mã #' . $editPitchRow['id'] . ')' : '➕ Thêm Sân Bóng Mới Vào Cụm: ' . e($curVenue['name'] ?? '')?>
            </b>
            <small style="margin:3px 0 0;color:#64748b">
              Chỉnh sửa đầy đủ Tên sân, Ảnh đại diện sân, Loại sân 5/7/11, Liên kết gộp sân Cha-Con, Bảng giá, Cơ cấu sân, Địa chỉ, Hotline và Mô tả giới thiệu
            </small>
          </div>
          <?php if ($editPitchRow): ?>
            <div style="display:flex;gap:8px">
              <a href="field-detail.php?id=<?=$editPitchRow['id']?>" target="_blank" class="btn sm outline">🌐 Xem trang sân thực tế ↗</a>
              <a href="?tab=pitches&venue_id=<?=$selectedVenueId?>&new_pitch=1#pitchEditorBox" class="btn sm bl">+ Thêm sân khác</a>
            </div>
          <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="form" style="max-width:100%">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="save_sub_pitch">
          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
          <input type="hidden" name="pitch_id" value="<?=(int)($editPitchRow['id'] ?? 0)?>">

          <div style="display:grid;grid-template-columns:1.25fr 0.95fr;gap:22px;align-items:start">
            <!-- CỘT TRÁI: THÔNG TIN ĐỊNH DANH, GIÁ THUÊ, ĐỊA CHỈ & MÔ TẢ CHI TIẾT -->
            <div>
              <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-weight:800;font-size:12.5px;padding:6px 12px;border-radius:8px;margin-bottom:12px">
                1️⃣ THÔNG TIN ĐỊNH DANH SÂN & LIÊN KẾT GHÉP/TÁCH SÂN (5 / 7 / 11)
              </div>

              <label style="margin-top:0">Tên sân bóng hiển thị trên hệ thống *</label>
              <input name="name" required value="<?=e($editPitchRow['name'] ?? (($curVenue['name'] ?? 'Sân Bóng') . ' - Sân 5A'))?>" placeholder="VD: Sân Banh Chảo Lửa (Hoặc Sân 5A - Chảo Lửa)">

              <div style="display:grid;grid-template-columns:0.8fr 1.1fr 1.1fr;gap:12px">
                <div>
                  <label>Mã sân (`pitch_code`)</label>
                  <input name="pitch_code" value="<?=e($editPitchRow['pitch_code'] ?? '5A')?>" placeholder="VD: 5A, 7A, 11A">
                </div>
                <div>
                  <label>Loại sân (`type`)</label>
                  <select name="type">
                    <option value="5" <?=($editPitchRow['type'] ?? '5') === '5' ? 'selected' : ''?>>⚽ Sân 5 người (5v5)</option>
                    <option value="7" <?=($editPitchRow['type'] ?? '') === '7' ? 'selected' : ''?>>⚽ Sân 7 người (7v7)</option>
                    <option value="11" <?=($editPitchRow['type'] ?? '') === '11' ? 'selected' : ''?>>🏟 Sân 11 người (11v11)</option>
                  </select>
                </div>
                <div>
                  <label>Trạng thái mở bán</label>
                  <select name="status">
                    <option value="active" <?=($editPitchRow['status'] ?? 'active') === 'active' ? 'selected' : ''?>>🟢 Hoạt động (Mở đặt lịch)</option>
                    <option value="maintenance" <?=($editPitchRow['status'] ?? '') === 'maintenance' ? 'selected' : ''?>>🛠️ Đang bảo trì (Tạm khóa)</option>
                  </select>
                </div>
              </div>

              <label>🔗 Liên kết Sân Cha gộp sân (Để tự động khóa chéo khi đặt Sân 7 / Sân 11 lớn)</label>
              <select name="parent_id">
                <option value="">-- Không thuộc sân nào (Sân chính độc lập hoặc Đại sân 11 người) --</option>
                <?php foreach ($venueFields as $pOpt): ?>
                  <?php if ((int)$pOpt['id'] !== (int)($editPitchRow['id'] ?? 0) && in_array($pOpt['type'], ['7', '11'], true)): ?>
                    <option value="<?=$pOpt['id']?>" <?=($editPitchRow['parent_id'] ?? null) == $pOpt['id'] ? 'selected' : ''?>>
                      🏟 [Sân <?=$pOpt['type']?> người - <?=e($pOpt['pitch_code'] ?: ('#' . $pOpt['id']))?>] <?=e($pOpt['name'])?>
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>

              <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-weight:800;font-size:12.5px;padding:6px 12px;border-radius:8px;margin:18px 0 12px">
                2️⃣ BẢNG GIÁ CƠ SỞ & QUY MÔ SÂN CON HIỂN THỊ
              </div>

              <div class="two" style="margin:0">
                <div>
                  <label style="margin-top:0">☀️ Giá thuê Giờ thường / giờ (VNĐ) *</label>
                  <input type="number" name="price_per_hour" step="10000" min="50000" value="<?=(int)($editPitchRow['price_per_hour'] ?? 180000)?>" required>
                </div>
                <div>
                  <label style="margin-top:0">🔥 Giá thuê Giờ vàng 16h-21h / giờ (VNĐ) *</label>
                  <input type="number" name="peak_price" step="10000" min="50000" value="<?=(int)($editPitchRow['peak_price'] ?? 300000)?>" required>
                </div>
              </div>

              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:#eff6ff;padding:9px 12px;border-radius:8px;border:1px solid #93c5fd;margin-top:10px">
                <input type="checkbox" name="sync_pricing_rules" value="1" checked style="width:16px;height:16px;margin:0">
                <span style="font-size:12.5px;color:#1e3a8a;font-weight:700">
                  ⚡ Tự động cập nhật luôn 5 khung giá động (Sáng, Giờ Vàng, Tối, Cuối tuần) theo mức giá mới này
                </span>
              </label>

              <label>🏟 Thông tin Quy mô / Cơ cấu sân hiển thị với khách hàng (`sub_pitches`)</label>
              <input name="sub_pitches" value="<?=e($editPitchRow['sub_pitches'] ?? '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)')?>" placeholder="VD: 6 sân 5 người, 2 sân 7 người liên hoàn (Có mái che)">

              <label>📝 Mô tả chi tiết mặt cỏ, đèn chiếu sáng & tiện ích của sân (`description`)</label>
              <textarea name="description" rows="4" placeholder="Mô tả chi tiết về chất lượng cỏ nhân tạo, đèn LED, khu vực căng-tin, bãi giữ xe..."><?=e($editPitchRow['description'] ?? ($curVenue['description'] ?? ''))?></textarea>
            </div>

            <!-- CỘT PHẢI: HÌNH ẢNH MẶT SÂN (UPLOAD + LIVE PREVIEW) & ĐỊA CHỈ / TỌA ĐỘ GPS -->
            <div>
              <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-weight:800;font-size:12.5px;padding:6px 12px;border-radius:8px;margin-bottom:12px">
                3️⃣ HÌNH ẢNH MẶT SÂN BÓNG (TẢI ẢNH MỚI HOẶC DÁN LINK ẢNH)
              </div>

              <?php $curPitchImg = $editPitchRow['image'] ?? 'assets/images/field-1.jpg'; ?>
              <div style="height:195px;border-radius:14px;overflow:hidden;border:2px solid #86efac;background:#0f172a;margin-bottom:12px;position:relative">
                <img id="pitchLivePreviewImg" src="<?=e(img_src($curPitchImg))?>" alt="Preview" style="width:100%;height:100%;object-fit:cover">
                <span style="position:absolute;bottom:10px;right:10px;background:rgba(15,23,42,0.85);color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px">
                  📸 Xem trước ảnh sân
                </span>
              </div>

              <label style="margin-top:0">📂 Tải ảnh mặt sân từ máy tính (JPG, PNG, WEBP)</label>
              <input type="file" name="pitch_image_file" accept="image/*" onchange="previewPitchFile(this)">

              <label>🔗 Hoặc dán đường dẫn URL ảnh sân bóng</label>
              <input name="pitch_image_url" id="inpPitchImgUrl" value="<?=e($curPitchImg)?>" placeholder="https://... hoặc assets/images/field-1.jpg" oninput="previewPitchUrl(this.value)">

              <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;font-weight:800;font-size:12.5px;padding:6px 12px;border-radius:8px;margin:18px 0 12px">
                4️⃣ ĐỊA CHỈ, HOTLINE & BẢN ĐỒ ĐỊNH VỊ CHUẨN TỪNG SÂN (VỆ TINH MẶT CỎ)
              </div>

              <?php
              $presetCatalogPitch = function_exists('get_verified_hcmc_pitches_catalog') ? get_verified_hcmc_pitches_catalog() : [];
              $venueCenterLat = (float)($curVenue['latitude'] ?? 10.8058333) ?: 10.8058333;
              $venueCenterLng = (float)($curVenue['longitude'] ?? 106.6583333) ?: 106.6583333;
              $curPitchLat    = (float)($editPitchRow['latitude'] ?? $venueCenterLat) ?: $venueCenterLat;
              $curPitchLng    = (float)($editPitchRow['longitude'] ?? $venueCenterLng) ?: $venueCenterLng;
              $siblingMapPins = [];
              foreach ($venueFields as $svf) {
                  $siblingMapPins[] = [
                      'id'      => (int)$svf['id'],
                      'name'    => $svf['name'],
                      'code'    => $svf['pitch_code'] ?: ('S' . $svf['type']),
                      'type'    => $svf['type'],
                      'lat'     => (float)($svf['latitude'] ?? $venueCenterLat),
                      'lng'     => (float)($svf['longitude'] ?? $venueCenterLng),
                      'current' => ($editPitchRow && (int)$editPitchRow['id'] === (int)$svf['id'] && !$isNewPitch),
                  ];
              }
              ?>

              <label style="margin-top:0">📌 Chọn nhanh vị trí chuẩn 10 Cụm Sân Thực Tế TP.HCM (Tự điền Địa chỉ + Tọa độ + Place ID)</label>
              <select onchange="applyPitchPresetLocation(this)" style="padding:8px 10px;border-radius:8px;border:1px solid #86efac;background:#f0fdf4;font-size:12px;font-weight:700;color:#15803d;width:100%;margin-bottom:8px">
                <option value="">-- Hoặc chọn nhanh từ 10 Cụm Sân Chuẩn TP.HCM --</option>
                <?php foreach ($presetCatalogPitch as $pcp): ?>
                  <option value="<?=e(json_encode(['lat'=>$pcp['lat'],'lng'=>$pcp['lng'],'addr'=>$pcp['address'],'dist'=>$pcp['district'],'phone'=>$pcp['phone'],'gmap'=>$pcp['gmap']], JSON_UNESCAPED_UNICODE))?>">
                    ⚽ <?=e($pcp['name'])?> (<?=e($pcp['district'])?> • <?=number_format($pcp['lat'], 5)?>, <?=number_format($pcp['lng'], 5)?>)
                  </option>
                <?php endforeach; ?>
              </select>

              <!-- Hộp Bản đồ Định vị Kéo-Thả Từng Sân trên nền Vệ tinh Mặt cỏ -->
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;margin-bottom:6px">
                <small style="margin:0;color:#0f172a;font-weight:700">🛰 Kéo thả ghim đỏ hoặc click lên mặt cỏ vệ tinh để đặt chuẩn vị trí sân:</small>
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                  <button type="button" class="btn sm" style="padding:3px 8px;font-size:11px;background:#0f172a" onclick="setPitchEditorMapMode('sat')">🛰 Vệ tinh</button>
                  <button type="button" class="btn sm outline" style="padding:3px 8px;font-size:11px" onclick="setPitchEditorMapMode('street')">🗺 Đường phố</button>
                  <button type="button" class="btn sm bl" style="padding:3px 8px;font-size:11px" onclick="alignPitchToVenueGrid()" title="Tự động căn vị trí sân con (5A, 5B, 7A) trên mặt cỏ Cụm sân">🎯 Căn theo Mã sân</button>
                </div>
              </div>
              <div id="pitchEditorLeafletMap" style="width:100%;height:235px;border-radius:12px;border:2px solid #16a34a;margin-bottom:10px;z-index:1"></div>

              <label style="margin-top:0">📍 Địa chỉ hiển thị của sân *</label>
              <input name="address" id="inpPitchAddr" required value="<?=e($editPitchRow['address'] ?? ($curVenue['address'] ?? ''))?>">

              <div class="two" style="margin:0">
                <div>
                  <label>Quận / Khu vực *</label>
                  <input name="district" id="inpPitchDist" required value="<?=e($editPitchRow['district'] ?? ($curVenue['district'] ?? ''))?>">
                </div>
                <div>
                  <label>📞 SĐT Hotline Ban quản lý sân *</label>
                  <input name="contact_phone" id="inpPitchPhone" required value="<?=e($editPitchRow['contact_phone'] ?? ($curVenue['contact_phone'] ?? $u['phone']))?>">
                </div>
              </div>

              <div class="two" style="margin:0">
                <div>
                  <label>🌐 Vĩ độ GPS (Latitude)</label>
                  <input type="number" step="0.0000001" name="latitude" id="inpPitchLat" value="<?=e(number_format($curPitchLat, 7, '.', ''))?>" oninput="syncPitchEditorPinFromInputs()">
                </div>
                <div>
                  <label>🌐 Kinh độ GPS (Longitude)</label>
                  <input type="number" step="0.0000001" name="longitude" id="inpPitchLng" value="<?=e(number_format($curPitchLng, 7, '.', ''))?>" oninput="syncPitchEditorPinFromInputs()">
                </div>
              </div>

              <label>🧭 Link Google Maps chỉ đường chuẩn (Tự động cập nhật khi kéo ghim)</label>
              <input name="google_maps_url" id="inpPitchGmap" value="<?=e($editPitchRow['google_maps_url'] ?? '')?>" placeholder="https://www.google.com/maps/dir/?api=1&destination=...">
            </div>
          </div>

          <div style="margin-top:20px;padding-top:16px;border-top:1.5px solid #e2e8f0;display:flex;justify-content:flex-end;gap:12px">
            <?php if ($isNewPitch): ?>
              <a href="?tab=pitches&venue_id=<?=$selectedVenueId?>" class="btn gray">Hủy thêm mới</a>
            <?php endif; ?>
            <button type="submit" class="btn" style="padding:13px 28px;font-size:15px;font-weight:800">
              💾 <?=$editPitchRow ? 'LƯU TOÀN BỘ TÙY CHỈNH SÂN BÓNG' : 'THÊM SÂN BÓNG MỚI VÀO CỤM'?>
            </button>
          </div>
        </form>
      </div>

      <script>
        function previewPitchFile(input) {
          if (!input || !input.files || !input.files[0]) return;
          var reader = new FileReader();
          reader.onload = function(e) {
            var img = document.getElementById('pitchLivePreviewImg');
            if (img) img.src = e.target.result;
          };
          reader.readAsDataURL(input.files[0]);
        }
        function previewPitchUrl(url) {
          var img = document.getElementById('pitchLivePreviewImg');
          if (!img || !url) return;
          img.src = /^https?:\/\//i.test(url) ? url : ('<?=project_base()?>' + url.replace(/^\/+/, ''));
        }

        var pitchEditorMap = null;
        var pitchEditorMarker = null;
        var pitchEditorLayers = {};
        var venueCenterCoords = { lat: <?=json_encode($venueCenterLat ?? 10.8058333)?>, lng: <?=json_encode($venueCenterLng ?? 106.6583333)?> };
        var siblingPinsData = <?=json_encode($siblingMapPins ?? [], JSON_UNESCAPED_UNICODE)?>;

        document.addEventListener('DOMContentLoaded', function () {
          var mapEl = document.getElementById('pitchEditorLeafletMap');
          if (!mapEl || typeof L === 'undefined') return;
          var lat = parseFloat(document.getElementById('inpPitchLat').value) || venueCenterCoords.lat;
          var lng = parseFloat(document.getElementById('inpPitchLng').value) || venueCenterCoords.lng;

          pitchEditorMap = L.map('pitchEditorLeafletMap').setView([lat, lng], 18);
          pitchEditorLayers.sat = L.tileLayer('https://{s}.google.com/vt/lyrs=y&hl=vi&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: '© Google Satellite Hybrid'
          });
          pitchEditorLayers.street = L.tileLayer('https://{s}.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: '© Google Maps'
          });
          pitchEditorLayers.sat.addTo(pitchEditorMap);

          // Hiển thị các sân anh em khác trong cùng Cụm sân để Chủ sân dễ căn vị trí sơ đồ mặt cỏ
          siblingPinsData.forEach(function (sp) {
            if (sp.current) return;
            var sibIcon = L.divIcon({
              className: 'sib-pitch-pin',
              html: '<div style="background:#1e293b;color:#93c5fd;padding:2px 7px;border-radius:999px;font-size:10.5px;font-weight:800;border:1.5px solid #60a5fa;white-space:nowrap;opacity:0.88">⚽ ' + sp.code + '</div>',
              iconSize: [56, 22],
              iconAnchor: [28, 11]
            });
            L.marker([sp.lat, sp.lng], { icon: sibIcon }).addTo(pitchEditorMap)
              .bindPopup('<b>[' + sp.code + '] ' + sp.name + '</b><br><small>Sân cùng cụm</small>');
          });

          var activeIcon = L.divIcon({
            className: 'active-pitch-drag-pin',
            html: '<div style="background:#dc2626;color:#fff;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;border:2.5px solid #fde047;box-shadow:0 0 0 5px rgba(220,38,38,0.35);white-space:nowrap">📍 Sân đang chọn</div>',
            iconSize: [110, 28],
            iconAnchor: [55, 14]
          });

          pitchEditorMarker = L.marker([lat, lng], { draggable: true, icon: activeIcon }).addTo(pitchEditorMap)
            .bindPopup('<b>🎯 Kéo thả ghim này tới đúng mặt sân cỏ</b>').openPopup();

          var updateInputsFromLatLng = function (newLat, newLng) {
            document.getElementById('inpPitchLat').value = newLat.toFixed(7);
            document.getElementById('inpPitchLng').value = newLng.toFixed(7);
            var gmapInp = document.getElementById('inpPitchGmap');
            if (gmapInp && (!gmapInp.value || gmapInp.value.indexOf('destination_place_id=') === -1)) {
              gmapInp.value = 'https://www.google.com/maps/dir/?api=1&destination=' + newLat.toFixed(7) + ',' + newLng.toFixed(7);
            }
          };

          pitchEditorMarker.on('dragend', function (e) {
            var p = e.target.getLatLng();
            updateInputsFromLatLng(p.lat, p.lng);
          });
          pitchEditorMap.on('click', function (e) {
            pitchEditorMarker.setLatLng(e.latlng);
            updateInputsFromLatLng(e.latlng.lat, e.latlng.lng);
          });
        });

        function setPitchEditorMapMode(mode) {
          if (!pitchEditorMap) return;
          if (mode === 'sat') {
            pitchEditorMap.removeLayer(pitchEditorLayers.street);
            pitchEditorLayers.sat.addTo(pitchEditorMap);
          } else {
            pitchEditorMap.removeLayer(pitchEditorLayers.sat);
            pitchEditorLayers.street.addTo(pitchEditorMap);
          }
        }

        function syncPitchEditorPinFromInputs() {
          if (!pitchEditorMap || !pitchEditorMarker) return;
          var lat = parseFloat(document.getElementById('inpPitchLat').value);
          var lng = parseFloat(document.getElementById('inpPitchLng').value);
          if (!isNaN(lat) && !isNaN(lng)) {
            pitchEditorMarker.setLatLng([lat, lng]);
            pitchEditorMap.panTo([lat, lng]);
          }
        }

        function applyPitchPresetLocation(sel) {
          if (!sel || !sel.value) return;
          try {
            var d = JSON.parse(sel.value);
            document.getElementById('inpPitchLat').value = Number(d.lat).toFixed(7);
            document.getElementById('inpPitchLng').value = Number(d.lng).toFixed(7);
            if (d.addr) document.getElementById('inpPitchAddr').value = d.addr;
            if (d.dist) document.getElementById('inpPitchDist').value = d.dist;
            if (d.phone) document.getElementById('inpPitchPhone').value = d.phone;
            if (d.gmap) document.getElementById('inpPitchGmap').value = d.gmap;
            if (pitchEditorMarker && pitchEditorMap) {
              pitchEditorMarker.setLatLng([d.lat, d.lng]);
              pitchEditorMap.flyTo([d.lat, d.lng], 18, { duration: 0.45 });
            }
          } catch (e) {}
        }

        function alignPitchToVenueGrid() {
          var codeInp = document.querySelector('input[name="pitch_code"]');
          var code = codeInp ? codeInp.value.trim().toUpperCase() : '5A';
          var offsets = {
            '11A': [0, 0],
            '7A':  [0.0001300, -0.0001300],
            '7B':  [0.0001300,  0.0001300],
            '5A':  [-0.0001400, -0.0001600],
            '5B':  [-0.0001400,  0.0000000],
            '5C':  [-0.0001400,  0.0001600],
            '5D':  [0.0000000,  -0.0001800],
            '5E':  [0.0000000,   0.0001800]
          };
          var off = offsets[code] || [0.0001200, 0.0001200];
          var newLat = venueCenterCoords.lat + off[0];
          var newLng = venueCenterCoords.lng + off[1];
          document.getElementById('inpPitchLat').value = newLat.toFixed(7);
          document.getElementById('inpPitchLng').value = newLng.toFixed(7);
          document.getElementById('inpPitchGmap').value = 'https://www.google.com/maps/dir/?api=1&destination=' + newLat.toFixed(7) + ',' + newLng.toFixed(7);
          if (pitchEditorMarker && pitchEditorMap) {
            pitchEditorMarker.setLatLng([newLat, newLng]);
            pitchEditorMap.flyTo([newLat, newLng], 19, { duration: 0.4 });
          }
        }
      </script>

    <?php elseif ($tab === 'pricing'): ?>
      <!-- ====================================================================
           MODULE 4: CẤU HÌNH BẢNG GIÁ LINH HOẠT THEO KHUNG GIỜ (DYNAMIC PRICING)
           ==================================================================== -->
      <?php
      $curPricingField = $selectedPricingFieldId ? owner_get_field($u, $selectedPricingFieldId) : ($venueFields[0] ?? null);
      $fieldRules = [];
      if ($curPricingField) {
          $selectedPricingFieldId = (int)$curPricingField['id'];
          $stR = $pdo->prepare("SELECT * FROM pricing_rules WHERE field_id = ? ORDER BY FIELD(day_type,'WEEKDAY','WEEKEND','HOLIDAY'), start_time ASC");
          $stR->execute([$selectedPricingFieldId]);
          $fieldRules = $stR->fetchAll();
      }
      ?>
      <div class="box" style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
          <h3 style="margin:0;font-size:18px;color:#0f172a">💰 Cấu hình Bảng giá Linh hoạt theo Khung giờ (`pitch_pricing`)</h3>
          <small style="margin:2px 0 0;color:#64748b">Thiết lập giá Giờ thường (05:00-16:00), Giờ vàng (16:00-20:30), Giờ tối (20:30-23:00) & Cuối tuần</small>
        </div>
        <form method="get" style="display:flex;gap:8px;align-items:center">
          <input type="hidden" name="tab" value="pricing">
          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
          <label style="margin:0;font-weight:700">Chọn sân:</label>
          <select name="field_id" onchange="this.form.submit()" style="margin:0;padding:8px 12px;font-weight:700">
            <?php foreach ($venueFields as $vf): ?>
              <option value="<?=$vf['id']?>" <?=(int)$vf['id'] === $selectedPricingFieldId ? 'selected' : ''?>>
                [<?=e($vf['pitch_code'] ?: ('PITCH_' . $vf['type']))?>] <?=e($vf['name'])?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($curPricingField): ?>
        <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:20px;align-items:start">
          <!-- Cột trái: Thiết lập nhanh Bộ 5 Khung Giá Chuẩn (Ngày thường + Giờ vàng + Cuối tuần) -->
          <div class="box" style="margin:0;border-top:4px solid #16a34a">
            <h4 style="margin:0 0 12px;color:#0f172a">⚡ Thiết lập Nhanh Khung Giá Chuẩn (Thứ 2-6 & Thứ 7-CN)</h4>
            <form method="post" class="form" style="max-width:100%">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="apply_standard_pricing">
              <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
              <input type="hidden" name="field_id" value="<?=$curPricingField['id']?>">

              <label>🟢 Ngày thường (Thứ 2 - Thứ 6): 05:00 – 16:00 (Giờ rẻ)</label>
              <input type="number" name="wd_morning" step="10000" value="<?=(int)($curPricingField['normal_price'] ?: $curPricingField['price_per_hour'])?>" required>

              <label>🔥 Ngày thường (Thứ 2 - Thứ 6): 16:00 – 20:30 (Giờ Vàng cao điểm)</label>
              <input type="number" name="wd_golden" step="10000" value="<?=(int)($curPricingField['peak_price'] ?: ($curPricingField['price_per_hour'] + 120000))?>" required>

              <label>🌙 Ngày thường (Thứ 2 - Thứ 6): 20:30 – 23:00 (Giờ tối trung bình)</label>
              <input type="number" name="wd_night" step="10000" value="<?=(int)round((($curPricingField['normal_price'] ?: $curPricingField['price_per_hour']) + ($curPricingField['peak_price'] ?: ($curPricingField['price_per_hour'] + 120000))) / 2)?>" required>

              <label>🌤️ Cuối tuần (Thứ 7 - Chủ nhật): 05:00 – 16:00</label>
              <input type="number" name="we_morning" step="10000" value="<?=(int)round(($curPricingField['normal_price'] ?: $curPricingField['price_per_hour']) * 1.15)?>" required>

              <label>🏆 Cuối tuần (Thứ 7 - Chủ nhật): 16:00 – 23:00 (Giờ Vàng cuối tuần)</label>
              <input type="number" name="we_golden" step="10000" value="<?=(int)round(($curPricingField['peak_price'] ?: ($curPricingField['price_per_hour'] + 120000)) * 1.1)?>" required>

              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:#f0fdf4;padding:10px;border-radius:8px;border:1px solid #86efac;margin-top:8px">
                <input type="checkbox" name="apply_all_venue" value="1" style="width:16px;height:16px;margin:0">
                <span style="font-size:12.5px;color:#065f46;font-weight:700">Áp dụng đồng loạt cho tất cả <?=count($venueFields)?> sân trong Cụm sân này</span>
              </label>

              <button type="submit" class="btn" style="width:100%;margin-top:12px">
                💾 ÁP DỤNG BẢNG GIÁ KHUNG GIỜ
              </button>
            </form>
          </div>

          <!-- Cột phải: Bảng các khung giá đang áp dụng & Form thêm khung giờ tùy chỉnh -->
          <div style="display:flex;flex-direction:column;gap:18px">
            <div class="box" style="margin:0">
              <div class="bh">
                <b>📋 Khung giá đang áp dụng cho: <?=e($curPricingField['name'])?></b>
              </div>
              <?php if (!$fieldRules): ?>
                <div class="empty">Chưa có khung giá riêng. Hãy bấm "Áp dụng Bảng giá Khung giờ" ở cột bên trái!</div>
              <?php else: ?>
                <div class="tw">
                  <table>
                    <thead>
                      <tr>
                        <th>Loại ngày</th>
                        <th>Khung giờ</th>
                        <th>Nhãn hiển thị</th>
                        <th>Đơn giá / giờ</th>
                        <th>Xóa</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($fieldRules as $fr): ?>
                        <tr>
                          <td>
                            <span class="tag <?=$fr['day_type'] === 'WEEKDAY' ? 'blue' : 'amber'?>">
                              <?=$fr['day_type'] === 'WEEKDAY' ? 'Thứ 2 - Thứ 6' : ($fr['day_type'] === 'WEEKEND' ? 'Thứ 7 - CN' : 'Ngày Lễ')?>
                            </span>
                          </td>
                          <td><b><?=substr($fr['start_time'], 0, 5)?> - <?=substr($fr['end_time'], 0, 5)?></b></td>
                          <td><?=e($fr['label'])?></td>
                          <td><b style="color:#15803d"><?=money($fr['price_per_hour'])?>/h</b></td>
                          <td>
                            <form method="post" class="inl">
                              <?=csrf_field()?>
                              <input type="hidden" name="action" value="delete_pricing_rule">
                              <input type="hidden" name="field_id" value="<?=$curPricingField['id']?>">
                              <input type="hidden" name="rule_id" value="<?=$fr['id']?>">
                              <button type="submit" class="btn sm red">✕</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>

            <div class="box" style="margin:0">
              <h4 style="margin:0 0 10px;color:#0f172a">➕ Thêm Khung Giờ Tùy Chỉnh</h4>
              <form method="post" class="form" style="max-width:100%">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="add_custom_pricing_rule">
                <input type="hidden" name="field_id" value="<?=$curPricingField['id']?>">
                <div class="two" style="margin:0">
                  <div>
                    <label>Loại ngày</label>
                    <select name="day_type">
                      <option value="WEEKDAY">Ngày thường (T2 - T6)</option>
                      <option value="WEEKEND">Cuối tuần (T7 - CN)</option>
                      <option value="HOLIDAY">Ngày Lễ</option>
                    </select>
                  </div>
                  <div>
                    <label>Giá thuê / giờ (VNĐ)</label>
                    <input type="number" name="price_per_hour" step="10000" value="250000" required>
                  </div>
                </div>
                <div class="two" style="margin:0">
                  <div>
                    <label>Từ giờ</label>
                    <input type="time" name="start_time" value="17:00" step="1800" required>
                  </div>
                  <div>
                    <label>Đến giờ</label>
                    <input type="time" name="end_time" value="20:30" step="1800" required>
                  </div>
                </div>
                <label>Tên khung giờ</label>
                <input name="label" value="🔥 Khung giờ Vàng đặc biệt" required>
                <button type="submit" class="btn sm" style="margin-top:8px">+ Thêm khung giờ</button>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'calendar'): ?>
      <!-- ====================================================================
           MODULE 5: LỊCH ĐẶT SÂN TRỰC QUAN (TIMELINE GRID) & POS TẠI QUẦY
           (CHECK-IN, BÁO BÙNG KÈO NO-SHOW TRỪ UY TÍN, ĐẶT WALK-IN QUA SĐT)
           ==================================================================== -->
      <div class="box" style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
          <h3 style="margin:0;font-size:18px;color:#0f172a">📅 Lịch Đặt Sân Trực Quan (Timeline Grid) & POS Tại Quầy</h3>
          <small style="margin:2px 0 0;color:#64748b">Bấm vào ô trống trên lưới để điền nhanh vào Form Đặt sân tại quầy (Walk-in) bên dưới</small>
        </div>
        <form method="get" style="display:flex;gap:8px;align-items:center">
          <input type="hidden" name="tab" value="calendar">
          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
          <label style="margin:0;font-weight:700">Ngày thi đấu:</label>
          <input type="date" name="date" value="<?=e($calDate)?>" onchange="this.form.submit()" style="margin:0;padding:8px 12px;font-weight:700">
        </form>
      </div>

      <!-- Lưới thời gian Timeline Calendar của toàn bộ sân con trong Cụm sân -->
      <div class="box" style="margin-bottom:20px;overflow-x:auto">
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;font-weight:700">
          <span>🟢 Ô xanh: Đang trống (Click để đặt Walk-in)</span>
          <span>🟡 Ô vàng: Khách đang giữ chỗ (`PENDING`)</span>
          <span>🔵 Ô xanh dương: Đã xác nhận (`CONFIRMED`)</span>
          <span>✅ Ô đậm: Đã Check-in (`COMPLETED`)</span>
          <span>🔒 Ô xám: Khóa chéo Sân Cha/Con</span>
        </div>

        <?php
        // Hiển thị lưới ca 60 phút từ 06:00 -> 22:00 cho từng sân trong Cụm sân
        $hourCols = [];
        for ($h = 6; $h < 22; $h++) {
            $hourCols[] = [
                'start' => sprintf('%02d:00', $h),
                'end'   => sprintf('%02d:00', $h + 1),
                'label' => sprintf('%02dh-%02dh', $h, $h + 1),
            ];
        }
        ?>
        <table class="pos-timeline-table">
          <thead>
            <tr>
              <th style="min-width:150px;text-align:left">Sân bóng</th>
              <?php foreach ($hourCols as $hc): ?>
                <th><?=$hc['label']?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($venueFields as $vf):
                $slotMap30 = get_field_busy_slots_30m($vf['id'], $calDate)['slots'];
            ?>
              <tr>
                <td style="background:#f8fafc;padding:8px;border-radius:6px;font-weight:800;color:#0f172a">
                  [<?=e($vf['pitch_code'] ?: ('S' . $vf['type']))?>] <?=e($vf['name'])?>
                </td>
                <?php foreach ($hourCols as $hc):
                    $s1 = $slotMap30[$hc['start']] ?? null;
                    $s2Key = substr($hc['start'], 0, 2) . ':30';
                    $s2 = $slotMap30[$s2Key] ?? null;
                    $busySlot = ($s1 && $s1['is_busy']) ? $s1 : (($s2 && $s2['is_busy']) ? $s2 : null);

                    if (!$busySlot) {
                        $cellClass = 'free';
                        $cellText  = 'Trống';
                    } elseif ($busySlot['conflict_type'] === 'SELF') {
                        $bkSt = $busySlot['booking_status'] ?? 'confirmed';
                        $cellClass = in_array($bkSt, ['pending', 'confirmed', 'completed'], true) ? $bkSt : 'confirmed';
                        $cellText  = strtoupper($bkSt === 'pending' ? 'HOLD' : ($bkSt === 'completed' ? 'CHECK-IN' : 'ĐÃ ĐẶT'));
                    } else {
                        $cellClass = 'locked';
                        $cellText  = '🔒 Khóa chéo';
                    }
                ?>
                  <td
                    class="pos-cell <?=$cellClass?>"
                    <?php if ($cellClass === 'free'): ?>
                      onclick="prefillWalkinSlot(<?=(int)$vf['id']?>, '<?=$hc['start']?>', '<?=$hc['end']?>')"
                    <?php endif; ?>
                    title="<?=e($vf['name'] . ' (' . $hc['start'] . '-' . $hc['end'] . '): ' . $cellText)?>"
                  >
                    <?=$cellText?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1.45fr;gap:20px;align-items:start">
        <!-- Cột trái: Form Tạo Lịch Đặt Trực Tiếp Tại Quầy (Walk-in / Offline Booking) -->
        <div class="box" style="margin:0;border-top:4px solid #16a34a" id="walkinFormBox">
          <h4 style="margin:0 0 12px;color:#0f172a">➕ Tạo Lịch Đặt Trực Tiếp Tại Quầy (Walk-in / Khách gọi SĐT)</h4>
          <form method="post" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="create_walkin_booking">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">

            <label>Chọn sân thi đấu *</label>
            <select name="field_id" id="walkinFieldSelect" required>
              <?php foreach ($venueFields as $vf): ?>
                <option value="<?=$vf['id']?>">[<?=e($vf['pitch_code'] ?: ('PITCH_' . $vf['type']))?>] <?=e($vf['name'])?> — <?=money($vf['price_per_hour'])?>/h</option>
              <?php endforeach; ?>
            </select>

            <label>Ngày đá *</label>
            <input type="date" name="booking_date" value="<?=e($calDate)?>" required>

            <div class="two" style="margin:0">
              <div>
                <label>Giờ bắt đầu (bước 30p)</label>
                <select name="start_time" id="walkinStartSelect">
                  <?php for ($t = strtotime('06:00'); $t <= strtotime('22:00'); $t += 1800): $v = date('H:i', $t); ?>
                    <option value="<?=$v?>" <?=$v === '17:30' ? 'selected' : ''?>><?=$v?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div>
                <label>Giờ kết thúc</label>
                <select name="end_time" id="walkinEndSelect">
                  <?php for ($t = strtotime('07:00'); $t <= strtotime('23:00'); $t += 1800): $v = date('H:i', $t); ?>
                    <option value="<?=$v?>" <?=$v === '19:00' ? 'selected' : ''?>><?=$v?></option>
                  <?php endfor; ?>
                </select>
              </div>
            </div>

            <div class="two" style="margin:0">
              <div>
                <label>Tên khách / Đội bóng *</label>
                <input name="walkin_name" required placeholder="VD: Anh Tuấn FC">
              </div>
              <div>
                <label>Số điện thoại khách *</label>
                <input name="walkin_phone" required placeholder="VD: 0909123456">
              </div>
            </div>

            <label>Ghi chú tại quầy</label>
            <input name="note" placeholder="VD: Khách quen đặt qua điện thoại, thuê thêm 2 bóng">

            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;background:#f0fdf4;padding:10px;border-radius:8px;border:1px solid #86efac;margin-top:8px">
              <input type="checkbox" name="paid_now" value="1" checked style="width:16px;height:16px;margin:0">
              <span style="font-size:13px;color:#065f46;font-weight:700">💵 Đã thu tiền mặt / chuyển khoản tại quầy</span>
            </label>

            <button type="submit" class="btn" style="width:100%;margin-top:12px">
              ⚡ TẠO ĐƠN WALK-IN & KHÓA SÂN NGAY
            </button>
          </form>
        </div>

        <!-- Cột phải: Bảng Duyệt Đơn, Check-in & Báo Bùng Kèo (No-show) có Bộ lọc trạng thái đồng bộ -->
        <div class="box" style="margin:0">
          <div class="bh" style="flex-wrap:wrap;gap:10px">
            <b>📋 Danh sách Đơn đặt sân & Xử lý POS (Check-in / No-show)</b>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php
              $posStTabs = [
                  'all'       => 'Tất cả (' . count($ownerBookings) . ')',
                  'pending'   => '⏳ Chờ duyệt',
                  'confirmed' => '💳 Đã thanh toán / Xác nhận',
                  'completed' => '🏆 Hoàn thành',
                  'cancelled' => '✕ Đã hủy',
              ];
              foreach ($posStTabs as $stKey => $stLbl):
              ?>
                <a class="btn sm <?=$bkStatusFilter === $stKey ? '' : 'outline'?>"
                   href="?tab=calendar&venue_id=<?=$selectedVenueId?>&date=<?=urlencode($calDate)?>&bk_status=<?=$stKey?>">
                  <?=$stLbl?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <?php
          $filteredPosBks = array_filter($ownerBookings, function ($row) use ($bkStatusFilter) {
              if ($bkStatusFilter === 'all') {
                  return true;
              }
              return ($row['status'] ?? '') === $bkStatusFilter;
          });
          ?>
          <?php if (!$filteredPosBks): ?>
            <div class="empty">Không có đơn đặt sân nào khớp với bộ lọc hiện tại.</div>
          <?php else: ?>
            <div class="tw">
              <table>
                <thead>
                  <tr>
                    <th>Mã / Nguồn</th>
                    <th>Sân & Giờ đá</th>
                    <th>Khách hàng & Uy tín</th>
                    <th>Tổng tiền</th>
                    <th>Trạng thái</th>
                    <th>Thao tác POS Chủ sân</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_slice($filteredPosBks, 0, 45) as $b):
                      $repT = get_reputation_tier((int)($b['reputation_score'] ?? 100));
                      $isWalkIn = (($b['booking_source'] ?? 'ONLINE') === 'WALK_IN');
                      $stBadgeMap = [
                          'pending'   => ['⏳ Chờ duyệt', 'amber'],
                          'confirmed' => ['💳 Đã thanh toán', 'blue'],
                          'completed' => ['🏆 Hoàn thành', 'green'],
                          'cancelled' => ['✕ Đã hủy', 'red'],
                      ];
                      [$stViText, $stViColor] = $stBadgeMap[$b['status']] ?? [$b['status'], 'gray'];
                  ?>
                    <tr>
                      <td>
                        <b>#<?=$b['id']?></b>
                        <span class="tag <?=$isWalkIn ? 'amber' : 'blue'?>" style="font-size:10px;display:inline-block;margin-top:2px">
                          <?=$isWalkIn ? '🏪 WALK-IN' : '🌐 ONLINE'?>
                        </span>
                      </td>
                      <td>
                        <b><?=e($b['fname'])?></b>
                        <small style="display:block"><?=date('d/m/Y', strtotime($b['booking_date']))?> • <b style="color:#16a34a"><?=substr($b['start_time'], 0, 5)?>-<?=substr($b['end_time'], 0, 5)?></b></small>
                      </td>
                      <td>
                        <b><?=e($b['walkin_name'] ?: $b['customer_name'])?></b>
                        <small style="display:block">📞 <?=e($b['walkin_phone'] ?: $b['customer_phone'])?></small>
                        <?php if (!$isWalkIn): ?>
                          <span class="tag <?=$repT['badge']?>" style="font-size:10px">⭐ <?=(int)($b['reputation_score'] ?? 100)?>đ</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <b style="color:#059669"><?=money($b['total_price'])?></b>
                        <small style="display:block"><?=$b['pay_status'] === 'paid' ? '✔ Đã thanh toán' : '⏳ Chờ thanh toán'?></small>
                      </td>
                      <td>
                        <span class="tag <?=$stViColor?>"><?=$stViText?></span>
                        <?php if (!empty($b['no_show'])): ?>
                          <small style="color:#dc2626;font-weight:700;display:block">🚫 Bùng kèo</small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                          <?php if ($b['status'] === 'pending'): ?>
                            <form method="post" class="inl">
                              <?=csrf_field()?>
                              <input type="hidden" name="action" value="pos_confirm_booking">
                              <input type="hidden" name="booking_id" value="<?=$b['id']?>">
                              <input type="hidden" name="cal_date" value="<?=e($calDate)?>">
                              <button type="submit" class="btn sm">✔ Duyệt</button>
                            </form>
                          <?php endif; ?>

                          <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
                            <form method="post" class="inl" onsubmit="return confirm('Xác nhận khách đã đến sân (Check-in) và cộng +5 Điểm Uy Tín?')">
                              <?=csrf_field()?>
                              <input type="hidden" name="action" value="pos_check_in">
                              <input type="hidden" name="booking_id" value="<?=$b['id']?>">
                              <input type="hidden" name="cal_date" value="<?=e($calDate)?>">
                              <button type="submit" class="btn sm bl">✅ Check-in</button>
                            </form>

                            <form method="post" class="inl" onsubmit="return confirm('⚠️ Báo cáo khách bùng kèo (No-show)? Đơn sẽ bị hủy và hệ thống tự động trừ -20 Điểm Uy Tín của khách!')">
                              <?=csrf_field()?>
                              <input type="hidden" name="action" value="pos_no_show">
                              <input type="hidden" name="booking_id" value="<?=$b['id']?>">
                              <input type="hidden" name="cal_date" value="<?=e($calDate)?>">
                              <button type="submit" class="btn sm red">🚫 No-show (-20 UT)</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <script>
        function prefillWalkinSlot(fieldId, startTime, endTime) {
          var fSel = document.getElementById('walkinFieldSelect');
          var sSel = document.getElementById('walkinStartSelect');
          var eSel = document.getElementById('walkinEndSelect');
          if (fSel) fSel.value = String(fieldId);
          if (sSel) sSel.value = startTime;
          if (eSel) eSel.value = endTime;
          var box = document.getElementById('walkinFormBox');
          if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      </script>

    <?php elseif ($tab === 'revenue'): ?>
      <!-- ====================================================================
           MODULE 6: BẢNG THỐNG KÊ DOANH THU CHỦ SÂN SAU KHI TRỪ PHÍ SÀN
           (Khách đặt sân -> Dòng tiền chảy 100% về TK Admin -> Khấu trừ Phí sàn -> Chủ sân thực nhận)
           ==================================================================== -->
      <?php
      $feePct   = get_platform_fee_percent();
      $netPct   = 100 - $feePct;
      $selMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '';

      // Tổng hợp theo từng tháng (YYYY-MM), theo từng sân con và danh sách đơn hợp lệ
      $monthlySummary = [];
      $pitchSummary   = [];
      $validBookings  = [];

      $totalGrossAll = 0;
      $totalFeeAll   = 0;
      $totalNetAll   = 0;
      $totalCountAll = 0;

      foreach ($ownerBookings as $ob) {
          if (!in_array($ob['status'], ['confirmed', 'completed'], true)) {
              continue;
          }
          $ym    = substr($ob['booking_date'], 0, 7);
          $gross = (int)$ob['total_price'];
          $fee   = (int)round($gross * ($feePct / 100));
          $net   = max(0, $gross - $fee);

          if (!isset($monthlySummary[$ym])) {
              $monthlySummary[$ym] = [
                  'ym'    => $ym,
                  'label' => 'Tháng ' . date('m/Y', strtotime($ym . '-01')),
                  'count' => 0,
                  'gross' => 0,
                  'fee'   => 0,
                  'net'   => 0,
              ];
          }
          $monthlySummary[$ym]['count']++;
          $monthlySummary[$ym]['gross'] += $gross;
          $monthlySummary[$ym]['fee']   += $fee;
          $monthlySummary[$ym]['net']   += $net;

          if ($selMonth === '' || $selMonth === $ym) {
              $totalCountAll++;
              $totalGrossAll += $gross;
              $totalFeeAll   += $fee;
              $totalNetAll   += $net;

              $fidKey = (int)$ob['field_id'];
              if (!isset($pitchSummary[$fidKey])) {
                  $pitchSummary[$fidKey] = [
                      'name'       => $ob['fname'],
                      'type'       => $ob['ftype'],
                      'pitch_code' => $ob['pitch_code'] ?? '',
                      'count'      => 0,
                      'gross'      => 0,
                      'fee'        => 0,
                      'net'        => 0,
                  ];
              }
              $pitchSummary[$fidKey]['count']++;
              $pitchSummary[$fidKey]['gross'] += $gross;
              $pitchSummary[$fidKey]['fee']   += $fee;
              $pitchSummary[$fidKey]['net']   += $net;

              $ob['calc_gross'] = $gross;
              $ob['calc_fee']   = $fee;
              $ob['calc_net']   = $net;
              $validBookings[]  = $ob;
          }
      }
      krsort($monthlySummary);
      ?>

      <div class="partner-hero-banner">
        <div>
          <h2>📈 Bảng Thống Kê Doanh Thu & Đối Soát Phí Sàn</h2>
          <small>
            Cơ chế dòng tiền: Khách đặt sân thanh toán <b>100% về Tài khoản Admin Sân Bóng Pro</b> • Hệ thống khấu trừ <b>Phí sàn <?=$feePct?>%</b> và thống kê <b>Doanh thu thực nhận (<?=$netPct?>%)</b> cho Chủ sân
          </small>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
            <input type="hidden" name="tab" value="revenue">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
            <select name="month" onchange="this.form.submit()" style="padding:8px 12px;border-radius:10px;border:1.5px solid #86efac;background:#fff;color:#0f172a;font-weight:700;font-size:13px">
              <option value="">📅 Tất cả các tháng</option>
              <?php foreach ($monthlySummary as $ymKey => $mRow): ?>
                <option value="<?=e($ymKey)?>" <?=$selMonth === $ymKey ? 'selected' : ''?>>
                  <?=e($mRow['label'])?> (<?=$mRow['count']?> đơn)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>

      <!-- 4 Thẻ KPI Tổng Hợp Doanh Thu Chủ Sân -->
      <div class="partner-kpi-grid">
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon blue">🏦</div>
          <div class="partner-kpi-meta">
            <small>Tổng thu về TK Admin (100%)</small>
            <b style="color:#1d4ed8"><?=money($totalGrossAll)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon amber">🏛️</div>
          <div class="partner-kpi-meta">
            <small>Phí vận hành sàn (-<?=$feePct?>%)</small>
            <b style="color:#dc2626">-<?=money($totalFeeAll)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon green">💰</div>
          <div class="partner-kpi-meta">
            <small>Chủ sân thực nhận (<?=$netPct?>%)</small>
            <b style="color:#15803d"><?=money($totalNetAll)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon purple">🎫</div>
          <div class="partner-kpi-meta">
            <small>Đơn đặt tính doanh thu</small>
            <b style="color:#6d28d9"><?=$totalCountAll?> đơn</b>
          </div>
        </div>
      </div>

      <!-- BẢNG 1: BẢNG ĐỐI SOÁT DOANH THU & KHẤU TRỪ PHÍ SÀN THEO THÁNG -->
      <div class="box" style="margin-bottom:20px;border-top:4px solid #16a34a">
        <div class="bh">
          <div>
            <b>🗓️ Bảng 1: Thống Kê Doanh Thu & Khấu Trừ Phí Sàn Theo Tháng</b>
            <small style="margin:2px 0 0;color:#64748b">
              Tổng hợp toàn bộ dòng tiền khách đặt sân chảy về TK Admin và số tiền thực nhận của Chủ sân sau khi trừ <?=$feePct?>% phí sàn
            </small>
          </div>
          <span class="tag green">Tỷ lệ chia sẻ: Chủ sân <?=$netPct?>% / Sàn <?=$feePct?>%</span>
        </div>

        <?php if (!$monthlySummary): ?>
          <div class="empty">Chưa có dữ liệu đơn đặt sân hoàn tất để thống kê theo tháng.</div>
        <?php else: ?>
          <div class="tw">
            <table>
              <thead>
                <tr>
                  <th>Kỳ đối soát (Tháng)</th>
                  <th>Số đơn thành công</th>
                  <th>Tổng tiền về TK Admin (100%)</th>
                  <th>Tỷ lệ Phí sàn</th>
                  <th>Phí sàn khấu trừ (-<?=$feePct?>%)</th>
                  <th>Chủ sân thực nhận (<?=$netPct?>%)</th>
                  <th>Trạng thái đối soát</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($monthlySummary as $ymKey => $mItem): ?>
                  <tr style="<?=$selMonth === $ymKey ? 'background:#f0fdf4' : ''?>">
                    <td>
                      <b><?=e($mItem['label'])?></b>
                      <small style="margin:2px 0 0;color:#64748b">Chu kỳ đối soát tự động</small>
                    </td>
                    <td><b><?=$mItem['count']?> đơn</b></td>
                    <td><b style="color:#1e293b"><?=money($mItem['gross'])?></b></td>
                    <td><span class="tag amber"><?=$feePct?>%</span></td>
                    <td><b style="color:#dc2626">-<?=money($mItem['fee'])?></b></td>
                    <td><b style="color:#16a34a;font-size:15px"><?=money($mItem['net'])?></b></td>
                    <td>
                      <span class="tag green">✔ Admin thu hộ & Đối soát</span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- HÀNG 2 CỘT: BẢNG THỐNG KÊ THEO TỪNG SÂN CON & THÔNG TIN NGÂN HÀNG NHẬN ĐỐI SOÁT -->
      <div style="display:grid;grid-template-columns:1.25fr 0.75fr;gap:20px;align-items:start;margin-bottom:20px">
        <!-- Bảng 2: Doanh thu theo từng Sân bóng thành phần -->
        <div class="box" style="margin:0">
          <div class="bh">
            <b>🏟 Bảng 2: Doanh Thu Thực Nhận Theo Từng Sân (5 / 7 / 11)</b>
          </div>
          <?php if (!$pitchSummary): ?>
            <div class="empty">Chưa có phát sinh doanh thu trên các sân con.</div>
          <?php else: ?>
            <div class="tw">
              <table>
                <thead>
                  <tr>
                    <th>Sân bóng</th>
                    <th>Số đơn</th>
                    <th>Thu về Admin (100%)</th>
                    <th>Phí sàn (-<?=$feePct?>%)</th>
                    <th>Thực nhận (<?=$netPct?>%)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pitchSummary as $ps): ?>
                    <tr>
                      <td>
                        <b><?=e($ps['name'])?></b>
                        <small style="margin:2px 0 0">Sân <?=$ps['type']?> người <?=!empty($ps['pitch_code']) ? '• ' . e($ps['pitch_code']) : ''?></small>
                      </td>
                      <td><?=$ps['count']?> đơn</td>
                      <td><b><?=money($ps['gross'])?></b></td>
                      <td style="color:#dc2626"><b>-<?=money($ps['fee'])?></b></td>
                      <td><b style="color:#16a34a"><?=money($ps['net'])?></b></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Hồ sơ Ngân hàng nhận tiền đối soát từ Admin -->
        <div class="box" style="margin:0;border-top:4px solid #2563eb">
          <div class="bh">
            <b>🏦 Tài Khoản Nhận Tiền Đối Soát Từ Admin</b>
            <span class="tag blue">Chủ sân thụ hưởng</span>
          </div>
          <small style="color:#475569;display:block;margin-bottom:10px">
            Admin hệ thống sẽ chuyển khoản phần <b>Doanh thu thực nhận (<?=$netPct?>%)</b> về tài khoản ngân hàng dưới đây của bạn:
          </small>
          <form method="post" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="update_partner_bank_info">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">

            <label>Ngân hàng nhận đối soát</label>
            <select name="bank_name">
              <?php foreach (['Vietcombank', 'MB Bank', 'Techcombank', 'ACB', 'BIDV', 'VPBank', 'VietinBank', 'TPBank'] as $bOpt): ?>
                <option value="<?=e($bOpt)?>" <?=($partnerAppInfo['bank_name'] ?? 'MB Bank') === $bOpt ? 'selected' : ''?>><?=e($bOpt)?></option>
              <?php endforeach; ?>
            </select>

            <label>Số tài khoản Ngân hàng *</label>
            <input name="bank_account_number" required value="<?=e($partnerAppInfo['bank_account_number'] ?? $u['phone'])?>">

            <label>Chủ tài khoản Ngân hàng (In hoa) *</label>
            <input name="bank_account_holder" required value="<?=e($partnerAppInfo['bank_account_holder'] ?? mb_strtoupper($u['full_name']))?>">

            <label>Mã số thuế (Nếu có)</label>
            <input name="tax_code" value="<?=e($partnerAppInfo['tax_code'] ?? '0316889101')?>">

            <button type="submit" class="btn bl" style="width:100%;margin-top:12px">
              💾 LƯU TÀI KHOẢN NHẬN ĐỐI SOÁT
            </button>
          </form>
        </div>
      </div>

      <!-- BẢNG 3: BẢNG KÊ CHI TIẾT TỪNG ĐƠN ĐẶT SÂN & KHẤU TRỪ PHÍ SÀN -->
      <div class="box" style="margin:0">
        <div class="bh">
          <div>
            <b>📋 Bảng 3: Chi Tiết Từng Đơn Đặt Sân & Khấu Trừ Phí Sàn (<?=count($validBookings)?> đơn)</b>
            <small style="margin:2px 0 0;color:#64748b">
              Mỗi đơn đặt sân thành công được bóc tách minh bạch: Tổng tiền khách trả về TK Admin (100%) − Phí sàn (<?=$feePct?>%) = Chủ sân thực nhận (<?=$netPct?>%)
            </small>
          </div>
        </div>

        <?php if (!$validBookings): ?>
          <div class="empty">Không có đơn đặt sân nào trong kỳ được chọn.</div>
        <?php else: ?>
          <div class="tw">
            <table>
              <thead>
                <tr>
                  <th>Mã đơn</th>
                  <th>Ngày & Khung giờ</th>
                  <th>Sân bóng</th>
                  <th>Khách hàng</th>
                  <th>Dòng tiền thu hộ</th>
                  <th>Tổng tiền gốc (100%)</th>
                  <th>Phí sàn (-<?=$feePct?>%)</th>
                  <th>Chủ sân thực nhận (<?=$netPct?>%)</th>
                  <th>Trạng thái</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($validBookings as $vb): ?>
                  <tr>
                    <td><b>#<?=(int)$vb['id']?></b></td>
                    <td>
                      <b><?=date('d/m/Y', strtotime($vb['booking_date']))?></b>
                      <small style="margin:2px 0 0;color:#16a34a;font-weight:700">
                        🕒 <?=substr($vb['start_time'], 0, 5)?> – <?=substr($vb['end_time'], 0, 5)?>
                      </small>
                    </td>
                    <td>
                      <b><?=e($vb['fname'])?></b>
                      <small style="margin:2px 0 0">Sân <?=$vb['ftype']?> người</small>
                    </td>
                    <td>
                      <b><?=e($vb['customer_name'])?></b>
                      <small style="margin:2px 0 0">📞 <?=e($vb['customer_phone'])?></small>
                    </td>
                    <td>
                      <span class="tag blue">🏦 Về TK Admin (<?=strtoupper($vb['pay_method'] ?: 'VIETQR')?>)</span>
                    </td>
                    <td><b><?=money($vb['calc_gross'])?></b></td>
                    <td style="color:#dc2626"><b>-<?=money($vb['calc_fee'])?></b></td>
                    <td><b style="color:#16a34a;font-size:14.5px"><?=money($vb['calc_net'])?></b></td>
                    <td>
                      <span class="tag green">
                        <?=$vb['status'] === 'completed' ? '🏆 Đã hoàn thành' : '✔ Đã xác nhận'?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#f0fdf4;font-weight:800">
                  <td colspan="5" style="text-align:right;color:#065f46">TỔNG CỘNG KỲ ĐỐI SOÁT (<?=$totalCountAll?> ĐƠN):</td>
                  <td><b style="color:#0f172a"><?=money($totalGrossAll)?></b></td>
                  <td><b style="color:#dc2626">-<?=money($totalFeeAll)?></b></td>
                  <td><b style="color:#16a34a;font-size:16px"><?=money($totalNetAll)?></b></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'vouchers'): ?>
      <!-- ====================================================================
           MODULE 6.5: QUẢN LÝ MÃ GIẢM GIÁ VOUCHER RIÊNG CỦA ĐỐI TÁC CHỦ SÂN
           ==================================================================== -->
      <?php
      if (function_exists('ensure_partner_vouchers_schema')) {
          ensure_partner_vouchers_schema();
      }
      $myVenueIds  = array_map(fn($v) => (int)$v['id'], $myVenues);
      $inVenuesSql = $myVenueIds ? implode(',', array_map('intval', $myVenueIds)) : '0';
      $myFieldIds  = get_owner_field_ids($u);
      $inFieldsSql = $myFieldIds ? implode(',', array_map('intval', $myFieldIds)) : '0';

      try {
          $stMyVouchers = $pdo->prepare("
              SELECT v.*, vn.name AS venue_name, fd.name AS field_name, fd.pitch_code
              FROM vouchers v
              LEFT JOIN venues vn ON vn.id = v.venue_id
              LEFT JOIN fields fd ON fd.id = v.field_id
              WHERE v.owner_id = ? OR v.venue_id IN ($inVenuesSql)
              ORDER BY v.id DESC
          ");
          $stMyVouchers->execute([$ownerId]);
          $myVouchers = $stMyVouchers->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
          $myVouchers = $pdo->query("SELECT * FROM vouchers ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
      }

      // Tính KPI thống kê Voucher của Chủ sân
      $vcActiveCnt    = 0;
      $vcUsedTotal    = 0;
      $vcRemainTotal  = 0;
      foreach ($myVouchers as $mv) {
          if ($mv['status'] === 'active' && $mv['expired_at'] >= date('Y-m-d')) {
              $vcActiveCnt++;
              $vcRemainTotal += max(0, (int)$mv['usage_limit'] - (int)$mv['used_count']);
          }
          $vcUsedTotal += (int)$mv['used_count'];
      }

      // Truy vấn danh sách đơn đặt sân tại cụm sân của Đối tác đã áp dụng Voucher
      $vcBookingsList = [];
      try {
          $stVcBookings = $pdo->query("
              SELECT b.id, b.booking_date, b.start_time, b.end_time, b.total_price, b.status, b.created_at,
                     u.full_name, u.phone,
                     f.name AS field_name, f.pitch_code,
                     v.code AS voucher_code, v.discount_type, v.discount_value
              FROM bookings b
              INNER JOIN vouchers v ON v.id = b.voucher_id
              INNER JOIN fields f ON f.id = b.field_id
              LEFT JOIN users u ON u.id = b.user_id
              WHERE b.field_id IN ($inFieldsSql) OR v.owner_id = {$ownerId}
              ORDER BY b.id DESC
              LIMIT 25
          ");
          $vcBookingsList = $stVcBookings ? $stVcBookings->fetchAll(PDO::FETCH_ASSOC) : [];
      } catch (Throwable $e) {
          $vcBookingsList = [];
      }

      $editVoucherRow = null;
      $editVoucherId  = (int)($_GET['edit_voucher'] ?? 0);
      if ($editVoucherId > 0) {
          foreach ($myVouchers as $mv) {
              if ((int)$mv['id'] === $editVoucherId) {
                  $editVoucherRow = $mv;
                  break;
              }
          }
      }

      // Tiền tố mã gợi ý theo tên Cụm sân hiện tại
      $suggestedPrefix = 'SAN' . ($selectedVenueId ?: 'VIP');
      $vnLower = mb_strtolower((string)($curVenue['name'] ?? ''), 'UTF-8');
      if (strpos($vnLower, 'chảo lửa') !== false) $suggestedPrefix = 'CHAOLUA';
      elseif (strpos($vnLower, 'k34') !== false) $suggestedPrefix = 'K34';
      elseif (strpos($vnLower, 'tao đàn') !== false) $suggestedPrefix = 'TAODAN';
      elseif (strpos($vnLower, 'phú thọ') !== false) $suggestedPrefix = 'PHUTHO';
      elseif (strpos($vnLower, 'gia định') !== false) $suggestedPrefix = 'GIADINH';
      elseif (strpos($vnLower, 'rạch miễu') !== false) $suggestedPrefix = 'RACHMIEU';
      elseif (strpos($vnLower, 'thành phát') !== false) $suggestedPrefix = 'THANHPHAT';
      elseif (strpos($vnLower, 'đầm sen') !== false) $suggestedPrefix = 'DAMSEN';
      elseif (strpos($vnLower, 'celadon') !== false) $suggestedPrefix = 'CELADON';
      elseif (strpos($vnLower, 'him lam') !== false) $suggestedPrefix = 'HIMLAM';

      $defaultPitchIdForLink = (int)($venueFields[0]['id'] ?? 1);
      ?>
      <div class="partner-hero-banner" style="margin-bottom:18px">
        <div>
          <h2>🎟 Quản lý Mã Giảm Giá (Voucher) Cụm Sân Đối Tác</h2>
          <small>
            Chủ động phát hành & kiểm soát mã ưu đãi riêng cho <b><?=e($curVenue['name'] ?? $u['full_name'])?></b> • Chỉ áp dụng cho khách đặt sân thuộc quyền quản lý của bạn
          </small>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a href="booking.php?field_id=<?=$defaultPitchIdForLink?>" class="btn sm" style="background:#fde047;color:#052e16 !important;font-weight:800">
            ⚡ Xem Giao Diện Áp Mã Đặt Sân ↗
          </a>
        </div>
      </div>

      <!-- 4 Thẻ KPI Thống kê Hiệu quả Voucher của Chủ sân -->
      <div class="partner-kpi-grid" style="margin-bottom:18px">
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon green">🎟</div>
          <div class="partner-kpi-meta">
            <small>Mã đang kích hoạt</small>
            <b style="color:#15803d"><?=$vcActiveCnt?> / <?=count($myVouchers)?> mã</b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon blue">🔥</div>
          <div class="partner-kpi-meta">
            <small>Tổng lượt khách đã dùng</small>
            <b style="color:#1d4ed8"><?=$vcUsedTotal?> lượt</b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon purple">🎁</div>
          <div class="partner-kpi-meta">
            <small>Lượt ưu đãi còn lại</small>
            <b style="color:#6d28d9"><?=$vcRemainTotal?> lượt</b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon amber">📋</div>
          <div class="partner-kpi-meta">
            <small>Đơn đặt sân áp mã</small>
            <b style="color:#d97706"><?=count($vcBookingsList)?> đơn</b>
          </div>
        </div>
      </div>

      <!-- Biểu mẫu Phát hành / Chỉnh sửa Voucher của Đối tác Chủ sân -->
      <div class="box" style="margin-bottom:18px;border:1.5px solid #86efac;background:linear-gradient(135deg,#ffffff,#f0fdf4)">
        <div class="bh" style="flex-wrap:wrap;gap:10px">
          <div>
            <b><?=$editVoucherRow ? '✏️ Chỉnh sửa mã Voucher: ' . e($editVoucherRow['code']) : '➕ Phát hành Mã giảm giá (Voucher) mới cho Sân của bạn'?></b>
            <small style="margin:2px 0 0;color:#64748b">Chọn mẫu nhanh bên phải hoặc tùy chỉnh thông số chi tiết bên dưới</small>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <button type="button" class="btn sm outline" onclick="fillPartnerVoucherTemplate('<?=$suggestedPrefix?>15', 'percent', 15, 200000, 60000, 100, 'Giảm 15% giờ thường & giờ vàng')" style="font-size:11.5px;padding:5px 10px">
              ⚡ Mẫu Giảm 15%
            </button>
            <button type="button" class="btn sm outline" onclick="fillPartnerVoucherTemplate('<?=$suggestedPrefix?>30K', 'fixed', 30000, 180000, 30000, 100, 'Giảm trực tiếp 30.000đ cho đội đặt lịch')" style="font-size:11.5px;padding:5px 10px">
              💸 Mẫu Giảm 30K
            </button>
            <button type="button" class="btn sm outline" onclick="fillPartnerVoucherTemplate('<?=$suggestedPrefix?>50K', 'fixed', 50000, 350000, 50000, 80, 'Ưu đãi giảm 50.000đ cho kèo Sân 7 / Sân 11')" style="font-size:11.5px;padding:5px 10px">
              🏆 Mẫu Giảm 50K Sân 7/11
            </button>
            <button type="button" class="btn sm bl" onclick="generateRandomPartnerVoucherCode('<?=$suggestedPrefix?>')" style="font-size:11.5px;padding:5px 10px">
              🎲 Tạo mã ngẫu nhiên
            </button>
            <?php if ($editVoucherRow): ?>
              <a href="?tab=vouchers&venue_id=<?=$selectedVenueId?>" class="btn sm gray">✕ Hủy sửa</a>
            <?php endif; ?>
          </div>
        </div>

        <form method="post" class="form" style="max-width:100%">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="save_partner_voucher">
          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
          <input type="hidden" name="voucher_id" value="<?=(int)($editVoucherRow['id'] ?? 0)?>">

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;align-items:end">
            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Mã Voucher *</label>
              <input
                name="code"
                id="inpPartnerVcCode"
                required
                value="<?=e($editVoucherRow['code'] ?? '')?>"
                placeholder="VD: <?=$suggestedPrefix?>30K"
                style="margin:0;text-transform:uppercase;font-weight:800;letter-spacing:0.5px"
              >
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Phạm vi Sân áp dụng *</label>
              <?php
              $curScopeVal = 'venue';
              if ($editVoucherRow) {
                  if (!empty($editVoucherRow['field_id'])) {
                      $curScopeVal = 'field_' . (int)$editVoucherRow['field_id'];
                  } elseif (empty($editVoucherRow['venue_id'])) {
                      $curScopeVal = 'all_my_venues';
                  }
              }
              ?>
              <select name="scope_target" style="margin:0;font-weight:600">
                <?php if ($curVenue): ?>
                  <option value="venue" <?=$curScopeVal === 'venue' ? 'selected' : ''?>>
                    🏟 Cụm sân: <?=e($curVenue['name'])?>
                  </option>
                <?php endif; ?>
                <option value="all_my_venues" <?=$curScopeVal === 'all_my_venues' ? 'selected' : ''?>>
                  🌐 Tất cả Cụm sân thuộc tài khoản của tôi
                </option>
                <?php foreach ($venueFields as $vfItem): ?>
                  <option value="field_<?=(int)$vfItem['id']?>" <?=$curScopeVal === ('field_' . (int)$vfItem['id']) ? 'selected' : ''?>>
                    ⚽ Riêng [<?=e($vfItem['pitch_code'] ?: ('S' . $vfItem['type']))?>] <?=e($vfItem['name'])?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Hình thức giảm *</label>
              <select name="discount_type" id="inpPartnerVcType" style="margin:0;font-weight:600">
                <option value="percent" <?=($editVoucherRow['discount_type'] ?? 'percent') === 'percent' ? 'selected' : ''?>>
                  % Phần trăm tiền sân
                </option>
                <option value="fixed" <?=($editVoucherRow['discount_type'] ?? '') === 'fixed' ? 'selected' : ''?>>
                  VNĐ Số tiền cố định
                </option>
              </select>
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Giá trị giảm *</label>
              <input
                type="number"
                name="discount_value"
                id="inpPartnerVcValue"
                required
                min="1"
                value="<?=e($editVoucherRow['discount_value'] ?? 15)?>"
                style="margin:0;font-weight:700"
              >
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Đơn tối thiểu (VNĐ)</label>
              <input
                type="number"
                name="min_order"
                id="inpPartnerVcMin"
                step="10000"
                value="<?=e($editVoucherRow['min_order'] ?? 180000)?>"
                style="margin:0"
              >
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Giảm tối đa (VNĐ)</label>
              <input
                type="number"
                name="max_discount"
                id="inpPartnerVcMax"
                step="5000"
                value="<?=e($editVoucherRow['max_discount'] ?? 50000)?>"
                style="margin:0"
              >
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Giới hạn lượt dùng</label>
              <input
                type="number"
                name="usage_limit"
                id="inpPartnerVcLimit"
                min="1"
                value="<?=e($editVoucherRow['usage_limit'] ?? 100)?>"
                style="margin:0"
              >
            </div>

            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Hạn sử dụng *</label>
              <input
                type="date"
                name="expired_at"
                required
                value="<?=e($editVoucherRow['expired_at'] ?? date('Y-12-31'))?>"
                style="margin:0"
              >
            </div>
          </div>

          <div style="margin-top:12px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end">
            <div>
              <label style="margin:0 0 5px;font-weight:700;color:#0f172a">Mô tả ưu đãi (Hiển thị cho khách khi đặt sân)</label>
              <input
                name="description"
                id="inpPartnerVcDesc"
                value="<?=e($editVoucherRow['description'] ?? '')?>"
                placeholder="VD: Giảm 15% giờ thường & tặng 1 lốc nước suối tại sân"
                style="margin:0"
              >
            </div>
            <button type="submit" class="btn" style="padding:11px 22px;font-weight:800;height:42px">
              <?=$editVoucherRow ? '💾 Lưu cập nhật Voucher' : '➕ Phát hành mã Voucher cho sân'?>
            </button>
          </div>
        </form>
      </div>

      <!-- Bảng danh sách Voucher thuộc quyền quản lý của Đối tác Chủ sân -->
      <div class="box" style="margin-bottom:18px">
        <div class="bh">
          <b>📋 Danh sách Mã Giảm Giá (Voucher) của Đối tác (<?=count($myVouchers)?> mã)</b>
          <small style="margin:0;color:#64748b">Bấm "Copy Link" để gửi đường dẫn đặt sân kèm sẵn mã ưu đãi cho các đội bóng</small>
        </div>

        <?php if (!$myVouchers): ?>
          <div class="empty">Cụm sân của bạn chưa có mã giảm giá nào. Hãy phát hành mã mới ở biểu mẫu bên trên!</div>
        <?php else: ?>
          <div class="tw">
            <table>
              <thead>
                <tr>
                  <th>Mã Voucher & Mô tả</th>
                  <th>Phạm vi Sân áp dụng</th>
                  <th>Mức ưu đãi</th>
                  <th>Đơn tối thiểu</th>
                  <th>Lượt đã dùng</th>
                  <th>Hạn sử dụng</th>
                  <th>Trạng thái</th>
                  <th>Thao tác quản lý</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($myVouchers as $vr):
                  $targetFidForVc = (int)($vr['field_id'] ?: $defaultPitchIdForLink);
                  $shareBookingUrl = project_base() . 'user/booking.php?field_id=' . $targetFidForVc . '&voucher=' . urlencode($vr['code']);
                  $isExpired = ($vr['expired_at'] < date('Y-m-d'));
                ?>
                  <tr>
                    <td>
                      <div style="display:flex;align-items:center;gap:6px">
                        <span style="display:inline-block;background:#f0fdf4;border:1.5px dashed #16a34a;color:#15803d;font-weight:900;padding:4px 10px;border-radius:8px;font-size:13px">
                          🎟 <?=e($vr['code'])?>
                        </span>
                        <button type="button" onclick="copyTextWithToast('<?=e($vr['code'])?>', this, '✔ Đã copy mã')" style="border:1px solid #cbd5e1;background:#fff;color:#334155;padding:3px 7px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer" title="Sao chép mã Voucher">
                          📋 Copy mã
                        </button>
                      </div>
                      <?php if (!empty($vr['description'])): ?>
                        <small style="display:block;color:#475569;margin-top:4px;font-size:11.5px"><?=e($vr['description'])?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($vr['field_id'])): ?>
                        <span class="tag blue">⚽ Riêng Sân <?=e($vr['pitch_code'] ?: '')?> - <?=e($vr['field_name'] ?: '')?></span>
                      <?php elseif (!empty($vr['venue_id'])): ?>
                        <span class="tag green">🏟 Cụm: <?=e($vr['venue_name'] ?: ($curVenue['name'] ?? 'Cụm sân Đối tác'))?></span>
                      <?php else: ?>
                        <span class="tag purple">🌐 Toàn bộ sân của Đối tác</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <b style="color:#16a34a;font-size:14px">
                        <?=$vr['discount_type'] === 'percent' ? ('-' . (int)$vr['discount_value'] . '%') : ('-' . money($vr['discount_value']))?>
                      </b>
                      <?php if ($vr['discount_type'] === 'percent' && (int)$vr['max_discount'] > 0): ?>
                        <small style="display:block;color:#64748b">Tối đa <?=money($vr['max_discount'])?></small>
                      <?php endif; ?>
                    </td>
                    <td><?=money($vr['min_order'])?></td>
                    <td>
                      <b><?=(int)$vr['used_count']?></b> / <?=(int)$vr['usage_limit']?> lượt
                      <div style="width:90px;height:5px;background:#e2e8f0;border-radius:999px;margin-top:4px;overflow:hidden">
                        <?php $usedPct = min(100, (int)round(((int)$vr['used_count'] / max(1, (int)$vr['usage_limit'])) * 100)); ?>
                        <div style="width:<?=$usedPct?>%;height:100%;background:<?=$usedPct >= 90 ? '#ef4444' : '#16a34a'?>"></div>
                      </div>
                    </td>
                    <td>
                      <?=date('d/m/Y', strtotime($vr['expired_at']))?>
                      <?php if ($isExpired): ?>
                        <small style="display:block;color:#dc2626;font-weight:700">Đã quá hạn</small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="tag <?=($vr['status'] === 'active' && !$isExpired) ? 'green' : 'gray'?>">
                        <?=($vr['status'] === 'active' && !$isExpired) ? '🟢 Đang mở' : '⏸ Tạm khóa'?>
                      </span>
                    </td>
                    <td>
                      <div style="display:flex;gap:5px;flex-wrap:wrap">
                        <button type="button" class="btn sm outline" onclick="copyTextWithToast(window.location.origin + '<?=e($shareBookingUrl)?>', this, '✔ Đã copy Link')" title="Sao chép Link đặt sân áp sẵn mã Voucher này" style="padding:5px 9px;font-size:11.5px">
                          🔗 Link đặt sân
                        </button>

                        <a class="btn sm outline" href="?tab=vouchers&venue_id=<?=$selectedVenueId?>&edit_voucher=<?=$vr['id']?>" style="padding:5px 9px;font-size:11.5px">✏️ Sửa</a>

                        <form method="post" class="inl">
                          <?=csrf_field()?>
                          <input type="hidden" name="action" value="quick_extend_voucher">
                          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
                          <input type="hidden" name="voucher_id" value="<?=$vr['id']?>">
                          <button type="submit" class="btn sm outline" title="Cộng thêm 50 lượt dùng & gia hạn +30 ngày" style="padding:5px 9px;font-size:11.5px;border-color:#86efac;color:#15803d !important">
                            ➕ Gia hạn
                          </button>
                        </form>

                        <form method="post" class="inl">
                          <?=csrf_field()?>
                          <input type="hidden" name="action" value="toggle_partner_voucher">
                          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
                          <input type="hidden" name="voucher_id" value="<?=$vr['id']?>">
                          <button type="submit" class="btn sm bl" style="padding:5px 9px;font-size:11.5px">
                            <?=$vr['status'] === 'active' ? '⏸ Khóa' : '▶️ Mở'?>
                          </button>
                        </form>

                        <form method="post" class="inl" onsubmit="return confirm('Bạn có chắc chắn muốn xóa mã Voucher <?=e($vr['code'])?> khỏi Cụm sân?')">
                          <?=csrf_field()?>
                          <input type="hidden" name="action" value="delete_partner_voucher">
                          <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
                          <input type="hidden" name="voucher_id" value="<?=$vr['id']?>">
                          <button type="submit" class="btn sm red" style="padding:5px 9px;font-size:11.5px">🗑 Xóa</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Bảng Lịch sử Khách hàng đã sử dụng Mã Giảm Giá tại Cụm sân của Đối tác -->
      <div class="box">
        <div class="bh">
          <b>🧾 Lịch sử Đơn Đặt Sân Đã Áp Dụng Voucher Của Đối Tác (<?=count($vcBookingsList)?> đơn gần nhất)</b>
          <small style="margin:0;color:#64748b">Theo dõi chi tiết các đội bóng đã sử dụng mã giảm giá của bạn</small>
        </div>

        <?php if (!$vcBookingsList): ?>
          <div class="empty">Chưa có đơn đặt sân nào sử dụng mã giảm giá tại cụm sân của bạn.</div>
        <?php else: ?>
          <div class="tw">
            <table>
              <thead>
                <tr>
                  <th>Mã Đơn</th>
                  <th>Khách hàng</th>
                  <th>Sân bóng</th>
                  <th>Ngày & Giờ đá</th>
                  <th>Mã Voucher đã dùng</th>
                  <th>Tổng thanh toán</th>
                  <th>Trạng thái</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($vcBookingsList as $vcb): ?>
                  <tr>
                    <td><b>#<?=(int)$vcb['id']?></b></td>
                    <td>
                      <b><?=e($vcb['full_name'] ?: 'Khách đặt sân')?></b>
                      <?php if (!empty($vcb['phone'])): ?>
                        <small style="display:block;color:#64748b">📞 <?=e($vcb['phone'])?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <b><?=e($vcb['field_name'])?></b>
                      <?php if (!empty($vcb['pitch_code'])): ?>
                        <span class="tag blue">Sân <?=e($vcb['pitch_code'])?></span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <b><?=date('d/m/Y', strtotime($vcb['booking_date']))?></b>
                      <small style="display:block;color:#15803d;font-weight:700"><?=substr($vcb['start_time'], 0, 5)?> - <?=substr($vcb['end_time'], 0, 5)?></small>
                    </td>
                    <td>
                      <span class="tag green">🎟 <?=e($vcb['voucher_code'])?></span>
                    </td>
                    <td><b style="color:#16a34a"><?=money($vcb['total_price'])?></b></td>
                    <td>
                      <span class="tag <?=in_array($vcb['status'], ['confirmed', 'completed']) ? 'green' : ($vcb['status'] === 'pending' ? 'amber' : 'red')?>">
                        <?=strtoupper(e($vcb['status']))?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <script>
        function fillPartnerVoucherTemplate(code, type, val, minOrd, maxDisc, limit, desc) {
          var cEl = document.getElementById('inpPartnerVcCode');
          var tEl = document.getElementById('inpPartnerVcType');
          var vEl = document.getElementById('inpPartnerVcValue');
          var mnEl = document.getElementById('inpPartnerVcMin');
          var mxEl = document.getElementById('inpPartnerVcMax');
          var lmEl = document.getElementById('inpPartnerVcLimit');
          var dsEl = document.getElementById('inpPartnerVcDesc');
          if (cEl) cEl.value = code;
          if (tEl) tEl.value = type;
          if (vEl) vEl.value = val;
          if (mnEl) mnEl.value = minOrd;
          if (mxEl) mxEl.value = maxDisc;
          if (lmEl) lmEl.value = limit;
          if (dsEl) dsEl.value = desc;
          if (cEl) cEl.focus();
        }

        function generateRandomPartnerVoucherCode(prefix) {
          var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
          var suffix = '';
          for (var i = 0; i < 3; i++) {
            suffix += chars.charAt(Math.floor(Math.random() * chars.length));
          }
          var cEl = document.getElementById('inpPartnerVcCode');
          if (cEl) {
            cEl.value = (prefix || 'SAN') + suffix;
            cEl.focus();
          }
        }

        function copyTextWithToast(text, btnEl, okLabel) {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
          } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
          }
          if (btnEl) {
            var old = btnEl.innerHTML;
            btnEl.innerHTML = okLabel || '✔ Đã copy';
            setTimeout(function () { btnEl.innerHTML = old; }, 1800);
          }
        }
      </script>

    <?php elseif ($tab === 'messages'): ?>
      <!-- ====================================================================
           MODULE 7: TRUNG TÂM TIN NHẮN CHỦ SÂN (SPLIT-PANE 2 CỘT + BÁO GIÁ SÂN)
           ==================================================================== -->
      <div class="partner-hero-banner" style="margin-bottom:16px">
        <div>
          <h2>💬 Trung Tâm Tin Nhắn Khách Hàng & Báo Giá Nhanh</h2>
          <small>
            Trò chuyện thời gian thực với khách đặt sân tại <b><?=e($curVenue['name'] ?? $u['full_name'])?></b> • Gửi Thẻ Đặt Sân (`Đặt Ngay`) trực tiếp vào khung chat
          </small>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="btn sm" style="background:#fde047;color:#052e16 !important;font-weight:800" onclick="toggleOwnerPitchCardModal(true)">
            📤 Gửi Thẻ Sân / Báo Giá Nhanh
          </button>
          <a href="?tab=calendar&venue_id=<?=$selectedVenueId?>" class="btn sm outline" style="color:#fff !important;border-color:#86efac">
            📅 Mở Lịch Đặt Sân POS →
          </a>
        </div>
      </div>

      <!-- BỐ CỤC SPLIT-PANE 2 CỘT CHUẨN TRUNG TÂM TIN NHẮN CHỦ SÂN -->
      <div class="omc-split-layout">

        <!-- CỘT TRÁI (320px): DANH SÁCH KHÁCH NHẮN TIN -->
        <div class="omc-left-pane">
          <div class="omc-search-box">
            <input type="text"
                   id="omcSearchInput"
                   placeholder="🔍 Tìm khách đặt sân, SĐT, tên sân..."
                   oninput="filterOwnerConversations()"
                   autocomplete="off">
          </div>

          <div class="omc-conv-list" id="omcConvList">
            <div class="omc-empty-conv">⏳ Đang tải danh sách khách nhắn tin...</div>
          </div>
        </div>

        <!-- CỘT PHẢI: KHUNG CHAT TRỰC TIẾP VỚI KHÁCH & GỬI THẺ BÁO GIÁ -->
        <div class="omc-right-pane">

          <!-- Header cuộc trò chuyện đang chọn -->
          <div class="omc-chat-head" id="omcChatHead">
            <div class="omc-cust-meta">
              <div class="omc-cust-avatar" id="omcActiveAvatar">K</div>
              <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <b id="omcActiveCustName" style="font-size:15px;color:#0f172a">Chọn khách hàng để trò chuyện</b>
                  <span class="tag green" id="omcActiveRepBadge" style="font-size:11px">⭐ 100 Uy tín</span>
                </div>
                <small id="omcActiveSubInfo" style="margin:2px 0 0;color:#047857;font-weight:700">
                  🏟 Đang tải thông tin sân khách liên hệ...
                </small>
              </div>
            </div>

            <div class="omc-head-actions">
              <button type="button"
                      class="omc-quote-btn"
                      onclick="toggleOwnerPitchCardModal()">
                📤 Gửi Thẻ Sân / Báo Giá Nhanh
              </button>
            </div>
          </div>

          <!-- Khung Chèn Nhanh Thẻ Đặt Sân / Báo Giá (Collapsible Drawer ngay dưới Header) -->
          <div class="omc-pitch-card-drawer" id="omcPitchCardDrawer" style="display:none">
            <div class="omc-pcd-top">
              <b>🏟 Gửi Thẻ Đặt Sân & Báo Giá Trực Tiếp Cho Khách Hàng</b>
              <button type="button" class="omc-pcd-close" onclick="toggleOwnerPitchCardModal(false)">✕ Đóng</button>
            </div>
            <div class="omc-pcd-grid">
              <div>
                <label>Chọn Sân con (5A/5B/7A/11A)</label>
                <select id="omcQuoteSubPitch" onchange="syncOwnerQuotePriceFromPitch()">
                  <?php foreach ($venueFields as $vfOpt):
                      $vfCode = !empty($vfOpt['pitch_code']) ? ('Sân ' . $vfOpt['pitch_code']) : ('Sân ' . $vfOpt['type'] . 'A');
                      $vfPeak = (int)($vfOpt['peak_price'] ?? round($vfOpt['price_per_hour'] * 1.6));
                  ?>
                    <option value="<?=(int)$vfOpt['id']?>"
                            data-code="<?=e($vfCode)?>"
                            data-price="<?=$vfPeak?>">
                      ⚽ <?=e($vfCode)?> — <?=e($vfOpt['name'])?> (<?=money($vfPeak)?>/ca)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label>Khung giờ báo giá</label>
                <select id="omcQuoteTimeSlot">
                  <option value="17:30 - 19:00">🕒 17:30 - 19:00 (Giờ vàng)</option>
                  <option value="18:00 - 19:30" selected>🔥 18:00 - 19:30 (Giờ đẹp nhất)</option>
                  <option value="19:00 - 20:30">🔥 19:00 - 20:30 (Giờ vàng)</option>
                  <option value="19:30 - 21:00">🌙 19:30 - 21:00 (Tối mát)</option>
                  <option value="20:30 - 22:00">🌙 20:30 - 22:00 (Đêm muộn)</option>
                  <option value="07:00 - 08:30">☀️ 07:00 - 08:30 (Sáng sớm)</option>
                </select>
              </div>

              <div>
                <label>Giá báo cho khách (VNĐ)</label>
                <input type="number" id="omcQuotePrice" step="10000" value="300000">
              </div>

              <div>
                <label>Ngày thi đấu</label>
                <input type="date" id="omcQuoteDate" value="<?=date('Y-m-d')?>" min="<?=date('Y-m-d')?>">
              </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
              <input type="text"
                     id="omcQuoteNote"
                     placeholder="Lời nhắn kèm thẻ sân (VD: Sân 5A đang trống ca 18h-19h30, bạn bấm Đặt Ngay để giữ chỗ 10p nhé!)"
                     style="flex:1;margin:0;padding:8px 12px;border-radius:9px;border:1px solid #86efac;font-size:12.5px">
              <button type="button" class="btn sm" style="background:#059669;white-space:nowrap" onclick="sendOwnerPitchQuoteCard()">
                🚀 Gửi Thẻ Đặt Sân Ngay →
              </button>
            </div>
          </div>

          <!-- Luồng Tin Nhắn Giữa Chủ Sân Và Khách (#omcMessagesStream) -->
          <div class="omc-messages-stream" id="omcMessagesStream">
            <div class="omc-empty-conv">Đang tải nội dung cuộc trò chuyện...</div>
          </div>

          <!-- Dải Chip Trả Lời Nhanh Dành Cho Chủ Sân -->
          <div class="omc-quick-replies">
            <button type="button" onclick="sendOwnerQuickReply('Dạ khung giờ này bên mình vẫn còn trống sân đẹp ạ! ⚽')">✔ Báo còn trống sân</button>
            <button type="button" onclick="toggleOwnerPitchCardModal(true)">📤 Đính kèm Thẻ Đặt Sân (300.000đ)</button>
            <button type="button" onclick="sendOwnerQuickReply('Dạ mình đã giữ chỗ tạm 10 phút cho đội bạn, bạn bấm nút Đặt Ngay trên thẻ sân để quét VietQR nhé!')">⏳ Xác nhận giữ sân 10p</button>
            <button type="button" onclick="sendOwnerQuickReply('Sân bên mình cỏ FIFA mới thay, có sẵn bóng thi đấu, áo bib và nước suối miễn phí tại quầy ạ!')">🌱 Gửi thông tin mặt cỏ & tiện ích</button>
            <button type="button" onclick="sendOwnerQuickReply('Đội bạn đặt cố định theo tháng bên mình giảm ngay 15% và tặng miễn phí nước uống mỗi trận nhé!')">🎁 Báo ưu đãi đặt cố định tháng</button>
          </div>

          <!-- Thanh Nhập Tin Nhắn Của Chủ Sân -->
          <form class="omc-input-bar" onsubmit="handleOwnerDashboardSend(event)">
            <button type="button"
                    class="omc-attach-card-btn"
                    title="Chèn Thẻ Báo Giá Sân"
                    onclick="toggleOwnerPitchCardModal()">
              🏟 +Thẻ sân
            </button>
            <input type="text"
                   id="omcReplyInput"
                   placeholder="Nhập tin nhắn trả lời khách đặt sân..."
                   autocomplete="off">
            <button type="submit" class="omc-send-btn">Gửi tin nhắn ➤</button>
          </form>

        </div>
      </div>

      <!-- CSS Scoped cho Trung Tâm Tin Nhắn Chủ Sân -->
      <style>
      .omc-split-layout {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 16px;
        height: 650px;
        min-height: 560px;
        background: #ffffff;
        border-radius: 18px;
        border: 1.5px solid #d1fae5;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.06);
        overflow: hidden;
      }
      .omc-left-pane {
        border-right: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        background: #f8fafc;
        min-height: 0;
      }
      .omc-search-box {
        padding: 12px;
        border-bottom: 1px solid #e2e8f0;
        background: #ffffff;
      }
      .omc-search-box input {
        width: 100%;
        padding: 9px 12px;
        border-radius: 10px;
        border: 1.5px solid #cbd5e1;
        font-size: 12.8px;
        font-family: inherit;
        margin: 0;
        outline: none;
      }
      .omc-search-box input:focus {
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.14);
      }
      .omc-conv-list {
        flex: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
      }
      .omc-conv-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
        background: #ffffff;
        transition: background 0.15s ease;
        text-align: left;
        border-left: 4px solid transparent;
      }
      .omc-conv-item:hover {
        background: #f0fdf4;
      }
      .omc-conv-item.active {
        background: #ecfdf5;
        border-left-color: #059669;
      }
      .omc-conv-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, #059669, #047857);
        color: #ffffff;
        font-weight: 800;
        font-size: 15px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
      }
      .omc-conv-body {
        flex: 1;
        min-width: 0;
      }
      .omc-conv-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        margin-bottom: 2px;
      }
      .omc-conv-name {
        font-size: 13.5px;
        font-weight: 800;
        color: #0f172a;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .omc-conv-time {
        font-size: 11px;
        color: #64748b;
        font-weight: 600;
        flex-shrink: 0;
      }
      .omc-conv-pitch {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        color: #047857;
        background: #dcfce7;
        padding: 1px 7px;
        border-radius: 6px;
        margin-bottom: 4px;
      }
      .omc-conv-bottom {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
      }
      .omc-conv-msg {
        font-size: 12px;
        color: #475569;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .omc-conv-unread {
        background: #ef4444;
        color: #ffffff;
        font-size: 11px;
        font-weight: 800;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }

      /* Right Chat Pane */
      .omc-right-pane {
        display: flex;
        flex-direction: column;
        background: #ffffff;
        min-height: 0;
      }
      .omc-chat-head {
        padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        background: #ffffff;
        flex-wrap: wrap;
      }
      .omc-cust-meta {
        display: flex;
        align-items: center;
        gap: 11px;
      }
      .omc-cust-avatar {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, #064e3b, #059669);
        color: #ffffff;
        font-weight: 800;
        font-size: 17px;
        display: grid;
        place-items: center;
      }
      .omc-quote-btn {
        border: 1.5px solid #059669;
        background: linear-gradient(135deg, #059669, #047857);
        color: #ffffff;
        font-weight: 800;
        font-size: 12.5px;
        padding: 8px 14px;
        border-radius: 10px;
        cursor: pointer;
        font-family: inherit;
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        transition: transform 0.15s;
      }
      .omc-quote-btn:hover {
        transform: translateY(-1px);
      }

      /* Pitch Card Drawer */
      .omc-pitch-card-drawer {
        background: #f0fdf4;
        border-bottom: 1.5px solid #86efac;
        padding: 12px 16px;
      }
      .omc-pcd-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
        color: #064e3b;
        font-size: 13px;
      }
      .omc-pcd-close {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        border-radius: 6px;
        padding: 3px 9px;
        font-size: 11.5px;
        font-weight: 700;
        cursor: pointer;
      }
      .omc-pcd-grid {
        display: grid;
        grid-template-columns: 1.4fr 1.1fr 0.9fr 0.9fr;
        gap: 10px;
      }
      .omc-pcd-grid label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #065f46;
        margin: 0 0 4px;
      }
      .omc-pcd-grid select,
      .omc-pcd-grid input {
        width: 100%;
        margin: 0 !important;
        padding: 7px 10px !important;
        border-radius: 8px !important;
        border: 1px solid #86efac !important;
        font-size: 12.5px !important;
        font-weight: 600;
        background: #ffffff;
      }

      /* Messages Stream */
      .omc-messages-stream {
        flex: 1;
        padding: 16px;
        overflow-y: auto;
        background: #f8fafc;
        display: flex;
        flex-direction: column;
        gap: 12px;
      }
      .omc-msg-row {
        display: flex;
        gap: 9px;
        max-width: 78%;
        align-items: flex-end;
      }
      .omc-msg-row.customer {
        align-self: flex-start;
      }
      .omc-msg-row.owner {
        align-self: flex-end;
        flex-direction: row-reverse;
      }
      .omc-msg-bubble {
        padding: 11px 14px;
        border-radius: 14px;
        font-size: 13.2px;
        line-height: 1.52;
      }
      .omc-msg-row.customer .omc-msg-bubble {
        background: #ffffff;
        color: #0f172a;
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
      }
      .omc-msg-row.owner .omc-msg-bubble {
        background: linear-gradient(135deg, #059669, #047857);
        color: #ffffff;
        border-bottom-right-radius: 4px;
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
      }
      .omc-msg-time {
        font-size: 11px;
        color: #64748b;
        margin-top: 3px;
      }
      .omc-msg-row.owner .omc-msg-time {
        text-align: right;
        color: #059669;
        font-weight: 600;
      }
      .omc-embedded-card {
        margin-top: 8px;
        background: #ffffff;
        border: 1.5px solid #86efac;
        border-radius: 12px;
        padding: 10px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        color: #0f172a;
      }
      .omc-ec-thumb {
        width: 70px;
        height: 58px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid #bbf7d0;
      }
      .omc-quick-replies {
        display: flex;
        gap: 7px;
        padding: 8px 14px;
        background: #ffffff;
        border-top: 1px solid #f1f5f9;
        overflow-x: auto;
        scrollbar-width: none;
      }
      .omc-quick-replies::-webkit-scrollbar { display: none; }
      .omc-quick-replies button {
        border: 1px solid #bbf7d0;
        background: #f0fdf4;
        color: #065f46;
        border-radius: 999px;
        padding: 5px 12px;
        font-size: 11.5px;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        font-family: inherit;
      }
      .omc-quick-replies button:hover {
        background: #059669;
        color: #ffffff;
      }
      .omc-input-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 11px 14px;
        border-top: 1px solid #e2e8f0;
        background: #ffffff;
      }
      .omc-attach-card-btn {
        border: 1.5px solid #34d399;
        background: #ecfdf5;
        color: #065f46;
        font-weight: 800;
        font-size: 12px;
        padding: 9px 12px;
        border-radius: 10px;
        cursor: pointer;
        white-space: nowrap;
        font-family: inherit;
      }
      .omc-input-bar input {
        flex: 1;
        margin: 0 !important;
        padding: 10px 14px !important;
        border-radius: 999px !important;
        border: 1.5px solid #cbd5e1 !important;
        font-size: 13px !important;
        outline: none;
      }
      .omc-input-bar input:focus {
        border-color: #059669 !important;
      }
      .omc-send-btn {
        border: 0;
        background: linear-gradient(135deg, #059669, #047857);
        color: #ffffff;
        font-weight: 800;
        font-size: 13px;
        padding: 10px 18px;
        border-radius: 999px;
        cursor: pointer;
        white-space: nowrap;
        font-family: inherit;
      }
      .omc-empty-conv {
        padding: 32px 16px;
        text-align: center;
        color: #64748b;
        font-size: 13px;
      }
      @media (max-width: 960px) {
        .omc-split-layout {
          grid-template-columns: 1fr;
          height: auto;
        }
        .omc-left-pane {
          max-height: 260px;
          border-right: 0;
          border-bottom: 1px solid #e2e8f0;
        }
        .omc-pcd-grid {
          grid-template-columns: 1fr 1fr;
        }
      }
      </style>

      <!-- Logic JavaScript Trung Tâm Tin Nhắn Chủ Sân (AJAX Polling 4s) -->
      <script>
      const OMC_API_URL = '../api/owner_chat.php';
      let omcConversations = [];
      let omcActiveCustomerId = 0;
      let omcActiveFieldId = <?=(int)($venueFields[0]['id'] ?? 1)?>;
      let omcActiveCustomerName = '';
      let omcThreadSignature = '';

      function omcEsc(str) {
        return String(str ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      function updateOwnerGlobalBadge(totalUnread) {
        const cnt = parseInt(totalUnread, 10) || 0;
        const sb = document.getElementById('sbOwnerChatBadge');
        const pill = document.getElementById('pillOwnerChatBadge');
        if (sb) {
          sb.style.display = cnt > 0 ? 'inline-flex' : 'none';
          sb.textContent = cnt;
        }
        if (pill) {
          pill.style.display = cnt > 0 ? 'inline-block' : 'none';
          pill.textContent = cnt;
        }
      }

      async function loadOwnerConversations(autoSelectFirst = false) {
        const q = (document.getElementById('omcSearchInput')?.value || '').trim();
        try {
          const res = await fetch(OMC_API_URL + '?action=owner_conversations&q=' + encodeURIComponent(q));
          const data = await res.json();
          if (!data || !data.ok) return;

          omcConversations = data.conversations || [];
          updateOwnerGlobalBadge(data.total_unread || 0);
          renderOwnerConversationList();

          if (autoSelectFirst && omcConversations.length > 0 && omcActiveCustomerId === 0) {
            selectOwnerConversation(omcConversations[0].customer_id, omcConversations[0].field_id);
          }
        } catch (err) {}
      }

      function renderOwnerConversationList() {
        const listEl = document.getElementById('omcConvList');
        if (!listEl) return;

        if (!omcConversations.length) {
          listEl.innerHTML = '<div class="omc-empty-conv">Chưa có cuộc hội thoại nào phù hợp.</div>';
          return;
        }

        let html = '';
        omcConversations.forEach(c => {
          const isAct = (parseInt(c.customer_id, 10) === omcActiveCustomerId && parseInt(c.field_id, 10) === omcActiveFieldId);
          const unreadBadge = c.unread_count > 0
            ? `<span class="omc-conv-unread">${c.unread_count}</span>`
            : '';

          html += `
            <div class="omc-conv-item ${isAct ? 'active' : ''}"
                 onclick="selectOwnerConversation(${c.customer_id}, ${c.field_id})">
              <div class="omc-conv-avatar">${omcEsc(c.customer_initial || 'K')}</div>
              <div class="omc-conv-body">
                <div class="omc-conv-top">
                  <span class="omc-conv-name">${omcEsc(c.customer_name)}</span>
                  <span class="omc-conv-time">${omcEsc(c.last_time)}</span>
                </div>
                <span class="omc-conv-pitch">🏟 ${omcEsc(c.pitch_badge)}</span>
                <div class="omc-conv-bottom">
                  <span class="omc-conv-msg">${omcEsc(c.last_message)}</span>
                  ${unreadBadge}
                </div>
              </div>
            </div>
          `;
        });
        listEl.innerHTML = html;
      }

      function filterOwnerConversations() {
        loadOwnerConversations(false);
      }

      async function selectOwnerConversation(customerId, fieldId) {
        omcActiveCustomerId = parseInt(customerId, 10) || 0;
        omcActiveFieldId = parseInt(fieldId, 10) || 0;
        omcThreadSignature = '';
        renderOwnerConversationList();
        await loadOwnerActiveThread(true);
      }

      async function loadOwnerActiveThread(scrollToBottom = false) {
        if (omcActiveCustomerId <= 0 || omcActiveFieldId <= 0) return;

        try {
          const url = OMC_API_URL + '?action=owner_load_thread&customer_id=' + encodeURIComponent(omcActiveCustomerId) + '&field_id=' + encodeURIComponent(omcActiveFieldId);
          const res = await fetch(url);
          const data = await res.json();
          if (!data || !data.ok) return;

          updateOwnerGlobalBadge(data.total_unread || 0);

          if (data.customer && data.field) {
            omcActiveCustomerName = data.customer.full_name;
            const avEl = document.getElementById('omcActiveAvatar');
            const nmEl = document.getElementById('omcActiveCustName');
            const rpEl = document.getElementById('omcActiveRepBadge');
            const sbEl = document.getElementById('omcActiveSubInfo');

            if (avEl) avEl.textContent = data.customer.initial || 'K';
            if (nmEl) nmEl.textContent = data.customer.full_name + ' • 📞 ' + data.customer.phone;
            if (rpEl) rpEl.textContent = '⭐ ' + data.customer.reputation_score + ' Uy tín';
            if (sbEl) sbEl.textContent = '🏟 Đang hỏi: ' + data.field.pitch_code + ' - ' + data.field.name + ' (' + data.field.district + ')';
          }

          // Đồng bộ danh sách sân con trong bộ tạo Thẻ báo giá nhanh
          if (Array.isArray(data.sub_pitches) && data.sub_pitches.length > 0) {
            const subSel = document.getElementById('omcQuoteSubPitch');
            if (subSel && subSel.getAttribute('data-loaded-fid') !== String(omcActiveFieldId)) {
              subSel.setAttribute('data-loaded-fid', String(omcActiveFieldId));
              subSel.innerHTML = data.sub_pitches.map(sp =>
                `<option value="${sp.id}" data-code="${omcEsc(sp.pitch_code)}" data-price="${sp.peak_price}">⚽ ${omcEsc(sp.pitch_code)} — ${omcEsc(sp.name)} (${omcEsc(sp.price_fmt)}/ca)</option>`
              ).join('');
              syncOwnerQuotePriceFromPitch();
            }
          }

          const msgs = data.messages || [];
          const sig = JSON.stringify(msgs.map(m => [m.id, m.is_read, m.message]));
          if (sig !== omcThreadSignature) {
            omcThreadSignature = sig;
            renderOwnerThreadMessages(msgs, data.customer);
          }

          loadOwnerConversations(false);
        } catch (err) {}
      }

      function renderOwnerThreadMessages(messages, customer) {
        const stream = document.getElementById('omcMessagesStream');
        if (!stream) return;

        if (!messages || !messages.length) {
          stream.innerHTML = '<div class="omc-empty-conv">Chưa có tin nhắn nào trong cuộc hội thoại này.</div>';
          return;
        }

        const custInitial = (customer && customer.initial) ? customer.initial : 'K';
        const custName = (customer && customer.full_name) ? customer.full_name : 'Khách hàng';
        let html = '';

        messages.forEach(m => {
          const isOwner = (m.sender_role === 'OWNER');
          let cardHtml = '';
          if (m.pitch_card) {
            const c = m.pitch_card;
            cardHtml = `
              <div class="omc-embedded-card">
                <div>
                  <b style="display:block;font-size:13px;color:#064e3b">🏟 ${omcEsc(c.name)}</b>
                  <span style="display:block;font-size:12px;font-weight:700;color:#059669;margin:2px 0 6px">${omcEsc(c.pitch_code)} (${omcEsc(c.time_slot)}) • ${omcEsc(c.price)}</span>
                  <a href="${omcEsc(c.book_url)}" target="_blank" style="display:inline-block;background:#16a34a;color:#fff !important;font-size:11.5px;font-weight:800;padding:5px 12px;border-radius:7px;text-decoration:none">⚡ Đặt Ngay →</a>
                </div>
                <img class="omc-ec-thumb" src="${omcEsc(c.image)}" alt="" onerror="this.style.display='none'">
              </div>
            `;
          }

          if (isOwner) {
            const readStatus = m.is_read ? '✓✓ Khách đã xem' : '✓ Đã gửi';
            html += `
              <div class="omc-msg-row owner">
                <div>
                  <div class="omc-msg-bubble">
                    <div>${omcEsc(m.message).replace(/\n/g, '<br>')}</div>
                    ${cardHtml}
                  </div>
                  <div class="omc-msg-time">${omcEsc(m.time_label)} • ${readStatus}</div>
                </div>
              </div>
            `;
          } else {
            html += `
              <div class="omc-msg-row customer">
                <div class="omc-conv-avatar" style="width:32px;height:32px;font-size:13px" title="${omcEsc(custName)}">${omcEsc(custInitial)}</div>
                <div>
                  <div class="omc-msg-bubble">
                    <b style="display:block;font-size:11px;color:#059669;margin-bottom:2px">${omcEsc(custName)}</b>
                    <div>${omcEsc(m.message).replace(/\n/g, '<br>')}</div>
                    ${cardHtml}
                  </div>
                  <div class="omc-msg-time">${omcEsc(m.time_label)}</div>
                </div>
              </div>
            `;
          }
        });

        stream.innerHTML = html;
        stream.scrollTop = stream.scrollHeight;
      }

      function toggleOwnerPitchCardModal(forceState) {
        const drawer = document.getElementById('omcPitchCardDrawer');
        if (!drawer) return;
        if (typeof forceState === 'boolean') {
          drawer.style.display = forceState ? 'block' : 'none';
        } else {
          drawer.style.display = (drawer.style.display === 'none') ? 'block' : 'none';
        }
      }

      function syncOwnerQuotePriceFromPitch() {
        const sel = document.getElementById('omcQuoteSubPitch');
        const priceInp = document.getElementById('omcQuotePrice');
        if (!sel || !priceInp) return;
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.getAttribute('data-price')) {
          priceInp.value = opt.getAttribute('data-price');
        }
      }

      async function sendOwnerPitchQuoteCard() {
        if (omcActiveCustomerId <= 0) return;
        const subSel = document.getElementById('omcQuoteSubPitch');
        const slotSel = document.getElementById('omcQuoteTimeSlot');
        const priceInp = document.getElementById('omcQuotePrice');
        const dateInp = document.getElementById('omcQuoteDate');
        const noteInp = document.getElementById('omcQuoteNote');

        const opt = subSel ? subSel.options[subSel.selectedIndex] : null;
        const subFieldId = subSel ? parseInt(subSel.value, 10) : omcActiveFieldId;
        const pitchCode = opt ? (opt.getAttribute('data-code') || 'Sân 5A') : 'Sân 5A';
        const timeSlot = slotSel ? slotSel.value : '18:00 - 19:30';
        const priceVal = priceInp ? priceInp.value : '300000';
        const dateVal = dateInp ? dateInp.value : '';
        const noteText = noteInp ? noteInp.value.trim() : '';

        await submitOwnerMessagePayload(noteText, {
          enabled: true,
          sub_field_id: subFieldId,
          pitch_code: pitchCode,
          time_slot: timeSlot,
          price: priceVal,
          date: dateVal
        });

        if (noteInp) noteInp.value = '';
        toggleOwnerPitchCardModal(false);
      }

      async function handleOwnerDashboardSend(e) {
        if (e && e.preventDefault) e.preventDefault();
        const inp = document.getElementById('omcReplyInput');
        const txt = inp ? inp.value.trim() : '';
        if (!txt) return;
        inp.value = '';
        await submitOwnerMessagePayload(txt, null);
      }

      async function sendOwnerQuickReply(text) {
        await submitOwnerMessagePayload(text, null);
      }

      async function submitOwnerMessagePayload(messageText, pitchCardObj) {
        if (omcActiveCustomerId <= 0 || omcActiveFieldId <= 0) return;
        try {
          const res = await fetch(OMC_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'owner_send_message',
              customer_id: omcActiveCustomerId,
              field_id: omcActiveFieldId,
              message: messageText,
              pitch_card: pitchCardObj
            })
          });
          const data = await res.json();
          if (data && data.ok) {
            omcThreadSignature = '';
            await loadOwnerActiveThread(true);
          }
        } catch (err) {}
      }

      document.addEventListener('DOMContentLoaded', function () {
        loadOwnerConversations(true);
        setInterval(function () {
          loadOwnerActiveThread(false);
        }, 4000);
      });
      </script>
    <?php endif; ?>

  </div>
</div>

<!-- HỘP THOẠI XÁC NHẬN ĐĂNG XUẤT TRONG KÊNH ĐỐI TÁC -->
<div
  id="partnerLogoutModal"
  onclick="if(event.target===this) this.style.display='none'"
  style="position:fixed;inset:0;background:rgba(15,23,42,0.62);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px"
>
  <div style="background:#fff;border-radius:18px;max-width:410px;width:100%;padding:24px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,0.25)">
    <div style="font-size:32px;margin-bottom:8px">🚪</div>
    <h3 style="margin:0 0 8px;color:#0f172a">Bạn có chắc chắn muốn đăng xuất?</h3>
    <p style="margin:0 0 18px;font-size:13.5px;color:#475569">Phiên làm việc Kênh Đối tác sẽ được đóng và xóa sạch Session/Cookie.</p>
    <div style="display:flex;gap:10px">
      <button type="button" class="btn gray" style="flex:1" onclick="document.getElementById('partnerLogoutModal').style.display='none'">Hủy</button>
      <a href="../logout.php" class="btn red" style="flex:1;font-weight:800" onclick="try{localStorage.clear();sessionStorage.clear();}catch(e){}">🚪 Đăng xuất</a>
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
