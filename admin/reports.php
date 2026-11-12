<?php
/**
 * ============================================================================
 * FILE: admin/reports.php
 * MÔ TẢ: Trang Báo cáo Hiệu suất hoạt động kinh doanh sân bóng.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Truy vấn thống kê số đơn theo trạng thái và Top 5 sân đông khách nhất.
 *   - Phần 2: Giao diện các thẻ chỉ số, biểu đồ ngang Top 5 sân & biểu đồ tròn trạng thái.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: TRUY VẤN SỐ LƯỢNG ĐƠN THEO TRẠNG THÁI & TOP 5 SÂN HIỆU SUẤT CAO NHẤT
// ----------------------------------------------------------------------------
[$from, $to] = get_range();

// 1.1. Đếm số lượng đơn đặt sân theo từng trạng thái (pending, confirmed, completed, cancelled)
$s = $pdo->prepare("
    SELECT status, COUNT(*) AS n
    FROM bookings
    WHERE booking_date BETWEEN ? AND ?
    GROUP BY status
");
$s->execute([$from, $to]);
$by = $s->fetchAll(PDO::FETCH_KEY_PAIR);

// 1.2. Truy vấn Top 5 sân bóng có số lượt đặt thành công và doanh thu cao nhất
$s = $pdo->prepare("
    SELECT
        f.name,
        COUNT(*) AS n,
        SUM(b.total_price) AS t
    FROM bookings b
    JOIN fields f ON f.id = b.field_id
    WHERE b.status IN ('confirmed', 'completed')
      AND b.booking_date BETWEEN ? AND ?
    GROUP BY f.id, f.name
    ORDER BY n DESC, t DESC
    LIMIT 5
");
$s->execute([$from, $to]);
$top = $s->fetchAll();

// 1.3. Tính tổng doanh thu thực thu trong kỳ báo cáo
$s = $pdo->prepare("
    SELECT COALESCE(SUM(total_price), 0)
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
      AND booking_date BETWEEN ? AND ?
");
$s->execute([$from, $to]);
$rev = $s->fetchColumn();

// Nhãn tiếng Việt cho từng trạng thái đơn
$lbl = [
    'pending'   => 'Chờ xác nhận',
    'confirmed' => 'Đã xác nhận',
    'completed' => 'Hoàn thành',
    'cancelled' => 'Đã hủy'
];
$all = array_sum($by);

$page  = 'reports';
$title = 'Báo cáo hiệu suất';
include '../includes/admin_header.php';

// Hiển thị thanh lọc khoảng thời gian (Từ ngày - Đến ngày)
echo range_form();
?>

<!-- ==========================================================================
     PHẦN 2: GIAO DIỆN THẺ THỐNG KÊ VÀ BIỂU ĐỒ TRỰC QUAN (BAR + DOUGHNUT)
     ========================================================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- 2.1. Danh sách các thẻ chỉ số tổng hợp -->
<div class="stats">
  <div class="stat">
    <span class="ic">📅</span>
    <div>
      <small>Tổng lượt đặt sân</small>
      <b><?=$all?> đơn</b>
    </div>
  </div>

  <div class="stat">
    <span class="ic">💲</span>
    <div>
      <small>Doanh thu thực tế</small>
      <b><?=money($rev)?></b>
    </div>
  </div>

  <?php foreach ($lbl as $k => $v): ?>
    <div class="stat">
      <div>
        <small><?=$v?></small>
        <b><?=(int)($by[$k] ?? 0)?></b>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- 2.2. Khu vực hiển thị 2 biểu đồ phân tích hiệu suất -->
<?php if (!$all): ?>
  <div class="box">
    <div class="empty">Không có dữ liệu hoạt động trong khoảng thời gian này.</div>
  </div>
<?php else: ?>
  <div class="two">
    <!-- Cột trái: Top 5 sân bóng được đặt nhiều nhất -->
    <div class="box">
      <div class="bh">
        <b>🏆 Top 5 sân bóng được đặt nhiều nhất</b>
      </div>

      <?php if (!$top): ?>
        <div class="empty">Chưa có đơn đặt sân thành công.</div>
      <?php else: ?>
        <div style="height:260px;margin-bottom:16px">
          <canvas id="c1"></canvas>
        </div>

        <div class="tw">
          <table>
            <tr>
              <th>Tên sân bóng</th>
              <th>Số lượt đặt</th>
              <th>Doanh thu mang lại</th>
            </tr>
            <?php foreach ($top as $r): ?>
              <tr>
                <td><b><?=e($r['name'])?></b></td>
                <td><?=$r['n']?> lượt</td>
                <td><b><?=money($r['t'])?></b></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Cột phải: Biểu đồ tròn tỉ lệ trạng thái đơn đặt sân -->
    <div class="box">
      <div class="bh">
        <b>🍩 Tỉ lệ trạng thái các đơn đặt sân</b>
      </div>
      <div style="height:280px">
        <canvas id="c2"></canvas>
      </div>
    </div>
  </div>

  <!-- 2.3. Khởi tạo biểu đồ bằng thư viện Chart.js -->
  <script>
    <?php if ($top): ?>
      new Chart(document.getElementById('c1'), {
        type: 'bar',
        data: {
          labels: <?=json_encode(array_column($top, 'name'))?>,
          datasets: [{
            label: 'Số lượt đặt',
            data: <?=json_encode(array_map('intval', array_column($top, 'n')))?>,
            backgroundColor: '#10b981',
            borderRadius: 6
          }]
        },
        options: {
          indexAxis: 'y',
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false }
          }
        }
      });
    <?php endif; ?>

    new Chart(document.getElementById('c2'), {
      type: 'doughnut',
      data: {
        labels: <?=json_encode(array_values($lbl))?>,
        datasets: [{
          data: <?=json_encode(array_map(fn($k) => (int)($by[$k] ?? 0), array_keys($lbl)))?>,
          backgroundColor: ['#3b82f6', '#10b981', '#6ee7b7', '#94a3b8']
        }]
      },
      options: {
        maintainAspectRatio: false
      }
    });
  </script>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
