<?php
/**
 * FILE: index.php
 * MÔ TẢ: Trang chủ của hệ thống Đặt Sân Bóng Trực Tuyến.
 * CHỨC NĂNG: Tìm kiếm & lọc sân theo khu vực/loại sân/giá/giờ trống, hiển thị danh sách sân,
 *            danh mục sân, lịch đặt gần đây của người dùng và các chương trình ưu đãi.
 */
require_once 'includes/auth.php';

// 1. Kiểm tra người dùng hiện tại (nếu là Admin thì chuyển hướng sang trang Quản trị)
$u=current_user();
if($u&&$u['role']==='admin'){ header('Location: admin/dashboard.php'); exit; }

// 2. Xử lý tham số tìm kiếm & lọc sân bóng từ URL ($_GET)
$q=trim($_GET['q']??''); $type=in_array($_GET['type']??'',['5','7','11'])?$_GET['type']:''; $dist=trim($_GET['district']??''); $max=(int)($_GET['max']??0);
$date=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['date']??'')?$_GET['date']:''; $start=preg_match('/^\d{2}:00$/',$_GET['start']??'')?$_GET['start']:'';
$sql="SELECT * FROM fields f WHERE f.status='active' AND (f.name LIKE ? OR f.address LIKE ?)"; $p=["%$q%","%$q%"];
if($type){ $sql.=" AND f.type=?"; $p[]=$type; } if($dist){ $sql.=" AND f.district=?"; $p[]=$dist; } if($max){ $sql.=" AND f.price_per_hour<=?"; $p[]=$max; }
if($date&&$start){ $end=sprintf('%02d:00',(int)$start+1); $sql.=" AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.field_id=f.id AND b.booking_date=? AND b.status IN('pending','confirmed','completed') AND b.start_time<? AND b.end_time>?)"; array_push($p,$date,$end,$start); }

// 3. Truy vấn danh sách sân, quận/huyện, sân đang bận hiện tại và thống kê theo loại sân
$s=$pdo->prepare($sql." ORDER BY f.rating DESC LIMIT 12"); $s->execute($p); $fields=$s->fetchAll();
$districts=$pdo->query("SELECT DISTINCT district FROM fields WHERE status='active' AND district<>'' ORDER BY district")->fetchAll(PDO::FETCH_COLUMN);
$busyNow=$pdo->query("SELECT DISTINCT field_id FROM bookings WHERE booking_date=CURDATE() AND status IN('pending','confirmed') AND start_time<=CURTIME() AND end_time>CURTIME()")->fetchAll(PDO::FETCH_COLUMN);
$cats=$pdo->query("SELECT type,COUNT(*) n,MIN(price_per_hour) p,MIN(image) img FROM fields WHERE status='active' GROUP BY type ORDER BY type")->fetchAll();

// 4. Lấy lịch đặt gần đây và số thông báo chưa đọc của khách hàng (nếu đã đăng nhập)
$my=[]; $unread=0;
if($u){
  $s=$pdo->prepare("SELECT b.*,f.name,f.image FROM bookings b JOIN fields f ON f.id=b.field_id WHERE b.user_id=? ORDER BY b.booking_date DESC,b.start_time DESC LIMIT 3"); $s->execute([$u['id']]); $my=$s->fetchAll();
  $s=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0"); $s->execute([$u['id']]); $unread=$s->fetchColumn();
}
$ad=$pdo->query("SELECT phone,email FROM users WHERE role='admin' AND status='active' LIMIT 1")->fetch()?:['phone'=>'','email'=>''];
$st=['pending'=>['Chờ xác nhận','blue'],'confirmed'=>['Đã xác nhận','green'],'cancelled'=>['Đã hủy','gray'],'completed'=>['Hoàn thành','green']];
$filtered=($q!==''||$type||$dist||$max||($date&&$start));
?><!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sân Bóng - Đặt sân nhanh chóng & trải nghiệm thể thao đỉnh cao</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/home.css"></head><body class="hp">
<!-- Thanh Header điều hướng trên cùng -->
<header class="hp-top"><a class="hp-brand" href="index.php">⚽ <div><b>Sân Bóng</b><small>Đặt sân dễ dàng - Chơi bóng thỏa thích</small></div></a>
<nav class="hp-nav"><a class="on" href="index.php">Trang chủ</a><a href="#fields">Sân bóng</a><a href="user/booking.php">Đặt sân</a><a href="#contact">Liên hệ</a></nav>
<form class="hp-search" method="get"><input name="q" placeholder="Tìm sân, địa điểm, quận huyện..." value="<?=e($q)?>"><button>🔍</button></form>
<div class="hp-user"><a href="user/notifications.php">🔔<?php if($unread):?><i class="dot"><?=$unread?></i><?php endif;?></a>
<?php if($u): ?><span class="avatar"><?=e(mb_substr($u['full_name'],0,1))?></span><a href="user/profile.php"><?=e($u['full_name'])?></a><?php else: ?><a class="lnk" href="login.php">Đăng nhập</a><?php endif; ?></div></header>

<!-- Khung bố cục 3 cột trang chủ: Sidebar trái + Nội dung chính + Sidebar phải -->
<div class="hp-wrap">
<!-- Cột 1: Menu điều hướng nhanh bên trái -->
<nav class="hp-side"><a class="on" href="index.php">🏠 Trang chủ</a><h6>ĐẶT SÂN</h6><a href="user/booking.php">📅 Đặt sân ngay</a><a href="user/my-bookings.php">🕘 Lịch đặt của tôi</a>
<h6>KHÁM PHÁ</h6><a href="#fields">⚽ Tất cả sân bóng</a><h6>TÀI KHOẢN</h6><a href="user/profile.php">👤 Hồ sơ cá nhân</a><a href="user/notifications.php">🔔 Thông báo<?php if($unread):?><i class="dot"><?=$unread?></i><?php endif;?></a>
<?php if($u): ?><a href="logout.php" onclick="return confirm('Đăng xuất?')">↪ Đăng xuất</a><?php else: ?><a href="login.php">🔑 Đăng nhập</a><a href="register.php">📝 Đăng ký</a><?php endif; ?></nav>

<!-- Cột 2: Nội dung chính (Banner tìm kiếm, Danh sách sân, Danh mục, Lịch đặt, Ưu đãi) -->
<main class="hp-main">
<!-- Phần Hero Banner & Bộ lọc tìm sân trực tuyến -->
<section class="hp-hero"><small class="k">SÂN BÓNG - NỀN TẢNG ĐẶT SÂN TRỰC TUYẾN</small><h1>Đặt sân bóng nhanh chóng<span>Trải nghiệm thể thao tuyệt vời</span></h1>
<div class="hp-chips"><span><i>✔</i> Đa dạng sân bóng</span><span><i>✔</i> Giá cả minh bạch</span><span><i>✔</i> Đặt sân dễ dàng</span><span><i>✔</i> Thanh toán an toàn</span></div>
<form class="hp-form" method="get"><input type="hidden" name="q" value="<?=e($q)?>">
<select name="district"><option value="">Địa điểm</option><?php foreach($districts as $d): ?><option <?=$dist===$d?'selected':''?>><?=e($d)?></option><?php endforeach; ?></select>
<select name="type"><option value="">Loại sân</option><?php foreach(['5','7','11'] as $tp): ?><option value="<?=$tp?>" <?=$type===$tp?'selected':''?>>Sân <?=$tp?> người</option><?php endforeach; ?></select>
<select name="max"><option value="">Mức giá</option><?php foreach([100000,120000,150000] as $m): ?><option value="<?=$m?>" <?=$max==$m?'selected':''?>>Đến <?=money($m)?></option><?php endforeach; ?></select>
<input type="date" name="date" min="<?=date('Y-m-d')?>" value="<?=e($date)?>"><select name="start"><option value="">Giờ</option><?php for($h=6;$h<=22;$h++): $v=sprintf('%02d:00',$h); ?><option <?=$start===$v?'selected':''?>><?=$v?></option><?php endfor; ?></select>
<button class="btn">🔍 Tìm sân</button></form></section>

<!-- Thanh điểm nổi bật (Feature Strip) -->
<section class="hp-strip"><div><span>🏟</span><p><b>Sân chất lượng</b><small>Cỏ nhân tạo, đạt chuẩn</small></p></div><div><span>⚡</span><p><b>Đặt sân nhanh</b><small>Vài thao tác đơn giản</small></p></div>
<div><span>💳</span><p><b>Thanh toán linh hoạt</b><small>Tiền mặt, chuyển khoản QR</small></p></div><div><span>💬</span><p><b>Tư vấn tự động</b><small>Chat ở góc phải màn hình</small></p></div></section>

<!-- Danh sách sân bóng -->
<section id="fields"><h3 class="hp-h"><span>📍 <?=$filtered?'Kết quả tìm kiếm':'Danh sách sân'?></span><?php if($filtered): ?><a href="index.php">Xóa bộ lọc</a><?php endif; ?></h3>
<?php if(!$fields): ?><div class="empty">Không tìm thấy sân phù hợp.</div><?php endif; ?><div class="hp-grid"><?php foreach($fields as $f): $b=in_array($f['id'],$busyNow); ?>
<div class="hp-card"><div class="hp-img"><img src="<?=e(img_src($f['image']))?>" alt="<?=e($f['name'])?>"><span class="hp-badge <?=$b?'b':''?>"><?=$b?'Đang sử dụng':'Đang trống'?></span></div>
<div class="hp-cb"><h4><?=e($f['name'])?></h4><small>📍 <?=e($f['address'])?></small><small>⭐ <b><?=e($f['rating'])?></b> (<?=e($f['review_count'])?> đánh giá)</small><p class="hp-pr"><?=money($f['price_per_hour'])?> / giờ</p>
<div class="hp-row"><a href="user/field-detail.php?id=<?=$f['id']?>">Chi tiết</a><a class="btn" href="user/booking.php?field_id=<?=$f['id']?>">Đặt sân ngay →</a></div></div></div><?php endforeach; ?></div></section>

<!-- Danh mục sân theo quy mô (Sân 5, 7, 11 người) -->
<?php if($cats): ?><section><h3 class="hp-h"><span>⚽ Danh mục sân bóng</span></h3><div class="hp-grid"><?php foreach($cats as $c): ?>
<div class="hp-card hp-cat"><div class="hp-img"><img src="<?=e(img_src($c['img']))?>" alt=""></div><div class="hp-cb"><h4>Sân <?=e($c['type'])?> người</h4><small><?=$c['n']?> sân đang hoạt động</small><p class="hp-pr">Từ <?=money($c['p'])?> / giờ</p>
<a class="btn" style="padding:8px;font-size:13px" href="?type=<?=e($c['type'])?>#fields">Xem sân →</a></div></div><?php endforeach; ?></div></section><?php endif; ?>

<!-- Bảng xem nhanh lịch đặt gần đây -->
<section class="hp-booking-table" id="booking-preview"><h3 class="hp-h"><span>🗓️ Lịch đặt sân gần đây</span><a href="user/my-bookings.php">Xem lịch của tôi →</a></h3>
<?php if($u && $my): ?><div class="hp-table-wrap"><table class="hp-table"><thead><tr><th>Sân bóng</th><th>Ngày</th><th>Giờ</th><th>Trạng thái</th></tr></thead><tbody>
<?php foreach($my as $bk): [$lb,$cl]=$st[$bk['status']]??['Đang xử lý','blue']; ?><tr><td><b><?=e($bk['name'])?></b></td><td><?=date('d/m/Y',strtotime($bk['booking_date']))?></td><td><?=substr($bk['start_time'],0,5)?>–<?=substr($bk['end_time'],0,5)?></td><td><span class="tag <?=$cl?>"><?=$lb?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><div class="hp-booking-empty"><span>📅</span><div><b>Lên lịch cho trận đấu tiếp theo</b><small>Chọn sân phù hợp, khung giờ còn trống và đặt sân chỉ trong vài bước.</small></div><a class="btn" href="user/booking.php">Tìm giờ trống →</a></div><?php endif; ?></section>

<!-- Phần Ưu đãi & Lý do chọn hệ thống -->
<section class="hp-deals"><h3 class="hp-h"><span>🎁 Ưu đãi dành cho bạn</span><a href="user/booking.php">Khám phá →</a></h3><div class="hp-deal-grid"><article class="hp-deal deal-green"><small>ƯU ĐÃI THÀNH VIÊN</small><b>Chơi nhiều – tiết kiệm hơn</b><span>Khám phá sân có mức giá phù hợp với đội bóng của bạn.</span><a href="user/booking.php">Tìm sân ngay ↗</a></article><article class="hp-deal deal-blue"><small>TRẬN ĐẤU CUỐI TUẦN</small><b>Rủ đội bóng, chốt lịch!</b><span>Chủ động chọn ngày và giờ trước khi sân được đặt kín.</span><a href="user/booking.php">Đặt lịch ↗</a></article><article class="hp-deal deal-purple"><small>GỢI Ý CHO BẠN</small><b>Tìm sân theo nhu cầu</b><span>Lọc theo loại sân, khu vực và mức giá mong muốn.</span><a href="#fields">Xem danh sách ↗</a></article></div></section>
<section class="hp-why"><h3 class="hp-h"><span>✨ Vì sao chọn Sân Bóng?</span></h3><div class="hp-why-grid"><div><span>🔎</span><b>Dễ tìm kiếm</b><small>Lọc sân theo khu vực, loại sân và ngân sách.</small></div><div><span>🕒</span><b>Chủ động thời gian</b><small>Xem thông tin và gửi yêu cầu đặt sân thuận tiện.</small></div><div><span>🔐</span><b>Thông tin rõ ràng</b><small>Theo dõi lịch đặt và trạng thái ngay trong tài khoản.</small></div></div></section>
</main>

<!-- Cột 3: Sidebar tiện ích bên phải (Lịch đặt, lọc nhanh, tin tức, hỗ trợ) -->
<aside class="hp-right"><div><div class="bh"><b>Lịch đặt của tôi</b><a href="user/my-bookings.php">Xem tất cả →</a></div>
<?php if(!$u): ?><div class="empty"><a href="login.php">Đăng nhập</a> để xem lịch đặt.</div><?php elseif(!$my): ?><div class="empty">Bạn chưa có lịch đặt nào.</div><?php endif; ?>
<?php foreach($my as $bk): [$lb,$cl]=$st[$bk['status']]; ?><div class="bk"><img src="<?=e(img_src($bk['image']))?>" alt=""><div><b><?=e($bk['name'])?></b><small>📅 <?=date('d/m/Y',strtotime($bk['booking_date']))?> | <?=substr($bk['start_time'],0,5)?>-<?=substr($bk['end_time'],0,5)?></small><span class="tag <?=$cl?>"><?=$lb?></span></div></div><?php endforeach; ?></div>
<div class="hp-promo"><b>Chơi bóng cùng bạn bè</b><small>Sân bóng chất lượng - Dịch vụ uy tín - Giá tốt nhất</small><a class="btn" href="user/booking.php">Đặt sân ngay →</a></div>
<div><b>Chọn sân theo nhu cầu</b><div class="hp-need" style="margin-top:10px"><a href="?type=5#fields">Sân 5 người</a><a href="?type=7#fields">Sân 7 người</a><a href="?type=11#fields">Sân 11 người</a><a href="index.php#fields">Tất cả sân</a></div></div>
<div class="hp-right-news"><div class="bh"><b>📰 Tin tức & sự kiện</b><a href="#contact">Mới nhất</a></div><a class="hp-news-item" href="user/booking.php"><span class="news-thumb">⚽</span><span><b>Sẵn sàng cho trận đấu cuối tuần</b><small>Chủ động chọn sân và giờ đá phù hợp cho đội.</small></span></a><a class="hp-news-item" href="user/booking.php"><span class="news-thumb news-two">🏆</span><span><b>Kết nối cộng đồng bóng đá</b><small>Tìm sân thuận tiện để gặp gỡ bạn bè.</small></span></a><a class="hp-news-item" href="user/my-bookings.php"><span class="news-thumb news-three">📅</span><span><b>Đừng quên kiểm tra lịch đặt</b><small>Theo dõi trạng thái xác nhận trong tài khoản.</small></span></a></div>
<div class="hp-right-review"><div class="bh"><b>⭐ Trải nghiệm dễ dàng</b></div><div class="hp-rating"><strong>4.9</strong><span>★★★★★<small>Thiết kế hướng đến trải nghiệm đặt sân thuận tiện</small></span></div><p>“Tìm sân, xem giá và theo dõi lịch đặt trong cùng một nơi.”</p><small class="review-by">— Gợi ý từ Sân Bóng</small></div>
<div class="hp-right-help"><span>🎧</span><div><b>Cần hỗ trợ đặt sân?</b><small>Liên hệ quản lý sân để được tư vấn.</small><a href="mailto:<?=e($ad['email'])?>">Gửi email hỗ trợ →</a></div></div>
</aside>
</div>

<!-- Chân trang (Footer) & Nhúng Widget Chat AI -->
<footer class="hp-foot" id="contact"><div class="foot-brand"><b>⚽ Sân Bóng</b><small>Nền tảng đặt sân bóng trực tuyến</small><p>Kết nối đội bóng với sân phù hợp – nhanh chóng, thuận tiện.</p><div class="hp-social"><span>f</span><span>▶</span><span>◎</span><span>♪</span></div></div>
<div><b>Liên hệ</b><small>📍 Việt Nam</small><small>📞 <?=e($ad['phone']?:'Đang cập nhật')?></small><small>✉ <?=e($ad['email']?:'Đang cập nhật')?></small><small>🕒 08:00 – 22:00</small></div>
<div><b>Chính sách</b><a href="#contact">Chính sách bảo mật</a><a href="#contact">Điều khoản sử dụng</a><a href="#contact">Chính sách hủy đặt</a></div>
<div><b>Hỗ trợ khách hàng</b><a href="user/booking.php">Hướng dẫn đặt sân</a><a href="user/my-bookings.php">Quản lý lịch đặt</a><a href="user/notifications.php">Thông báo</a><a href="mailto:<?=e($ad['email'])?>">Liên hệ hỗ trợ</a></div>
<div class="hp-newsletter"><b>Nhận tin từ Sân Bóng</b><small>Cập nhật thông tin và ưu đãi mới.</small><form onsubmit="event.preventDefault();this.querySelector('.newsletter-msg').textContent='Cảm ơn bạn đã quan tâm Sân Bóng!';"><input type="email" required placeholder="Email của bạn"><button type="submit">Đăng ký</button><small class="newsletter-msg"></small></form></div>
<div class="hp-copy">© <?=date('Y')?> Sân Bóng. Bảo lưu mọi quyền.</div></footer>
<?php if (is_file(__DIR__.'/includes/chat_widget.php')) include 'includes/chat_widget.php'; ?></body></html>
