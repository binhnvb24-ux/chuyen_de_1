<?php
/**
 * ============================================================================
 * FILE: user/my-bookings.php
 * MÔ TẢ: Trang quản lý danh sách lịch đặt sân & Vé điện tử QR của Khách hàng.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý yêu cầu hủy đơn đặt sân từ khách hàng (yêu cầu trước 2 giờ).
 *   - Phần 2: Truy vấn toàn bộ lịch sử đặt sân kèm trạng thái thanh toán.
 *   - Phần 3: Giao diện Banner tiêu đề và bảng danh sách Vé / Lịch thi đấu.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu người dùng phải đăng nhập mới được xem lịch đặt sân cá nhân
$u = require_login();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ YÊU CẦU HỦY ĐƠN ĐẶT SÂN TỪ KHÁCH HÀNG (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // 1.1. Tìm đơn đặt sân theo ID và đảm bảo đơn đó thuộc về chính khách hàng đang đăng nhập
    $booking_id = (int)($_POST['id'] ?? 0);
    $s = $pdo->prepare("
        SELECT b.*, f.name
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $s->execute([$booking_id, $u['id']]);
    $b = $s->fetch();

    // 1.2. Kiểm tra điều kiện hủy: Đơn phải đang ở trạng thái Chờ xác nhận hoặc Đã xác nhận
    if (!$b || !in_array($b['status'], ['pending', 'confirmed'])) {
        flash('err', 'Không thể hủy đơn đặt sân này.');
    }
    // 1.3. Kiểm tra quy định thời gian: Phải hủy trước giờ bóng lăn tối thiểu 2 tiếng (7200 giây)
    elseif (strtotime($b['booking_date'] . ' ' . $b['start_time']) - time() < 7200) {
        flash('err', 'Quy định: Chỉ được hủy sân trước giờ bắt đầu tối thiểu 2 giờ.');
    }
    // 1.4. Hợp lệ: Cập nhật trạng thái đơn sang Đã hủy (cancelled) và hoàn tiền nếu đã thanh toán
    else {
        $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")
            ->execute([$b['id']]);

        $pdo->prepare("UPDATE payments SET status = IF(status = 'paid', 'refunded', status) WHERE booking_id = ?")
            ->execute([$b['id']]);

        // Ghi thông báo vào hộp thư của người dùng
        $formatted_date = date('d/m/Y', strtotime($b['booking_date']));
        notify($u['id'], "Bạn đã hủy đặt {$b['name']} ngày {$formatted_date}.");

        flash('ok', 'Đã hủy lịch đặt sân thành công.');
    }

    header('Location: my-bookings.php');
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN DANH SÁCH LỊCH ĐẶT SÂN KÈM TRẠNG THÁI THANH TOÁN
// ----------------------------------------------------------------------------
$s = $pdo->prepare("
    SELECT
        b.*,
        f.name,
        f.type AS ftype,
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

// Ánh xạ trạng thái đơn đặt sân sang nhãn hiển thị và màu thẻ tag
$st = [
    'pending'   => ['Chờ xác nhận', 'blue'],
    'confirmed' => ['Đã xác nhận', 'green'],
    'cancelled' => ['Đã hủy', 'gray'],
    'completed' => ['Hoàn thành', 'green']
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
    <h2>🎫 LỊCH THI ĐẤU & VÉ ĐIỆN TỬ QR CỦA TÔI</h2>
    <small>Quản lý các trận cầu đã đặt, mở vé QR Check-in tại sân hoặc đăng tìm đối thủ giao hữu</small>
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
      Hãy chọn khung giờ trên sơ đồ trực quan và lên lịch cho trận đấu tiếp theo!
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
          <th>Sân thi đấu</th>
          <th>Ngày đá</th>
          <th>Khung giờ</th>
          <th>Tổng chi phí</th>
          <th>Thanh toán</th>
          <th>Trạng thái</th>
          <th>Thao tác & Vé QR</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): [$lb, $cl] = $st[$r['status']] ?? ['Đang xử lý', 'blue']; ?>
          <tr>
            <!-- Mã đơn đặt sân -->
            <td>
              <b style="font-family:var(--font-sport);font-size:15px;color:#0f172a">#<?=$r['id']?></b>
            </td>

            <!-- Ảnh và tên sân bóng -->
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <img
                  src="<?=e(img_src($r['image']))?>"
                  alt="<?=e($r['name'])?>"
                  style="width:52px;height:40px;border-radius:6px;object-fit:cover"
                >
                <div>
                  <b><?=e($r['name'])?></b>
                  <small style="margin:0">Sân <?=$r['ftype']?>v<?=$r['ftype']?></small>
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

            <!-- Trạng thái đơn đặt sân -->
            <td>
              <span class="tag <?=$cl?>"><?=$lb?></span>
            </td>

            <!-- Các nút thao tác: Mở Vé QR / Thanh toán và Hủy đơn -->
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ($r['status'] !== 'cancelled'): ?>
                  <a class="btn sm <?=$r['pay_status'] === 'paid' ? '' : 'bl'?>" href="payment-demo.php?booking_id=<?=$r['id']?>">
                    <?=$r['pay_status'] === 'paid' ? '🎫 Vé QR & Biên lai' : '💳 Thanh toán / Vé QR'?>
                  </a>
                <?php endif; ?>

                <?php if (in_array($r['status'], ['pending', 'confirmed'])): ?>
                  <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn hủy đơn đặt sân này?')">
                    <?=csrf_field()?>
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <button class="btn sm red" type="submit">Hủy</button>
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

<?php include '../includes/footer.php'; ?>
