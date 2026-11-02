<?php
/**
 * ============================================================================
 * FILE: admin/revenue.php
 * MÔ TẢ: Trang Thống kê Doanh thu chi tiết theo Ngày / Tháng / Năm (Kèm xuất CSV).
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xác định khoảng ngày lọc và nhóm dữ liệu theo Ngày / Tháng / Năm.
 *   - Phần 2: Xuất báo cáo doanh thu ra file Excel/CSV khi có tham số ?export=csv.
 *   - Phần 3: Giao diện thẻ tổng hợp, biểu đồ cột (Chart.js) và bảng số liệu.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XÁC ĐỊNH KHOẢNG THỜI GIAN LỌC VÀ CHẾ ĐỘ NHÓM (NGÀY / THÁNG / NĂM)
// ----------------------------------------------------------------------------
[$from, $to] = get_range();

// Định nghĩa các chế độ gom nhóm thời gian trong MySQL DATE_FORMAT
$modes = [
    'day'   => ['%Y-%m-%d', 'Ngày'],
    'month' => ['%Y-%m',    'Tháng'],
    'year'  => ['%Y',       'Năm']
];

$mode = isset($modes[$_GET['mode'] ?? '']) ? $_GET['mode'] : 'day';
$fmt  = $modes[$mode][0];

// Quy tắc tính doanh thu: Chỉ tính các đơn Đã xác nhận (confirmed) và Hoàn thành (completed)
$s = $pdo->prepare("
    SELECT
        DATE_FORMAT(booking_date, '$fmt') AS g,
        COUNT(*) AS n,
        SUM(total_price) AS t
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
      AND booking_date BETWEEN ? AND ?
    GROUP BY g
    ORDER BY g ASC
");
$s->execute([$from, $to]);
$rows = $s->fetchAll();

// Tính tổng doanh thu và tổng số lượt đặt sân trong khoảng thời gian đã chọn
$total = array_sum(array_column($rows, 't'));
$cnt   = array_sum(array_column($rows, 'n'));

// ----------------------------------------------------------------------------
// PHẦN 2: XUẤT BÁO CÁO DOANH THU RA FILE EXCEL / CSV (?export=csv)
// ----------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bao-cao-doanh-thu-' . $from . '-den-' . $to . '.csv"');

    // In ký tự BOM UTF-8 để Microsoft Excel hiển thị đúng tiếng Việt có dấu
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [$modes[$mode][1], 'Số lượt đặt thành công', 'Doanh thu (VNĐ)']);

    foreach ($rows as $r) {
        fputcsv($out, [$r['g'], $r['n'], $r['t']]);
    }

    fputcsv($out, ['TỔNG CỘNG', $cnt, $total]);
    fclose($out);
    exit;
}

// Tạo phần mở rộng cho thanh công cụ lọc (chọn chế độ Ngày/Tháng/Năm + nút Xuất CSV)
$mode_options = '';
foreach ($modes as $k => $v) {
    $selected      = ($k === $mode) ? ' selected' : '';
    $mode_options .= '<option value="' . $k . '"' . $selected . '>Theo ' . mb_strtolower($v[1]) . '</option>';
}

$extra  = '<select name="mode">' . $mode_options . '</select>';
$extra .= ' <a class="btn bl" href="?from=' . urlencode($from) . '&to=' . urlencode($to) . '&mode=' . urlencode($mode) . '&export=csv">📥 Xuất Excel (CSV)</a>';

$page  = 'revenue';
$title = 'Thống kê doanh thu';
include '../includes/admin_header.php';

// Hiển thị thanh công cụ lọc khoảng thời gian
echo range_form($extra);
?>

<!-- ==========================================================================
     PHẦN 3: GIAO DIỆN THẺ TỔNG HỢP, BIỂU ĐỒ CỘT CHART.JS & BẢNG SỐ LIỆU
     ========================================================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- 3.1. Hai thẻ thống kê tổng doanh thu và tổng số đơn thành công -->
<div class="stats">
  <div class="stat">
    <span class="ic">💲</span>
    <div>
      <small>Tổng doanh thu kỳ này</small>
      <b><?=money($total)?></b>
    </div>
  </div>

  <div class="stat">
    <span class="ic">📅</span>
    <div>
      <small>Số lượt đặt tính doanh thu</small>
      <b><?=$cnt?> đơn</b>
    </div>
  </div>
</div>

<!-- 3.2. Biểu đồ cột và bảng chi tiết doanh thu -->
<?php if (!$rows): ?>
  <div class="box">
    <div class="empty">Không có dữ liệu doanh thu trong khoảng thời gian này.</div>
  </div>
<?php else: ?>
  <div class="two">
    <!-- Cột trái: Biểu đồ cột doanh thu -->
    <div class="box">
      <div class="bh">
        <b>📊 Biểu đồ doanh thu theo <?=mb_strtolower($modes[$mode][1])?></b>
      </div>
      <div style="height:280px">
        <canvas id="ch"></canvas>
      </div>
    </div>

    <!-- Cột phải: Bảng số liệu chi tiết -->
    <div class="box">
      <div class="bh">
        <b>📋 Bảng kê chi tiết doanh thu</b>
      </div>
      <div class="tw">
        <table>
          <tr>
            <th><?=$modes[$mode][1]?></th>
            <th>Lượt đặt thành công</th>
            <th>Doanh thu</th>
          </tr>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><b><?=e($r['g'])?></b></td>
              <td><?=$r['n']?> đơn</td>
              <td><b><?=money($r['t'])?></b></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <!-- Khởi tạo biểu đồ cột Chart.js -->
  <script>
    new Chart(document.getElementById('ch'), {
      type: 'bar',
      data: {
        labels: <?=json_encode(array_column($rows, 'g'))?>,
        datasets: [{
          label: 'Doanh thu (VNĐ)',
          data: <?=json_encode(array_map('intval', array_column($rows, 't')))?>,
          backgroundColor: '#10b981',
          borderRadius: 6
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        }
      }
    });
  </script>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
