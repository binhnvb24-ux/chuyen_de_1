<?php
/**
 * FILE: admin/reports.php
 * MÔ TẢ: Trang Báo cáo Hiệu suất hoạt động kinh doanh sân bóng.
 * CHỨC NĂNG: Thống kê Top 5 sân được đặt nhiều nhất và biểu đồ tròn (Doughnut) tỉ lệ trạng thái đơn.
 */
require_once '../includes/auth.php';
$adm=require_admin();

// 1. Truy vấn số lượng đơn theo trạng thái và Top 5 sân bóng có hiệu suất cao nhất
[$from,$to]=get_range();
$s=$pdo->prepare("SELECT status,COUNT(*) n FROM bookings WHERE booking_date BETWEEN ? AND ? GROUP BY status"); $s->execute([$from,$to]); $by=$s->fetchAll(PDO::FETCH_KEY_PAIR);
$s=$pdo->prepare("SELECT f.name,COUNT(*) n,SUM(b.total_price) t FROM bookings b JOIN fields f ON f.id=b.field_id WHERE b.status IN('confirmed','completed') AND b.booking_date BETWEEN ? AND ? GROUP BY f.id,f.name ORDER BY n DESC,t DESC LIMIT 5"); $s->execute([$from,$to]); $top=$s->fetchAll();
$s=$pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status IN('confirmed','completed') AND booking_date BETWEEN ? AND ?"); $s->execute([$from,$to]); $rev=$s->fetchColumn();
$lbl=['pending'=>'Chờ xác nhận','confirmed'=>'Đã xác nhận','completed'=>'Hoàn thành','cancelled'=>'Đã hủy']; $all=array_sum($by);
$page='reports'; $title='Báo cáo'; include '../includes/admin_header.php'; echo range_form(); ?>

<!-- 2. Giao diện thẻ thống kê và 2 biểu đồ trực quan (Bar ngang + Doughnut) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<div class="stats"><div class="stat"><span class="ic">📅</span><div><small>Tổng lượt đặt</small><b><?=$all?></b></div></div><div class="stat"><span class="ic">💲</span><div><small>Doanh thu (không tính đơn hủy/chờ)</small><b><?=money($rev)?></b></div></div>
<?php foreach($lbl as $k=>$v): ?><div class="stat"><div><small><?=$v?></small><b><?=(int)($by[$k]??0)?></b></div></div><?php endforeach; ?></div>
<?php if(!$all): ?><div class="box"><div class="empty">Không có dữ liệu trong khoảng thời gian này.</div></div><?php else: ?>
<div class="two"><div class="box"><b>Sân được đặt nhiều nhất</b><?php if(!$top): ?><div class="empty">Chưa có.</div><?php else: ?><div style="height:260px"><canvas id="c1"></canvas></div><div class="tw"><table><tr><th>Sân</th><th>Lượt đặt</th><th>Doanh thu</th></tr><?php foreach($top as $r): ?><tr><td><?=e($r['name'])?></td><td><?=$r['n']?></td><td><?=money($r['t'])?></td></tr><?php endforeach; ?></table></div><?php endif; ?></div>
<div class="box"><b>Tỉ lệ trạng thái đặt sân</b><div style="height:260px"><canvas id="c2"></canvas></div></div></div>
<script>
<?php if($top): ?>new Chart(document.getElementById('c1'),{type:'bar',data:{labels:<?=json_encode(array_column($top,'name'))?>,datasets:[{data:<?=json_encode(array_map('intval',array_column($top,'n')))?>,backgroundColor:'#15803d'}]},options:{indexAxis:'y',maintainAspectRatio:false,plugins:{legend:{display:false}}}});<?php endif; ?>
new Chart(document.getElementById('c2'),{type:'doughnut',data:{labels:<?=json_encode(array_values($lbl))?>,datasets:[{data:<?=json_encode(array_map(fn($k)=>(int)($by[$k]??0),array_keys($lbl)))?>,backgroundColor:['#3b82f6','#15803d','#86efac','#9ca3af']}]},options:{maintainAspectRatio:false}});</script><?php endif; include '../includes/admin_footer.php';
