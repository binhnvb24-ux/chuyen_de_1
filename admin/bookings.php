<?php
/**
 * ============================================================================
 * FILE: admin/bookings.php
 * MÔ TẢ: Trang Quản lý Đơn đặt sân & Đối soát Thanh toán của Admin.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý cập nhật trạng thái đơn đặt sân & xác nhận thu tiền (POST).
 *   - Phần 2: Truy vấn lọc danh sách đơn đặt sân theo từ khóa, ngày và trạng thái.
 *   - Phần 3: Xuất báo cáo danh sách đơn đặt sân ra file Excel/CSV (?export=csv).
 *   - Phần 4: Truy vấn chi tiết hóa đơn & dịch vụ đi kèm của 1 đơn (?view=ID).
 *   - Phần 5: Giao diện Thanh bộ lọc tìm kiếm & Nút xuất Excel.
 *   - Phần 6: Khung hiển thị Chi tiết Hóa đơn & Dịch vụ đi kèm.
 *   - Phần 7: Bảng danh sách toàn bộ đơn đặt sân và các nút thao tác.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ CẬP NHẬT TRẠNG THÁI ĐƠN ĐẶT SÂN & ĐỐI SOÁT THANH TOÁN (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Bảng ánh xạ hành động sang trạng thái mới và nội dung thông báo gửi cho khách
    $map = [
        'confirm'  => ['confirmed', 'đã được xác nhận'],
        'cancel'   => ['cancelled', 'đã bị hủy/từ chối'],
        'complete' => ['completed', 'đã hoàn thành']
    ];

    $a  = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // Lấy thông tin đơn đặt sân cần xử lý kèm trạng thái thanh toán
    $s = $pdo->prepare("
        SELECT b.*, f.name, p.status AS pay_status, p.amount AS pay_amount
        FROM bookings b
        JOIN fields f ON f.id = b.field_id
        LEFT JOIN payments p ON p.booking_id = b.id
        WHERE b.id = ?
    ");
    $s->execute([$id]);
    $b = $s->fetch();

    // 1.1. Trường hợp Admin nhấn nút "💲 Thu tiền" (Xác nhận đã thanh toán)
    if ($b && $a === 'mark_paid') {
        $txn = 'ADM' . date('ymdHis');
        $stmt = $pdo->prepare("
            UPDATE payments
            SET status = 'paid',
                transaction_code = COALESCE(transaction_code, ?),
                paid_at = NOW()
            WHERE booking_id = ?
        ");
        $stmt->execute([$txn, $b['id']]);

        notify($b['user_id'], "Đơn đặt {$b['name']} (#{$b['id']}) đã được Admin xác nhận thanh toán thành công.");
        flash('ok', 'Đã xác nhận thanh toán cho đơn #' . $b['id']);
    }
    // 1.2. Trường hợp Admin nhấn nút Xác nhận / Hoàn thành / Hủy đơn
    elseif ($b && isset($map[$a])) {
        $new_status = $map[$a][0];
        $msg_text   = $map[$a][1];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE bookings SET status = ?, hold_expires_at = NULL WHERE id = ?")
                ->execute([$new_status, $b['id']]);

            // Nếu hoàn thành đơn -> đánh dấu thanh toán là đã thu tiền
            if ($a === 'complete') {
                $pdo->prepare("
                    UPDATE payments
                    SET status = 'paid', paid_at = COALESCE(paid_at, NOW())
                    WHERE booking_id = ?
                ")->execute([$b['id']]);
            }

            // Nếu hủy đơn đã thanh toán -> chuyển trạng thái thanh toán sang hoàn tiền (refunded) & hoàn tiền vào Ví nội bộ
            if ($a === 'cancel') {
                $wasPaid   = (($b['pay_status'] ?? '') === 'paid');
                $refundAmt = $wasPaid ? (float)($b['pay_amount'] ?: $b['total_price']) : 0.0;
                $pdo->prepare("
                    UPDATE payments
                    SET status = IF(status = 'paid', 'refunded', status)
                    WHERE booking_id = ?
                ")->execute([$b['id']]);

                if ($refundAmt > 0 && function_exists('record_wallet_and_reputation')) {
                    record_wallet_and_reputation(
                        (int)$b['user_id'],
                        'REFUND',
                        $refundAmt,
                        0,
                        0,
                        "Admin hủy đơn #{$b['id']} ({$b['name']}) — Hoàn 100% tiền thanh toán vào Ví nội bộ",
                        (int)$b['id'],
                        (int)$adm['id']
                    );
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $formatted_date = date('d/m/Y', strtotime($b['booking_date']));
        notify($b['user_id'], "Lịch đặt {$b['name']} ngày {$formatted_date} {$msg_text}.");
        flash('ok', 'Đã cập nhật trạng thái đơn #' . $b['id']);
    } else {
        flash('err', 'Thao tác không hợp lệ.');
    }

    header('Location: bookings.php');
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN VÀ LỌC DANH SÁCH ĐƠN ĐẶT SÂN
// ----------------------------------------------------------------------------
$fs   = $_GET['status'] ?? '';
$q    = trim($_GET['q'] ?? '');
$date = $_GET['date'] ?? '';

$sql = "
    SELECT
        b.*,
        f.name AS fname,
        f.type AS ftype,
        f.price_per_hour,
        u.full_name,
        u.phone,
        u.email,
        v.code AS vcode,
        p.method AS pay_method,
        p.status AS pay_status,
        p.transaction_code,
        p.paid_at
    FROM bookings b
    JOIN fields f ON f.id = b.field_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN vouchers v ON v.id = b.voucher_id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE (f.name LIKE ? OR u.full_name LIKE ? OR p.transaction_code LIKE ?)
";
$p = ["%$q%", "%$q%", "%$q%"];

if ($fs) {
    $sql .= " AND b.status = ?";
    $p[]  = $fs;
}
if ($date) {
    $sql .= " AND b.booking_date = ?";
    $p[]  = $date;
}

$s = $pdo->prepare($sql . " ORDER BY b.booking_date DESC, b.start_time DESC");
$s->execute($p);
$rows = $s->fetchAll();

// Ánh xạ trạng thái đơn sang tên tiếng Việt và màu thẻ tag
$st = [
    'pending'   => ['Chờ xác nhận', 'blue'],
    'confirmed' => ['Đã xác nhận', 'green'],
    'cancelled' => ['Đã hủy', 'gray'],
    'completed' => ['Hoàn thành', 'green']
];

// ----------------------------------------------------------------------------
// PHẦN 3: XUẤT DANH SÁCH ĐƠN ĐẶT SÂN RA FILE EXCEL / CSV (?export=csv)
// ----------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="danh-sach-dat-san-' . date('Ymd-His') . '.csv"');

    // In ký tự UTF-8 BOM để mở bằng Microsoft Excel không bị lỗi font tiếng Việt
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Mã đơn', 'Sân bóng', 'Khách hàng', 'Số điện thoại',
        'Ngày đá', 'Khung giờ', 'Mã Voucher', 'Tổng tiền (VNĐ)',
        'PT Thanh toán', 'TT Thanh toán', 'Mã GD', 'Trạng thái đơn'
    ]);

    foreach ($rows as $r) {
        $pay_label = ($r['pay_status'] === 'paid')
            ? 'Đã thanh toán'
            : (($r['pay_status'] === 'refunded') ? 'Đã hoàn tiền' : 'Chờ thanh toán');

        fputcsv($out, [
            '#' . $r['id'],
            $r['fname'],
            $r['full_name'],
            $r['phone'],
            date('d/m/Y', strtotime($r['booking_date'])),
            substr($r['start_time'], 0, 5) . '-' . substr($r['end_time'], 0, 5),
            $r['vcode'] ?: 'Không',
            $r['total_price'],
            strtoupper($r['pay_method'] ?: 'CASH'),
            $pay_label,
            $r['transaction_code'] ?: '',
            $st[$r['status']][0] ?? $r['status']
        ]);
    }

    fclose($out);
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 4: TRUY VẤN CHI TIẾT HÓA ĐƠN & DỊCH VỤ ĐI KÈM CỦA 1 ĐƠN (?view=ID)
// ----------------------------------------------------------------------------
$detailBk   = null;
$detailSvcs = [];

if (!empty($_GET['view'])) {
    $vid = (int)$_GET['view'];

    // Tìm trong danh sách đã truy vấn trước
    foreach ($rows as $r_item) {
        if ((int)$r_item['id'] === $vid) {
            $detailBk = $r_item;
            break;
        }
    }

    // Nếu không nằm trong bộ lọc hiện tại thì truy vấn trực tiếp từ CSDL
    if (!$detailBk) {
        $stD = $pdo->prepare("
            SELECT
                b.*,
                f.name AS fname,
                f.type AS ftype,
                f.price_per_hour,
                u.full_name,
                u.phone,
                u.email,
                v.code AS vcode,
                p.method AS pay_method,
                p.status AS pay_status,
                p.transaction_code,
                p.paid_at
            FROM bookings b
            JOIN fields f ON f.id = b.field_id
            JOIN users u ON u.id = b.user_id
            LEFT JOIN vouchers v ON v.id = b.voucher_id
            LEFT JOIN payments p ON p.booking_id = b.id
            WHERE b.id = ?
        ");
        $stD->execute([$vid]);
        $detailBk = $stD->fetch();
    }

}

$page  = 'bookings';
$title = 'Quản lý Đặt sân & Thanh toán';
include '../includes/admin_header.php';
?>

<!-- ==========================================================================
     PHẦN 5: THANH BỘ LỌC TÌM KIẾM ĐƠN ĐẶT SÂN & NÚT XUẤT EXCEL/CSV
     ========================================================================== -->
<form class="sbar" method="get">
  <input
    name="q"
    placeholder="Tên sân / khách hàng / mã giao dịch..."
    value="<?=e($q)?>"
  >
  <input
    type="date"
    name="date"
    value="<?=e($date)?>"
  >
  <select name="status">
    <option value="">Tất cả trạng thái</option>
    <?php foreach ($st as $k => $v): ?>
      <option value="<?=$k?>" <?=$fs === $k ? 'selected' : ''?>><?=$v[0]?></option>
    <?php endforeach; ?>
  </select>

  <button class="btn" type="submit">🔍 Lọc</button>
  <a
    class="btn bl"
    href="?q=<?=urlencode($q)?>&date=<?=urlencode($date)?>&status=<?=urlencode($fs)?>&export=csv"
  >
    📥 Xuất Excel (CSV)
  </a>
</form>

<!-- ==========================================================================
     PHẦN 6: KHUNG XEM CHI TIẾT HÓA ĐƠN & DỊCH VỤ ĐI KÈM (?view=ID)
     ========================================================================== -->
<?php if ($detailBk): ?>
  <div class="box" style="border:2px solid #10b981 !important;margin-bottom:20px">
    <div class="bh">
      <b>
        🧾 CHI TIẾT HÓA ĐƠN ĐẶT SÂN #<?=$detailBk['id']?>
        — <?=e($detailBk['fname'])?> (Sân <?=$detailBk['ftype']?> người)
      </b>
      <a href="bookings.php" class="btn sm red">✕ Đóng chi tiết</a>
    </div>

    <div class="two" style="margin-bottom:0">
      <!-- Cột trái: Thông tin khách hàng, thời gian đá và phương thức thanh toán -->
      <div>
        <small>
          👤 Khách hàng: <b><?=e($detailBk['full_name'])?></b>
          (SĐT: <?=e($detailBk['phone'])?> • Email: <?=e($detailBk['email'])?>)
        </small>
        <small>
          📅 Ngày thi đấu: <b style="color:#10b981"><?=date('d/m/Y', strtotime($detailBk['booking_date']))?></b>
          (Khung giờ: <b><?=substr($detailBk['start_time'], 0, 5)?> - <?=substr($detailBk['end_time'], 0, 5)?></b>)
        </small>
        <small>
          🎟 Mã giảm giá Voucher: <b style="color:#f59e0b"><?=e($detailBk['vcode'] ?: 'Không áp dụng')?></b>
        </small>
        <small>
          💳 Thanh toán: <b><?=strtoupper($detailBk['pay_method'] ?: 'CASH')?></b>
          • Mã GD: <b><?=e($detailBk['transaction_code'] ?: 'Chưa có')?></b>
        </small>
        <?php if (!empty($detailBk['note'])): ?>
          <small>📝 Ghi chú của khách: <i>"<?=e($detailBk['note'])?>"</i></small>
        <?php endif; ?>
      </div>

      <!-- Cột phải: Tổng kết thanh toán của đơn -->
      <div style="display:flex;flex-direction:column;justify-content:center">
        <div style="text-align:right;font-size:16px">
          Tổng thanh toán đơn:
          <b style="color:#10b981;font-size:20px"><?=money($detailBk['total_price'])?></b>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ==========================================================================
     PHẦN 7: BẢNG HIỂN THỊ DANH SÁCH ĐƠN ĐẶT SÂN & CÁC NÚT THAO TÁC
     ========================================================================== -->
<div class="box">
  <?php if (!$rows): ?>
    <div class="empty">Không có lịch đặt sân nào phù hợp với bộ lọc.</div>
  <?php else: ?>
    <div class="tw">
      <table>
        <tr>
          <th>#</th>
          <th>Sân bóng</th>
          <th>Khách hàng</th>
          <th>Ngày & Giờ</th>
          <th>Tổng tiền</th>
          <th>Thanh toán</th>
          <th>Trạng thái</th>
          <th>Thao tác</th>
        </tr>
        <?php foreach ($rows as $r): [$lb, $cl] = $st[$r['status']]; ?>
          <tr>
            <!-- Mã đơn -->
            <td><b>#<?=$r['id']?></b></td>

            <!-- Tên sân, mã Voucher và ghi chú -->
            <td>
              <b><?=e($r['fname'])?></b>
              <?php if (!empty($r['vcode'])): ?>
                <small style="color:#10b981">🎟 Voucher: <?=e($r['vcode'])?></small>
              <?php endif; ?>
              <?php if (!empty($r['note'])): ?>
                <small>📝 <?=e($r['note'])?></small>
              <?php endif; ?>
            </td>

            <!-- Họ tên và SĐT khách hàng -->
            <td>
              <?=e($r['full_name'])?>
              <small>📞 <?=e($r['phone'])?></small>
            </td>

            <!-- Ngày đá và khung giờ -->
            <td>
              <?=date('d/m/Y', strtotime($r['booking_date']))?>
              <small><?=substr($r['start_time'], 0, 5)?> - <?=substr($r['end_time'], 0, 5)?></small>
            </td>

            <!-- Tổng tiền -->
            <td><b><?=money($r['total_price'])?></b></td>

            <!-- Trạng thái thanh toán -->
            <td>
              <?php
              $pay_tag_color = ($r['pay_status'] === 'paid')
                  ? 'green'
                  : (($r['pay_status'] === 'refunded') ? 'gray' : 'blue');
              $pay_tag_text = ($r['pay_status'] === 'paid')
                  ? 'Đã TT'
                  : (($r['pay_status'] === 'refunded') ? 'Hoàn tiền' : 'Chờ TT');
              ?>
              <span class="tag <?=$pay_tag_color?>">
                <?=strtoupper($r['pay_method'] ?: 'CASH')?> • <?=$pay_tag_text?>
              </span>
              <?php if (!empty($r['transaction_code'])): ?>
                <small><?=e($r['transaction_code'])?></small>
              <?php endif; ?>
            </td>

            <!-- Trạng thái đơn đặt sân -->
            <td><span class="tag <?=$cl?>"><?=$lb?></span></td>

            <!-- Các nút thao tác quản lý đơn -->
            <td>
              <a class="btn sm bl" href="?view=<?=$r['id']?>" title="Xem chi tiết dịch vụ & hóa đơn">
                🧾 Chi tiết
              </a>

              <?php if ($r['pay_status'] !== 'paid' && $r['status'] !== 'cancelled'): ?>
                <form class="inl" method="post">
                  <?=csrf_field()?>
                  <input type="hidden" name="id" value="<?=$r['id']?>">
                  <input type="hidden" name="action" value="mark_paid">
                  <button class="btn sm pu" type="submit" title="Xác nhận đã thu tiền">
                    💲 Thu tiền
                  </button>
                </form>
              <?php endif; ?>

              <?php
              // Danh sách các nút chuyển trạng thái tùy theo trạng thái hiện tại của đơn
              $status_actions = [
                  'pending'   => [
                      ['confirm', 'Xác nhận', ''],
                      ['cancel',  'Từ chối',  'red']
                  ],
                  'confirmed' => [
                      ['complete', 'Hoàn thành', 'bl'],
                      ['cancel',   'Hủy',        'red']
                  ]
              ][$r['status']] ?? [];

              foreach ($status_actions as $a):
              ?>
                <form
                  class="inl"
                  method="post"
                  onsubmit="return <?=$a[0] === 'cancel' ? "confirm('Xác nhận hủy đơn đặt sân này?')" : 'true'?>"
                >
                  <?=csrf_field()?>
                  <input type="hidden" name="id" value="<?=$r['id']?>">
                  <input type="hidden" name="action" value="<?=$a[0]?>">
                  <button class="btn sm <?=$a[2]?>" type="submit"><?=$a[1]?></button>
                </form>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include '../includes/admin_footer.php'; ?>
