<?php
/**
 * ============================================================================
 * FILE: config/database.php
 * MÔ TẢ: Tệp cấu hình kết nối Cơ sở dữ liệu MySQL sử dụng thư viện PDO.
 * MÔI TRƯỜNG: Chạy trên XAMPP mặc định (Host: localhost, User: root, Pass: rỗng).
 * ============================================================================
 */

// ----------------------------------------------------------------------------
// PHẦN 1: KHAI BÁO THÔNG SỐ KẾT NỐI CƠ SỞ DỮ LIỆU
// ----------------------------------------------------------------------------
$DB_HOST = 'localhost';         // Địa chỉ máy chủ MySQL
$DB_NAME = 'quan_ly_san_bong';  // Tên cơ sở dữ liệu trong phpMyAdmin
$DB_USER = 'root';              // Tài khoản quản trị MySQL mặc định của XAMPP
$DB_PASS = '';                  // Mật khẩu mặc định của XAMPP (để trống)

// ----------------------------------------------------------------------------
// PHẦN 2: KHỞI TẠO ĐỐI TƯỢNG KẾT NỐI PDO
// ----------------------------------------------------------------------------
try {
    // Tạo chuỗi kết nối DSN với bảng mã chuẩn tiếng Việt utf8mb4
    $dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

    // Thiết lập các tùy chọn an toàn và tiện lợi cho PDO
    $options = [
        // Bật chế độ ném ngoại lệ (Exception) khi truy vấn SQL gặp lỗi
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // Mặc định trả về kết quả truy vấn dưới dạng mảng kết hợp (Associative Array)
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Tắt giả lập Prepared Statement để chống SQL Injection ở mức MySQL Native
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Khởi tạo đối tượng $pdo dùng chung cho toàn bộ dự án
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

} catch (PDOException $e) {
    // Nếu chưa tạo CSDL hoặc MySQL chưa bật, dừng chương trình và báo hướng dẫn
    die('Không kết nối được CSDL. Hãy bật MySQL trong XAMPP và import file database.sql bằng phpMyAdmin.');
}
