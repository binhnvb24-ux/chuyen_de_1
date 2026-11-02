<?php
/**
 * ============================================================================
 * FILE: logout.php
 * MÔ TẢ: Xử lý đăng xuất tài khoản khỏi hệ thống Đặt Sân Bóng.
 * CHỨC NĂNG:
 *   1. Khởi động hoặc tiếp tục phiên làm việc (Session) hiện tại.
 *   2. Xóa sạch mảng dữ liệu $_SESSION.
 *   3. Hủy toàn bộ Session trên máy chủ bằng session_destroy().
 *   4. Chuyển hướng người dùng quay trở về Trang chủ (index.php).
 * ============================================================================
 */

// Bước 1: Khởi tạo phiên làm việc nếu chưa được bật
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bước 2: Làm rỗng toàn bộ biến lưu trữ trong Session (uid, csrf, flash...)
$_SESSION = [];

// Bước 3: Hủy hoàn toàn phiên làm việc trên máy chủ
session_destroy();

// Bước 4: Điều hướng người dùng về lại Trang chủ
header('Location: index.php');
exit;
