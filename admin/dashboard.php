<?php
/**
 * ============================================================================
 * FILE: admin/dashboard.php
 * MÔ TẢ: Bảng điều khiển trung tâm (Dashboard) của Quản trị viên.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Truy vấn các chỉ số thống kê tổng quan (Số sân, Đơn hôm nay, Doanh thu).
 *   - Phần 2: Lấy dữ liệu doanh thu 7 ngày gần nhất để vẽ biểu đồ đường (Line Chart).
 *   - Phần 3: Truy vấn 6 đơn đặt sân mới nhất kèm trạng thái thanh toán.
 *   - Phần 4: Giao diện 4 thẻ thống kê nhanh (Stats Cards).
 *   - Phần 5: Biểu đồ doanh thu 7 ngày & Bảng 6 đơn đặt sân mới nhất.
 *   - Phần 6: Các thẻ lối tắt điều hướng nhanh (Quick Actions).
 *   - Phần 7: Khởi tạo biểu đồ bằng thư viện Chart.js.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: TRUY VẤN CÁC CHỈ SỐ THỐNG KÊ TỔNG QUAN
// ----------------------------------------------------------------------------
$c = fn($sql) => $pdo->query($sql)->fetchColumn();

$totalFields = $c("SELECT COUNT(*) FROM fields");
$todayBk     = $c("SELECT COUNT(*) FROM bookings WHERE booking_date = CURDATE()");
$totalUsers  = $c("SELECT COUNT(*) FROM users WHERE role = 'user'");
$todayRev    = $c("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE booking_date = CURDATE() AND status IN ('confirmed', 'completed')");
$totalRev    = $c("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE status IN ('confirmed', 'completed')");

$aiChatCount = 0;
try {
    $aiChatCount = (int)$c("SELECT COUNT(*) FROM ai_chat_logs");
} catch (Exception $e) {}

// ----------------------------------------------------------------------------
// PHẦN 2: LẤY DỮ LIỆU DOANH THU 7 NGÀY GẦN NHẤT ĐỂ VẼ BIỂU ĐỒ ĐƯỜNG
// ----------------------------------------------------------------------------
$rev = $pdo->query("
    SELECT booking_date AS d, SUM(total_price) AS t
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
      AND booking_date >= CURDATE() - INTERVAL 6 DAY
    GROUP BY booking_date
")->fetchAll(PDO::FETCH_KEY_PAIR);

$labels = [];
$vals   = [];

for ($i = 6; $i >= 0; $i--) {
    $d        = date('Y-m-d', strtotime("-$i day"));
    $labels[] = date('d/m', strtotime($d));
    $vals[]   = (int)($rev[$d] ?? 0);
}

// ----------------------------------------------------------------------------
// PHẦN 3: TRUY VẤN 6 ĐƠN ĐẶT SÂN MỚI NHẤT KÈM THÔNG TIN KHÁCH & THANH TOÁN
// ----------------------------------------------------------------------------
$recent = $pdo->query("
    SELECT
        b.*,
        f.name AS fname,
        u.full_name,
        p.status AS pay_status,
        p.method AS pay_method
    FROM bookings b
    JOIN fields f ON f.id = b.field_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN payments p ON p.booking_id = b.id
    ORDER BY b.created_at DESC, b.id DESC
    LIMIT 6
")->fetchAll();

// Ánh xạ trạng thái đơn sang nhãn hiển thị và màu sắc thẻ tag
$st = [
    'pending'   => ['Chờ xác nhận', 'blue'],
    'confirmed' => ['Đã xác nhận', 'green'],
    'cancelled' => ['Đã hủy', 'gray'],
    'completed' => ['Hoàn thành', 'green']
];

// Dữ liệu hiển thị cho 4 thẻ thống kê đầu trang
$cards = [
    ['🏟', 'Tổng số sân bóng',      $totalFields . ' sân'],
    ['📅', 'Đặt sân hôm nay',       $todayBk . ' đơn'],
    ['💲', 'Doanh thu hôm nay',     money($todayRev)],
    ['💰', 'Tổng doanh thu lũy kế', money($totalRev)]
];

$page  = 'dashboard';
$title = 'Bảng Điều Khiển Trung Tâm';
include '../includes/admin_header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- ==========================================================================
     PHẦN 4: CÁC THẺ THỐNG KÊ NHANH (STATS CARDS)
     ========================================================================== -->
<div class="stats">
  <?php foreach ($cards as $k): ?>
    <div class="stat">
      <span class="ic"><?=$k[0]?></span>
      <div>
        <small><?=$k[1]?></small>
        <b><?=e($k[2])?></b>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ==========================================================================
     PHẦN 5: BIỂU ĐỒ DOANH THU 7 NGÀY & DANH SÁCH ĐƠN ĐẶT SÂN MỚI NHẤT
     ========================================================================== -->
<div class="two">
  <!-- Cột trái: Biểu đồ đường doanh thu 7 ngày gần nhất -->
  <div class="box">
    <div class="bh">
      <b>📈 Biểu đồ doanh thu (7 ngày gần nhất)</b>
      <a href="revenue.php">Chi tiết doanh thu →</a>
    </div>
    <div style="height:280px">
      <canvas id="ch"></canvas>
    </div>
  </div>

  <!-- Cột phải: Bảng 6 đơn đặt sân mới nhất -->
  <div class="box">
    <div class="bh">
      <b>📋 Đơn đặt sân mới nhất</b>
      <a href="bookings.php">Quản lý tất cả →</a>
    </div>

    <?php if (!$recent): ?>
      <div class="empty">Chưa có lượt đặt sân nào.</div>
    <?php else: ?>
      <div class="tw">
        <table>
          <tr>
            <th>Mã</th>
            <th>Sân & Thời gian</th>
            <th>Khách hàng</th>
            <th>Thanh toán</th>
            <th>Trạng thái</th>
          </tr>
          <?php foreach ($recent as $r): [$lb, $cl] = $st[$r['status']]; ?>
            <tr>
              <td><b>#<?=$r['id']?></b></td>
              <td>
                <b><?=e($r['fname'])?></b>
                <small>
                  <?=date('d/m/Y', strtotime($r['booking_date']))?>
                  (<?=substr($r['start_time'], 0, 5)?> - <?=substr($r['end_time'], 0, 5)?>)
                </small>
              </td>
              <td><?=e($r['full_name'])?></td>
              <td>
                <span class="tag <?=$r['pay_status'] === 'paid' ? 'green' : 'gray'?>">
                  <?=strtoupper($r['pay_method'] ?: 'CASH')?>
                  • <?=$r['pay_status'] === 'paid' ? 'Đã TT' : 'Chờ TT'?>
                </span>
              </td>
              <td><span class="tag <?=$cl?>"><?=$lb?></span></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==========================================================================
     PHẦN 6: LỐI TẮT ĐIỀU HƯỚNG NHANH (QUICK ACTIONS)
     ========================================================================== -->
<div class="quick">
  <div class="box">
    <b>🏟 Quản lý Sân & Dịch vụ</b>
    <small>
      Thêm mới sân bóng, cập nhật giá thuê theo giờ và quản lý dịch vụ tiện ích
      (thuê bóng, áo Bib, nước uống, trọng tài).
    </small>
    <div style="display:flex;gap:10px;width:100%;margin-top:auto">
      <a class="btn" style="flex:1" href="fields.php">Quản lý sân →</a>
      <a class="btn bl" style="flex:1" href="services.php">Dịch vụ →</a>
    </div>
  </div>

  <div class="box">
    <b>💳 Đối soát Đặt sân & Voucher</b>
    <small>
      Duyệt lịch đặt sân, xác nhận thanh toán Demo (VietQR / MoMo / VNPay)
      và phát hành mã giảm giá khuyến mãi.
    </small>
    <div style="display:flex;gap:10px;width:100%;margin-top:auto">
      <a class="btn bl" style="flex:1" href="bookings.php">Đơn đặt sân →</a>
      <a class="btn pu" style="flex:1" href="vouchers.php">Mã Voucher →</a>
    </div>
  </div>

  <div class="box">
    <b>🤖 Trợ lý AI & Cấu hình VietQR (<?=$aiChatCount?> tin nhắn)</b>
    <small>
      Tùy chỉnh tên Trợ lý AI, khóa Google Gemini API và tài khoản ngân hàng
      nhận tiền tự động trên cổng thanh toán Demo.
    </small>
    <a class="btn" href="settings.php">⚙ Cấu hình AI & Ngân hàng →</a>
  </div>
</div>

<!-- ==========================================================================
     PHẦN 7: KHỞI TẠO BIỂU ĐỒ DOANH THU CHART.JS
     ========================================================================== -->
<script>
new Chart(document.getElementById('ch'), {
  type: 'line',
  data: {
    labels: <?=json_encode($labels)?>,
    datasets: [{
      label: 'Doanh thu (VNĐ)',
      data: <?=json_encode($vals)?>,
      borderColor: '#10b981',
      backgroundColor: 'rgba(16, 185, 129, 0.16)',
      borderWidth: 3,
      pointBackgroundColor: '#34d399',
      pointRadius: 4,
      fill: true,
      tension: 0.35
    }]
  },
  options: {
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false }
    },
    scales: {
      x: {
        ticks: { color: '#64748b' },
        grid:  { color: 'rgba(148, 163, 184, 0.12)' }
      },
      y: {
        ticks: { color: '#64748b' },
        grid:  { color: 'rgba(148, 163, 184, 0.12)' }
      }
    }
  }
});
</script>

<?php include '../includes/admin_footer.php'; ?>
