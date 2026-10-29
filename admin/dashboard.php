<?php
/**
 * FILE: admin/dashboard.php
 * MÔ TẢ: Bảng điều khiển trung tâm (Dashboard) của Quản trị viên.
 * CHỨC NĂNG: Thống kê tổng quan số sân, lượt đặt hôm nay, doanh thu hôm nay & lũy kế,
 *            vẽ biểu đồ doanh thu 7 ngày gần nhất (Chart.js) và hiển thị 6 đơn đặt sân mới nhất.
 */
require_once '../includes/auth.php';
$adm = require_admin();

// 1. Truy vấn các chỉ số thống kê tổng quan
$c = fn($sql) => $pdo->query($sql)->fetchColumn();
$totalFields = $c("SELECT COUNT(*) FROM fields");
$todayBk = $c("SELECT COUNT(*) FROM bookings WHERE booking_date=CURDATE()");
$totalUsers = $c("SELECT COUNT(*) FROM users WHERE role='user'");
$todayRev = $c("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE booking_date=CURDATE() AND status IN('confirmed','completed')");
$totalRev = $c("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status IN('confirmed','completed')");
$aiChatCount = 0;
try { $aiChatCount = (int)$c("SELECT COUNT(*) FROM ai_chat_logs"); } catch(Exception $e){}

// 2. Lấy dữ liệu doanh thu 7 ngày gần nhất để vẽ biểu đồ đường (Line Chart)
$rev = $pdo->query("SELECT booking_date d,SUM(total_price) t FROM bookings WHERE status IN('confirmed','completed') AND booking_date>=CURDATE()-INTERVAL 6 DAY GROUP BY booking_date")->fetchAll(PDO::FETCH_KEY_PAIR);
$labels = []; $vals = [];
for($i=6;$i>=0;$i--){
  $d = date('Y-m-d',strtotime("-$i day"));
  $labels[] = date('d/m',strtotime($d));
  $vals[] = (int)($rev[$d]??0);
}

// 3. Truy vấn 6 đơn đặt sân mới nhất kèm thông tin khách hàng và thanh toán
$recent = $pdo->query("SELECT b.*,f.name fname,u.full_name,p.status pay_status,p.method pay_method FROM bookings b JOIN fields f ON f.id=b.field_id JOIN users u ON u.id=b.user_id LEFT JOIN payments p ON p.booking_id=b.id ORDER BY b.created_at DESC,b.id DESC LIMIT 6")->fetchAll();
$st = ['pending'=>['Chờ xác nhận','blue'],'confirmed'=>['Đã xác nhận','green'],'cancelled'=>['Đã hủy','gray'],'completed'=>['Hoàn thành','green']];

$cards = [
  ['🏟','Tổng số sân bóng',$totalFields . ' sân'],
  ['📅','Đặt sân hôm nay',$todayBk . ' đơn'],
  ['💲','Doanh thu hôm nay',money($todayRev)],
  ['💰','Tổng doanh thu lũy kế',money($totalRev)]
];

$page = 'dashboard';
$title = 'Bảng Điều Khiển Trung Tâm';
include '../includes/admin_header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- 4. Các thẻ thống kê nhanh (Stats Cards) -->
<div class="stats">
  <?php foreach($cards as $k): ?>
    <div class="stat">
      <span class="ic"><?=$k[0]?></span>
      <div>
        <small><?=$k[1]?></small>
        <b><?=e($k[2])?></b>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- 5. Khu vực Biểu đồ doanh thu 7 ngày & Danh sách đơn đặt sân mới nhất -->
<div class="two">
  <div class="box">
    <div class="bh">
      <b>📈 Biểu đồ doanh thu (7 ngày gần nhất)</b>
      <a href="revenue.php">Chi tiết doanh thu →</a>
    </div>
    <div style="height:280px"><canvas id="ch"></canvas></div>
  </div>

  <div class="box">
    <div class="bh">
      <b>📋 Đơn đặt sân mới nhất</b>
      <a href="bookings.php">Quản lý tất cả →</a>
    </div>
    <?php if(!$recent): ?>
      <div class="empty">Chưa có lượt đặt sân nào.</div>
    <?php else: ?>
      <div class="tw">
        <table>
          <tr><th>Mã</th><th>Sân & Thời gian</th><th>Khách</th><th>Thanh toán</th><th>Trạng thái</th></tr>
          <?php foreach($recent as $r): [$lb,$cl]=$st[$r['status']]; ?>
            <tr>
              <td><b>#<?=$r['id']?></b></td>
              <td>
                <b><?=e($r['fname'])?></b>
                <small><?=date('d/m/Y',strtotime($r['booking_date']))?> (<?=substr($r['start_time'],0,5)?>-<?=substr($r['end_time'],0,5)?>)</small>
              </td>
              <td><?=e($r['full_name'])?></td>
              <td>
                <span class="tag <?=$r['pay_status']==='paid'?'green':'gray'?>">
                  <?=strtoupper($r['pay_method']?:'CASH')?> • <?=$r['pay_status']==='paid'?'Đã TT':'Chờ TT'?>
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

<!-- 6. Lối tắt điều hướng nhanh (Quick Actions) -->
<div class="quick">
  <div class="box">
    <b>🏟 Quản lý Sân & Dịch vụ</b>
    <small>Thêm mới sân bóng, cập nhật giá thuê theo giờ và quản lý dịch vụ tiện ích (thuê bóng, áo Bib, nước uống, trọng tài).</small>
    <div style="display:flex;gap:10px;width:100%;margin-top:auto">
      <a class="btn" style="flex:1" href="fields.php">Quản lý sân →</a>
      <a class="btn bl" style="flex:1" href="services.php">Dịch vụ →</a>
    </div>
  </div>

  <div class="box">
    <b>💳 Đối soát Đặt sân & Voucher</b>
    <small>Duyệt lịch đặt sân, xác nhận thanh toán Demo (VietQR / MoMo / VNPay) và phát hành mã giảm giá khuyến mãi.</small>
    <div style="display:flex;gap:10px;width:100%;margin-top:auto">
      <a class="btn bl" style="flex:1" href="bookings.php">Đơn đặt sân →</a>
      <a class="btn pu" style="flex:1" href="vouchers.php">Mã Voucher →</a>
    </div>
  </div>

  <div class="box">
    <b>🤖 Trợ lý AI & Cấu hình VietQR (<?=$aiChatCount?> tin nhắn)</b>
    <small>Tùy chỉnh tên Trợ lý AI, khóa Google Gemini API và tài khoản ngân hàng nhận tiền tự động trên cổng thanh toán Demo.</small>
    <a class="btn" href="settings.php">⚙ Cấu hình AI & Ngân hàng →</a>
  </div>
</div>

<!-- 7. Khởi tạo biểu đồ Chart.js -->
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
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
      y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } }
    }
  }
});
</script>
<?php include '../includes/admin_footer.php'; ?>
