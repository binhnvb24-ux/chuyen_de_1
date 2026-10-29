<?php
/**
 * FILE: config/database.php
 * MÔ TẢ: Cấu hình kết nối Cơ sở dữ liệu MySQL bằng PDO.
 * Chạy trên môi trường XAMPP mặc định (user: root, không mật khẩu).
 */

// Thông số kết nối CSDL
$DB_HOST='localhost'; $DB_NAME='quan_ly_san_bong'; $DB_USER='root'; $DB_PASS='';

// Khởi tạo đối tượng PDO với chế độ báo lỗi Exception và trả về mảng kết hợp (Associative Array)
try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
} catch (PDOException $e) { die('Không kết nối được CSDL. Hãy import database.sql bằng phpMyAdmin.'); }
