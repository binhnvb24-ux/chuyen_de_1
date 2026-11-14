<?php
/**
 * ============================================================================
 * FILE: admin/vouchers.php
 * MÔ TẢ: Quyền thiết lập mã giảm giá (Voucher) đã được phân quyền về cho từng
 *        Tài khoản Đối tác Chủ sân (user/owner-dashboard.php?tab=vouchers).
 *        Admin không còn trực tiếp tạo/sửa Voucher tại đây.
 * ============================================================================
 */

require_once '../includes/auth.php';
$adm = require_admin();

flash('ok', 'ℹ️ Quyền phát hành & thiết lập Voucher khuyến mãi hiện thuộc về từng Tài khoản Đối tác Chủ sân (trong Kênh Chủ Sân).');
header('Location: dashboard.php');
exit;
