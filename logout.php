<?php
/**
 * FILE: logout.php
 * MÔ TẢ: Xử lý đăng xuất, xóa toàn bộ dữ liệu Session và điều hướng về Trang chủ.
 */
session_start(); $_SESSION=[]; session_destroy();
header('Location: index.php'); exit;
