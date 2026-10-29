<?php
/**
 * FILE: admin/bookings.php
 * MÔ TẢ: Trang Quản lý Đơn đặt sân & Đối soát Thanh toán của Admin.
 * CHỨC NĂNG: Tìm kiếm/lọc đơn đặt sân, xác nhận đơn, từ chối/hủy đơn (tự động hoàn tiền nếu đã TT),
 *            đánh dấu hoàn thành trận đấu và xác nhận đã thu tiền thủ công.
 */
require_once '../includes/auth.php';
$adm = require_admin();

// 1. Xử lý các thao tác cập nhật trạng thái đơn đặt sân & thanh toán (POST)
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $map = [
    'confirm'  => ['confirmed','đã được xác nhận'],
    'cancel'   => ['cancelled','đã bị hủy/từ chối'],
    'complete' => ['completed','đã hoàn thành']
  ];
  $a = $_POST['action'] ?? '';
  $s = $pdo->prepare("SELECT b.*,f.name FROM bookings b JOIN fields f ON f.id=b.field_id WHERE b.id=?");
  $s->execute([(int)$_POST['id']]);
  $b = $s->fetch();

  if ($b && $a === 'mark_paid') {
    $txn = 'ADM' . date('ymdHis');
    $pdo->prepare("UPDATE payments SET status='paid', transaction_code=COALESCE(transaction_code, ?), paid_at=NOW() WHERE booking_id=?")->execute([$txn, $b['id']]);
    notify($b['user_id'], "Đơn đặt {$b['name']} (#{$b['id']}) đã được Admin xác nhận thanh toán thành công.");
    flash('ok', 'Đã xác nhận thanh toán cho đơn #' . $b['id']);
  } elseif($b && isset($map[$a])){
    $pdo->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$map[$a][0],$b['id']]);
    if($a==='complete') $pdo->prepare("UPDATE payments SET status='paid',paid_at=COALESCE(paid_at,NOW()) WHERE booking_id=?")->execute([$b['id']]);
    if($a==='cancel') $pdo->prepare("UPDATE payments SET status=IF(status='paid','refunded',status) WHERE booking_id=?")->execute([$b['id']]);
    notify($b['user_id'],"Lịch đặt {$b['name']} ngày ".date('d/m/Y',strtotime($b['booking_date'])).' '.$map[$a][1].'.');
    flash('ok','Đã cập nhật đơn #'.$b['id']);
  } else flash('err','Thao tác không hợp lệ.');
  header('Location: bookings.php'); exit;
}

// 2. Truy vấn và lọc danh sách đơn đặt sân theo từ khóa, ngày và trạng thái
$fs=$_GET['status']??''; $q=trim($_GET['q']??''); $date=$_GET['date']??'';
$sql="SELECT b.*,f.name fname,u.full_name,u.phone,p.method pay_method,p.status pay_status,p.transaction_code FROM bookings b JOIN fields f ON f.id=b.field_id JOIN users u ON u.id=b.user_id LEFT JOIN payments p ON p.booking_id=b.id WHERE (f.name LIKE ? OR u.full_name LIKE ? OR p.transaction_code LIKE ?)";
$p=["%$q%","%$q%","%$q%"];
if($fs){ $sql.=" AND b.status=?"; $p[]=$fs; }
if($date){ $sql.=" AND b.booking_date=?"; $p[]=$date; }
$s=$pdo->prepare($sql." ORDER BY b.booking_date DESC,b.start_time DESC"); $s->execute($p); $rows=$s->fetchAll();
$st=['pending'=>['Chờ xác nhận','blue'],'confirmed'=>['Đã xác nhận','green'],'cancelled'=>['Đã hủy','gray'],'completed'=>['Hoàn thành','green']];
$page = 'bookings'; $title = 'Quản lý Đặt sân & Thanh toán';
include '../includes/admin_header.php';
?>
<!-- 3. Thanh bộ lọc tìm kiếm đơn đặt sân -->
<form class="sbar" method="get">
  <input name="q" placeholder="Tên sân / khách hàng / mã giao dịch..." value="<?=e($q)?>">
  <input type="date" name="date" value="<?=e($date)?>">
  <select name="status">
    <option value="">Tất cả trạng thái</option>
    <?php foreach($st as $k=>$v): ?><option value="<?=$k?>" <?=$fs===$k?'selected':''?>><?=$v[0]?></option><?php endforeach; ?>
  </select>
  <button class="btn">Lọc</button>
</form>

<!-- 4. Bảng hiển thị danh sách đơn đặt sân & các nút thao tác duyệt/hủy/thu tiền -->
<div class="box">
  <?php if(!$rows): ?><div class="empty">Không có lịch đặt phù hợp.</div><?php else: ?>
  <div class="tw">
    <table>
      <tr><th>#</th><th>Sân</th><th>Khách hàng</th><th>Ngày & Giờ</th><th>Tổng tiền</th><th>Thanh toán</th><th>Trạng thái</th><th>Thao tác</th></tr>
      <?php foreach($rows as $r): [$lb,$cl]=$st[$r['status']]; ?>
        <tr>
          <td><b>#<?=$r['id']?></b></td>
          <td><?=e($r['fname'])?><?php if(!empty($r['note'])): ?><small>📝 <?=e($r['note'])?></small><?php endif; ?></td>
          <td><?=e($r['full_name'])?><small>📞 <?=e($r['phone'])?></small></td>
          <td><?=date('d/m/Y',strtotime($r['booking_date']))?><small><?=substr($r['start_time'],0,5)?> - <?=substr($r['end_time'],0,5)?></small></td>
          <td><b><?=money($r['total_price'])?></b></td>
          <td>
            <span class="tag <?=$r['pay_status']==='paid'?'green':($r['pay_status']==='refunded'?'gray':'blue')?>">
              <?=strtoupper($r['pay_method']?:'CASH')?> • <?=$r['pay_status']==='paid'?'Đã TT':($r['pay_status']==='refunded'?'Hoàn tiền':'Chờ TT')?>
            </span>
            <?php if(!empty($r['transaction_code'])): ?><small><?=e($r['transaction_code'])?></small><?php endif; ?>
          </td>
          <td><span class="tag <?=$cl?>"><?=$lb?></span></td>
          <td>
            <?php if($r['pay_status']!=='paid' && $r['status']!=='cancelled'): ?>
              <form class="inl" method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="mark_paid"><button class="btn sm pu" title="Xác nhận đã thu tiền">💲 Thu tiền</button></form>
            <?php endif; ?>
            <?php foreach(['pending'=>[['confirm','Xác nhận',''],['cancel','Từ chối','red']],'confirmed'=>[['complete','Hoàn thành','bl'],['cancel','Hủy','red']]][$r['status']]??[] as $a): ?>
              <form class="inl" method="post" onsubmit="return <?=$a[0]==='cancel'?"confirm('Xác nhận hủy đơn?')":'true'?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="<?=$a[0]?>"><button class="btn sm <?=$a[2]?>"><?=$a[1]?></button></form>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include '../includes/admin_footer.php'; ?>
