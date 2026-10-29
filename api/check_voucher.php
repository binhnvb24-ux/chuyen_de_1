<?php
/**
 * FILE: api/check_voucher.php
 * MÔ TẢ: API kiểm tra tính hợp lệ và tính toán số tiền giảm của mã Voucher (trả về JSON).
 * ĐƯỢC GỌI TỪ: Trang đặt sân (user/booking.php) thông qua Fetch AJAX.
 */
require_once '../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

// 1. Nhận mã giảm giá và tạm tính đơn hàng
$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
$subtotal = (int)($_GET['subtotal'] ?? $_POST['subtotal'] ?? 0);

if ($code === '') {
  echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập mã giảm giá.']);
  exit;
}

// 2. Truy vấn thông tin Voucher và kiểm tra hạn dùng, số lượt dùng, giá trị đơn tối thiểu
$stmt = $pdo->prepare("SELECT * FROM vouchers WHERE code=? AND status='active'");
$stmt->execute([$code]);
$v = $stmt->fetch();

if (!$v) {
  echo json_encode(['ok' => false, 'message' => 'Mã giảm giá không tồn tại hoặc đã ngừng kích hoạt.']);
  exit;
}
if ($v['expired_at'] < date('Y-m-d')) {
  echo json_encode(['ok' => false, 'message' => 'Mã giảm giá này đã hết hạn sử dụng.']);
  exit;
}
if ($v['used_count'] >= $v['usage_limit']) {
  echo json_encode(['ok' => false, 'message' => 'Mã giảm giá đã hết lượt sử dụng.']);
  exit;
}
if ($subtotal > 0 && $subtotal < $v['min_order']) {
  echo json_encode([
    'ok' => false,
    'message' => 'Đơn hàng tối thiểu từ ' . money($v['min_order']) . ' mới áp dụng được mã này.'
  ]);
  exit;
}

// 3. Tính toán số tiền được giảm (theo % hoặc số tiền cố định) và trả về JSON
$discount = 0;
if ($subtotal > 0) {
  if ($v['discount_type'] === 'percent') {
    $discount = (int)round(($subtotal * $v['discount_value']) / 100);
    if ($v['max_discount'] > 0 && $discount > $v['max_discount']) {
      $discount = (int)$v['max_discount'];
    }
  } else {
    $discount = (int)$v['discount_value'];
  }
  if ($discount > $subtotal) $discount = $subtotal;
}

$desc = $v['discount_type'] === 'percent'
  ? ('Giảm ' . $v['discount_value'] . '%' . ($v['max_discount'] > 0 ? ' (tối đa ' . money($v['max_discount']) . ')' : ''))
  : ('Giảm trực tiếp ' . money($v['discount_value']));

echo json_encode([
  'ok' => true,
  'code' => $v['code'],
  'discount_type' => $v['discount_type'],
  'discount_value' => (int)$v['discount_value'],
  'max_discount' => (int)$v['max_discount'],
  'min_order' => (int)$v['min_order'],
  'discount_amount' => $discount,
  'final_total' => max(0, $subtotal - $discount),
  'message' => "Áp dụng thành công mã {$v['code']}: $desc"
]);
