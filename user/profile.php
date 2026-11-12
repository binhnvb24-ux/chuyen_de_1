<?php
/**
 * MÔ TẢ: Trung tâm Tài khoản Cá nhân Đa vai trò (Customer Profile & Partner Hub)
 * GỒM 4 TAB CHỨC NĂNG CHÍNH:
 *   - Tab 1 (profile): Hồ sơ cá nhân, Hồ sơ Cầu thủ/FC & Thanh Điểm Uy Tín (Reputation Score).
 *   - Tab 2 (bookings): Quản lý Lịch đặt sân & Hủy sân thông minh (Hoàn tiền Ví / Cảnh báo trừ Uy tín).
 *   - Tab 3 (wallet): Ví nội bộ (wallet_balance), Điểm thưởng (loyalty_points) & Lịch sử giao dịch.
 *   - Tab 4 (partner): Đăng ký Hợp tác Mở sân (Multi-step Form + Bản đồ Leaflet Google Maps Tiles
 *                      + Theo dõi tiến trình duyệt đơn + Nút Chuyển sang Kênh Chủ Sân).
 */

require_once '../includes/auth.php';
$u = require_login();

$tab = $_GET['tab'] ?? 'profile';
if (!in_array($tab, ['profile', 'bookings', 'wallet', 'partner', 'security'], true)) {
    $tab = 'profile';
}

// ----------------------------------------------------------------------------
// XỬ LÝ CÁC YÊU CẦU POST TRÊN CẢ 4 TAB
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // 1. Cập nhật Hồ sơ liên hệ + Hồ sơ Cầu thủ / Đội bóng (Tab 1)
    if ($action === 'update_profile') {
        $fullName  = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $teamName  = trim($_POST['team_name'] ?? '');
        $position  = trim($_POST['preferred_position'] ?? 'Tiền vệ');
        $skill     = trim($_POST['skill_level'] ?? 'Vui vẻ');
        $districts = trim($_POST['preferred_districts'] ?? '');
        $jerseyNum = max(1, min(99, (int)($_POST['jersey_number'] ?? 10)));
        $bio       = trim($_POST['bio'] ?? '');

        $stmtEmail = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $stmtEmail->execute([$email, $u['id']]);

        if (mb_strlen($fullName) < 2 || !preg_match('/^0\d{9,10}$/', $phone) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'Thông tin không hợp lệ (Họ tên >= 2 ký tự, SĐT 10 số bắt đầu bằng 0, Email đúng định dạng).');
        } elseif ($stmtEmail->fetchColumn()) {
            flash('err', 'Địa chỉ Email này đã được sử dụng bởi một tài khoản khác.');
        } else {
            $uploadedAvatar = save_uploaded_asset('avatar_file', 'avatar_' . $u['id']);
            $finalAvatar    = $uploadedAvatar ?: ($u['avatar'] ?? '');

            $pdo->prepare("
                UPDATE users
                SET full_name = ?, phone = ?, email = ?, avatar = ?,
                    preferred_position = ?, skill_level = ?, team_name = ?, preferred_districts = ?
                WHERE id = ?
            ")->execute([
                $fullName, $phone, $email, $finalAvatar,
                $position, $skill, $teamName, $districts, $u['id']
            ]);

            $pdo->prepare("
                INSERT INTO user_profiles (user_id, preferred_position, skill_level, team_name, preferred_districts, jersey_number, bio)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    preferred_position = VALUES(preferred_position),
                    skill_level = VALUES(skill_level),
                    team_name = VALUES(team_name),
                    preferred_districts = VALUES(preferred_districts),
                    jersey_number = VALUES(jersey_number),
                    bio = VALUES(bio)
            ")->execute([$u['id'], $position, $skill, $teamName, $districts, $jerseyNum, $bio]);

            flash('ok', '✔ Đã cập nhật Hồ sơ cá nhân & Hồ sơ cầu thủ thành công!');
        }
        header('Location: profile.php?tab=profile');
        exit;
    }

    // 2. Đổi mật khẩu bảo mật (Tab 1 & Tab 5)
    if ($action === 'change_password') {
        $oldPass = $_POST['old'] ?? '';
        $newPass = $_POST['new'] ?? '';
        $confirm = $_POST['new2'] ?? '';
        $isGoogleNoPass = (($u['auth_provider'] ?? 'local') === 'google' || empty($u['password']));

        if (!$isGoogleNoPass && !password_verify($oldPass, (string)$u['password'])) {
            flash('err', 'Mật khẩu hiện tại không chính xác.');
        } elseif (strlen($newPass) < 6) {
            flash('err', 'Mật khẩu mới phải có tối thiểu 6 ký tự.');
        } elseif ($newPass !== $confirm) {
            flash('err', 'Mật khẩu nhập lại không khớp.');
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $u['id']]);
            flash('ok', '🔐 Đã cập nhật mật khẩu bảo vệ tài khoản thành công.');
        }
        $retTab = in_array($_POST['return_tab'] ?? '', ['profile', 'security'], true) ? $_POST['return_tab'] : 'security';
        header('Location: profile.php?tab=' . $retTab);
        exit;
    }

    // 3. Hủy đặt sân thông minh: Hoàn tiền vào Ví nội bộ (nếu trước 2h) hoặc Trừ điểm uy tín (nếu hủy sát giờ < 2h) (Tab 2)
    if ($action === 'cancel_booking') {
        $bookingId       = (int)($_POST['booking_id'] ?? 0);
        $forceLateCancel = (int)($_POST['force_late_cancel'] ?? 0) === 1;

        $stmtB = $pdo->prepare("
            SELECT b.*, f.name AS field_name, p.status AS pay_status, p.amount AS paid_amount
            FROM bookings b
            JOIN fields f ON f.id = b.field_id
            LEFT JOIN payments p ON p.booking_id = b.id
            WHERE b.id = ? AND b.user_id = ?
            LIMIT 1
        ");
        $stmtB->execute([$bookingId, $u['id']]);
        $bk = $stmtB->fetch();

        if (!$bk || !in_array($bk['status'], ['pending', 'confirmed'], true)) {
            flash('err', 'Không thể hủy đơn đặt sân này (Đơn đã hoàn tất hoặc đã hủy trước đó).');
        } else {
            $kickoffTs   = strtotime($bk['booking_date'] . ' ' . $bk['start_time']);
            $secondsLeft = $kickoffTs - time();
            $hoursLeft   = round($secondsLeft / 3600, 1);
            $isPaid      = ($bk['pay_status'] === 'paid' || $bk['status'] === 'confirmed');
            $refundAmt   = $isPaid ? (int)($bk['paid_amount'] ?: $bk['total_price']) : 0;

            if ($secondsLeft >= 7200) {
                // HỦY HỢP LỆ TRƯỚC 2 GIỜ: Hoàn tiền cọc/thanh toán vào Ví nội bộ (wallet_balance)
                $pdo->prepare("
                    UPDATE bookings
                    SET status = 'cancelled',
                        cancel_reason = 'Khách hủy hợp lệ trước giờ quy định (> 2 giờ) - Đã hoàn tiền vào Ví nội bộ'
                    WHERE id = ?
                ")->execute([$bk['id']]);

                $pdo->prepare("
                    UPDATE payments
                    SET status = IF(status = 'paid', 'refunded', status)
                    WHERE booking_id = ?
                ")->execute([$bk['id']]);

                if ($refundAmt > 0) {
                    record_wallet_and_reputation(
                        $u['id'],
                        'REFUND',
                        $refundAmt,
                        0,
                        0,
                        "Hoàn tiền 100% đơn #{$bk['id']} ({$bk['field_name']} ngày " . date('d/m/Y', strtotime($bk['booking_date'])) . ") do hủy hợp lệ trước 2 giờ",
                        $bk['id']
                    );
                    notify($u['id'], "💰 Đã hoàn " . money($refundAmt) . " vào Ví nội bộ của bạn sau khi hủy lịch {$bk['field_name']}.");
                    flash('ok', "✔ Đã hủy sân hợp lệ trước giờ quy định! Hệ thống đã hoàn lại " . money($refundAmt) . " vào Ví nội bộ của bạn.");
                } else {
                    notify($u['id'], "❌ Bạn đã hủy giữ chỗ {$bk['field_name']} ngày " . date('d/m/Y', strtotime($bk['booking_date'])) . " hợp lệ (Không bị trừ điểm uy tín).");
                    flash('ok', "✔ Đã hủy giữ chỗ {$bk['field_name']} hợp lệ (Không bị trừ điểm uy tín).");
                }
            } else {
                // HỦY SÁT GIỜ (< 2 GIỜ TRƯỚC KHI BÓNG LĂN): Cảnh báo hoặc trừ -15 Điểm uy tín
                if (!$forceLateCancel) {
                    flash('err', "⚠️ CẢNH BÁO SÁT GIỜ: Trận đấu tại {$bk['field_name']} chỉ còn {$hoursLeft} giờ nữa là bóng lăn (< 2 giờ quy định). Nếu bạn vẫn muốn hủy khẩn cấp, hãy bấm nút 'Xác nhận hủy sát giờ (-15 điểm uy tín)'.");
                } else {
                    $pdo->prepare("
                        UPDATE bookings
                        SET status = 'cancelled',
                            cancel_reason = 'Hủy sát giờ (< 2 giờ trước giờ bóng lăn) - Bị trừ 15 điểm uy tín'
                        WHERE id = ?
                    ")->execute([$bk['id']]);

                    record_wallet_and_reputation(
                        $u['id'],
                        'REPUTATION',
                        0,
                        -15,
                        0,
                        "Trừ 15 điểm uy tín do hủy sân sát giờ (< 2 giờ) đối với đơn #{$bk['id']} ({$bk['field_name']})",
                        $bk['id']
                    );
                    notify($u['id'], "⚠️ Bạn đã hủy sát giờ đơn #{$bk['id']} ({$bk['field_name']}). Tài khoản bị trừ -15 điểm uy tín theo quy định.");
                    flash('cancel', "Đã hủy đơn #{$bk['id']} ({$bk['field_name']}). Do hủy sát giờ (< 2h), tài khoản bị trừ 15 điểm uy tín và không hoàn cọc.");
                }
            }
        }
        header('Location: profile.php?tab=bookings');
        exit;
    }

    // 4. Nạp tiền thử vào Ví nội bộ hoặc Đổi điểm thưởng Loyalty sang tiền Ví (Tab 3)
    if ($action === 'wallet_topup_demo') {
        $topupAmt = max(50000, min(2000000, (int)($_POST['amount'] ?? 200000)));
        record_wallet_and_reputation(
            $u['id'],
            'DEPOSIT',
            $topupAmt,
            0,
            (int)floor($topupAmt / 10000),
            'Nạp tiền vào Ví nội bộ Sân Bóng Pro qua VietQR 24/7',
            null
        );
        notify($u['id'], "💳 Nạp thành công " . money($topupAmt) . " vào Ví nội bộ Sân Bóng Pro!");
        flash('ok', "💳 Đã nạp thành công " . money($topupAmt) . " vào Ví nội bộ (Tặng thêm +" . (int)floor($topupAmt / 10000) . " điểm thưởng)!");
        header('Location: profile.php?tab=wallet');
        exit;
    }

    if ($action === 'redeem_loyalty') {
        $curLoyalty = (int)($u['loyalty_points'] ?? 0);
        if ($curLoyalty < 100) {
            flash('err', 'Bạn cần tối thiểu 100 điểm tích lũy để quy đổi thành 20.000đ vào Ví nội bộ.');
        } else {
            record_wallet_and_reputation(
                $u['id'],
                'LOYALTY',
                20000,
                0,
                -100,
                'Quy đổi 100 Điểm thưởng tích lũy nhận 20.000đ vào Ví nội bộ',
                null
            );
            flash('ok', '🎁 Đã đổi 100 Điểm thưởng thành +20.000đ vào Ví nội bộ của bạn!');
        }
        header('Location: profile.php?tab=wallet');
        exit;
    }

    // 5. Gửi / Cập nhật Hồ sơ Đăng ký Hợp tác Mở sân (Multi-step Form trên Tab 4)
    if ($action === 'submit_partner_application') {
        $venueName   = trim($_POST['venue_name'] ?? '');
        $venueAddr   = trim($_POST['venue_address'] ?? '');
        $district    = trim($_POST['district'] ?? 'Quận 1');
        $city        = trim($_POST['city'] ?? 'TP. Hồ Chí Minh');
        $lat         = (float)($_POST['latitude'] ?? 10.7768890) ?: 10.7768890;
        $lng         = (float)($_POST['longitude'] ?? 106.7008060) ?: 106.7008060;
        $cnt5        = max(0, min(30, (int)($_POST['cnt_pitch_5'] ?? 4)));
        $cnt7        = max(0, min(20, (int)($_POST['cnt_pitch_7'] ?? 2)));
        $cnt11       = max(0, min(10, (int)($_POST['cnt_pitch_11'] ?? 1)));
        $priceNormal = max(80000, (int)($_POST['price_normal'] ?? 160000));
        $pricePeak   = max($priceNormal, (int)($_POST['price_peak'] ?? 290000));
        $phone       = trim($_POST['contact_phone'] ?? ($u['phone'] ?? '0901234567'));
        $taxCode     = trim($_POST['tax_code'] ?? '');
        $bankName    = trim($_POST['bank_name'] ?? 'MB Bank');
        $bankAccNo   = trim($_POST['bank_account_number'] ?? '');
        $bankHolder  = mb_strtoupper(trim($_POST['bank_account_holder'] ?? ($u['full_name'] ?? '')));

        if ($venueName === '' || $venueAddr === '' || $bankAccNo === '' || $bankHolder === '') {
            flash('err', 'Vui lòng điền đầy đủ Tên cụm sân, Địa chỉ và Tài khoản ngân hàng nhận tiền đối soát.');
            header('Location: profile.php?tab=partner');
            exit;
        }

        $pitchSummary = "{$cnt5} sân 5 người, {$cnt7} sân 7 người" . ($cnt11 > 0 ? " (Ghép được {$cnt11} sân 11 người)" : "");

        // Xử lý upload hoặc link ảnh thực tế sân bóng, GPKD, CCCD mặt trước & mặt sau
        $upVenueImg = save_uploaded_asset('venue_image_file', 'venue_' . $u['id']);
        $upLicense  = save_uploaded_asset('business_license_file', 'license_' . $u['id']);
        $upCccdF    = save_uploaded_asset('id_card_front_file', 'cccd_front_' . $u['id']);
        $upCccdB    = save_uploaded_asset('id_card_back_file', 'cccd_back_' . $u['id']);

        $venueImgUrl = $upVenueImg ?: (trim($_POST['venue_images_url'] ?? '') ?: 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900');
        $licenseUrl  = $upLicense  ?: (trim($_POST['business_license_url'] ?? '') ?: 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?w=600');
        $cccdFront   = $upCccdF    ?: (trim($_POST['id_card_front_url'] ?? '') ?: 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?w=600');
        $cccdBack    = $upCccdB    ?: (trim($_POST['id_card_back_url'] ?? '') ?: 'https://images.unsplash.com/photo-1554224154-26032ffc0d07?w=600');

        // Kiểm tra xem user có đơn đang REJECTED hoặc PENDING để cập nhật lại hay tạo mới
        $stmtExistApp = $pdo->prepare("SELECT id FROM partner_applications WHERE user_id = ? AND status IN ('PENDING','REJECTED') ORDER BY id DESC LIMIT 1");
        $stmtExistApp->execute([$u['id']]);
        $existAppId = (int)$stmtExistApp->fetchColumn();

        if ($existAppId > 0) {
            $pdo->prepare("
                UPDATE partner_applications
                SET venue_name = ?, venue_address = ?, district = ?, city = ?,
                    latitude = ?, longitude = ?, pitch_summary = ?,
                    cnt_pitch_5 = ?, cnt_pitch_7 = ?, cnt_pitch_11 = ?,
                    price_normal = ?, price_peak = ?, contact_phone = ?,
                    venue_images = ?, tax_code = ?, business_license_url = ?,
                    id_card_front_url = ?, id_card_back_url = ?,
                    bank_name = ?, bank_account_number = ?, bank_account_holder = ?,
                    status = 'PENDING', rejection_reason = NULL
                WHERE id = ?
            ")->execute([
                $venueName, $venueAddr, $district, $city,
                $lat, $lng, $pitchSummary,
                $cnt5, $cnt7, $cnt11,
                $priceNormal, $pricePeak, $phone,
                $venueImgUrl, $taxCode, $licenseUrl,
                $cccdFront, $cccdBack,
                $bankName, $bankAccNo, $bankHolder,
                $existAppId
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO partner_applications (
                    user_id, venue_name, venue_address, district, city,
                    latitude, longitude, pitch_summary,
                    cnt_pitch_5, cnt_pitch_7, cnt_pitch_11,
                    price_normal, price_peak, contact_phone,
                    venue_images, tax_code, business_license_url,
                    id_card_front_url, id_card_back_url,
                    bank_name, bank_account_number, bank_account_holder, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')
            ")->execute([
                $u['id'], $venueName, $venueAddr, $district, $city,
                $lat, $lng, $pitchSummary,
                $cnt5, $cnt7, $cnt11,
                $priceNormal, $pricePeak, $phone,
                $venueImgUrl, $taxCode, $licenseUrl,
                $cccdFront, $cccdBack,
                $bankName, $bankAccNo, $bankHolder
            ]);
        }

        if (($u['partner_status'] ?? 'NONE') !== 'APPROVED') {
            $pdo->prepare("UPDATE users SET partner_status = 'PENDING' WHERE id = ?")->execute([$u['id']]);
        }

        $admId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($admId > 0) {
            notify($admId, "📋 Hồ sơ đăng ký Mở sân mới cần duyệt: '{$venueName}' ({$district}) từ tài khoản {$u['full_name']} ({$phone}).");
        }
        notify($u['id'], "✅ Đã gửi hồ sơ đăng ký hợp tác mở sân '{$venueName}' ({$district}). Hệ thống đang thẩm định hồ sơ của bạn.");

        flash('ok', "🎉 Đã gửi hồ sơ đăng ký mở sân '{$venueName}' thành công! Trạng thái hiện tại: PENDING (Đang chờ Admin phê duyệt).");
        header('Location: profile.php?tab=partner');
        exit;
    }
}

// Tải lại dữ liệu mới nhất của User sau khi cập nhật
$u = require_login();
$repScore = (int)($u['reputation_score'] ?? 100);
$repTier  = get_reputation_tier($repScore);
$walletBalance = (float)($u['wallet_balance'] ?? 0);
$loyaltyPoints = (int)($u['loyalty_points'] ?? 0);
$roleCode      = get_user_role_code($u);
$partnerStatus = strtoupper((string)($u['partner_status'] ?? 'NONE'));

// Lấy hồ sơ cầu thủ mở rộng từ user_profiles
$stmtProf = $pdo->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
$stmtProf->execute([$u['id']]);
$uProf = $stmtProf->fetch() ?: [
    'preferred_position'  => $u['preferred_position'] ?? 'Tiền vệ',
    'skill_level'         => $u['skill_level'] ?? 'Vui vẻ',
    'team_name'           => $u['team_name'] ?? '',
    'preferred_districts' => $u['preferred_districts'] ?? '',
    'jersey_number'       => 10,
    'bio'                 => '',
];

// Lấy danh sách Lịch đặt sân của tôi (phục vụ Tab 2)
$bkFilter = $_GET['bk_status'] ?? 'all';
$stmtBk = $pdo->prepare("
    SELECT b.*, f.name AS field_name, f.type AS ftype, f.district, f.image,
           p.status AS pay_status, p.method AS pay_method, p.amount AS paid_amount
    FROM bookings b
    JOIN fields f ON f.id = b.field_id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE b.user_id = ?
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$stmtBk->execute([$u['id']]);
$allMyBookings = $stmtBk->fetchAll();

$filteredBookings = array_filter($allMyBookings, function ($bItem) use ($bkFilter) {
    if ($bkFilter === 'upcoming') {
        return in_array($bItem['status'], ['pending', 'confirmed'], true);
    }
    if ($bkFilter === 'completed') {
        return $bItem['status'] === 'completed';
    }
    if ($bkFilter === 'cancelled') {
        return $bItem['status'] === 'cancelled';
    }
    return true;
});

// Lấy lịch sử Ví nội bộ & Điểm uy tín (phục vụ Tab 1 & Tab 3)
$stmtTx = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 30");
$stmtTx->execute([$u['id']]);
$walletTxs = $stmtTx->fetchAll();

// Lấy danh sách đơn đăng ký Chủ sân của user (phục vụ Tab 4)
$stmtApps = $pdo->prepare("SELECT * FROM partner_applications WHERE user_id = ? ORDER BY id DESC");
$stmtApps->execute([$u['id']]);
$myPartnerApps = $stmtApps->fetchAll();
$latestApp     = $myPartnerApps[0] ?? null;

$active = ($tab === 'partner') ? 'partner' : 'prof';
$title  = 'Trung tâm Tài khoản, Điểm Uy Tín, Ví Nội Bộ & Đăng ký Mở Sân';
include '../includes/header.php';
?>

<style>
  .up-summary-banner {
    background: linear-gradient(125deg, #0f172a 0%, #1e293b 58%, #064e3b 100%);
    color: #ffffff;
    border-radius: 18px;
    padding: 22px 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 18px;
    border-bottom: 4px solid #22c55e;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
  }
  .up-user-left {
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .up-avatar-big {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: linear-gradient(135deg, #22c55e, #15803d);
    border: 3px solid #ffffff;
    display: grid;
    place-items: center;
    font-size: 26px;
    font-weight: 800;
    color: #ffffff;
    overflow: hidden;
    flex-shrink: 0;
  }
  .up-avatar-big img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .up-role-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 5px;
  }
  .up-pill {
    font-size: 11px;
    font-weight: 800;
    padding: 3px 10px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }
  .up-pill.role { background: #22c55e; color: #052e16; }
  .up-pill.partner { background: rgba(255,255,255,0.16); color: #a7f3d0; border: 1px solid rgba(167,243,208,0.4); }
  .up-kpis {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .up-kpi-box {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    padding: 10px 15px;
    min-width: 135px;
  }
  .up-kpi-box small {
    display: block;
    font-size: 11px;
    color: #cbd5e1;
    margin: 0 0 2px;
  }
  .up-kpi-box b {
    font-size: 18px;
    font-weight: 800;
    color: #ffffff;
  }

  /* Thanh chuyển 4 Tab */
  .up-tabs-nav {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 22px;
  }
  .up-tab-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 13px 14px;
    border-radius: 12px;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    color: #334155;
    font-size: 13.5px;
    font-weight: 800;
    text-decoration: none !important;
    transition: all 0.18s ease;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
  }
  .up-tab-btn:hover {
    border-color: #86efac;
    color: #15803d;
    transform: translateY(-2px);
  }
  .up-tab-btn.active {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #ffffff !important;
    border-color: #15803d;
    box-shadow: 0 8px 20px rgba(22, 163, 74, 0.25);
  }

  /* Thanh Điểm Uy Tín (Reputation Meter) */
  .rep-card {
    border-radius: 16px;
    padding: 20px 22px;
    margin-bottom: 22px;
    border: 2px solid <?=$repTier['border']?>;
    background: <?=$repTier['bg']?>;
  }
  .rep-progress-track {
    width: 100%;
    height: 16px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
    margin: 12px 0;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.08);
  }
  .rep-progress-fill {
    height: 100%;
    width: <?=$repScore?>%;
    background: <?=$repTier['color']?>;
    border-radius: 999px;
    transition: width 0.5s ease;
  }
  .rep-tiers-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 14px;
  }
  .rep-tier-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 12px;
  }
  .rep-tier-item.current {
    border: 2px solid <?=$repTier['color']?>;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
  }

  /* Multi-step Partner Form & Map */
  .partner-stepper {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
  }
  .ps-step {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 12px;
    background: #f8fafc;
    border: 1.5px solid #cbd5e1;
    cursor: pointer;
    font-weight: 700;
    color: #475569;
  }
  .ps-step.active {
    background: #ecfdf5;
    border-color: #16a34a;
    color: #065f46;
  }
  .ps-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #cbd5e1;
    color: #0f172a;
    display: grid;
    place-items: center;
    font-weight: 800;
    font-size: 13px;
  }
  .ps-step.active .ps-num {
    background: #16a34a;
    color: #ffffff;
  }
  #partnerPickerMap {
    width: 100%;
    height: 350px;
    border-radius: 14px;
    border: 2px solid #86efac;
    z-index: 1;
  }
  /* Bố cục 2 cột Trang Profile: Sidebar Trái (Menu + Chuyển kênh + Đăng xuất) + Nội dung Phải */
  .up-layout-shell {
    display: grid;
    grid-template-columns: 290px 1fr;
    gap: 22px;
    align-items: start;
  }
  .up-side-menu {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 18px;
    padding: 18px;
    position: sticky;
    top: 84px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
  }
  .up-side-head {
    text-align: center;
    padding-bottom: 14px;
    border-bottom: 1px dashed #cbd5e1;
    margin-bottom: 14px;
  }
  .up-side-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    margin: 0 auto 10px;
    background: linear-gradient(135deg, #22c55e, #15803d);
    color: #fff;
    font-size: 28px;
    font-weight: 800;
    display: grid;
    place-items: center;
    overflow: hidden;
    border: 3px solid #dcfce7;
  }
  .up-side-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .up-channel-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 11px 14px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none !important;
    margin-bottom: 8px;
    transition: all 0.18s ease;
  }
  .up-channel-btn.partner-ch {
    background: linear-gradient(135deg, #065f46, #16a34a);
    color: #ffffff !important;
    box-shadow: 0 6px 16px rgba(22, 163, 74, 0.25);
  }
  .up-channel-btn.admin-ch {
    background: linear-gradient(135deg, #0f172a, #1e293b);
    color: #4ade80 !important;
    border: 1px solid #22c55e;
  }
  .up-side-nav-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .up-side-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 11px 13px;
    border-radius: 11px;
    color: #334155;
    font-weight: 700;
    font-size: 13.5px;
    text-decoration: none !important;
    transition: all 0.16s ease;
    border: 1px solid transparent;
  }
  .up-side-link:hover {
    background: #f0fdf4;
    color: #15803d;
    border-color: #bbf7d0;
  }
  .up-side-link.active {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #ffffff !important;
    box-shadow: 0 6px 16px rgba(22, 163, 74, 0.22);
  }
  .up-logout-btn {
    width: 100%;
    margin-top: 14px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1.5px solid #fecaca;
    background: #fef2f2;
    color: #dc2626;
    font-weight: 800;
    font-size: 13.5px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.18s ease;
  }
  .up-logout-btn:hover {
    background: #dc2626;
    color: #ffffff;
    border-color: #dc2626;
  }
  .logout-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.62);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 16px;
  }
  .logout-modal-card {
    background: #ffffff;
    border-radius: 18px;
    max-width: 420px;
    width: 100%;
    padding: 24px;
    text-align: center;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
    border: 1px solid #fecaca;
  }
  @media (max-width: 980px) {
    .up-layout-shell { grid-template-columns: 1fr; }
    .up-side-menu { position: static; }
    .up-tabs-nav { grid-template-columns: 1fr 1fr; }
    .rep-tiers-grid { grid-template-columns: 1fr; }
  }
</style>

<!-- ==========================================================================
     BỐ CỤC 2 CỘT TRANG PROFILE: SIDEBAR TRÁI (MENU + CHUYỂN KÊNH + ĐĂNG XUẤT)
     VÀ KHUNG NỘI DUNG PHẢI
     ========================================================================== -->
<div class="up-layout-shell">

  <!-- CỘT TRÁI: SIDEBAR TRANG HỒ SƠ CÁ NHÂN -->
  <aside class="up-side-menu">
    <!-- 1. Khối Thông tin chung & Badge Điểm uy tín -->
    <div class="up-side-head">
      <div class="up-side-avatar">
        <?php if (!empty($u['avatar'])): ?>
          <img src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name'])?>" referrerpolicy="no-referrer" onerror="this.style.display='none';this.parentElement.textContent=<?=json_encode(mb_substr($u['full_name'], 0, 1), JSON_UNESCAPED_UNICODE)?>">
        <?php else: ?>
          <?=e(mb_substr($u['full_name'], 0, 1))?>
        <?php endif; ?>
      </div>
      <b style="font-size:16.5px;color:#0f172a;display:block"><?=e($u['full_name'])?></b>
      <small style="color:#64748b;margin:2px 0 8px;display:block">📞 <?=e($u['phone'])?> • @<?=e($u['username'])?></small>

      <?php if (!empty($u['google_id'])): ?>
        <div style="margin-bottom:6px">
          <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:700" title="Google ID: <?=e($u['google_id'])?>">
            <svg width="12" height="12" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
            Đã liên kết Google
          </span>
        </div>
      <?php endif; ?>

      <!-- Badge Điểm uy tín đổi màu Xanh / Vàng / Đỏ -->
      <span style="display:inline-block;padding:4px 11px;border-radius:999px;background:<?=$repTier['color']?>;color:#fff;font-size:11.5px;font-weight:800">
        <?=$repTier['badge_text']?>
      </span>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;text-align:left">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:7px 9px">
          <small style="margin:0;font-size:10.5px;color:#15803d">💰 Ví nội bộ</small>
          <b style="font-size:13px;color:#065f46"><?=money($walletBalance)?></b>
        </div>
        <div style="background:#fefce8;border:1px solid #fde047;border-radius:10px;padding:7px 9px">
          <small style="margin:0;font-size:10.5px;color:#a16207">🎁 Điểm thưởng</small>
          <b style="font-size:13px;color:#854d0e"><?=$loyaltyPoints?> điểm</b>
        </div>
      </div>
    </div>

    <!-- 2. Nút Chuyển Kênh Thông Minh (Dành cho PITCH_OWNER và ADMIN) -->
    <?php if (is_pitch_owner($u)): ?>
      <a href="owner-dashboard.php" class="up-channel-btn partner-ch">
        <span>🏟 Kênh Chủ Sân (Partner Portal)</span>
      </a>
    <?php endif; ?>
    <?php if ($roleCode === 'ADMIN'): ?>
      <a href="../admin/dashboard.php" class="up-channel-btn admin-ch">
        <span>🛡️ Vào Trang Quản Trị (Admin)</span>
      </a>
    <?php endif; ?>

    <!-- 3. Danh sách Menu các Tab trong Trang Profile -->
    <div class="up-side-nav-list">
      <a class="up-side-link <?=$tab === 'profile' ? 'active' : ''?>" href="profile.php?tab=profile">
        <span>👤 Thông tin & Hồ sơ cầu thủ</span>
        <span>›</span>
      </a>
      <a class="up-side-link <?=$tab === 'bookings' ? 'active' : ''?>" href="profile.php?tab=bookings">
        <span>📅 Lịch sử đặt sân & Hoàn cọc</span>
        <span class="tag green" style="font-size:10.5px;padding:1px 7px"><?=count($allMyBookings)?></span>
      </a>
      <a class="up-side-link <?=$tab === 'wallet' ? 'active' : ''?>" href="profile.php?tab=wallet">
        <span>💰 Ví hoàn tiền & Điểm thưởng</span>
        <span>›</span>
      </a>
      <a class="up-side-link <?=$tab === 'partner' ? 'active' : ''?>" href="profile.php?tab=partner">
        <span>🤝 Đăng ký Hợp tác Mở sân</span>
        <span style="font-size:11px"><?=$partnerStatus === 'PENDING' ? '⏳' : ($partnerStatus === 'APPROVED' ? '✔' : '›')?></span>
      </a>
      <a class="up-side-link <?=$tab === 'security' ? 'active' : ''?>" href="profile.php?tab=security">
        <span>🔐 Bảo mật & Đổi mật khẩu</span>
        <span>›</span>
      </a>
    </div>

    <!-- 4. Nút Đăng xuất màu đỏ trực quan ở cuối Sidebar trái của Profile -->
    <button type="button" class="up-logout-btn" onclick="openLogoutConfirmModal()">
      <span>🚪 Đăng xuất khỏi tài khoản</span>
    </button>
  </aside>

  <!-- CỘT PHẢI: KHUNG NỘI DUNG CHÍNH CỦA TRANG PROFILE -->
  <div class="up-main-panel">

<?php if ($tab === 'profile'): ?>
<!-- ==========================================================================
     TAB 1: HỒ SƠ CÁ NHÂN, HỒ SƠ CẦU THỦ & THANH ĐIỂM UY TÍN (REPUTATION SCORE)
     ========================================================================== -->
<div class="rep-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <span style="display:inline-block;padding:4px 12px;border-radius:999px;background:<?=$repTier['color']?>;color:#fff;font-size:12px;font-weight:800;margin-bottom:6px">
        <?=$repTier['badge_text']?>
      </span>
      <h3 style="margin:0;font-size:18px;color:#0f172a"><?=$repTier['title']?></h3>
      <small style="margin:3px 0 0;color:#334155;font-size:13px"><?=$repTier['policy']?></small>
    </div>
    <div style="text-align:right">
      <div style="font-size:32px;font-weight:800;color:<?=$repTier['color']?>;line-height:1"><?=$repScore?><span style="font-size:15px;color:#64748b">/100</span></div>
      <small style="margin:2px 0 0;color:#64748b">Chỉ số Uy tín Đội trưởng</small>
    </div>
  </div>

  <!-- Thanh tiến trình Điểm Uy Tín -->
  <div class="rep-progress-track">
    <div class="rep-progress-fill"></div>
  </div>

  <!-- Giải thích 3 Mức Điểm Uy Tín -->
  <div class="rep-tiers-grid">
    <div class="rep-tier-item <?=$repTier['code'] === 'GOOD' ? 'current' : ''?>">
      <b style="color:#16a34a;display:block;margin-bottom:3px">🟢 Từ 80 – 100 điểm (Uy tín Tốt)</b>
      <span style="color:#475569">Được giữ chỗ HOLD 10 phút, đặt cọc tiêu chuẩn, hủy trước 2h được hoàn 100% cọc vào Ví.</span>
    </div>
    <div class="rep-tier-item <?=$repTier['code'] === 'WARNING' ? 'current' : ''?>">
      <b style="color:#d97706;display:block;margin-bottom:3px">🟡 Từ 60 – 79 điểm (Cảnh báo)</b>
      <span style="color:#475569">Tài khoản có lịch sử hủy sát giờ. Bắt buộc thanh toán trước 100% khi đặt lịch.</span>
    </div>
    <div class="rep-tier-item <?=$repTier['code'] === 'RESTRICTED' ? 'current' : ''?>">
      <b style="color:#dc2626;display:block;margin-bottom:3px">🔴 Dưới 60 điểm (Hạn chế)</b>
      <span style="color:#475569">Khóa tính năng tự động giữ chỗ sân do nhiều lần bùng kèo / hủy sát giờ.</span>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.25fr 0.75fr;gap:22px;align-items:start">
  <!-- Cột trái: Cập nhật Thông tin liên hệ & Hồ sơ Cầu thủ / Đội bóng -->
  <div class="box">
    <h3 style="margin:0 0 16px;font-size:17px;color:#0f172a">⚽ Hồ Sơ Liên Hệ & Hồ Sơ Cầu Thủ / Đội Bóng</h3>
    <form method="post" enctype="multipart/form-data" class="form" style="max-width:100%">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="update_profile">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label>Tên đăng nhập (Cố định)</label>
          <input value="<?=e($u['username'])?>" disabled>
        </div>
        <div>
          <label>Ảnh đại diện (Avatar mới nếu muốn đổi)</label>
          <input type="file" name="avatar_file" accept="image/*">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label>Họ và tên Đội trưởng *</label>
          <input name="full_name" required value="<?=e($u['full_name'])?>">
        </div>
        <div>
          <label>Số điện thoại liên hệ *</label>
          <input name="phone" required value="<?=e($u['phone'])?>">
        </div>
      </div>

      <label>Địa chỉ Email nhận vé điện tử QR *</label>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <input type="email" name="email" required value="<?=e($u['email'])?>" style="flex:1">
        <?php if (!empty($u['google_id'])): ?>
          <span style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:12px;font-weight:700;white-space:nowrap">
            ✔ Đã liên kết Google
          </span>
        <?php endif; ?>
      </div>

      <hr style="border:0;border-top:1px dashed #cbd5e1;margin:16px 0">
      <b style="display:block;font-size:14px;color:#15803d;margin-bottom:8px">🏆 Hồ sơ Cầu thủ & Đội bóng (Phục vụ Đặt sân & Ghép kèo nhanh)</b>

      <div style="display:grid;grid-template-columns:1.3fr 0.7fr;gap:14px">
        <div>
          <label>Tên Đội bóng (Tên FC)</label>
          <input name="team_name" value="<?=e($uProf['team_name'])?>" placeholder="VD: FC Anh Em Quận 1">
        </div>
        <div>
          <label>Số áo yêu thích</label>
          <input type="number" name="jersey_number" min="1" max="99" value="<?=(int)($uProf['jersey_number'] ?? 7)?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label>Vị trí sở trường trên sân</label>
          <select name="preferred_position">
            <?php foreach (['Thủ môn', 'Hậu vệ', 'Tiền vệ', 'Tiền đạo', 'Đa năng'] as $posOpt): ?>
              <option value="<?=e($posOpt)?>" <?=($uProf['preferred_position'] ?? '') === $posOpt ? 'selected' : ''?>><?=e($posOpt)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Trình độ thi đấu</label>
          <select name="skill_level">
            <?php foreach (['Vui vẻ', 'Phong trào', 'Bán chuyên'] as $skOpt): ?>
              <option value="<?=e($skOpt)?>" <?=($uProf['skill_level'] ?? '') === $skOpt ? 'selected' : ''?>><?=e($skOpt)?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label>Khu vực Quận / Huyện thường đá</label>
      <input name="preferred_districts" value="<?=e($uProf['preferred_districts'])?>" placeholder="VD: Tân Bình, Bình Thạnh, Quận 1, Quận 7">

      <label>Giới thiệu ngắn về lối chơi / Đội bóng</label>
      <input name="bio" value="<?=e($uProf['bio'] ?? '')?>" placeholder="VD: Đá đẹp, văn minh, hay giao lưu tối Thứ 3 & Thứ 6">

      <button class="btn" style="margin-top:16px;width:100%" type="submit">💾 LƯU HỒ SƠ CÁ NHÂN & CẦU THỦ</button>
    </form>
  </div>

  <!-- Cột phải: Đổi mật khẩu & Lịch sử Điểm Uy Tín gần đây -->
  <div style="display:flex;flex-direction:column;gap:18px">
    <div class="box">
      <h3 style="margin:0 0 14px;font-size:16.5px;color:#0f172a">🔐 Đổi Mật Khẩu Bảo Vệ</h3>
      <form method="post" class="form" style="max-width:100%">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="change_password">
        <label>Mật khẩu hiện tại</label>
        <input type="password" name="old" placeholder="••••••••" required>
        <label>Mật khẩu mới (>= 6 ký tự)</label>
        <input type="password" name="new" placeholder="••••••••" required>
        <label>Nhập lại mật khẩu mới</label>
        <input type="password" name="new2" placeholder="••••••••" required>
        <button class="btn" style="margin-top:14px;width:100%" type="submit">🔑 CẬP NHẬT MẬT KHẨU</button>
      </form>
    </div>

    <div class="box">
      <h4 style="margin:0 0 10px;font-size:15px;color:#0f172a">📊 Biến động Điểm Uy Tín gần đây</h4>
      <?php
      $repLogs = array_filter($walletTxs, fn($x) => (int)$x['reputation_delta'] !== 0);
      if (!$repLogs):
      ?>
        <small style="color:#64748b">Chưa có biến động trừ điểm nào. Hãy duy trì lịch đặt đúng giờ!</small>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach (array_slice($repLogs, 0, 5) as $rl): ?>
            <div style="padding:8px 10px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px">
              <div style="display:flex;justify-content:space-between;font-weight:800">
                <span style="color:<?=(int)$rl['reputation_delta'] >= 0 ? '#16a34a' : '#dc2626'?>">
                  <?=(int)$rl['reputation_delta'] >= 0 ? '+' . (int)$rl['reputation_delta'] : (int)$rl['reputation_delta']?> điểm uy tín
                </span>
                <span style="color:#64748b;font-weight:500"><?=date('d/m/Y H:i', strtotime($rl['created_at']))?></span>
              </div>
              <div style="color:#334155;margin-top:3px"><?=e($rl['reason'])?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif ($tab === 'bookings'): ?>
<!-- ==========================================================================
     TAB 2: QUẢN LÝ LỊCH ĐẶT SÂN CỦA TÔI & HỦY SÂN HOÀN TIỀN VÍ / CẢNH BÁO UY TÍN
     ========================================================================== -->
<div class="box">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
    <div>
      <h3 style="margin:0;font-size:18px;color:#0f172a">📅 Quản Lý Lịch Đặt Sân Của Tôi</h3>
      <small style="margin:3px 0 0;color:#64748b">
        Chính sách hoàn cọc tự động: Hủy trước giờ bóng lăn <b>&ge; 2 giờ</b> được hoàn 100% tiền vào Ví nội bộ. Hủy sát giờ (<b>&lt; 2 giờ</b>) bị trừ <b>-15 điểm uy tín</b>.
      </small>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php
      $bkTabs = [
          'all'       => 'Tất cả (' . count($allMyBookings) . ')',
          'upcoming'  => '⏳ Sắp diễn ra',
          'completed' => '🏆 Đã hoàn thành',
          'cancelled' => '✕ Đã hủy',
      ];
      foreach ($bkTabs as $kBk => $lblBk):
      ?>
        <a href="profile.php?tab=bookings&bk_status=<?=$kBk?>"
           class="btn sm <?=$bkFilter === $kBk ? '' : 'outline'?>">
          <?=$lblBk?>
        </a>
      <?php endforeach; ?>
      <a href="booking.php" class="btn sm">+ Đặt sân mới</a>
    </div>
  </div>

  <?php if (!$filteredBookings): ?>
    <div class="empty">Không có đơn đặt sân nào trong danh mục này.</div>
  <?php else: ?>
    <div class="tw">
      <table>
        <tr>
          <th>Mã vé</th>
          <th>Cụm sân bóng</th>
          <th>Ngày & Giờ thi đấu</th>
          <th>Tổng tiền / Thanh toán</th>
          <th>Trạng thái</th>
          <th>Quyền Hủy & Thao tác</th>
        </tr>
        <?php foreach ($filteredBookings as $bRow):
            $kickoffTs = strtotime($bRow['booking_date'] . ' ' . $bRow['start_time']);
            $secLeft   = $kickoffTs - time();
            $hrsLeft   = round($secLeft / 3600, 1);
            $canCancel = in_array($bRow['status'], ['pending', 'confirmed'], true) && $secLeft > -3600;
            $isSafeCancel = $secLeft >= 7200;
        ?>
          <tr>
            <td><b>#<?=(int)$bRow['id']?></b></td>
            <td>
              <b><?=e($bRow['field_name'])?></b>
              <small style="margin:2px 0 0">📍 <?=e($bRow['district'])?> • Sân <?=$bRow['ftype']?> người</small>
            </td>
            <td>
              <b><?=date('d/m/Y', strtotime($bRow['booking_date']))?></b>
              <small style="margin:2px 0 0;color:#16a34a;font-weight:700">
                🕒 <?=substr($bRow['start_time'], 0, 5)?> – <?=substr($bRow['end_time'], 0, 5)?>
              </small>
            </td>
            <td>
              <b style="color:#16a34a"><?=money($bRow['total_price'])?></b>
              <small style="margin:2px 0 0">
                <?=$bRow['pay_status'] === 'paid' ? '🟢 Đã thanh toán' : ($bRow['pay_status'] === 'refunded' ? '💸 Đã hoàn Ví' : '⏳ Chưa thanh toán')?>
              </small>
            </td>
            <td>
              <?php if ($bRow['status'] === 'confirmed'): ?>
                <span class="tag green">✔ Sắp diễn ra</span>
              <?php elseif ($bRow['status'] === 'pending'): ?>
                <span class="tag blue">⏳ Đang giữ chỗ</span>
              <?php elseif ($bRow['status'] === 'completed'): ?>
                <span class="tag green">🏆 Đã hoàn thành</span>
              <?php else: ?>
                <span class="tag gray">✕ Đã hủy</span>
                <?php if (!empty($bRow['cancel_reason'])): ?>
                  <small style="font-size:10.5px;color:#64748b;max-width:190px"><?=e($bRow['cancel_reason'])?></small>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <?php if ($bRow['status'] === 'pending' && $bRow['pay_status'] !== 'paid'): ?>
                  <a class="btn sm" href="payment-demo.php?booking_id=<?=(int)$bRow['id']?>">💳 Thanh toán</a>
                <?php endif; ?>

                <?php if ($canCancel): ?>
                  <?php if ($isSafeCancel): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Hủy hợp lệ trước giờ bóng lăn (còn <?=$hrsLeft?>h): Tiền đã thanh toán sẽ được hoàn 100% vào Ví nội bộ và không bị trừ điểm uy tín. Xác nhận hủy?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="cancel_booking">
                      <input type="hidden" name="booking_id" value="<?=(int)$bRow['id']?>">
                      <button type="submit" class="btn sm outline" style="border-color:#16a34a;color:#15803d">
                        💸 Hủy & Hoàn Ví (Còn <?=$hrsLeft?>h)
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('⚠️ CẢNH BÁO HỦY SÁT GIỜ (Chỉ còn <?=$hrsLeft?> giờ < 2 giờ quy định):\n- Không được hoàn lại tiền cọc\n- Bị TRỪ -15 ĐIỂM UY TÍN\nBạn có chắc chắn vẫn muốn hủy khẩn cấp không?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="cancel_booking">
                      <input type="hidden" name="booking_id" value="<?=(int)$bRow['id']?>">
                      <input type="hidden" name="force_late_cancel" value="1">
                      <button type="submit" class="btn sm red">
                        ⚠️ Hủy sát giờ (-15đ Uy tín)
                      </button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>

                <a class="btn sm outline" href="my-bookings.php">🎫 Xem vé QR</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'wallet'): ?>
<!-- ==========================================================================
     TAB 3: VÍ NỘI BỘ (WALLET_BALANCE), ĐIỂM THƯỞNG & LỊCH SỬ GIAO DỊCH
     ========================================================================== -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
  <!-- Khối Số dư Ví nội bộ & Nạp nhanh -->
  <div class="box" style="background:linear-gradient(135deg,#064e3b,#047857);color:#ffffff;border:0">
    <small style="color:#a7f3d0;font-weight:700;text-transform:uppercase">💰 SỐ DƯ VÍ NỘI BỘ SÂN BÓNG PRO</small>
    <div style="font-size:34px;font-weight:800;margin:6px 0 10px;color:#ffffff"><?=money($walletBalance)?></div>
    <p style="margin:0 0 16px;font-size:12.5px;color:#d1fae5;line-height:1.5">
      Ví nội bộ dùng để nhận <b>tiền hoàn cọc tức thì (100%)</b> khi hủy sân trước giờ quy định, chia tiền cáp kèo hoặc thanh toán đặt sân nhanh không cần quét lại QR.
    </p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="wallet_topup_demo">
      <select name="amount" style="flex:1;min-width:160px;margin:0;background:#fff;color:#0f172a;font-weight:700;border-radius:8px;padding:8px 12px">
        <option value="100000">+ Nạp 100.000đ vào Ví (Demo)</option>
        <option value="200000" selected>+ Nạp 200.000đ vào Ví (Demo)</option>
        <option value="500000">+ Nạp 500.000đ vào Ví (Demo)</option>
      </select>
      <button type="submit" class="btn" style="background:#fde047;color:#0f172a;font-weight:800">⚡ Nạp tiền vào Ví</button>
    </form>
  </div>

  <!-- Khối Điểm thưởng tích lũy (Loyalty Points) -->
  <div class="box" style="display:flex;flex-direction:column;justify-content:space-between">
    <div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <small style="color:#15803d;font-weight:800;text-transform:uppercase">🎁 ĐIỂM THƯỞNG TÍCH LŨY (LOYALTY POINTS)</small>
        <span class="tag green">100 điểm = 20.000đ</span>
      </div>
      <div style="font-size:32px;font-weight:800;color:#0f172a;margin:6px 0"><?=$loyaltyPoints?> <span style="font-size:15px;color:#64748b">điểm</span></div>
      <p style="margin:0 0 14px;font-size:12.5px;color:#475569;line-height:1.5">
        Mỗi lần đặt sân hoàn tất hoặc nạp ví, bạn được cộng điểm tích lũy. Đủ <b>100 điểm</b> có thể quy đổi ngay thành <b>20.000đ tiền mặt trong Ví nội bộ</b>!
      </p>
    </div>
    <form method="post">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="redeem_loyalty">
      <button type="submit" class="btn" style="width:100%" <?=$loyaltyPoints < 100 ? 'disabled' : ''?>>
        🎁 Đổi 100 Điểm Thưởng lấy +20.000đ vào Ví nội bộ
      </button>
    </form>
  </div>
</div>

<!-- Bảng Lịch sử giao dịch Ví & Điểm uy tín -->
<div class="box">
  <h3 style="margin:0 0 14px;font-size:17px;color:#0f172a">📜 Lịch Sử Biến Động Số Dư Ví & Điểm Uy Tín</h3>
  <?php if (!$walletTxs): ?>
    <div class="empty">Chưa có giao dịch nào trong Ví nội bộ của bạn.</div>
  <?php else: ?>
    <div class="tw">
      <table>
        <tr>
          <th>Mã GD</th>
          <th>Thời gian</th>
          <th>Loại giao dịch</th>
          <th>Biến động Ví</th>
          <th>Số dư sau GD</th>
          <th>Điểm Uy tín</th>
          <th>Nội dung / Lý do chi tiết</th>
        </tr>
        <?php foreach ($walletTxs as $tx):
            $typeMap = [
                'REFUND'       => ['💸 Hoàn tiền cọc', 'green'],
                'PAYMENT'      => ['⚽ Thanh toán sân', 'blue'],
                'DEPOSIT'      => ['💳 Nạp tiền Ví', 'green'],
                'REPUTATION'   => ['🛡️ Điểm uy tín', 'amber'],
                'LOYALTY'      => ['🎁 Đổi điểm thưởng', 'green'],
                'ADMIN_ADJUST' => ['⚙️ Admin điều chỉnh', 'blue'],
            ];
            [$tLabel, $tColor] = $typeMap[$tx['tx_type']] ?? [$tx['tx_type'], 'gray'];
        ?>
          <tr>
            <td><b>#<?=(int)$tx['id']?></b></td>
            <td><?=date('d/m/Y H:i', strtotime($tx['created_at']))?></td>
            <td><span class="tag <?=$tColor?>"><?=$tLabel?></span></td>
            <td>
              <?php if ((float)$tx['amount'] != 0): ?>
                <b style="color:<?=(float)$tx['amount'] > 0 ? '#16a34a' : '#dc2626'?>">
                  <?=(float)$tx['amount'] > 0 ? '+' . money($tx['amount']) : '-' . money(abs($tx['amount']))?>
                </b>
              <?php else: ?>
                <span style="color:#94a3b8">—</span>
              <?php endif; ?>
            </td>
            <td><b><?=money($tx['balance_after'])?></b></td>
            <td>
              <?php if ((int)$tx['reputation_delta'] !== 0): ?>
                <b style="color:<?=(int)$tx['reputation_delta'] > 0 ? '#16a34a' : '#dc2626'?>">
                  <?=(int)$tx['reputation_delta'] > 0 ? '+' . (int)$tx['reputation_delta'] : (int)$tx['reputation_delta']?>đ
                </b>
                <small style="display:inline;color:#64748b">(→ <?=(int)$tx['reputation_after']?>)</small>
              <?php else: ?>
                <span style="color:#94a3b8">Giữ nguyên (<?=(int)$tx['reputation_after']?>)</span>
              <?php endif; ?>
            </td>
            <td><?=e($tx['reason'])?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'partner'): ?>
<!-- ==========================================================================
     TAB 4: ĐĂNG KÝ HỢP TÁC MỞ SÂN (MULTI-STEP FORM + BẢN ĐỒ LEAFLET + TIẾN TRÌNH DUYỆT)
     ========================================================================== -->

<?php if ($partnerStatus === 'APPROVED' || is_pitch_owner($u)): ?>
  <?php $myProfileVenues = get_owner_venues($u); ?>
  <div class="partner-hero-banner" style="margin-bottom:20px">
    <div>
      <span class="partner-verified-pill verified" style="margin-bottom:8px">✔ ĐÃ XÁC MINH ĐỐI TÁC CHỦ SÂN (PITCH_OWNER)</span>
      <h2>🏟 Kênh Chủ Sân & Hồ Sơ Đối Tác Đã Kích Hoạt</h2>
      <small>
        Bạn đang sở hữu <b><?=count($myProfileVenues)?> Cụm sân</b> trên bản đồ Sân Bóng Pro. Quản lý sân 5/7/11, bảng giá khung giờ, lịch POS và rút tiền đối soát tại Partner Portal.
      </small>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="owner-dashboard.php" class="btn" style="background:#fde047;color:#052e16 !important;font-weight:800;padding:12px 20px">
        🏟 Mở Kênh Chủ Sân (Partner Portal) →
      </a>
      <a href="owner-dashboard.php?tab=wallet" class="btn outline" style="color:#fff !important;border-color:#86efac">
        🏦 Ví Đối tác & Rút tiền
      </a>
    </div>
  </div>
<?php endif; ?>

<?php if ($latestApp): ?>
  <!-- Khối Theo dõi Tiến trình xét duyệt Hồ sơ Mở sân (Đồng bộ Badge & Card với Partner Dashboard) -->
  <div class="box" style="margin-bottom:22px;border-left:5px solid <?=$latestApp['status'] === 'APPROVED' ? '#16a34a' : ($latestApp['status'] === 'REJECTED' ? '#dc2626' : '#f59e0b')?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
          <b>📋 Hồ sơ Đối tác Mở sân #<?=(int)$latestApp['id']?>: <?=e($latestApp['venue_name'])?></b>
          <?php if ($latestApp['status'] === 'PENDING'): ?>
            <span class="tag amber">⏳ Chờ duyệt (PENDING)</span>
          <?php elseif ($latestApp['status'] === 'APPROVED'): ?>
            <span class="tag green">✔ Đã xác minh & Kích hoạt sân</span>
          <?php else: ?>
            <span class="tag red">✕ Cần chỉnh sửa & gửi lại</span>
          <?php endif; ?>
        </div>
        <small style="margin:0;color:#475569">
          📍 Địa chỉ: <?=e($latestApp['venue_address'])?> (<?=e($latestApp['district'])?>) • Tọa độ GPS: <code><?=$latestApp['latitude']?>, <?=$latestApp['longitude']?></code> • Quy mô: <?=e($latestApp['pitch_summary'])?>
        </small>
      </div>
      <small style="color:#64748b;margin:0">Cập nhật: <?=date('d/m/Y H:i', strtotime($latestApp['created_at']))?></small>
    </div>

    <?php if ($latestApp['status'] === 'REJECTED' && !empty($latestApp['rejection_reason'])): ?>
      <div style="margin-top:12px;padding:12px 14px;border-radius:10px;background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;font-size:13px">
        <b>⚠️ Lý do Admin từ chối / Yêu cầu bổ sung:</b> <?=e($latestApp['rejection_reason'])?>
        <div style="margin-top:4px;font-size:12px;color:#b91c1c">
          👉 Vui lòng cập nhật lại thông tin ở biểu mẫu bên dưới và bấm <b>"Gửi lại Hồ sơ Xét duyệt"</b>.
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Biểu mẫu Multi-Step (2 Bước) Đăng ký Hợp tác Mở sân kèm Bản đồ Leaflet (Google Maps Tiles) -->
<div class="box">
  <h3 style="margin:0 0 6px;font-size:19px;color:#0f172a">
    🤝 <?=($latestApp && $latestApp['status'] === 'REJECTED') ? 'Cập Nhật & Gửi Lại Hồ Sơ Đăng Ký Chủ Sân' : 'Đăng Ký Hợp Tác Mở Sân Bóng Mới (Multi-step Form)'?>
  </h3>
  <small style="margin:0 0 16px;color:#64748b">
    Hoàn tất 2 bước dưới đây để đưa cụm sân của bạn lên bản đồ Sân Bóng Pro và kích hoạt Kênh Quản Lý Chủ Sân.
  </small>

  <!-- Thanh chuyển Bước 1 & Bước 2 -->
  <div class="partner-stepper">
    <div class="ps-step active" id="stepHeader1" onclick="switchPartnerStep(1)">
      <span class="ps-num">1</span>
      <div>
        <div>Bước 1: Thông tin Cụm sân & Vị trí Bản đồ GPS</div>
        <small style="margin:0;font-weight:500;font-size:11px">Click hoặc kéo thả ghim trên bản đồ để lấy Latitude / Longitude</small>
      </div>
    </div>
    <div class="ps-step" id="stepHeader2" onclick="switchPartnerStep(2)">
      <span class="ps-num">2</span>
      <div>
        <div>Bước 2: Quy mô sân 5/7/11, Pháp lý & Ngân hàng Đối soát</div>
        <small style="margin:0;font-weight:500;font-size:11px">Tải ảnh sân, CCCD/GPKD và tài khoản nhận tiền đặt sân</small>
      </div>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="form" style="max-width:100%" id="partnerMultiStepForm">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="submit_partner_application">

    <!-- ==================== BƯỚC 1: THÔNG TIN SÂN & BẢN ĐỒ LEAFLET ==================== -->
    <div id="partnerStep1">
      <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:14px">
        <div>
          <label>🏟 Tên cụm sân bóng đăng ký *</label>
          <input type="text" name="venue_name" id="inpVenueName" required
                 value="<?=e($latestApp['venue_name'] ?? '')?>"
                 placeholder="VD: Cụm Sân Bóng Đá Ngôi Sao Tân Bình">
        </div>
        <div>
          <label>📞 Số điện thoại Quản lý sân *</label>
          <input type="text" name="contact_phone" required
                 value="<?=e($latestApp['contact_phone'] ?? $u['phone'])?>"
                 placeholder="VD: 0901234567">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1.2fr 0.4fr 0.4fr;gap:14px">
        <div>
          <label>📍 Địa chỉ chi tiết cụm sân *</label>
          <input type="text" name="venue_address" id="inpVenueAddr" required
                 value="<?=e($latestApp['venue_address'] ?? '')?>"
                 placeholder="VD: 158 Hoàng Hoa Thám, Phường 12, Quận Tân Bình, TP.HCM">
        </div>
        <div>
          <label>Quận / Khu vực *</label>
          <select name="district" id="inpVenueDist" onchange="jumpPartnerMapToDistrict(this.value)">
            <?php
            $distList = ['Tân Bình', 'Bình Thạnh', 'Quận 11', 'Quận 1', 'Quận 7', 'TP. Thủ Đức', 'Gò Vấp', 'Quận 12', 'Quận 3', 'Quận 10', 'Phú Nhuận'];
            $curDist  = $latestApp['district'] ?? 'Tân Bình';
            foreach ($distList as $dOpt):
            ?>
              <option value="<?=e($dOpt)?>" <?=$curDist === $dOpt ? 'selected' : ''?>><?=e($dOpt)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Tỉnh / Thành phố</label>
          <input type="text" name="city" value="<?=e($latestApp['city'] ?? 'TP. Hồ Chí Minh')?>" readonly>
        </div>
      </div>

      <!-- Khung chọn tọa độ trên Bản đồ Leaflet.js (Hỗ trợ Google Maps Tiles miễn phí + Kéo thả ghim) -->
      <div style="margin-top:12px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:14px;padding:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
          <div>
            <b style="color:#065f46;font-size:14px">🗺 Chọn vị trí Cụm sân trên Bản đồ (Click hoặc kéo thả ghim đỏ)</b>
            <small style="margin:2px 0 0;color:#047857">Tọa độ Latitude & Longitude sẽ tự động cập nhật chính xác theo điểm ghim</small>
          </div>
          <button type="button" class="btn sm" onclick="useMyCurrentGpsForVenue()">📍 Lấy vị trí GPS hiện tại của tôi</button>
        </div>

        <div id="partnerPickerMap"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
          <div>
            <label style="margin-top:0">🌐 Vĩ độ (Latitude) *</label>
            <input type="number" step="0.0000001" name="latitude" id="inpVenueLat" required
                   value="<?=e($latestApp['latitude'] ?? '10.8016800')?>">
          </div>
          <div>
            <label style="margin-top:0">🌐 Kinh độ (Longitude) *</label>
            <input type="number" step="0.0000001" name="longitude" id="inpVenueLng" required
                   value="<?=e($latestApp['longitude'] ?? '106.6626100')?>">
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn" onclick="switchPartnerStep(2)">
          Tiếp tục sang Bước 2 (Quy mô sân & Pháp lý) →
        </button>
      </div>
    </div>

    <!-- ==================== BƯỚC 2: QUY MÔ SÂN, PHÁP LÝ & NGÂN HÀNG ==================== -->
    <div id="partnerStep2" style="display:none">
      <b style="display:block;font-size:14.5px;color:#15803d;margin-bottom:8px">⚽ 1. Cơ cấu số lượng sân con & Bảng giá dự kiến</b>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
        <div>
          <label>Số lượng Sân 5 người</label>
          <input type="number" name="cnt_pitch_5" min="0" max="30" value="<?=(int)($latestApp['cnt_pitch_5'] ?? 4)?>">
        </div>
        <div>
          <label>Số lượng Sân 7 người</label>
          <input type="number" name="cnt_pitch_7" min="0" max="20" value="<?=(int)($latestApp['cnt_pitch_7'] ?? 2)?>">
        </div>
        <div>
          <label>Số Sân 11 người (Ghép sân)</label>
          <input type="number" name="cnt_pitch_11" min="0" max="10" value="<?=(int)($latestApp['cnt_pitch_11'] ?? 1)?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label>☀️ Giá thuê Giờ thường (VNĐ/giờ) *</label>
          <input type="number" name="price_normal" min="80000" step="10000" required value="<?=(int)($latestApp['price_normal'] ?? 160000)?>">
        </div>
        <div>
          <label>🔥 Giá thuê Giờ vàng 17h-21h (VNĐ/giờ) *</label>
          <input type="number" name="price_peak" min="100000" step="10000" required value="<?=(int)($latestApp['price_peak'] ?? 290000)?>">
        </div>
      </div>

      <hr style="border:0;border-top:1px dashed #cbd5e1;margin:16px 0">
      <b style="display:block;font-size:14.5px;color:#15803d;margin-bottom:8px">🪪 2. Hình ảnh thực tế Cụm sân & Hồ sơ pháp lý (CCCD / Giấy phép KD)</b>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label>📸 Tải ảnh thực tế Cụm sân (hoặc dán Link ảnh)</label>
          <input type="file" name="venue_image_file" accept="image/*">
          <input type="text" name="venue_images_url" value="<?=e($latestApp['venue_images'] ?? '')?>" placeholder="Hoặc dán URL ảnh sân bóng..." style="margin-top:6px">
        </div>
        <div>
          <label>🏢 Mã số thuế Hộ kinh doanh / Doanh nghiệp</label>
          <input type="text" name="tax_code" value="<?=e($latestApp['tax_code'] ?? '0316889900')?>" placeholder="VD: 0316889900">
          <label style="margin-top:8px">📄 Ảnh Giấy phép kinh doanh</label>
          <input type="file" name="business_license_file" accept="image/*">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label>🪪 Ảnh CCCD Chủ sân (Mặt trước)</label>
          <input type="file" name="id_card_front_file" accept="image/*">
        </div>
        <div>
          <label>🪪 Ảnh CCCD Chủ sân (Mặt sau)</label>
          <input type="file" name="id_card_back_file" accept="image/*">
        </div>
      </div>

      <hr style="border:0;border-top:1px dashed #cbd5e1;margin:16px 0">
      <b style="display:block;font-size:14.5px;color:#15803d;margin-bottom:8px">🏦 3. Tài khoản Ngân hàng Nhận tiền Đối soát Đặt sân</b>

      <div style="display:grid;grid-template-columns:0.8fr 1.1fr 1.1fr;gap:14px">
        <div>
          <label>Ngân hàng thụ hưởng *</label>
          <select name="bank_name">
            <?php foreach (['MB Bank', 'Vietcombank', 'Techcombank', 'BIDV', 'ACB', 'VietinBank', 'TPBank', 'VPBank'] as $bOpt): ?>
              <option value="<?=e($bOpt)?>" <?=($latestApp['bank_name'] ?? 'MB Bank') === $bOpt ? 'selected' : ''?>><?=e($bOpt)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Số tài khoản nhận đối soát *</label>
          <input type="text" name="bank_account_number" required
                 value="<?=e($latestApp['bank_account_number'] ?? $u['phone'])?>"
                 placeholder="VD: 0901234567">
        </div>
        <div>
          <label>Tên chủ tài khoản (In hoa) *</label>
          <input type="text" name="bank_account_holder" required
                 value="<?=e($latestApp['bank_account_holder'] ?? mb_strtoupper($u['full_name']))?>"
                 placeholder="VD: NGUYEN VAN A">
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;gap:12px">
        <button type="button" class="btn outline" onclick="switchPartnerStep(1)">← Quay lại Bước 1 (Bản đồ)</button>
        <button type="submit" class="btn" style="background:#16a34a;padding:12px 24px">
          🚀 <?=($latestApp && $latestApp['status'] === 'REJECTED') ? 'GỬI LẠI HỒ SƠ XÉT DUYỆT' : 'GỬI HỒ SƠ ĐĂNG KÝ CHỦ SÂN'?>
        </button>
      </div>
    </div>
  </form>
</div>

<script>
  let partnerMap = null;
  let partnerMarker = null;

  const DISTRICT_CENTERS = {
    'Tân Bình': [10.8016800, 106.6626100],
    'Bình Thạnh': [10.8087500, 106.6961500],
    'Quận 11': [10.7694500, 106.6579200],
    'Quận 1': [10.7751200, 106.6928500],
    'Quận 7': [10.7326500, 106.6997800],
    'TP. Thủ Đức': [10.8294000, 106.7231000],
    'Gò Vấp': [10.8382000, 106.6665000],
    'Quận 12': [10.8633000, 106.6542000],
    'Quận 3': [10.7843000, 106.6844000],
    'Quận 10': [10.7745000, 106.6675000],
    'Phú Nhuận': [10.7992000, 106.6803000]
  };

  function switchPartnerStep(step) {
    const s1 = document.getElementById('partnerStep1');
    const s2 = document.getElementById('partnerStep2');
    const h1 = document.getElementById('stepHeader1');
    const h2 = document.getElementById('stepHeader2');
    if (step === 2) {
      const vName = document.getElementById('inpVenueName').value.trim();
      const vAddr = document.getElementById('inpVenueAddr').value.trim();
      if (!vName || !vAddr) {
        alert('Vui lòng nhập Tên cụm sân và Địa chỉ chi tiết ở Bước 1 trước khi sang Bước 2!');
        return;
      }
      s1.style.display = 'none';
      s2.style.display = 'block';
      h1.classList.remove('active');
      h2.classList.add('active');
    } else {
      s1.style.display = 'block';
      s2.style.display = 'none';
      h1.classList.add('active');
      h2.classList.remove('active');
      if (partnerMap) setTimeout(() => partnerMap.invalidateSize(), 150);
    }
  }

  function updateLatLngInputs(lat, lng) {
    const latInp = document.getElementById('inpVenueLat');
    const lngInp = document.getElementById('inpVenueLng');
    if (latInp) latInp.value = Number(lat).toFixed(7);
    if (lngInp) lngInp.value = Number(lng).toFixed(7);
  }

  function jumpPartnerMapToDistrict(distName) {
    if (!partnerMap || !DISTRICT_CENTERS[distName]) return;
    const [lat, lng] = DISTRICT_CENTERS[distName];
    partnerMap.flyTo([lat, lng], 15, { duration: 0.6 });
    if (partnerMarker) partnerMarker.setLatLng([lat, lng]);
    updateLatLngInputs(lat, lng);
  }

  function useMyCurrentGpsForVenue() {
    if (!navigator.geolocation) {
      alert('Trình duyệt không hỗ trợ định vị GPS.');
      return;
    }
    navigator.geolocation.getCurrentPosition(function (pos) {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      if (partnerMap) partnerMap.flyTo([lat, lng], 16, { duration: 0.6 });
      if (partnerMarker) partnerMarker.setLatLng([lat, lng]);
      updateLatLngInputs(lat, lng);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    const mapBox = document.getElementById('partnerPickerMap');
    if (!mapBox || typeof L === 'undefined') return;

    const initLat = parseFloat(document.getElementById('inpVenueLat').value) || 10.8016800;
    const initLng = parseFloat(document.getElementById('inpVenueLng').value) || 106.6626100;

    partnerMap = L.map('partnerPickerMap').setView([initLat, initLng], 15);

    // Sử dụng Google Maps Tiles miễn phí (kèm fallback OpenStreetMap)
    const googleStreets = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
      maxZoom: 20,
      subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      attribution: '© Google Maps & OpenStreetMap'
    });
    const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap'
    });
    googleStreets.addTo(partnerMap);
    L.control.layers({ 'Bản đồ Google Maps': googleStreets, 'OpenStreetMap': osmLayer }).addTo(partnerMap);

    partnerMarker = L.marker([initLat, initLng], { draggable: true }).addTo(partnerMap)
      .bindPopup('<b>📍 Vị trí Cụm sân đăng ký</b><br>Kéo thả ghim hoặc click bản đồ để đổi tọa độ').openPopup();

    partnerMarker.on('dragend', function (e) {
      const pos = e.target.getLatLng();
      updateLatLngInputs(pos.lat, pos.lng);
    });

    partnerMap.on('click', function (e) {
      partnerMarker.setLatLng(e.latlng);
      updateLatLngInputs(e.latlng.lat, e.latlng.lng);
    });
  });
</script>
<?php elseif ($tab === 'security'): ?>
<!-- ==========================================================================
     TAB 5: BẢO MẬT TÀI KHOẢN, ĐỔI MẬT KHẨU & ĐĂNG XUẤT AN TOÀN
     ========================================================================== -->
<div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:22px;align-items:start">
  <div class="box">
    <h3 style="margin:0 0 14px;font-size:18px;color:#0f172a">🔐 Đổi Mật Khẩu Bảo Vệ Tài Khoản</h3>
    <small style="color:#64748b;display:block;margin-bottom:16px">
      Sử dụng mật khẩu mạnh tối thiểu 6 ký tự để bảo vệ số dư Ví nội bộ và quyền quản lý Cụm sân của bạn.
    </small>
    <form method="post" class="form" style="max-width:100%">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="return_tab" value="security">

      <label>Mật khẩu hiện tại *</label>
      <input type="password" name="old" placeholder="••••••••" required>

      <label>Mật khẩu mới (&ge; 6 ký tự) *</label>
      <input type="password" name="new" placeholder="••••••••" required>

      <label>Nhập lại mật khẩu mới *</label>
      <input type="password" name="new2" placeholder="••••••••" required>

      <button class="btn" style="margin-top:16px;width:100%" type="submit">
        🔑 CẬP NHẬT MẬT KHẨU MỚI
      </button>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:18px">
    <div class="box" style="border-left:4px solid #16a34a">
      <h4 style="margin:0 0 10px;color:#0f172a">🛡️ Trạng thái Phiên đăng nhập & Phân quyền</h4>
      <div style="font-size:13.5px;line-height:1.7;color:#334155">
        <div>• Tài khoản: <b>@<?=e($u['username'])?></b> (ID #<?=(int)$u['id']?>)</div>
        <div>• Vai trò hệ thống: <b><?=e($roleCode)?></b></div>
        <div>• Trạng thái tài khoản: <b><?=e($u['account_status'] ?? 'ACTIVE')?></b></div>
        <div>• Trạng thái đối tác: <b><?=e($partnerStatus)?></b></div>
        <div>• Liên kết mạng xã hội:
          <?php if (!empty($u['google_id'])): ?>
            <b style="color:#1d4ed8">✔ Đã liên kết Google (<?=e($u['email'])?>)</b>
          <?php else: ?>
            <span style="color:#64748b">Chưa liên kết Google (Đăng nhập Google cùng Email để tự động liên kết)</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="box" style="background:#fef2f2;border:1.5px solid #fecaca">
      <h4 style="margin:0 0 8px;color:#991b1b">🚪 Đăng Xuất Khỏi Tài Khoản</h4>
      <small style="color:#b91c1c;display:block;margin-bottom:14px;line-height:1.5">
        Khi đăng xuất, hệ thống sẽ xóa toàn bộ Phiên làm việc (Session), Cookie xác thực và dữ liệu tạm trên trình duyệt của bạn.
      </small>
      <button type="button" class="btn red" style="width:100%;padding:12px;font-weight:800" onclick="openLogoutConfirmModal()">
        🚪 ĐĂNG XUẤT KHỎI HỆ THỐNG
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

  </div><!-- /.up-main-panel -->
</div><!-- /.up-layout-shell -->

<!-- HỘP THOẠI XÁC NHẬN ĐĂNG XUẤT (CONFIRM LOGOUT MODAL) -->
<div class="logout-modal-backdrop" id="logoutConfirmModal" onclick="if(event.target===this) closeLogoutConfirmModal()">
  <div class="logout-modal-card">
    <div style="width:58px;height:58px;border-radius:50%;background:#fee2e2;color:#dc2626;display:grid;place-items:center;font-size:26px;margin:0 auto 12px">
      🚪
    </div>
    <h3 style="margin:0 0 8px;font-size:19px;color:#0f172a">Xác nhận Đăng xuất?</h3>
    <p style="margin:0 0 20px;font-size:13.5px;color:#475569;line-height:1.55">
      Bạn có chắc chắn muốn đăng xuất khỏi tài khoản <b><?=e($u['full_name'])?></b>? Toàn bộ phiên làm việc hiện tại sẽ được đóng an toàn.
    </p>
    <div style="display:flex;gap:10px;justify-content:center">
      <button type="button" class="btn gray" style="flex:1" onclick="closeLogoutConfirmModal()">
        Ở lại trang
      </button>
      <button type="button" class="btn red" id="btnConfirmLogoutNow" style="flex:1;font-weight:800" onclick="executeCleanLogout()">
        🚪 Đăng xuất ngay
      </button>
    </div>
  </div>
</div>

<script>
  function openLogoutConfirmModal() {
    var modal = document.getElementById('logoutConfirmModal');
    if (modal) modal.style.display = 'flex';
  }
  function closeLogoutConfirmModal() {
    var modal = document.getElementById('logoutConfirmModal');
    if (modal) modal.style.display = 'none';
  }
  async function executeCleanLogout() {
    var btn = document.getElementById('btnConfirmLogoutNow');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Đang đăng xuất...';
    }
    try {
      localStorage.removeItem('sb_user_cache');
      sessionStorage.clear();
    } catch (e) {}
    try {
      var res = await fetch('../logout.php?ajax=1', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      var data = await res.json();
      window.location.href = (data && data.redirect) ? data.redirect : '../login.php';
    } catch (err) {
      window.location.href = '../logout.php';
    }
  }
</script>

<?php include '../includes/footer.php'; ?>
