<?php
/**
 * FILE: user/my-bookings.php
 * MÔ TẢ: Trang quản lý danh sách lịch đặt sân của Khách hàng.
 * CHỨC NĂNG: Xem lịch sử đặt sân, trạng thái thanh toán, truy cập cổng Thanh toán Demo / Biên lai
 *            và hủy đơn đặt sân (yêu cầu hủy trước giờ bóng lăn tối thiểu 2 tiếng).
 */
require_once '../includes/auth.php';
$u = require_login();

// 1. Xử lý yêu cầu hủy đơn đặt sân từ khách hàng (POST)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  csrf_check();
  $s = $pdo->prepare("SELECT b.*,f.name FROM bookings b JOIN fields f ON f.id=b.field_id WHERE b.id=? AND b.user_id=?"); 
  $s->execute([(int)$_POST['id'], $u['id']]); 
  $b = $s->fetch();

  if(!$b || !in_array($b['status'], ['pending','confirmed'])) flash('err','Không thể hủy đơn đặt sân này.');
  elseif(strtotime($b['booking_date'].' '.$b['start_time']) - time() < 7200) flash('err','Quy định: Chỉ được hủy sân trước giờ bắt đầu tối thiểu 2 giờ.');
  else { 
    $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$b['id']]);
    $pdo->prepare("UPDATE payments SET status=IF(status='paid','refunded',status) WHERE booking_id=?")->execute([$b['id']]);
    notify($u['id'], "Bạn đã hủy đặt {$b['name']} ngày ".date('d/m/Y',strtotime($b['booking_date'])).'.'); 
    flash('ok', 'Đã hủy lịch đặt sân thành công.'); 
  }
  header("Location: my-bookings.php"); 
  exit;
}

// 2. Truy vấn danh sách toàn bộ đơn đặt sân kèm trạng thái thanh toán của khách hàng
$s = $pdo->prepare("SELECT b.*,f.name,f.image, p.status pay_status, p.method pay_method, p.transaction_code FROM bookings b JOIN fields f ON f.id=b.field_id LEFT JOIN payments p ON p.booking_id=b.id WHERE b.user_id=? ORDER BY b.booking_date DESC,b.start_time DESC"); 
$s->execute([$u['id']]); 
$rows = $s->fetchAll();

$st = [
  'pending'   => ['Chờ xác nhận', 'blue'],
  'confirmed' => ['Đã xác nhận', 'green'],
  'cancelled' => ['Đã hủy', 'gray'],
  'completed' => ['Hoàn thành', 'green']
];

$active = 'mine'; 
$title = 'Lịch đặt của tôi'; 
include '../includes/header.php'; 
?>

<!-- 3. Giao diện bảng danh sách đơn đặt sân -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#0f172a">📅 Quản Lý Lịch Đặt Sân</h2>
    <small style="font-size:13.5px;color:#64748b">Xem danh sách các trận đấu bạn đã đặt, thanh toán Demo và theo dõi trạng thái</small>
  </div>
  <a class="btn" href="booking.php">+ Đặt thêm sân mới</a>
</div>

<?php if(!$rows): ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:48px;margin-bottom:12px">⚽</div>
    <h3 style="color:#0f172a;margin:0 0 8px">Bạn chưa có lịch đặt sân nào</h3>
    <small style="color:#64748b;font-size:14px;margin-bottom:20px">Hãy tìm kiếm sân bóng yêu thích và đặt ngay một trận cầu sôi động!</small>
    <a class="btn" href="booking.php" style="margin-top:16px">Khám phá và đặt sân ngay →</a>
  </div>
<?php else: ?>
  <div class="tw">
    <table>
      <thead>
        <tr>
          <th>Mã đơn</th>
          <th>Sân bóng</th>
          <th>Ngày thi đấu</th>
          <th>Khung giờ</th>
          <th>Tổng chi phí</th>
          <th>Thanh toán</th>
          <th>Trạng thái</th>
          <th>Thao tác</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): [$lb, $cl] = $st[$r['status']] ?? ['Đang xử lý', 'blue']; ?>
          <tr>
            <td><b style="color:#64748b">#<?=$r['id']?></b></td>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <img src="<?=e(img_src($r['image']))?>" alt="" style="width:48px;height:38px;border-radius:6px;object-fit:cover">
                <b><?=e($r['name'])?></b>
              </div>
            </td>
            <td><?=date('d/m/Y', strtotime($r['booking_date']))?></td>
            <td><b><?=substr($r['start_time'],0,5)?> - <?=substr($r['end_time'],0,5)?></b></td>
            <td><b style="color:#059669"><?=money($r['total_price'])?></b></td>
            <td>
              <?php if($r['pay_status']==='paid'): ?>
                <span class="tag green">✔ Đã thanh toán</span>
                <?php if(!empty($r['transaction_code'])): ?><small style="font-size:11px"><?=e($r['transaction_code'])?></small><?php endif; ?>
              <?php elseif($r['pay_status']==='refunded'): ?>
                <span class="tag gray">↩ Đã hoàn tiền</span>
              <?php else: ?>
                <span class="tag amber">⏳ Chờ thanh toán</span>
              <?php endif; ?>
            </td>
            <td><span class="tag <?=$cl?>"><?=$lb?></span></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if($r['status'] !== 'cancelled'): ?>
                  <a class="btn sm <?=$r['pay_status']==='paid'?'outline':'bl'?>" href="payment-demo.php?booking_id=<?=$r['id']?>">
                    <?=$r['pay_status']==='paid'?'🧾 Biên lai':'💳 Thanh toán Demo'?>
                  </a>
                <?php endif; ?>
                <?php if(in_array($r['status'], ['pending','confirmed'])): ?>
                  <form class="inl" method="post" onsubmit="return confirm('Bạn có chắc chắn muốn hủy đơn đặt sân này?')">
                    <?=csrf_field()?>
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <button class="btn sm red" type="submit">Hủy đơn</button>
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
