<?php
/**
 * ============================================================================
 * FILE: user/owner-dashboard.php
 * MÔ TẢ: Kênh Đối tác Chủ sân (Partner / Pitch Owner Dashboard) - 5 Phân hệ:
 *   1. overview : Tổng quan, 4 KPI, Biểu đồ doanh thu 7 ngày & Bảng đối soát Ngân hàng.
 *   2. venue    : Quản lý Thông tin Cụm sân (`venues`), Tiện ích, Giờ mở/đóng, Bản đồ Leaflet.
 *   3. pitches  : Quản lý Sơ đồ Sân con 5/7/11 (`fields`/`pitches`), Liên kết Cha-Con (`parent_id`), Bảo trì.
 *   4. pricing  : Cấu hình Bảng giá linh hoạt theo Khung giờ (`pricing_rules`/`pitch_pricing`: Ngày thường / Giờ vàng / Cuối tuần).
 *   5. calendar : Lịch Đặt sân Trực quan (Timeline Grid) & POS (Check-in, Báo bùng kèo No-show trừ Uy tín, Đặt tại quầy Walk-in).
 * ============================================================================
 */

require_once '../includes/auth.php';

// Middleware kiểm tra quyền PITCH_OWNER (hoặc ADMIN)
$u       = require_pitch_owner();
$ownerId = (int)$u['id'];
$isAdmin = (get_user_role_code($u) === 'ADMIN');

$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'venue', 'pitches', 'pricing', 'calendar', 'wallet'], true)) {
    $tab = 'overview';
}

// Tải danh sách Cụm sân (`venues`) của Chủ sân
$myVenues = get_owner_venues($u);

// Nếu tài khoản PITCH_OWNER có sân trong `fields` nhưng chưa có bản ghi `venues`, tự động khởi tạo 1 Cụm sân từ sân đầu tiên
if (!$myVenues) {
    $stFirstF = $pdo->prepare("SELECT * FROM fields WHERE owner_id = ? ORDER BY id ASC LIMIT 1");
    $stFirstF->execute([$ownerId]);
    $firstF = $stFirstF->fetch();
    if ($firstF) {
        $pdo->prepare("
            INSERT INTO venues (owner_id, name, address, district, latitude, longitude, open_time, close_time, amenities, images, contact_phone, description, status)
            VALUES (?, ?, ?, ?, ?, ?, '05:30:00', '23:00:00', 'wifi,parking,canteen,shoes', ?, ?, ?, 'ACTIVE')
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
        $pdo->prepare("UPDATE fields SET venue_id = ? WHERE owner_id = ?")->execute([$newVid, $ownerId]);
        $myVenues = get_owner_venues($u);
    }
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

    // 3. THÊM / CẬP NHẬT SÂN CON & CẤU HÌNH SÂN GỘP CHA-CON (Tab 3: Pitch & Sub-pitch Management)
    if ($action === 'save_sub_pitch') {
        $pitchId   = (int)($_POST['pitch_id'] ?? 0);
        $vId       = (int)($_POST['venue_id'] ?? $selectedVenueId);
        $vRow      = owner_get_venue($u, $vId);
        $pName     = trim($_POST['name'] ?? '');
        $pCode     = strtoupper(trim($_POST['pitch_code'] ?? '5A'));
        $pType     = in_array($_POST['type'] ?? '', ['5', '7', '11'], true) ? $_POST['type'] : '5';
        $parentId  = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $basePrice = max(50000, (int)($_POST['price_per_hour'] ?? 180000));
        $peakPrice = max($basePrice, (int)($_POST['peak_price'] ?? ($basePrice + 120000)));
        $pStatus   = in_array($_POST['status'] ?? '', ['active', 'maintenance'], true) ? $_POST['status'] : 'active';

        if (!$vRow || $pName === '') {
            flash('err', 'Vui lòng chọn Cụm sân hợp lệ và nhập tên sân con.');
        } elseif ($pitchId > 0 && ($parentId === $pitchId || field_parent_creates_cycle($pitchId, $parentId))) {
            flash('err', 'Liên kết Sân Cha không hợp lệ (không thể chọn chính nó hoặc tạo vòng lặp Cha-Con).');
        } else {
            if ($pitchId > 0) {
                $fCheck = owner_get_field($u, $pitchId);
                if (!$fCheck) {
                    flash('err', 'Bạn không có quyền sửa sân con này.');
                } else {
                    $pdo->prepare("
                        UPDATE fields
                        SET venue_id=?, parent_id=?, pitch_code=?, name=?, type=?,
                            price_per_hour=?, normal_price=?, peak_price=?, status=?
                        WHERE id=?
                    ")->execute([
                        $vId, $parentId, $pCode, $pName, $pType,
                        $basePrice, $basePrice, $peakPrice, $pStatus, $pitchId
                    ]);
                    rebuild_field_conflict_map();
                    flash('ok', "✔ Đã cập nhật sân '{$pName}' ({$pCode}) và đồng bộ khóa lịch chéo Cha-Con!");
                }
            } else {
                $vImgs = json_decode((string)$vRow['images'], true) ?: [];
                $defImg = $vImgs[0] ?? 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900';
                $pdo->prepare("
                    INSERT INTO fields (
                        venue_id, owner_id, parent_id, pitch_code, name, type,
                        address, district, latitude, longitude,
                        price_per_hour, sub_pitches, normal_price, peak_price,
                        contact_phone, description, status, image
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $vId,
                    (int)$vRow['owner_id'] ?: $ownerId,
                    $parentId,
                    $pCode,
                    $pName,
                    $pType,
                    $vRow['address'],
                    $vRow['district'],
                    $vRow['latitude'],
                    $vRow['longitude'],
                    $basePrice,
                    "Sân {$pType} người ({$pCode}) thuộc {$vRow['name']}",
                    $basePrice,
                    $peakPrice,
                    $vRow['contact_phone'] ?: $u['phone'],
                    "Sân thành phần hỗ trợ khóa lịch chéo tự động thuộc {$vRow['name']}",
                    $pStatus,
                    $defImg
                ]);
                $newFid = (int)$pdo->lastInsertId();

                // Tạo khung giá mặc định cho sân con mới
                $stPr = $pdo->prepare("INSERT INTO pricing_rules (field_id, day_type, start_time, end_time, price_per_hour, label) VALUES (?, ?, ?, ?, ?, ?)");
                $stPr->execute([$newFid, 'WEEKDAY', '05:00:00', '16:00:00', $basePrice, 'Giờ thường ban ngày']);
                $stPr->execute([$newFid, 'WEEKDAY', '16:00:00', '20:30:00', $peakPrice, '🔥 Giờ Vàng cao điểm']);
                $stPr->execute([$newFid, 'WEEKDAY', '20:30:00', '23:00:00', (int)round(($basePrice + $peakPrice) / 2), 'Giờ tối muộn']);
                $stPr->execute([$newFid, 'WEEKEND', '05:00:00', '16:00:00', (int)round($basePrice * 1.15), 'Cuối tuần ban ngày']);
                $stPr->execute([$newFid, 'WEEKEND', '16:00:00', '23:00:00', (int)round($peakPrice * 1.1), '🔥 Cuối tuần Giờ Vàng']);

                rebuild_field_conflict_map();
                flash('ok', "🏟 Đã thêm sân con '{$pName}' ({$pCode}) và kích hoạt khóa lịch chéo Cha-Con!");
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
        header('Location: owner-dashboard.php?tab=wallet&venue_id=' . $selectedVenueId);
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
        header('Location: owner-dashboard.php?tab=wallet&venue_id=' . $selectedVenueId);
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

    <a class="ps-menu-link <?=$tab === 'calendar' ? 'active' : ''?>" href="?tab=calendar&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">📅</span><span>Quản lý Lịch đặt sân</span></span>
      <?php if ($pendingOwnerBkCount > 0): ?>
        <span class="ps-menu-badge" style="background:#ef4444;color:#fff"><?=$pendingOwnerBkCount?></span>
      <?php else: ?>
        <span>›</span>
      <?php endif; ?>
    </a>

    <a class="ps-menu-link <?=$tab === 'wallet' ? 'active' : ''?>" href="?tab=wallet&venue_id=<?=$selectedVenueId?>">
      <span style="display:inline-flex;align-items:center;gap:9px"><span class="ps-ic">🏦</span><span>Ví Đối tác & Rút tiền</span></span>
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

    <!-- Thanh Sub-nav Ngang đồng bộ 6 mục -->
    <div class="partner-subnav-pills">
      <a class="partner-subnav-pill <?=$tab === 'overview' ? 'active' : ''?>" href="?tab=overview&venue_id=<?=$selectedVenueId?>">📊 Tổng quan & Doanh thu</a>
      <a class="partner-subnav-pill <?=$tab === 'pitches' ? 'active' : ''?>" href="?tab=pitches&venue_id=<?=$selectedVenueId?>">🏟 Sân bóng (5/7/11)</a>
      <a class="partner-subnav-pill <?=$tab === 'pricing' ? 'active' : ''?>" href="?tab=pricing&venue_id=<?=$selectedVenueId?>">💰 Giá theo Khung giờ</a>
      <a class="partner-subnav-pill <?=$tab === 'calendar' ? 'active' : ''?>" href="?tab=calendar&venue_id=<?=$selectedVenueId?>">📅 Lịch đặt sân (Bookings)</a>
      <a class="partner-subnav-pill <?=$tab === 'wallet' ? 'active' : ''?>" href="?tab=wallet&venue_id=<?=$selectedVenueId?>">🏦 Ví Đối tác & Rút tiền</a>
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
          <a href="?tab=wallet&venue_id=<?=$selectedVenueId?>" class="btn sm outline" style="color:#fff !important;border-color:#86efac">🏦 Ví Đối tác: <?=money($u['wallet_balance'] ?? 0)?></a>
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

        <!-- Bảng đối soát tiền cọc sàn chuyển về tài khoản Ngân hàng Chủ sân -->
        <div class="box" style="margin:0;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #86efac">
          <h4 style="margin:0 0 12px;color:#065f46;font-family:var(--font-sport);font-size:17px">
            🏦 ĐỐI SOÁT TIỀN CỌC SÀN & NGÂN HÀNG NHẬN TIỀN
          </h4>
          <?php
          $platformFee   = round($onlineReconcileTotal * 0.05);
          $netPayoutToOwner = max(0, $onlineReconcileTotal - $platformFee);
          ?>
          <div style="background:#ffffff;border-radius:12px;padding:14px;border:1px solid #bbf7d0;font-size:13px;display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
            <div style="display:flex;justify-content:space-between">
              <span style="color:#475569">Tiền khách TT Online (VietQR/Ví/MoMo):</span>
              <b><?=money($onlineReconcileTotal)?></b>
            </div>
            <div style="display:flex;justify-content:space-between">
              <span style="color:#475569">Tiền mặt thu trực tiếp tại quầy (POS):</span>
              <b><?=money($cashCounterTotal)?></b>
            </div>
            <div style="display:flex;justify-content:space-between;color:#dc2626">
              <span>Phí vận hành sàn (5% đơn Online):</span>
              <b>-<?=money($platformFee)?></b>
            </div>
            <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1.5px dashed #86efac;font-size:14.5px">
              <b style="color:#065f46">Sàn chuyển về STK Chủ sân:</b>
              <b style="color:#16a34a;font-size:17px"><?=money($netPayoutToOwner)?></b>
            </div>
          </div>

          <?php if ($partnerAppInfo): ?>
            <div style="font-size:12.5px;color:#064e3b;line-height:1.6">
              <div>• Ngân hàng thụ hưởng: <b><?=e($partnerAppInfo['bank_name'])?></b></div>
              <div>• Số tài khoản: <b><?=e($partnerAppInfo['bank_account_number'])?></b></div>
              <div>• Chủ tài khoản: <b><?=e($partnerAppInfo['bank_account_holder'])?></b></div>
            </div>
          <?php else: ?>
            <small style="color:#047857">Chu kỳ đối soát tự động vào Thứ 2 hàng tuần.</small>
          <?php endif; ?>
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
          <form method="post" class="inl">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="toggle_venue_status">
            <input type="hidden" name="venue_id" value="<?=$curVenue['id']?>">
            <button type="submit" class="btn <?=$curVenue['status'] === 'ACTIVE' ? 'red' : ''?>">
              <?=$curVenue['status'] === 'ACTIVE' ? '⏸ Tạm ngưng mở bán Cụm sân' : '▶️ Kích hoạt Mở bán Cụm sân'?>
            </button>
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

              <!-- Cột phải: Bản đồ Leaflet kéo thả ghim cập nhật Latitude / Longitude -->
              <div>
                <label style="margin-bottom:6px">🗺 Vị trí Ghim Cụm sân trên Bản đồ (Click hoặc kéo thả ghim)</label>
                <div id="ownerVenueMap" style="width:100%;height:340px;border-radius:14px;border:2px solid #16a34a;margin-bottom:12px"></div>
                <div class="two" style="margin:0">
                  <div>
                    <label>Vĩ độ (Latitude)</label>
                    <input name="latitude" id="inpOwnerLat" value="<?=e($curVenue['latitude'])?>" required>
                  </div>
                  <div>
                    <label>Kinh độ (Longitude)</label>
                    <input name="longitude" id="inpOwnerLng" value="<?=e($curVenue['longitude'])?>" required>
                  </div>
                </div>
              </div>
            </div>

            <button type="submit" class="btn" style="margin-top:14px;width:100%;padding:13px;font-weight:800">
              💾 LƯU CÀI ĐẶT CỤM SÂN & VỊ TRÍ BẢN ĐỒ
            </button>
          </form>
        </div>

        <script>
          document.addEventListener('DOMContentLoaded', function () {
            var mapEl = document.getElementById('ownerVenueMap');
            if (!mapEl || typeof L === 'undefined') return;
            var lat = parseFloat(document.getElementById('inpOwnerLat').value) || 10.776889;
            var lng = parseFloat(document.getElementById('inpOwnerLng').value) || 106.700806;
            var vMap = L.map('ownerVenueMap').setView([lat, lng], 16);
            L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
              maxZoom: 20,
              subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
              attribution: '© Google Maps'
            }).addTo(vMap);
            var marker = L.marker([lat, lng], { draggable: true }).addTo(vMap)
              .bindPopup('<b>📍 Vị trí Cụm sân</b><br>Kéo thả ghim để cập nhật tọa độ').openPopup();
            marker.on('dragend', function (e) {
              var p = e.target.getLatLng();
              document.getElementById('inpOwnerLat').value = p.lat.toFixed(7);
              document.getElementById('inpOwnerLng').value = p.lng.toFixed(7);
            });
            vMap.on('click', function (e) {
              marker.setLatLng(e.latlng);
              document.getElementById('inpOwnerLat').value = e.latlng.lat.toFixed(7);
              document.getElementById('inpOwnerLng').value = e.latlng.lng.toFixed(7);
            });
          });
        </script>
      <?php endif; ?>

    <?php elseif ($tab === 'pitches'): ?>
      <!-- ====================================================================
           MODULE 3: QUẢN LÝ SƠ ĐỒ SÂN CON & CẤU HÌNH SÂN GỘP CHA-CON (5/7/11)
           ==================================================================== -->
      <?php
      $editPitchId = (int)($_GET['edit_pitch'] ?? 0);
      $editPitchRow = $editPitchId ? owner_get_field($u, $editPitchId) : null;
      ?>
      <div style="display:grid;grid-template-columns:1.35fr 0.95fr;gap:20px;align-items:start">
        <!-- Cột trái: Danh sách Sân con & Sơ đồ quan hệ Cha-Con -->
        <div class="box" style="margin:0">
          <div class="bh">
            <b>🏟 Sơ đồ Sân con & Liên kết Sân Gộp (<?=count($venueFields)?> sân)</b>
            <span class="tag green">Khóa lịch chéo tự động</span>
          </div>

          <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:12.5px;color:#1e3a8a;line-height:1.5">
            🔗 <b>Quy tắc Khóa Lịch Chéo Sân Gộp (`parent_pitch_id`):</b> Khi khách đặt <b>Sân 11 (hoặc Sân 7 Cha)</b>, hệ thống tự động khóa lịch tất cả các <b>Sân 5 / Sân 7 Con</b> bên dưới trong cùng khung giờ — và ngược lại, nếu 1 sân con đã có khách đặt thì tự động khóa không cho đặt sân lớn ở khung giờ đó.
          </div>

          <?php if (!$venueFields): ?>
            <div class="empty">Chưa có sân con nào trong cụm sân này. Hãy thêm ở biểu mẫu bên phải!</div>
          <?php else: ?>
            <div class="tw">
              <table>
                <thead>
                  <tr>
                    <th>Mã & Tên sân</th>
                    <th>Loại (`pitch_type`)</th>
                    <th>Sân Cha (`parent_pitch_id`)</th>
                    <th>Giá thường / Vàng</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($venueFields as $vf): ?>
                    <tr>
                      <td>
                        <span class="tag blue" style="font-size:11px"><?=e($vf['pitch_code'] ?: ('#' . $vf['id']))?></span>
                        <b><?=e($vf['name'])?></b>
                      </td>
                      <td>
                        <b>PITCH_<?=e($vf['type'])?></b>
                        <small style="display:block">Sân <?=$vf['type']?>v<?=$vf['type']?></small>
                      </td>
                      <td>
                        <?php if (!empty($vf['parent_id'])): ?>
                          <span class="tag green">🔗 Thuộc <?=e($vf['parent_code'] ?: '')?> <?=e($vf['parent_name'])?></span>
                        <?php else: ?>
                          <small style="color:#64748b">Sân gốc / Nguyên cụm</small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <b><?=money($vf['price_per_hour'])?></b>
                        <small style="display:block;color:#d97706">🔥 Vàng: <?=money($vf['peak_price'] ?? ($vf['price_per_hour'] + 120000))?></small>
                      </td>
                      <td>
                        <form method="post" class="inl">
                          <?=csrf_field()?>
                          <input type="hidden" name="action" value="toggle_pitch_maintenance">
                          <input type="hidden" name="field_id" value="<?=$vf['id']?>">
                          <button type="submit" class="tag <?=$vf['status'] === 'active' ? 'green' : 'amber'?>" style="border:none;cursor:pointer">
                            <?=$vf['status'] === 'active' ? '🟢 ACTIVE' : '🛠️ MAINTENANCE'?>
                          </button>
                        </form>
                      </td>
                      <td>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                          <a class="btn sm" href="?tab=pitches&venue_id=<?=$selectedVenueId?>&edit_pitch=<?=$vf['id']?>">✏️ Sửa</a>
                          <a class="btn sm bl" href="?tab=pricing&venue_id=<?=$selectedVenueId?>&field_id=<?=$vf['id']?>">💰 Giá</a>
                          <form method="post" class="inl" onsubmit="return confirm('Xác nhận xóa sân con này?')">
                            <?=csrf_field()?>
                            <input type="hidden" name="action" value="delete_sub_pitch">
                            <input type="hidden" name="field_id" value="<?=$vf['id']?>">
                            <button type="submit" class="btn sm red">🗑️</button>
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

        <!-- Cột phải: Form Thêm / Sửa Sân con & Liên kết Sân Gộp -->
        <div class="box" style="margin:0;border-top:4px solid #16a34a">
          <div class="bh">
            <b><?=$editPitchRow ? '✏️ Chỉnh sửa Sân #' . $editPitchRow['id'] : '➕ Thêm Sân Con / Sân Gộp Mới'?></b>
            <?php if ($editPitchRow): ?>
              <a href="?tab=pitches&venue_id=<?=$selectedVenueId?>" class="btn sm gray">Hủy sửa</a>
            <?php endif; ?>
          </div>

          <form method="post" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="save_sub_pitch">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">
            <input type="hidden" name="pitch_id" value="<?=(int)($editPitchRow['id'] ?? 0)?>">

            <label>Tên sân (VD: Sân 5A, Sân 5B, Sân 11 Nguyên Cụm) *</label>
            <input name="name" required value="<?=e($editPitchRow['name'] ?? '')?>" placeholder="VD: Sân 5A - Cụm Chảo Lửa">

            <div class="two" style="margin:0">
              <div>
                <label>Mã sân (`pitch_code`)</label>
                <input name="pitch_code" value="<?=e($editPitchRow['pitch_code'] ?? '5A')?>" placeholder="VD: 5A, 5B, 7A, 11A">
              </div>
              <div>
                <label>Loại sân (`pitch_type`)</label>
                <select name="type">
                  <option value="5" <?=($editPitchRow['type'] ?? '5') === '5' ? 'selected' : ''?>>PITCH_5 (Sân 5 người)</option>
                  <option value="7" <?=($editPitchRow['type'] ?? '') === '7' ? 'selected' : ''?>>PITCH_7 (Sân 7 người)</option>
                  <option value="11" <?=($editPitchRow['type'] ?? '') === '11' ? 'selected' : ''?>>PITCH_11 (Sân 11 người)</option>
                </select>
              </div>
            </div>

            <label>🔗 Liên kết Sân Cha (`parent_pitch_id` — Để khóa lịch chéo khi gộp sân)</label>
            <select name="parent_id">
              <option value="">-- Không thuộc sân nào (Sân gốc 11 người hoặc Sân độc lập) --</option>
              <?php foreach ($venueFields as $pOpt): ?>
                <?php if ((int)$pOpt['id'] !== (int)($editPitchRow['id'] ?? 0) && in_array($pOpt['type'], ['7', '11'], true)): ?>
                  <option value="<?=$pOpt['id']?>" <?=($editPitchRow['parent_id'] ?? null) == $pOpt['id'] ? 'selected' : ''?>>
                    🏟 [PITCH_<?=$pOpt['type']?> - <?=e($pOpt['pitch_code'] ?: ('#' . $pOpt['id']))?>] <?=e($pOpt['name'])?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>

            <div class="two" style="margin:0">
              <div>
                <label>Giá Giờ Thường / giờ (VNĐ)</label>
                <input type="number" name="price_per_hour" step="10000" min="50000" value="<?=(int)($editPitchRow['price_per_hour'] ?? 180000)?>" required>
              </div>
              <div>
                <label>Giá Giờ Vàng / giờ (VNĐ)</label>
                <input type="number" name="peak_price" step="10000" min="50000" value="<?=(int)($editPitchRow['peak_price'] ?? 300000)?>" required>
              </div>
            </div>

            <label>Trạng thái vận hành</label>
            <select name="status">
              <option value="active" <?=($editPitchRow['status'] ?? 'active') === 'active' ? 'selected' : ''?>>🟢 ACTIVE — Mở nhận khách</option>
              <option value="maintenance" <?=($editPitchRow['status'] ?? '') === 'maintenance' ? 'selected' : ''?>>🛠️ MAINTENANCE — Bảo trì cỏ / đèn (Tạm khóa)</option>
            </select>

            <button type="submit" class="btn" style="width:100%;margin-top:10px">
              💾 <?=$editPitchRow ? 'LƯU CẬP NHẬT SÂN' : 'THÊM SÂN CON & KÍCH HOẠT KHÓA CHÉO'?>
            </button>
          </form>
        </div>
      </div>

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

    <?php elseif ($tab === 'wallet'): ?>
      <!-- ====================================================================
           MODULE 6: VÍ ĐỐI TÁC & RÚT TIỀN ĐỐI SOÁT VỀ NGÂN HÀNG
           (Số dư khả dụng, Số dư tạm giữ Escrow, Rút tiền & Lịch sử giao dịch)
           ==================================================================== -->
      <?php
      $availPartnerWal = (float)($u['wallet_balance'] ?? 0);
      $platformFeeAmt  = round($onlineReconcileTotal * 0.05);
      $netOnlineRev    = max(0, $onlineReconcileTotal - $platformFeeAmt);
      ?>
      <div class="partner-hero-banner">
        <div>
          <h2>🏦 Ví Đối Tác, Đối Soát Doanh Thu & Rút Tiền Ngân Hàng</h2>
          <small>Quản lý số dư khả dụng, số dư tạm giữ (Escrow) của các đơn sắp đá và lập lệnh rút tiền 24/7</small>
        </div>
        <span class="partner-verified-pill verified" style="margin:0;padding:7px 14px;font-size:12px">
          🛡️ Chu kỳ đối soát tự động • Phí sàn 5%
        </span>
      </div>

      <!-- 4 Thẻ KPI Tài chính Đối tác -->
      <div class="partner-kpi-grid">
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon green">💰</div>
          <div class="partner-kpi-meta">
            <small>Số dư khả dụng (Rút ngay)</small>
            <b style="color:#15803d"><?=money($availPartnerWal)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon amber">⏳</div>
          <div class="partner-kpi-meta">
            <small>Số dư tạm giữ (Đơn chờ đá)</small>
            <b style="color:#d97706"><?=money($escrowHoldTotal)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon blue">💳</div>
          <div class="partner-kpi-meta">
            <small>Thực nhận Online (-5% phí)</small>
            <b style="color:#1d4ed8"><?=money($netOnlineRev)?></b>
          </div>
        </div>
        <div class="partner-kpi-card">
          <div class="partner-kpi-icon purple">🏪</div>
          <div class="partner-kpi-meta">
            <small>Thu trực tiếp tại quầy (POS)</small>
            <b style="color:#6d28d9"><?=money($cashCounterTotal)?></b>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.05fr 0.95fr;gap:20px;align-items:start;margin-bottom:20px">
        <!-- Cột trái: Form Lập lệnh Rút tiền về Ngân hàng -->
        <div class="box" style="margin:0;border-top:4px solid #16a34a">
          <div class="bh">
            <b>💸 Lập Lệnh Rút Tiền Về Tài Khoản Ngân Hàng</b>
            <span class="tag green">Giải ngân 24/7</span>
          </div>
          <form method="post" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="request_partner_payout">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">

            <label>Số tiền muốn rút (VNĐ) — Tối đa: <?=money($availPartnerWal)?> *</label>
            <input type="number" name="payout_amount" min="50000" step="10000"
                   value="<?=max(50000, min(500000, (int)$availPartnerWal))?>" required>

            <div class="two" style="margin:0">
              <div>
                <label>Ngân hàng thụ hưởng *</label>
                <select name="bank_name">
                  <?php foreach (['Vietcombank', 'MB Bank', 'Techcombank', 'ACB', 'BIDV', 'VPBank', 'VietinBank', 'TPBank'] as $bOpt): ?>
                    <option value="<?=e($bOpt)?>" <?=($partnerAppInfo['bank_name'] ?? 'MB Bank') === $bOpt ? 'selected' : ''?>><?=e($bOpt)?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label>Số tài khoản nhận tiền *</label>
                <input name="bank_account_number" required
                       value="<?=e($partnerAppInfo['bank_account_number'] ?? $u['phone'])?>"
                       placeholder="VD: 0071001238888">
              </div>
            </div>

            <label>Tên chủ tài khoản thụ hưởng (In hoa không dấu) *</label>
            <input name="bank_account_holder" required
                   value="<?=e($partnerAppInfo['bank_account_holder'] ?? mb_strtoupper($u['full_name']))?>"
                   placeholder="VD: TRAN MINH QUAN">

            <label>Nội dung / Ghi chú đối soát</label>
            <input name="note" value="Rút doanh thu đối soát cụm sân <?=e($curVenue['name'] ?? '')?>">

            <button type="submit" class="btn" style="width:100%;margin-top:14px">
              🏦 XÁC NHẬN RÚT TIỀN VỀ NGÂN HÀNG
            </button>
          </form>
        </div>

        <!-- Cột phải: Thông tin Tài khoản Ngân hàng Mặc định & Mã số thuế -->
        <div class="box" style="margin:0;border-top:4px solid #2563eb">
          <div class="bh">
            <b>🏦 Hồ Sơ Ngân Hàng Đối Soát Mặc Định</b>
            <span class="tag blue">Đã xác thực</span>
          </div>
          <form method="post" class="form" style="max-width:100%">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="update_partner_bank_info">
            <input type="hidden" name="venue_id" value="<?=$selectedVenueId?>">

            <label>Ngân hàng nhận tiền đối soát hàng tuần</label>
            <select name="bank_name">
              <?php foreach (['Vietcombank', 'MB Bank', 'Techcombank', 'ACB', 'BIDV', 'VPBank', 'VietinBank', 'TPBank'] as $bOpt): ?>
                <option value="<?=e($bOpt)?>" <?=($partnerAppInfo['bank_name'] ?? 'MB Bank') === $bOpt ? 'selected' : ''?>><?=e($bOpt)?></option>
              <?php endforeach; ?>
            </select>

            <label>Số tài khoản Ngân hàng *</label>
            <input name="bank_account_number" required value="<?=e($partnerAppInfo['bank_account_number'] ?? $u['phone'])?>">

            <label>Chủ tài khoản Ngân hàng *</label>
            <input name="bank_account_holder" required value="<?=e($partnerAppInfo['bank_account_holder'] ?? mb_strtoupper($u['full_name']))?>">

            <label>Mã số thuế Hộ kinh doanh / Doanh nghiệp</label>
            <input name="tax_code" value="<?=e($partnerAppInfo['tax_code'] ?? '0316889101')?>">

            <button type="submit" class="btn bl" style="width:100%;margin-top:14px">
              💾 LƯU THÔNG TIN NGÂN HÀNG THỤ HƯỞNG
            </button>
          </form>
        </div>
      </div>

      <!-- Bảng Lịch sử Rút tiền & Biến động Ví Đối tác -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
        <div class="box" style="margin:0">
          <div class="bh">
            <b>📜 Lịch sử Lệnh Rút tiền Ngân hàng</b>
          </div>
          <?php if (!$partnerPayoutList): ?>
            <div class="empty">Chưa có lệnh rút tiền nào.</div>
          <?php else: ?>
            <div class="tw">
              <table>
                <thead>
                  <tr>
                    <th>Mã lệnh</th>
                    <th>Thời gian</th>
                    <th>Ngân hàng nhận</th>
                    <th>Số tiền rút</th>
                    <th>Trạng thái</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($partnerPayoutList as $pr): ?>
                    <tr>
                      <td><b>#PO-<?=$pr['id']?></b></td>
                      <td><?=date('d/m/Y H:i', strtotime($pr['created_at']))?></td>
                      <td>
                        <b><?=e($pr['bank_name'])?></b>
                        <small><?=e($pr['bank_account_number'])?> • <?=e($pr['bank_account_holder'])?></small>
                      </td>
                      <td><b style="color:#15803d"><?=money($pr['amount'])?></b></td>
                      <td>
                        <span class="tag <?=$pr['status'] === 'COMPLETED' ? 'green' : ($pr['status'] === 'REJECTED' ? 'red' : 'amber')?>">
                          <?=$pr['status'] === 'COMPLETED' ? '✔ Đã chuyển khoản' : ($pr['status'] === 'REJECTED' ? '✕ Từ chối' : '⏳ Đang xử lý')?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="box" style="margin:0">
          <div class="bh">
            <b>💳 Biến động Ví Đối tác & Điểm thưởng</b>
          </div>
          <?php if (!$partnerWalletLogs): ?>
            <div class="empty">Chưa có biến động số dư nào.</div>
          <?php else: ?>
            <div class="tw">
              <table>
                <thead>
                  <tr>
                    <th>Thời gian</th>
                    <th>Loại GD</th>
                    <th>Biến động</th>
                    <th>Số dư sau GD</th>
                    <th>Nội dung</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($partnerWalletLogs as $wl): ?>
                    <tr>
                      <td><?=date('d/m H:i', strtotime($wl['created_at']))?></td>
                      <td><span class="tag blue"><?=e($wl['tx_type'])?></span></td>
                      <td>
                        <b style="color:<?=(float)$wl['amount'] >= 0 ? '#16a34a' : '#dc2626'?>">
                          <?=(float)$wl['amount'] >= 0 ? '+' . money($wl['amount']) : '-' . money(abs($wl['amount']))?>
                        </b>
                      </td>
                      <td><b><?=money($wl['balance_after'])?></b></td>
                      <td><small style="margin:0;color:#334155"><?=e($wl['reason'])?></small></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
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
