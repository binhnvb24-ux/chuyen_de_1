<?php
/**
 * ============================================================================
 * FILE: api/partner_account_api.php
 * MÔ TẢ: Backend REST API xử lý Hệ thống Tài khoản Đa vai trò (CUSTOMER - PITCH_OWNER - ADMIN),
 *        Ví nội bộ, Điểm uy tín (Reputation Score) và Luồng Đăng ký / Duyệt Đối tác Mở sân.
 * ============================================================================
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Vui lòng đăng nhập để thực hiện thao tác này.']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// 1. API Lấy thông tin tài khoản, Điểm uy tín, Số dư Ví & Trạng thái Đối tác của người dùng hiện tại
if ($action === 'my_overview') {
    $tier = get_reputation_tier($u['reputation_score'] ?? 100);
    $stmtLogs = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 20");
    $stmtLogs->execute([$u['id']]);
    $stmtApps = $pdo->prepare("SELECT * FROM partner_applications WHERE user_id = ? ORDER BY id DESC");
    $stmtApps->execute([$u['id']]);

    echo json_encode([
        'ok' => true,
        'user' => [
            'id'                  => (int)$u['id'],
            'username'            => $u['username'],
            'full_name'           => $u['full_name'],
            'phone'               => $u['phone'],
            'email'               => $u['email'],
            'account_role'        => get_user_role_code($u),
            'account_status'      => $u['account_status'] ?? 'ACTIVE',
            'partner_status'      => $u['partner_status'] ?? 'NONE',
            'wallet_balance'      => (float)($u['wallet_balance'] ?? 0),
            'reputation_score'    => (int)($u['reputation_score'] ?? 100),
            'loyalty_points'      => (int)($u['loyalty_points'] ?? 0),
            'preferred_position'  => $u['preferred_position'] ?? 'Tiền vệ',
            'skill_level'         => $u['skill_level'] ?? 'Vui vẻ',
            'team_name'           => $u['team_name'] ?? '',
            'preferred_districts' => $u['preferred_districts'] ?? '',
        ],
        'reputation_tier'      => $tier,
        'wallet_transactions'  => $stmtLogs->fetchAll(),
        'partner_applications' => $stmtApps->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. API Kiểm tra điều kiện Hủy đặt sân (Xem trước xem được hoàn tiền vào Ví hay bị trừ Điểm uy tín)
if ($action === 'preview_cancel_booking') {
    $bookingId = (int)($_REQUEST['booking_id'] ?? 0);
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
        echo json_encode(['ok' => false, 'message' => 'Đơn đặt sân không hợp lệ hoặc đã kết thúc.']);
        exit;
    }

    $kickoffTs = strtotime($bk['booking_date'] . ' ' . $bk['start_time']);
    $secondsLeft = $kickoffTs - time();
    $hoursLeft = round($secondsLeft / 3600, 1);
    $isPaid = ($bk['pay_status'] === 'paid' || $bk['status'] === 'confirmed');
    $refundAmount = $isPaid ? (int)($bk['paid_amount'] ?: $bk['total_price']) : 0;

    if ($secondsLeft >= 7200) {
        echo json_encode([
            'ok'               => true,
            'is_late_cancel'   => false,
            'hours_left'       => $hoursLeft,
            'refund_amount'    => $refundAmount,
            'reputation_delta' => 0,
            'policy_message'   => $refundAmount > 0
                ? "Hủy hợp lệ trước giờ bóng lăn ({$hoursLeft} giờ): Hoàn lại 100% (" . money($refundAmount) . ") vào Ví nội bộ, không bị trừ điểm uy tín."
                : "Hủy hợp lệ trước giờ bóng lăn ({$hoursLeft} giờ): Không bị trừ điểm uy tín.",
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok'               => true,
            'is_late_cancel'   => true,
            'hours_left'       => $hoursLeft,
            'refund_amount'    => 0,
            'reputation_delta' => -15,
            'policy_message'   => "⚠️ CẢNH BÁO HỦY SÁT GIỜ (Chỉ còn {$hoursLeft} giờ trước khi bóng lăn < 2 giờ quy định): Nếu tiếp tục hủy, bạn sẽ KHÔNG được hoàn tiền cọc và bị TRỪ -15 ĐIỂM UY TÍN!",
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 3. API Dành riêng cho ADMIN: Duyệt / Từ chối đơn Chủ sân hoặc Điều chỉnh Điểm uy tín
if (in_array($action, ['admin_approve_partner', 'admin_reject_partner', 'admin_adjust_reputation'], true)) {
    if (($u['role'] ?? '') !== 'admin' && ($u['account_role'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Chỉ Quản trị viên (ADMIN) mới có quyền thực hiện thao tác này.']);
        exit;
    }

    if ($action === 'admin_approve_partner') {
        $appId = (int)($_POST['app_id'] ?? 0);
        $res = approve_partner_application($appId, $u['id']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'admin_reject_partner') {
        $appId  = (int)($_POST['app_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập lý do từ chối hồ sơ.']);
            exit;
        }
        $stmtApp = $pdo->prepare("SELECT * FROM partner_applications WHERE id = ?");
        $stmtApp->execute([$appId]);
        $app = $stmtApp->fetch();
        if (!$app) {
            echo json_encode(['ok' => false, 'message' => 'Không tìm thấy đơn đăng ký.']);
            exit;
        }

        $pdo->prepare("
            UPDATE partner_applications
            SET status = 'REJECTED', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ")->execute([$reason, $u['id'], $appId]);

        $pdo->prepare("UPDATE users SET partner_status = 'REJECTED' WHERE id = ?")->execute([$app['user_id']]);
        notify($app['user_id'], "⚠️ Hồ sơ đăng ký mở sân '{$app['venue_name']}' cần bổ sung thông tin. Lý do: {$reason}. Vui lòng vào mục Hồ sơ -> Đăng ký Hợp tác Mở sân để cập nhật và gửi lại.");

        echo json_encode(['ok' => true, 'message' => 'Đã từ chối hồ sơ và gửi lý do phản hồi cho khách hàng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'admin_adjust_reputation') {
        $targetUid = (int)($_POST['user_id'] ?? 0);
        $repDelta  = (int)($_POST['reputation_delta'] ?? 0);
        $walDelta  = (float)($_POST['wallet_delta'] ?? 0);
        $reason    = trim($_POST['reason'] ?? 'Admin điều chỉnh điểm uy tín / số dư ví');

        $res = record_wallet_and_reputation($targetUid, 'ADMIN_ADJUST', $walDelta, $repDelta, 0, $reason, null, $u['id']);
        if (!$res) {
            echo json_encode(['ok' => false, 'message' => 'Không tìm thấy người dùng.']);
            exit;
        }
        notify($targetUid, "🔔 Quản trị viên đã cập nhật tài khoản của bạn: Uy tín " . ($repDelta >= 0 ? "+$repDelta" : "$repDelta") . " điểm (" . $reason . ").");
        echo json_encode(['ok' => true, 'data' => $res, 'message' => 'Đã cập nhật điểm uy tín & ví của người dùng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode(['ok' => false, 'message' => 'Action không hợp lệ.']);
