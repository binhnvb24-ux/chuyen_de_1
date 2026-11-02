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

    // 1.2. Trường hợp hủy đơn đặt sân vãng lai thông thường (Trước 2 giờ)
    $s = $pdo->prepare("
        SELECT b.*, f.name
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $s->execute([$booking_id, $u['id']]);
    $b = $s->fetch();

    if (!$b || !in_array($b['status'], ['pending', 'confirmed'])) {
        flash('err', 'Không thể hủy đơn đặt sân này.');
    } elseif (strtotime($b['booking_date'] . ' ' . $b['start_time']) - time() < 7200) {
        flash('err', 'Quy định: Khách lẻ chỉ được hủy sân trước giờ bắt đầu tối thiểu 2 giờ.');
    } else {
        $pdo->prepare("
            UPDATE bookings
            SET status = 'cancelled',
                cancel_reason = 'Khách hàng chủ động hủy trước 2 giờ'
            WHERE id = ?
        ")->execute([$b['id']]);

        $pdo->prepare("
            UPDATE payments
            SET status = IF(status = 'paid', 'refunded', status)
            WHERE booking_id = ?
        ")->execute([$b['id']]);

        $formatted_date = date('d/m/Y', strtotime($b['booking_date']));
        notify($u['id'], "❌ Bạn đã hủy đặt {$b['name']} ngày {$formatted_date}.");

        flash('cancel', "Đã hủy lịch đặt {$b['name']} ngày {$formatted_date} thành công.");
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

$active = 'mine';
$title  = 'Vé & Lịch đặt của tôi';
include '../includes/header.php';
?>

<!-- ==========================================================================
     PHẦN 3: GIAO DIỆN HIỂN THỊ DANH SÁCH LỊCH ĐẶT SÂN & VÉ ĐIỆN TỬ QR
     ========================================================================== -->

<!-- 3.1. Banner tiêu đề trang và nút lối tắt -->
<div class="sport-page-head">
  <div>
    <h2>🎫 LỊCH THI ĐẤU, HỢP ĐỒNG CỐ ĐỊNH & VÉ ĐIỆN TỬ QR</h2>
    <small>
      Quản lý các trận cầu đã đặt, theo dõi thời gian giữ chỗ HOLD 10 phút,
      mở vé QR Check-in hoặc Báo nghỉ / Xả sân cố định (trước 24h)
    </small>
  </div>
  <div style="display:flex;gap:10px">
    <a class="btn sm bl" href="matchmaking.php?new=1">⚔️ Đăng Cáp Kèo</a>
    <a class="btn sm" href="booking.php">+ Đặt thêm sân mới</a>
  </div>
</div>

<!-- 3.2. Hiển thị thông báo trống nếu khách hàng chưa đặt sân lần nào -->
<?php if (!$rows): ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:48px;margin-bottom:12px">⚽</div>
    <h3 style="color:#0f172a;font-family:var(--font-sport);margin:0 0 8px">
      BẠN CHƯA CÓ LỊCH ĐẶT SÂN NÀO
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
        <?php foreach ($rows as $r): [$lb, $cl] = $st[$r['status']] ?? ['Đang xử lý', 'blue']; ?>
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
                  <?php else: ?>
                    <!-- Nút Hủy đơn lẻ thông thường -->
                    <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn hủy đơn đặt sân này?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="id" value="<?=$r['id']?>">
                      <button class="btn sm red" type="submit">Hủy</button>
                    </form>
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
