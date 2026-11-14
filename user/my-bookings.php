<?php
/**
 * ============================================================================
 * FILE: user/my-bookings.php
 * MÔ TẢ: Trang quản lý danh sách lịch đặt sân, Hợp đồng cố định & Vé điện tử QR.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý hủy đơn vãng lai (Trước 2h) hoặc Báo nghỉ / Xả sân cố định (Trước 24h).
 *   - Phần 2: Truy vấn toàn bộ lịch sử đặt sân kèm trạng thái thanh toán & thời hạn HOLD.
 *   - Phần 3: Giao diện Banner tiêu đề và bảng danh sách Vé / Lịch thi đấu.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu người dùng phải đăng nhập mới được xem lịch đặt sân cá nhân
$u = require_login();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ HỦY ĐƠN VÃNG LAI (TRƯỚC 2H) HOẶC XẢ SÂN CỐ ĐỊNH (TRƯỚC 24H)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $act        = $_POST['action'] ?? 'cancel';
    $booking_id = (int)($_POST['id'] ?? 0);

    // 1.1. Trường hợp Đội cố định nhấn nút "🔓 Báo nghỉ / Xả sân (Trước 24h)"
    if ($act === 'release_recurring') {
        $res = release_recurring_slot($u['id'], $booking_id);
        flash($res['ok'] ? 'cancel' : 'err', $res['message']);
        header('Location: my-bookings.php');
        exit;
    }

    // 1.2. Trường hợp hủy đơn đặt sân vãng lai thông thường (Kiểm tra quy tắc Trước 2 giờ / Sát giờ < 2h)
    $s = $pdo->prepare("
        SELECT b.*, f.name, p.status AS pay_status, p.amount AS pay_amount
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        LEFT JOIN payments p ON p.booking_id = b.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $s->execute([$booking_id, $u['id']]);
    $b = $s->fetch();

    if (!$b || !in_array($b['status'], ['pending', 'confirmed'])) {
        flash('err', 'Không thể hủy đơn đặt sân này.');
    } else {
        $secondsLeft = strtotime($b['booking_date'] . ' ' . $b['start_time']) - time();
        if ($secondsLeft <= 0) {
            flash('err', 'Trận đấu đã bắt đầu hoặc đã qua, không thể hủy lịch.');
        } else {
            $isEarlyCancel = ($secondsLeft >= 7200); // Trước giờ bóng lăn >= 2 tiếng
            $paidAmt = ($b['pay_status'] === 'paid') ? (float)($b['pay_amount'] ?: $b['total_price']) : 0.0;
            $formatted_date = date('d/m/Y', strtotime($b['booking_date']));

            $pdo->beginTransaction();
            try {
                if ($isEarlyCancel) {
                    $pdo->prepare("
                        UPDATE bookings
                        SET status = 'cancelled',
                            cancel_reason = 'Khách hàng chủ động hủy hợp lệ trước 2 giờ',
                            hold_expires_at = NULL
                        WHERE id = ?
                    ")->execute([$b['id']]);

                    if ($paidAmt > 0) {
                        $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE booking_id = ?")
                            ->execute([$b['id']]);
                        record_wallet_and_reputation(
                            (int)$u['id'],
                            'REFUND',
                            $paidAmt,
                            0,
                            0,
                            "Hoàn 100% tiền cọc hủy sân hợp lệ trước 2h — Đơn #{$b['id']} ({$b['name']})",
                            (int)$b['id']
                        );
                    }

                    notify($u['id'], "↩ Đã hủy hợp lệ đơn #{$b['id']} ({$b['name']} ngày {$formatted_date})." . ($paidAmt > 0 ? " Hệ thống đã ghi nhận hoàn trả 100% (" . money($paidAmt) . ")." : ""));
                    $pdo->commit();
                    flash('cancel', "Đã hủy lịch đặt {$b['name']} ngày {$formatted_date} hợp lệ." . ($paidAmt > 0 ? " Hệ thống đã ghi nhận hoàn trả 100% (" . money($paidAmt) . ")!" : ""));
                } else {
                    // Hủy sát giờ (< 2 tiếng trước giờ bóng lăn): Cảnh báo & Trừ -15 điểm uy tín
                    $pdo->prepare("
                        UPDATE bookings
                        SET status = 'cancelled',
                            cancel_reason = 'Hủy sát giờ (< 2 tiếng trước giờ bóng lăn) - Trừ 15 điểm uy tín',
                            hold_expires_at = NULL
                        WHERE id = ?
                    ")->execute([$b['id']]);

                    record_wallet_and_reputation(
                        (int)$u['id'],
                        'REPUTATION',
                        0,
                        -15,
                        0,
                        "Hủy sân sát giờ (< 2h trước trận) — Đơn #{$b['id']} ({$b['name']} ngày {$formatted_date})",
                        (int)$b['id']
                    );

                    notify($u['id'], "⚠️ Bạn đã hủy sát giờ (< 2h) đơn #{$b['id']} ({$b['name']}). Hệ thống trừ -15 Điểm Uy Tín theo quy định.");
                    $pdo->commit();
                    flash('err', "Đã hủy đơn #{$b['id']} ({$b['name']}). Do hủy sát giờ (< 2 tiếng trước giờ đá), tài khoản bị trừ -15 Điểm Uy Tín.");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('err', 'Lỗi xử lý hủy sân: ' . $e->getMessage());
            }
        }
    }

    header('Location: my-bookings.php');
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN DANH SÁCH LỊCH ĐẶT SÂN KÈM TRẠNG THÁI THANH TOÁN & HOLD
// ----------------------------------------------------------------------------
$s = $pdo->prepare("
    SELECT
        b.*,
        f.name,
        f.type AS ftype,
        f.pitch_code,
        f.image,
        p.status AS pay_status,
        p.method AS pay_method,
        p.transaction_code
    FROM bookings b
    JOIN fields f ON f.id = b.field_id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE b.user_id = ?
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$s->execute([$u['id']]);
$rows = $s->fetchAll();

// Truy vấn thêm danh sách các Kèo Giao Hữu mà người dùng đã nhận hoặc đã đăng (bao gồm cả lịch sử đã hủy)
$stmtMatches = $pdo->prepare("
    SELECT m.*, f.name AS fname, f.type AS ftype, f.district
    FROM match_requests m
    JOIN fields f ON f.id = m.field_id
    WHERE (m.creator_id = ? OR m.opponent_id = ?)
    ORDER BY m.match_date DESC, m.start_time ASC
");
$stmtMatches->execute([$u['id'], $u['id']]);
$myMatches = $stmtMatches->fetchAll();

// Ánh xạ trạng thái đơn đặt sân sang nhãn hiển thị và màu thẻ tag
$st = [
    'pending'   => ['⏳ Giữ chỗ (HOLD)', 'blue'],
    'confirmed' => ['✔ Đã xác nhận',     'green'],
    'cancelled' => ['✕ Đã hủy / Đã xả',  'gray'],
    'completed' => ['🏆 Hoàn thành',     'green']
];

// Tính toán 4 chỉ số KPI thống kê đơn đặt sân của khách hàng
$kpiTotal     = count($rows);
$kpiUpcoming  = 0;
$kpiCompleted = 0;
$kpiCancelled = 0;
foreach ($rows as $rowItem) {
    if (in_array($rowItem['status'], ['pending', 'confirmed'], true)) {
        $kpiUpcoming++;
    } elseif ($rowItem['status'] === 'completed') {
        $kpiCompleted++;
    } elseif ($rowItem['status'] === 'cancelled') {
        $kpiCancelled++;
    }
}

// Lọc theo Tab trạng thái trên giao diện (all, upcoming, completed, cancelled)
$tab = $_GET['tab'] ?? 'all';
$filteredRows = array_filter($rows, function ($item) use ($tab) {
    if ($tab === 'upcoming') {
        return in_array($item['status'], ['pending', 'confirmed'], true);
    }
    if ($tab === 'completed') {
        return $item['status'] === 'completed';
    }
    if ($tab === 'cancelled') {
        return $item['status'] === 'cancelled';
    }
    return true;
});

$active = 'mine';
$title  = 'Vé đặt sân & Lịch thi đấu của tôi';
include '../includes/header.php';
?>

<!-- ==========================================================================
     PHẦN 3: GIAO DIỆN HIỂN THỊ DANH SÁCH LỊCH ĐẶT SÂN & VÉ ĐIỆN TỬ QR
     ========================================================================== -->

<!-- 3.1. Banner tiêu đề trang và nút lối tắt -->
<div class="sport-page-head">
  <div>
    <h2>Vé đặt sân & lịch thi đấu của tôi</h2>
    <small>
      Quản lý các trận cầu đã đặt, theo dõi thời gian giữ chỗ, mở vé QR Check-in hoặc hủy lịch trước 2 giờ.
    </small>
  </div>
  <div style="display:flex;gap:10px">
    <a class="btn sm" href="booking.php">+ Đặt sân mới</a>
  </div>
</div>

<!-- 3.1B. Hàng 4 Thẻ KPI Thống Kê Trạng Thái Đơn Đặt Sân -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px">
  <div class="box" style="margin:0;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div>
      <b style="font-size:24px;color:#0f172a;display:block;line-height:1.1"><?=$kpiTotal?></b>
      <small style="margin:4px 0 0;font-weight:700;color:#475569">Tổng đơn đặt</small>
    </div>
    <div style="width:42px;height:42px;border-radius:12px;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:18px">📋</div>
  </div>

  <div class="box" style="margin:0;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div>
      <b style="font-size:24px;color:#0f172a;display:block;line-height:1.1"><?=$kpiUpcoming?></b>
      <small style="margin:4px 0 0;font-weight:700;color:#475569">Sắp diễn ra</small>
    </div>
    <div style="width:42px;height:42px;border-radius:12px;background:#fef3c7;color:#d97706;display:grid;place-items:center;font-size:18px">⏳</div>
  </div>

  <div class="box" style="margin:0;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div>
      <b style="font-size:24px;color:#0f172a;display:block;line-height:1.1"><?=$kpiCompleted?></b>
      <small style="margin:4px 0 0;font-weight:700;color:#475569">Đã hoàn thành</small>
    </div>
    <div style="width:42px;height:42px;border-radius:12px;background:#dcfce7;color:#15803d;display:grid;place-items:center;font-size:18px">✅</div>
  </div>

  <div class="box" style="margin:0;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px">
    <div>
      <b style="font-size:24px;color:#0f172a;display:block;line-height:1.1"><?=$kpiCancelled?></b>
      <small style="margin:4px 0 0;font-weight:700;color:#475569">Đã hủy</small>
    </div>
    <div style="width:42px;height:42px;border-radius:12px;background:#fee2e2;color:#dc2626;display:grid;place-items:center;font-size:18px">❌</div>
  </div>
</div>

<!-- 3.1C. Khung trắng Bảng Lịch đặt sân & Thanh Tab Lọc Nhanh -->
<div class="box">
  <div class="bh">
    <b>🗓 Lịch đặt sân</b>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <a href="?tab=all" class="btn sm <?=$tab === 'all' ? '' : 'gray'?>" style="border-radius:999px">
      Tất cả
    </a>
    <a href="?tab=upcoming" class="btn sm <?=$tab === 'upcoming' ? '' : 'gray'?>" style="border-radius:999px">
      Sắp diễn ra
    </a>
    <a href="?tab=completed" class="btn sm <?=$tab === 'completed' ? '' : 'gray'?>" style="border-radius:999px">
      Đã hoàn thành
    </a>
    <a href="?tab=cancelled" class="btn sm <?=$tab === 'cancelled' ? '' : 'gray'?>" style="border-radius:999px">
      Đã hủy
    </a>
  </div>

<!-- 3.2. Hiển thị thông báo trống nếu khách hàng chưa đặt sân lần nào -->
<?php if (!$filteredRows): ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:48px;margin-bottom:12px">⚽</div>
    <h3 style="color:#0f172a;font-family:var(--font-sport);margin:0 0 8px">
      CHƯA CÓ ĐƠN ĐẶT SÂN TRONG MỤC NÀY
    </h3>
    <small style="color:#64748b;font-size:14px;margin-bottom:20px">
      Hãy chọn khung giờ 30 phút trên sơ đồ trực quan hoặc đăng ký lịch cố định hàng tuần!
    </small>
    <a class="btn" href="booking.php" style="margin-top:16px">⚡ Mở sơ đồ đặt sân ngay →</a>
  </div>
<?php else: ?>
  <!-- 3.3. Bảng danh sách chi tiết các đơn đặt sân của khách hàng -->
  <div class="tw">
    <table>
      <thead>
        <tr>
          <th>Mã vé</th>
          <th>Sân thi đấu & Loại lịch</th>
          <th>Ngày đá</th>
          <th>Khung giờ</th>
          <th>Tổng chi phí</th>
          <th>Thanh toán</th>
          <th>Trạng thái</th>
          <th>Thao tác / Xả sân</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filteredRows as $r): [$lb, $cl] = $st[$r['status']] ?? ['Đang xử lý', 'blue']; ?>
          <tr>
            <!-- Mã đơn đặt sân -->
            <td>
              <b style="font-family:var(--font-sport);font-size:15px;color:#0f172a">#<?=$r['id']?></b>
            </td>

            <!-- Ảnh, tên sân bóng và nhãn Hợp đồng cố định (nếu có) -->
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <img
                  src="<?=e(img_src($r['image']))?>"
                  alt="<?=e($r['name'])?>"
                  style="width:52px;height:40px;border-radius:6px;object-fit:cover"
                >
                <div>
                  <b><?=e($r['name'])?></b>
                  <small style="margin:0">
                    Sân <?=$r['ftype']?>v<?=$r['ftype']?>
                    <?=!empty($r['pitch_code']) ? ' (' . e($r['pitch_code']) . ')' : ''?>
                  </small>
                  <?php if (!empty($r['recurring_contract_id'])): ?>
                    <span class="tag blue" style="font-size:10.5px;padding:2px 7px;margin-top:3px;display:inline-block">
                      🔄 Lịch cố định #<?=$r['recurring_contract_id']?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- Ngày thi đấu -->
            <td>
              <b><?=date('d/m/Y', strtotime($r['booking_date']))?></b>
            </td>

            <!-- Khung giờ bắt đầu - kết thúc -->
            <td>
              <b style="font-family:var(--font-sport);color:#16a34a">
                <?=substr($r['start_time'], 0, 5)?> - <?=substr($r['end_time'], 0, 5)?>
              </b>
            </td>

            <!-- Tổng chi phí thanh toán -->
            <td>
              <b style="font-family:var(--font-sport);font-size:15px;color:#059669">
                <?=money($r['total_price'])?>
              </b>
            </td>

            <!-- Trạng thái thanh toán & Mã giao dịch -->
            <td>
              <?php if ($r['pay_status'] === 'paid'): ?>
                <span class="tag green">✔ Đã thanh toán</span>
                <?php if (!empty($r['transaction_code'])): ?>
                  <small style="font-size:11px"><?=e($r['transaction_code'])?></small>
                <?php endif; ?>
              <?php elseif ($r['pay_status'] === 'refunded'): ?>
                <span class="tag gray">↩ Đã hoàn tiền</span>
              <?php else: ?>
                <span class="tag amber">⏳ Chờ thanh toán</span>
              <?php endif; ?>
            </td>

            <!-- Trạng thái đơn đặt sân & Thời hạn giữ chỗ HOLD 10 phút -->
            <td>
              <span class="tag <?=$cl?>"><?=$lb?></span>
              <?php if ($r['status'] === 'pending' && !empty($r['hold_expires_at'])): ?>
                <small style="color:#d97706;font-weight:700;font-size:11px;margin-top:3px">
                  ⏰ Giữ đến: <?=date('H:i:s', strtotime($r['hold_expires_at']))?>
                </small>
              <?php elseif ($r['status'] === 'cancelled' && !empty($r['cancel_reason'])): ?>
                <small style="color:#64748b;font-size:11px;margin-top:3px">
                  <?=e($r['cancel_reason'])?>
                </small>
              <?php endif; ?>
            </td>

            <!-- Các nút thao tác: Mở Vé QR, Xả sân cố định (Trước 24h) hoặc Hủy đơn lẻ (Trước 2h) -->
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ($r['status'] !== 'cancelled'): ?>
                  <a class="btn sm <?=$r['pay_status'] === 'paid' ? '' : 'bl'?>" href="payment-demo.php?booking_id=<?=$r['id']?>">
                    <?=$r['pay_status'] === 'paid' ? '🎫 Vé QR & Biên lai' : '💳 Thanh toán / Vé QR'?>
                  </a>
                <?php endif; ?>

                <?php if (in_array($r['status'], ['pending', 'confirmed'])): ?>
                  <?php if (!empty($r['recurring_contract_id'])): ?>
                    <!-- Nút Báo nghỉ / Xả sân cho buổi thuộc Hợp đồng cố định -->
                    <form
                      class="inl"
                      method="post"
                      onsubmit="return confirm('Xác nhận Báo nghỉ / Xả sân buổi cố định này (Trước 24h)? Sân sẽ được mở lại cho khách vãng lai trong ngày hôm đó, các tuần sau vẫn giữ nguyên!')"
                    >
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="release_recurring">
                      <input type="hidden" name="id" value="<?=$r['id']?>">
                      <button class="btn sm red" type="submit" title="Mở lại slot ngày hôm nay cho khách vãng lai mà không mất lịch các tuần sau">
                        🔓 Báo nghỉ / Xả sân
                      </button>
                    </form>
                  <?php else:
                    $secBeforeMatch = strtotime($r['booking_date'] . ' ' . $r['start_time']) - time();
                    $cancelConfirmMsg = ($secBeforeMatch >= 7200)
                        ? 'Xác nhận hủy đơn đặt sân #' . $r['id'] . ' (Trước giờ đá >= 2 tiếng)? Nếu đơn đã thanh toán, 100% tiền sẽ được hoàn ngay vào Ví nội bộ của bạn.'
                        : '⚠️ CẢNH BÁO HỦY SÁT GIỜ (< 2 tiếng trước khi bóng lăn): Nếu tiếp tục hủy đơn #' . $r['id'] . ', tài khoản của bạn sẽ bị trừ -15 Điểm Uy Tín và không được hoàn tiền cọc. Bạn có chắc chắn muốn hủy?';
                  ?>
                    <?php if ($secBeforeMatch > 0): ?>
                      <!-- Nút Hủy đơn lẻ thông thường (Tích hợp kiểm tra hoàn tiền Ví / Trừ điểm uy tín) -->
                      <form class="inl" method="post" onsubmit="return confirm(<?=json_encode($cancelConfirmMsg)?>)">
                        <?=csrf_field()?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?=$r['id']?>">
                        <button class="btn sm red" type="submit">
                          <?=$secBeforeMatch >= 7200 ? '↩ Hủy & Hoàn Ví' : '⚠️ Hủy sát giờ (-15 UT)'?>
                        </button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
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

<!-- ==========================================================================
     PHẦN 4: BẢNG XEM LẠI KÈO GIAO HỮU ĐÃ NHẬN HOẶC ĐÃ ĐĂNG
     ========================================================================== -->
<div class="box" style="margin-top:24px;border-top:4px solid #2563eb">
  <div class="bh">
    <b>⚔️ KÈO GIAO HỮU ĐÃ NHẬN & ĐÃ ĐĂNG CỦA ĐỘI BẠN (<?=count($myMatches)?>)</b>
    <a href="matchmaking.php">Vào Sàn Cáp Kèo →</a>
  </div>

  <?php if (!$myMatches): ?>
    <div class="empty">
      Bạn chưa đăng hoặc nhận kèo giao hữu nào.
      <a href="matchmaking.php"><b>Vào Sàn Cáp Kèo ngay →</b></a>
    </div>
  <?php else: ?>
    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Vai trò</th>
            <th>Cặp đấu (Chủ nhà vs Đối thủ)</th>
            <th>Sân thi đấu</th>
            <th>Ngày & Giờ</th>
            <th>Chia tiền sân</th>
            <th>SĐT Liên hệ</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myMatches as $mm):
              $isCreator = ((int)$mm['creator_id'] === (int)$u['id']);
          ?>
            <tr>
              <td>
                <?php if ($isCreator): ?>
                  <span class="tag green">🛡️ Đội chủ nhà</span>
                <?php else: ?>
                  <span class="tag blue">⚔️ Đội nhận kèo</span>
                <?php endif; ?>
              </td>
              <td>
                <b><?=e($mm['team_name'])?></b>
                <span style="color:#dc2626;font-weight:800"> VS </span>
                <b><?=e($mm['opponent_name'] ?: 'Đang chờ đối...')?></b>
              </td>
              <td>
                <b><?=e($mm['fname'])?></b>
                <small>Sân <?=$mm['ftype']?> • <?=e($mm['district'])?></small>
              </td>
              <td>
                <b><?=date('d/m/Y', strtotime($mm['match_date']))?></b>
                <small style="color:#16a34a;font-weight:700"><?=substr($mm['start_time'], 0, 5)?> - <?=substr($mm['end_time'], 0, 5)?></small>
              </td>
              <td><?=e($mm['fee_share'])?></td>
              <td>
                <?php if (!$isCreator): ?>
                  <small style="margin:0">Đội chủ nhà:</small>
                  <b>📞 <?=e($mm['contact_phone'])?></b>
                <?php elseif (!empty($mm['opponent_phone'])): ?>
                  <small style="margin:0">Đội nhận kèo:</small>
                  <b>📞 <?=e($mm['opponent_phone'])?></b>
                <?php else: ?>
                  <small>Chưa có</small>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($mm['status'] === 'matched'): ?>
                  <span class="tag green">✅ Đã chốt kèo</span>
                <?php elseif ($mm['status'] === 'cancelled'): ?>
                  <span class="tag red">❌ Đã hủy kèo</span>
                <?php else: ?>
                  <span class="tag amber">⏳ Đang chờ đối</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                  <?php if ($mm['status'] !== 'cancelled'): ?>
                    <?php if ($isCreator): ?>
                      <form method="post" action="matchmaking.php" class="inl" onsubmit="return confirm('Bạn có chắc chắn muốn hủy kèo giao hữu này?')">
                        <?=csrf_field()?>
                        <input type="hidden" name="action" value="cancel_match">
                        <input type="hidden" name="match_id" value="<?=$mm['id']?>">
                        <input type="hidden" name="redirect_to" value="my-bookings">
                        <button class="btn sm red" type="submit">✕ Hủy kèo</button>
                      </form>
                    <?php elseif ($mm['status'] === 'matched'): ?>
                      <form method="post" action="matchmaking.php" class="inl" onsubmit="return confirm('Xác nhận hủy nhận kèo này và mở lại kèo cho đội khác?')">
                        <?=csrf_field()?>
                        <input type="hidden" name="action" value="leave_match">
                        <input type="hidden" name="match_id" value="<?=$mm['id']?>">
                        <input type="hidden" name="redirect_to" value="my-bookings">
                        <button class="btn sm red" type="submit">↩ Hủy nhận kèo</button>
                      </form>
                    <?php endif; ?>
                  <?php else: ?>
                    <small style="color:#64748b">Kèo đã đóng</small>
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

<?php include '../includes/footer.php'; ?>
