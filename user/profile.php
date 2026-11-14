<?php
/**
 * ============================================================================
 * FILE: user/profile.php
 * MÔ TẢ: Trang Thông Tin Cá Nhân & Hồ Sơ Cầu Thủ (Modern Sport Player Profile)
 * GỒM 3 TAB CHỨC NĂNG GỌN GÀNG, HIỆN ĐẠI (ĐÃ LOẠI BỎ VÍ NỘI BỘ):
 *   - Tab 1 (profile) : Thông tin liên hệ, Thẻ Cầu thủ/Đội bóng (Player Card),
 *                       Đổi mật khẩu bảo mật & Chỉ số Điểm Uy Tín (Reputation Score).
 *   - Tab 2 (bookings): Lịch đặt sân cá nhân, Mở vé QR & Hủy lịch thông minh.
 *   - Tab 3 (partner) : Đăng ký Hợp tác Mở sân (Multi-step Form + Bản đồ Leaflet GPS).
 * ============================================================================
 */

require_once '../includes/auth.php';
$u = require_login();

$tab = $_GET['tab'] ?? 'profile';
if (!in_array($tab, ['profile', 'bookings', 'partner'], true)) {
    $tab = 'profile';
}

// ----------------------------------------------------------------------------
// XỬ LÝ CÁC YÊU CẦU POST (CẬP NHẬT HỒ SƠ, ĐỔI MẬT KHẨU, HỦY LỊCH, ĐĂNG KÝ CHỦ SÂN)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // 1. Cập nhật Thông tin cá nhân + Hồ sơ Cầu thủ / Đội bóng
    if ($action === 'update_profile') {
        $fullName  = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $teamName  = trim($_POST['team_name'] ?? '');
        $position  = trim($_POST['preferred_position'] ?? 'Tiền vệ');
        $skill     = trim($_POST['skill_level'] ?? 'Phong trào');
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

            flash('ok', '✔ Đã cập nhật thông tin cá nhân và hồ sơ cầu thủ thành công!');
        }
        header('Location: profile.php?tab=profile');
        exit;
    }

    // 2. Đổi mật khẩu bảo mật
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
            flash('err', 'Mật khẩu xác nhận nhập lại không khớp.');
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $u['id']]);
            flash('ok', '🔐 Đã cập nhật mật khẩu bảo vệ tài khoản thành công.');
        }
        header('Location: profile.php?tab=profile');
        exit;
    }

    // 3. Hủy đặt sân: Trước 2h (hoàn trả cọc, không trừ uy tín) vs Sát giờ < 2h (trừ 15 điểm uy tín)
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
            $isPaid      = ($bk['pay_status'] === 'paid');
            $refundAmt   = $isPaid ? (int)($bk['paid_amount'] ?: $bk['total_price']) : 0;

            if ($secondsLeft <= 0) {
                flash('err', 'Trận đấu đã bắt đầu hoặc đã qua, không thể hủy lịch.');
            } elseif ($secondsLeft >= 7200) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("
                        UPDATE bookings
                        SET status = 'cancelled',
                            hold_expires_at = NULL,
                            cancel_reason = 'Khách hủy hợp lệ trước giờ quy định (> 2 giờ)'
                        WHERE id = ?
                    ")->execute([$bk['id']]);

                    $pdo->prepare("
                        UPDATE payments
                        SET status = IF(status = 'paid', 'refunded', status)
                        WHERE booking_id = ?
                    ")->execute([$bk['id']]);

                    if ($refundAmt > 0) {
                        notify($u['id'], "↩ Đã hủy hợp lệ lịch {$bk['field_name']} (Đơn #{$bk['id']}). Hệ thống đã ghi nhận hoàn trả " . money($refundAmt) . ".");
                        flash('ok', "✔ Đã hủy sân hợp lệ trước giờ quy định! Hệ thống đã ghi nhận hoàn trả " . money($refundAmt) . " cho đơn #{$bk['id']}.");
                    } else {
                        notify($u['id'], "↩ Bạn đã hủy giữ chỗ {$bk['field_name']} ngày " . date('d/m/Y', strtotime($bk['booking_date'])) . " hợp lệ (Không bị trừ điểm uy tín).");
                        flash('ok', "✔ Đã hủy giữ chỗ {$bk['field_name']} hợp lệ (Không bị trừ điểm uy tín).");
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash('err', 'Lỗi xử lý hủy sân: ' . $e->getMessage());
                }
            } else {
                if (!$forceLateCancel) {
                    flash('err', "⚠️ CẢNH BÁO SÁT GIỜ: Trận đấu tại {$bk['field_name']} chỉ còn {$hoursLeft} giờ nữa là bóng lăn (< 2 giờ quy định). Nếu vẫn muốn hủy, hãy bấm xác nhận hủy sát giờ (-15 điểm uy tín).");
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("
                            UPDATE bookings
                            SET status = 'cancelled',
                                hold_expires_at = NULL,
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
                        $pdo->commit();
                        flash('cancel', "Đã hủy đơn #{$bk['id']} ({$bk['field_name']}). Do hủy sát giờ (< 2h), tài khoản bị trừ 15 điểm uy tín.");
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        flash('err', 'Lỗi xử lý hủy sân sát giờ: ' . $e->getMessage());
                    }
                }
            }
        }
        header('Location: profile.php?tab=bookings');
        exit;
    }

    // 4. Gửi / Cập nhật Hồ sơ Đăng ký Hợp tác Mở sân (Tab 3)
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

        $upVenueImg = save_uploaded_asset('venue_image_file', 'venue_' . $u['id']);
        $upLicense  = save_uploaded_asset('business_license_file', 'license_' . $u['id']);
        $upCccdF    = save_uploaded_asset('id_card_front_file', 'cccd_front_' . $u['id']);
        $upCccdB    = save_uploaded_asset('id_card_back_file', 'cccd_back_' . $u['id']);

        $venueImgUrl = $upVenueImg ?: (trim($_POST['venue_images_url'] ?? '') ?: 'https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900');
        $licenseUrl  = $upLicense  ?: (trim($_POST['business_license_url'] ?? '') ?: 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?w=600');
        $cccdFront   = $upCccdF    ?: (trim($_POST['id_card_front_url'] ?? '') ?: 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?w=600');
        $cccdBack    = $upCccdB    ?: (trim($_POST['id_card_back_url'] ?? '') ?: 'https://images.unsplash.com/photo-1554224154-26032ffc0d07?w=600');

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

        flash('ok', "🎉 Đã gửi hồ sơ đăng ký mở sân '{$venueName}' thành công! Trạng thái hiện tại: Đang chờ Admin phê duyệt.");
        header('Location: profile.php?tab=partner');
        exit;
    }
}

// ----------------------------------------------------------------------------
// TẢI DỮ LIỆU CÁ NHÂN, HỒ SƠ CẦU THỦ, LỊCH ĐẶT & HỒ SƠ ĐỐI TÁC
// ----------------------------------------------------------------------------
$u = require_login();
$repScore      = (int)($u['reputation_score'] ?? 100);
$repTier       = get_reputation_tier($repScore);
$roleCode      = get_user_role_code($u);
$partnerStatus = strtoupper((string)($u['partner_status'] ?? 'NONE'));

$stmtProf = $pdo->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
$stmtProf->execute([$u['id']]);
$uProf = $stmtProf->fetch() ?: [
    'preferred_position'  => $u['preferred_position'] ?? 'Tiền vệ',
    'skill_level'         => $u['skill_level'] ?? 'Phong trào',
    'team_name'           => $u['team_name'] ?? '',
    'preferred_districts' => $u['preferred_districts'] ?? '',
    'jersey_number'       => 10,
    'bio'                 => '',
];

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

// Lấy lịch sử biến động Điểm uy tín
$stmtRepLogs = $pdo->prepare("
    SELECT * FROM wallet_transactions
    WHERE user_id = ? AND reputation_delta <> 0
    ORDER BY id DESC LIMIT 8
");
$stmtRepLogs->execute([$u['id']]);
$repLogs = $stmtRepLogs->fetchAll();

// Lấy danh sách đơn đăng ký Chủ sân của user
$stmtApps = $pdo->prepare("SELECT * FROM partner_applications WHERE user_id = ? ORDER BY id DESC");
$stmtApps->execute([$u['id']]);
$myPartnerApps = $stmtApps->fetchAll();
$latestApp     = $myPartnerApps[0] ?? null;

$active = ($tab === 'partner') ? 'partner' : 'prof';
$title  = 'Thông tin cá nhân & Hồ sơ cầu thủ - Sân Bóng Pro';
include '../includes/header.php';
?>

<style>
  /* ==========================================================================
     GIAO DIỆN THÔNG TIN CÁ NHÂN MỚI (MODERN SPORT PLAYER CARD UI)
     ========================================================================== */
  .pro-hero-banner {
    position: relative;
    overflow: hidden;
    background: linear-gradient(125deg, #052e16 0%, #064e3b 52%, #0f172a 100%);
    color: #ffffff;
    border-radius: 20px;
    padding: 26px 28px;
    margin-bottom: 20px;
    box-shadow: 0 16px 36px rgba(5, 46, 22, 0.2);
    border: 1px solid rgba(74, 222, 128, 0.28);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
  }
  .pro-hero-banner::after {
    content: "";
    position: absolute;
    right: -60px;
    top: -60px;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(34, 197, 94, 0.22) 0%, transparent 70%);
    pointer-events: none;
  }
  .pro-hero-left {
    display: flex;
    align-items: center;
    gap: 20px;
    z-index: 2;
  }
  .pro-avatar-wrap {
    position: relative;
    width: 86px;
    height: 86px;
    flex-shrink: 0;
  }
  .pro-avatar-circle {
    width: 86px;
    height: 86px;
    border-radius: 50%;
    background: linear-gradient(135deg, #22c55e, #15803d);
    border: 3px solid #4ade80;
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.3);
    display: grid;
    place-items: center;
    font-size: 32px;
    font-weight: 800;
    color: #ffffff;
    overflow: hidden;
  }
  .pro-avatar-circle img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .pro-jersey-badge {
    position: absolute;
    bottom: -4px;
    right: -4px;
    background: #f59e0b;
    color: #0f172a;
    font-family: var(--font-sport);
    font-size: 12px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 999px;
    border: 2px solid #052e16;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
  }
  .pro-identity h2 {
    margin: 0 0 6px;
    font-size: 23px;
    font-weight: 800;
    color: #ffffff;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .pro-role-pill {
    font-size: 11px;
    font-weight: 800;
    padding: 4px 11px;
    border-radius: 999px;
    background: rgba(34, 197, 94, 0.2);
    border: 1px solid #4ade80;
    color: #bbf7d0;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  .pro-meta-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 13px;
    color: #d1fae5;
    margin-bottom: 8px;
  }
  .pro-meta-row span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
  }
  .pro-tags-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .pro-mini-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 700;
    padding: 4px 11px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    color: #ecfdf5;
    border: 1px solid rgba(255, 255, 255, 0.18);
  }

  .pro-hero-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 12px;
    z-index: 2;
  }
  .pro-stat-cards {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .pro-stat-box {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 14px;
    padding: 10px 16px;
    min-width: 118px;
    text-align: center;
  }
  .pro-stat-box small {
    font-size: 11px;
    color: #a7f3d0;
    margin: 0 0 2px;
    text-transform: uppercase;
    font-weight: 700;
  }
  .pro-stat-box b {
    font-family: var(--font-sport);
    font-size: 20px;
    font-weight: 800;
    color: #ffffff;
  }
  .pro-hero-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  /* Thanh chuyển 3 Tab gọn gàng */
  .pro-tabs-bar {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
  }
  .pro-tab-item {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 13px 16px;
    border-radius: 14px;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    color: #334155;
    font-size: 13.5px;
    font-weight: 800;
    text-decoration: none !important;
    transition: all 0.18s ease;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
  }
  .pro-tab-item:hover {
    border-color: #86efac;
    color: #15803d;
    transform: translateY(-2px);
  }
  .pro-tab-item.active {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #ffffff !important;
    border-color: #15803d;
    box-shadow: 0 8px 20px rgba(22, 163, 74, 0.25);
  }

  /* Bố cục 2 cột chính trên Tab Thông tin cá nhân */
  .pro-main-grid {
    display: grid;
    grid-template-columns: 1.35fr 0.95fr;
    gap: 20px;
    align-items: start;
  }
  .pro-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 22px 24px;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04);
    margin-bottom: 20px;
  }
  .pro-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding-bottom: 14px;
    margin-bottom: 16px;
    border-bottom: 1.5px solid #f1f5f9;
  }
  .pro-card-head h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .pro-section-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 800;
    color: #15803d;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    padding: 5px 12px;
    border-radius: 999px;
    margin: 18px 0 12px;
  }
  .pro-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }
  .pro-field {
    margin-bottom: 12px;
  }
  .pro-field label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 5px;
  }
  .pro-field input,
  .pro-field select,
  .pro-field textarea {
    width: 100%;
    padding: 10px 13px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13.5px;
    color: #0f172a;
    background: #ffffff;
    transition: border-color 0.18s, box-shadow 0.18s;
    outline: none;
  }
  .pro-field input:focus,
  .pro-field select:focus,
  .pro-field textarea:focus {
    border-color: #16a34a;
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.15);
  }
  .pro-field input[disabled] {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
  }

  /* Thanh Điểm Uy Tín gọn đẹp */
  .pro-rep-strip {
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 20px;
    border: 1.5px solid <?=$repTier['border']?>;
    background: <?=$repTier['bg']?>;
  }
  .pro-rep-track {
    width: 100%;
    height: 12px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
    margin: 10px 0 12px;
  }
  .pro-rep-fill {
    height: 100%;
    width: <?=$repScore?>%;
    background: <?=$repTier['color']?>;
    border-radius: 999px;
  }
  .pro-rep-tiers {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
  }
  .pro-rep-tier-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 9px 11px;
    font-size: 11.5px;
  }
  .pro-rep-tier-box.current {
    border: 2px solid <?=$repTier['color']?>;
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

  @media (max-width: 960px) {
    .pro-main-grid { grid-template-columns: 1fr; }
    .pro-tabs-bar { grid-template-columns: 1fr; }
    .pro-rep-tiers { grid-template-columns: 1fr; }
    .pro-hero-right { align-items: flex-start; width: 100%; }
  }
</style>

<!-- ==========================================================================
     1. BANNER HỒ SƠ CẦU THỦ / TÀI KHOẢN CÁ NHÂN (HERO PLAYER BANNER)
     ========================================================================== -->
<div class="pro-hero-banner">
  <div class="pro-hero-left">
    <div class="pro-avatar-wrap">
      <div class="pro-avatar-circle" id="heroAvatarBox">
        <?php if (!empty($u['avatar'])): ?>
          <img id="heroAvatarImg" src="<?=e(img_src($u['avatar']))?>" alt="<?=e($u['full_name'])?>" referrerpolicy="no-referrer" onerror="this.style.display='none';this.parentElement.textContent=<?=json_encode(mb_substr($u['full_name'], 0, 1), JSON_UNESCAPED_UNICODE)?>">
        <?php else: ?>
          <?=e(mb_substr($u['full_name'], 0, 1))?>
        <?php endif; ?>
      </div>
      <span class="pro-jersey-badge" title="Số áo cầu thủ">#<?=(int)($uProf['jersey_number'] ?? 10)?></span>
    </div>

    <div class="pro-identity">
      <h2>
        <?=e($u['full_name'])?>
        <span class="pro-role-pill">
          <?php if ($roleCode === 'ADMIN'): ?>
            🛡️ Quản trị viên
          <?php elseif (is_pitch_owner($u)): ?>
            🏟 Chủ sân đối tác
          <?php else: ?>
            ⚽ Đội trưởng / Cầu thủ
          <?php endif; ?>
        </span>
      </h2>

      <div class="pro-meta-row">
        <span>👤 @<?=e($u['username'])?></span>
        <span>•</span>
        <span>📞 <?=e($u['phone'] ?: 'Chưa cập nhật')?></span>
        <span>•</span>
        <span>✉️ <?=e($u['email'])?></span>
      </div>

      <div class="pro-tags-row">
        <span class="pro-mini-chip">🏆 <?=e(!empty($uProf['team_name']) ? $uProf['team_name'] : 'FC ' . $u['full_name'])?></span>
        <span class="pro-mini-chip">👟 Vị trí: <?=e($uProf['preferred_position'] ?: 'Tiền vệ')?></span>
        <span class="pro-mini-chip">⚡ Trình độ: <?=e($uProf['skill_level'] ?: 'Phong trào')?></span>
        <?php if (!empty($u['google_id'])): ?>
          <span class="pro-mini-chip" style="background:rgba(59,130,246,0.25);border-color:#93c5fd;color:#dbeafe">
            ✔ Đã liên kết Google
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="pro-hero-right">
    <div class="pro-stat-cards">
      <div class="pro-stat-box">
        <small>Lịch đã đặt</small>
        <b><?=count($allMyBookings)?> trận</b>
      </div>
      <div class="pro-stat-box">
        <small>Điểm uy tín</small>
        <b style="color:#4ade80"><?=$repScore?>/100</b>
      </div>
      <div class="pro-stat-box">
        <small>Hạng uy tín</small>
        <b><?=e($repTier['label'])?></b>
      </div>
    </div>

    <div class="pro-hero-actions">
      <?php if (is_pitch_owner($u)): ?>
        <a href="owner-dashboard.php?tab=pitches" class="btn sm" style="background:#fde047;color:#052e16 !important;font-weight:800">
          🛠️ Tùy chỉnh Sân của tôi →
        </a>
        <a href="owner-dashboard.php?tab=revenue" class="btn sm outline" style="border-color:#4ade80;color:#bbf7d0 !important">
          📈 Bảng Doanh thu & Phí sàn
        </a>
      <?php endif; ?>
      <?php if ($roleCode === 'ADMIN'): ?>
        <a href="../admin/dashboard.php" class="btn sm bl">
          🛡️ Trang Quản Trị →
        </a>
      <?php endif; ?>
      <button type="button" class="btn sm red" onclick="openLogoutConfirmModal()">
        🚪 Đăng xuất
      </button>
    </div>
  </div>
</div>

<!-- ==========================================================================
     2. THANH ĐIỀU HƯỚNG 3 TAB GỌN GÀNG (ĐÃ BỎ TAB VÍ NỘI BỘ)
     ========================================================================== -->
<div class="pro-tabs-bar">
  <a href="profile.php?tab=profile" class="pro-tab-item <?=$tab === 'profile' ? 'active' : ''?>">
    <span>👤 Thông tin cá nhân & Cầu thủ</span>
  </a>
  <a href="profile.php?tab=bookings" class="pro-tab-item <?=$tab === 'bookings' ? 'active' : ''?>">
    <span>📅 Lịch đặt sân của tôi (<?=count($allMyBookings)?>)</span>
  </a>
  <a href="profile.php?tab=partner" class="pro-tab-item <?=$tab === 'partner' ? 'active' : ''?>">
    <span>🤝 Đăng ký Hợp tác Mở sân <?=$partnerStatus === 'APPROVED' ? '✔' : ($partnerStatus === 'PENDING' ? '⏳' : '')?></span>
  </a>
</div>

<?php if ($tab === 'profile'): ?>
<!-- ==========================================================================
     TAB 1: THÔNG TIN CÁ NHÂN, THẺ CẦU THỦ & BẢO MẬT TÀI KHOẢN (2 CỘT HIỆN ĐẠI)
     ========================================================================== -->
<div class="pro-rep-strip">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?=$repTier['color']?>;color:#fff;font-size:11.5px;font-weight:800;margin-bottom:4px">
        <?=$repTier['badge_text']?>
      </span>
      <b style="display:block;font-size:15.5px;color:#0f172a"><?=$repTier['title']?></b>
      <small style="margin:2px 0 0;color:#334155;font-size:12.5px">
        Đặt sân đúng hẹn và thanh toán đúng hạn giúp duy trì 100 điểm uy tín, mở khóa giữ chỗ HOLD 10 phút mọi khung giờ vàng.
      </small>
    </div>
    <div style="text-align:right">
      <div style="font-family:var(--font-sport);font-size:28px;font-weight:800;color:<?=$repTier['color']?>;line-height:1">
        <?=$repScore?><span style="font-size:14px;color:#64748b">/100</span>
      </div>
      <small style="margin:2px 0 0;color:#64748b">Điểm Uy Tín</small>
    </div>
  </div>

  <div class="pro-rep-track">
    <div class="pro-rep-fill"></div>
  </div>

  <div class="pro-rep-tiers">
    <div class="pro-rep-tier-box <?=$repTier['code'] === 'GOOD' ? 'current' : ''?>">
      <b style="color:#16a34a;display:block">🟢 80 – 100 điểm (Uy tín Tốt)</b>
      <span style="color:#475569">Được giữ chỗ HOLD 10 phút, hủy trước 2h không bị trừ điểm uy tín.</span>
    </div>
    <div class="pro-rep-tier-box <?=$repTier['code'] === 'WARNING' ? 'current' : ''?>">
      <b style="color:#d97706;display:block">🟡 60 – 79 điểm (Cảnh báo)</b>
      <span style="color:#475569">Yêu cầu thanh toán chuyển khoản trước 100% khi đặt lịch.</span>
    </div>
    <div class="pro-rep-tier-box <?=$repTier['code'] === 'RESTRICTED' ? 'current' : ''?>">
      <b style="color:#dc2626;display:block">🔴 Dưới 60 điểm (Hạn chế)</b>
      <span style="color:#475569">Tạm khóa giữ chỗ tự động do nhiều lần hủy sát giờ / bùng kèo.</span>
    </div>
  </div>
</div>

<div class="pro-main-grid">
  <!-- CỘT TRÁI: THÔNG TIN LIÊN HỆ & THẺ CẦU THỦ / ĐỘI BÓNG -->
  <div class="pro-card">
    <div class="pro-card-head">
      <h3>📝 Thông Tin Liên Hệ & Hồ Sơ Cầu Thủ</h3>
      <span class="tag green">Đang hoạt động</span>
    </div>

    <form method="post" enctype="multipart/form-data">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="update_profile">

      <div class="pro-form-grid">
        <div class="pro-field">
          <label>Tên đăng nhập (Cố định)</label>
          <input value="@<?=e($u['username'])?>" disabled>
        </div>
        <div class="pro-field">
          <label>Đổi ảnh đại diện (JPG, PNG, WEBP)</label>
          <input type="file" name="avatar_file" accept="image/*" onchange="previewSelectedAvatar(this)">
        </div>
      </div>

      <div class="pro-form-grid">
        <div class="pro-field">
          <label>Họ và tên người dùng *</label>
          <input name="full_name" required value="<?=e($u['full_name'])?>" placeholder="Nhập họ và tên">
        </div>
        <div class="pro-field">
          <label>Số điện thoại liên hệ *</label>
          <input name="phone" required value="<?=e($u['phone'])?>" placeholder="VD: 0901234567">
        </div>
      </div>

      <div class="pro-field">
        <label>Địa chỉ Email nhận vé điện tử QR *</label>
        <input type="email" name="email" required value="<?=e($u['email'])?>" placeholder="example@gmail.com">
      </div>

      <div class="pro-section-tag">⚽ Thẻ Cầu Thủ & Đội Bóng (Tự động điền nhanh khi Đặt sân & Cáp kèo)</div>

      <div class="pro-form-grid">
        <div class="pro-field">
          <label>Tên Đội bóng (Tên FC)</label>
          <input name="team_name" value="<?=e($uProf['team_name'])?>" placeholder="VD: FC Anh Em Sài Gòn">
        </div>
        <div class="pro-field">
          <label>Số áo thi đấu yêu thích (1 - 99)</label>
          <input type="number" name="jersey_number" min="1" max="99" value="<?=(int)($uProf['jersey_number'] ?? 10)?>">
        </div>
      </div>

      <div class="pro-form-grid">
        <div class="pro-field">
          <label>Vị trí sở trường trên sân</label>
          <select name="preferred_position">
            <?php foreach (['Thủ môn', 'Hậu vệ', 'Tiền vệ', 'Tiền đạo', 'Đa năng'] as $posOpt): ?>
              <option value="<?=e($posOpt)?>" <?=($uProf['preferred_position'] ?? '') === $posOpt ? 'selected' : ''?>><?=e($posOpt)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="pro-field">
          <label>Trình độ thi đấu</label>
          <select name="skill_level">
            <?php foreach (['Vui vẻ', 'Phong trào', 'Bán chuyên'] as $skOpt): ?>
              <option value="<?=e($skOpt)?>" <?=($uProf['skill_level'] ?? '') === $skOpt ? 'selected' : ''?>><?=e($skOpt)?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="pro-field">
        <label>Khu vực Quận / Huyện thường đá tại TP.HCM</label>
        <input name="preferred_districts" value="<?=e($uProf['preferred_districts'])?>" placeholder="VD: Tân Bình, Bình Thạnh, Quận 1, Quận 7">
      </div>

      <div class="pro-field">
        <label>Giới thiệu ngắn về lối chơi / Khung giờ hay đá</label>
        <input name="bio" value="<?=e($uProf['bio'] ?? '')?>" placeholder="VD: Đá giao hữu văn minh, thường ra sân tối Thứ 3 & Thứ 5">
      </div>

      <button class="btn" style="width:100%;padding:12px;margin-top:6px;font-weight:800" type="submit">
        💾 LƯU THÔNG TIN CÁ NHÂN & HỒ SƠ CẦU THỦ
      </button>
    </form>
  </div>

  <!-- CỘT PHẢI: ĐỔI MẬT KHẨU BẢO VỆ & LỊCH SỬ ĐIỂM UY TÍN -->
  <div>
    <div class="pro-card">
      <div class="pro-card-head">
        <h3>🔐 Đổi Mật Khẩu Bảo Vệ</h3>
        <span class="tag blue">Bảo mật</span>
      </div>

      <form method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="change_password">

        <div class="pro-field">
          <label>Mật khẩu hiện tại</label>
          <input type="password" name="old" placeholder="••••••••" required>
        </div>

        <div class="pro-field">
          <label>Mật khẩu mới (Tối thiểu 6 ký tự)</label>
          <input type="password" name="new" placeholder="••••••••" required>
        </div>

        <div class="pro-field">
          <label>Nhập lại mật khẩu mới</label>
          <input type="password" name="new2" placeholder="••••••••" required>
        </div>

        <button class="btn bl" style="width:100%;padding:11px;font-weight:800" type="submit">
          🔑 CẬP NHẬT MẬT KHẨU MỚI
        </button>
      </form>
    </div>

    <div class="pro-card">
      <div class="pro-card-head">
        <h3>📊 Lịch Sử Điểm Uy Tín</h3>
        <span class="tag green"><?=$repScore?>/100 điểm</span>
      </div>

      <?php if (!$repLogs): ?>
        <div style="padding:12px;background:#f8fafc;border-radius:10px;border:1px dashed #cbd5e1;font-size:13px;color:#475569">
          ✔ Tài khoản của bạn chưa có lịch sử vi phạm hay hủy sân sát giờ. Hãy tiếp tục duy trì phong độ!
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ($repLogs as $rl): ?>
            <div style="padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12.5px">
              <div style="display:flex;justify-content:space-between;font-weight:800;margin-bottom:3px">
                <span style="color:<?=(int)$rl['reputation_delta'] >= 0 ? '#16a34a' : '#dc2626'?>">
                  <?=(int)$rl['reputation_delta'] >= 0 ? '+' . (int)$rl['reputation_delta'] : (int)$rl['reputation_delta']?> điểm uy tín
                </span>
                <span style="color:#64748b;font-weight:500"><?=date('d/m/Y H:i', strtotime($rl['created_at']))?></span>
              </div>
              <div style="color:#334155"><?=e($rl['reason'])?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif ($tab === 'bookings'): ?>
<!-- ==========================================================================
     TAB 2: LỊCH ĐẶT SÂN CỦA TÔI & HỦY SÂN THÔNG MINH
     ========================================================================== -->
<div class="pro-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
    <div>
      <h3 style="margin:0;font-size:18px;color:#0f172a">📅 Quản Lý Lịch Đặt Sân Cá Nhân</h3>
      <small style="margin:3px 0 0;color:#64748b">
        Hủy trước giờ bóng lăn <b>&ge; 2 giờ</b> không bị trừ điểm uy tín và được hoàn trả 100% tiền đã thanh toán. Hủy sát giờ (<b>&lt; 2 giờ</b>) bị trừ <b>-15 điểm uy tín</b>.
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
          <th>Thao tác</th>
        </tr>
        <?php foreach ($filteredBookings as $bRow):
            $kickoffTs    = strtotime($bRow['booking_date'] . ' ' . $bRow['start_time']);
            $secLeft      = $kickoffTs - time();
            $hrsLeft      = round($secLeft / 3600, 1);
            $canCancel    = in_array($bRow['status'], ['pending', 'confirmed'], true) && $secLeft > 0;
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
                <?=$bRow['pay_status'] === 'paid' ? '🟢 Đã thanh toán' : ($bRow['pay_status'] === 'refunded' ? '↩ Đã hoàn tiền' : '⏳ Chưa thanh toán')?>
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
                    <form method="post" style="display:inline" onsubmit="return confirm('Hủy hợp lệ trước giờ bóng lăn (còn <?=$hrsLeft?>h): Không bị trừ điểm uy tín. Xác nhận hủy lịch?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="cancel_booking">
                      <input type="hidden" name="booking_id" value="<?=(int)$bRow['id']?>">
                      <button type="submit" class="btn sm outline" style="border-color:#16a34a;color:#15803d">
                        ↩ Hủy hợp lệ (Còn <?=$hrsLeft?>h)
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('⚠️ CẢNH BÁO HỦY SÁT GIỜ (Chỉ còn <?=$hrsLeft?> giờ < 2 giờ quy định):\n- Không được hoàn lại tiền cọc\n- Bị TRỪ -15 ĐIỂM UY TÍN\nBạn có chắc chắn vẫn muốn hủy không?')">
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

                <a class="btn sm outline" href="payment-demo.php?booking_id=<?=(int)$bRow['id']?>">🎫 Vé QR</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'partner'): ?>
<!-- ==========================================================================
     TAB 3: ĐĂNG KÝ HỢP TÁC MỞ SÂN (MULTI-STEP FORM + BẢN ĐỒ LEAFLET + TIẾN TRÌNH DUYỆT)
     ========================================================================== -->
<?php if ($partnerStatus === 'APPROVED' || is_pitch_owner($u)): ?>
  <?php $myProfileVenues = get_owner_venues($u); ?>
  <div class="partner-hero-banner" style="margin-bottom:20px">
    <div>
      <span class="partner-verified-pill verified" style="margin-bottom:8px">✔ ĐÃ XÁC MINH ĐỐI TÁC CHỦ SÂN (PITCH_OWNER)</span>
      <h2>🏟 Kênh Chủ Sân & Hồ Sơ Đối Tác Đã Kích Hoạt</h2>
      <small>
        Bạn đang sở hữu <b><?=count($myProfileVenues)?> Cụm sân</b> trên bản đồ Sân Bóng Pro. Quản lý sân 5/7/11, bảng giá khung giờ và lịch POS tại Partner Portal.
      </small>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="owner-dashboard.php" class="btn" style="background:#fde047;color:#052e16 !important;font-weight:800;padding:12px 20px">
        🏟 Mở Kênh Chủ Sân (Partner Portal) →
      </a>
    </div>
  </div>
<?php endif; ?>

<?php if ($latestApp): ?>
  <div class="pro-card" style="border-left:5px solid <?=$latestApp['status'] === 'APPROVED' ? '#16a34a' : ($latestApp['status'] === 'REJECTED' ? '#dc2626' : '#f59e0b')?>">
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

<div class="pro-card">
  <h3 style="margin:0 0 6px;font-size:19px;color:#0f172a">
    🤝 <?=($latestApp && $latestApp['status'] === 'REJECTED') ? 'Cập Nhật & Gửi Lại Hồ Sơ Đăng Ký Chủ Sân' : 'Đăng Ký Hợp Tác Mở Sân Bóng Mới'?>
  </h3>
  <small style="margin:0 0 16px;color:#64748b">
    Hoàn tất 2 bước dưới đây để đưa cụm sân của bạn lên bản đồ Sân Bóng Pro và kích hoạt Kênh Quản Lý Chủ Sân.
  </small>

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
<?php endif; ?>

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
  function previewSelectedAvatar(input) {
    if (!input || !input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      var box = document.getElementById('heroAvatarBox');
      if (box) {
        box.innerHTML = '<img src="' + e.target.result + '" alt="Avatar Preview" style="width:100%;height:100%;object-fit:cover">';
      }
    };
    reader.readAsDataURL(input.files[0]);
  }
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
      btn.textContent = '⏳ Đang đăng xuất...';
    }
    try {
      var res = await fetch('<?=project_base()?>logout.php?ajax=1&redirect=home', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      var data = await res.json();
      if (data && data.redirect) {
        window.location.href = data.redirect;
        return;
      }
    } catch (e) {}
    window.location.href = '<?=project_base()?>logout.php?redirect=home';
  }
</script>

<?php include '../includes/footer.php'; ?>
