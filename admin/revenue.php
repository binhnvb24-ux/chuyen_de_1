<?php
/**
 * FILE: admin/revenue.php
 * MÔ TẢ: Trang Thống kê Doanh thu chi tiết theo Ngày / Tháng / Năm.
 * CHỨC NĂNG: Lọc doanh thu theo khoảng ngày và chế độ nhóm, vẽ biểu đồ cột (Bar Chart) và bảng số liệu.
 */
require_once '../includes/auth.php';
$adm=require_admin();

// 1. Xác định khoảng thời gian lọc và chế độ nhóm (Ngày / Tháng / Năm)
[$from,$to]=get_range();
$modes=['day'=>['%Y-%m-%d','Ngày'],'month'=>['%Y-%m','Tháng'],'year'=>['%Y','Năm']];
$mode=isset($modes[$_GET['mode']??''])?$_GET['mode']:'day'; $fmt=$modes[$mode][0];

// Quy tắc doanh thu: chỉ tính đơn Đã xác nhận + Hoàn thành (không tính Chờ xác nhận, Đã hủy)
$s=$pdo->prepare("SELECT DATE_FORMAT(booking_date,'$fmt') g,COUNT(*) n,SUM(total_price) t FROM bookings WHERE status IN('confirmed','completed') AND booking_date BETWEEN ? AND ? GROUP BY g ORDER BY g");
$s->execute([$from,$to]); $rows=$s->fetchAll();
$total=array_sum(array_column($rows,'t')); $cnt=array_sum(array_column($rows,'n'));
$extra='<select name="mode">'.implode('',array_map(fn($k,$v)=>'<option value="'.$k.'"'.($k===$mode?' selected':'').'>Theo '.mb_strtolower($v[1]).'</option>',array_keys($modes),$modes)).'</select>';
$page='revenue'; $title='Doanh thu'; include '../includes/admin_header.php'; echo range_form($extra); ?>

<!-- 2. Hiển thị thẻ tổng hợp, biểu đồ cột Chart.js và bảng chi tiết doanh thu -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<div class="stats"><div class="stat"><span class="ic">💲</span><div><small>Tổng doanh thu</small><b><?=money($total)?></b></div></div><div class="stat"><span class="ic">📅</span><div><small>Số lượt đặt tính doanh thu</small><b><?=$cnt?></b></div></div></div>
<?php if(!$rows): ?><div class="box"><div class="empty">Không có doanh thu trong khoảng thời gian này.</div></div><?php else: ?>
<div class="two"><div class="box"><b>Biểu đồ doanh thu</b><div style="height:280px"><canvas id="ch"></canvas></div></div>
<div class="box"><div class="tw"><table><tr><th><?=$modes[$mode][1]?></th><th>Lượt đặt</th><th>Doanh thu</th></tr><?php foreach($rows as $r): ?><tr><td><?=e($r['g'])?></td><td><?=$r['n']?></td><td><?=money($r['t'])?></td></tr><?php endforeach; ?></table></div></div></div>
<script>new Chart(document.getElementById('ch'),{type:'bar',data:{labels:<?=json_encode(array_column($rows,'g'))?>,datasets:[{data:<?=json_encode(array_map('intval',array_column($rows,'t')))?>,backgroundColor:'#15803d'}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}}}});</script><?php endif; include '../includes/admin_footer.php';
