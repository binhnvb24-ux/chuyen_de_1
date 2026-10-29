<?php
/**
 * FILE: includes/auth.php
 * MÔ TẢ: Thư viện lõi dùng chung cho toàn dự án.
 * CHỨC NĂNG: Quản lý Session, bảo mật CSRF/XSS, tự động nâng cấp bảng CSDL,
 *            đọc/ghi cài đặt hệ thống, xác thực phân quyền (User/Admin), thông báo và bộ lọc ngày.
 */

// 1. Khởi tạo Session và nạp kết nối CSDL
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/database.php';

// 2. Các hàm tiện ích định dạng & bảo mật (XSS, tiền tệ VNĐ, CSRF Token)
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((int)$n,0,',','.').'đ'; }
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(){ return '<input type="hidden" name="csrf" value="'.csrf_token().'">'; }
function csrf_check(){ if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')){ http_response_code(419); die('CSRF token không hợp lệ.'); } }

// 3. Hàm lấy đường dẫn gốc của dự án (VD: /quan-ly-san-bong/)
function project_base(){
  static $base = null;
  if ($base === null) {
    $folder = basename(dirname(__DIR__));
    $base = '/' . $folder . '/';
  }
  return $base;
}

// 4. Hàm tự động kiểm tra và tạo các bảng/cột nâng cấp nếu CSDL cũ chưa có
function ensure_upgraded_schema(){
  global $pdo;
  static $checked = false;
  if($checked) return;
  $checked = true;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
      setting_key VARCHAR(80) PRIMARY KEY,
      setting_value TEXT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_chat_logs (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NULL,
      session_id VARCHAR(100) NOT NULL DEFAULT '',
      role ENUM('user','assistant') NOT NULL DEFAULT 'user',
      message TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_session(session_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      booking_id INT UNSIGNED NULL,
      user_id INT UNSIGNED NOT NULL,
      field_id INT UNSIGNED NOT NULL,
      rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
      comment TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_reviews_field(field_id)
    ) ENGINE=InnoDB");
    $cols = $pdo->query("SHOW COLUMNS FROM payments LIKE 'transaction_code'")->fetch();
    if(!$cols){
      $pdo->exec("ALTER TABLE payments ADD COLUMN transaction_code VARCHAR(80) NULL AFTER amount");
      $pdo->exec("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','bank','transfer','qr','momo','vnpay') NOT NULL DEFAULT 'cash'");
    }
    $bcols = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'note'")->fetch();
    if(!$bcols){
      $pdo->exec("ALTER TABLE bookings ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT '' AFTER voucher_id");
    }
  } catch (Exception $ex) {}
}
ensure_upgraded_schema();

// 5. Hàm đọc & lưu thông số cấu hình hệ thống (bảng system_settings)
function get_setting($key, $default = ''){
  global $pdo;
  static $cache = null;
  if($cache === null){
    $cache = [];
    try {
      $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
      foreach($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
    } catch(Exception $e){}
  }
  return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting($key, $val){
  global $pdo;
  $stmt = $pdo->prepare("INSERT INTO system_settings(setting_key, setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
  $stmt->execute([$key, $val]);
}

// 6. Các hàm xác thực đăng nhập và kiểm tra quyền truy cập (User / Admin)
function current_user(){ global $pdo;
  if(empty($_SESSION['uid'])) return null;
  $s=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active'"); $s->execute([$_SESSION['uid']]);
  $u=$s->fetch(); if(!$u){ session_destroy(); return null; } return $u; }

function require_login(){ $u=current_user(); if(!$u){ header('Location: '.project_base().'login.php'); exit; } return $u; }
function require_admin(){ $u=require_login(); if($u['role']!=='admin'){ http_response_code(403); header('Location: '.project_base().'index.php'); exit; } return $u; }

// 7. Các hàm thông báo Flash (Session), thông báo DB và xử lý đường dẫn ảnh
function flash($type,$msg){ $_SESSION['flash']=[$type,$msg]; }
function notify($uid,$msg){ global $pdo; $pdo->prepare("INSERT INTO notifications(user_id,message) VALUES(?,?)")->execute([$uid,$msg]); }
function img_src($p){ return preg_match('#^https?://#',$p)?$p:project_base().ltrim((string)$p,'/'); }

// 8. Các hàm hỗ trợ lọc khoảng ngày cho trang Thống kê & Báo cáo Admin
function get_range(){ $f=$_GET['from']??''; $t=$_GET['to']??''; $ok=fn($d)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)&&strtotime($d);
  if(!$ok($f)) $f=date('Y-m-d',strtotime('-29 day')); if(!$ok($t)) $t=date('Y-m-d'); if($f>$t) [$f,$t]=[$t,$f]; return [$f,$t]; }
function range_form($extra=''){ [$f,$t]=get_range(); return '<form class="sbar" method="get" style="background:#fff;margin:0 0 14px"><label>Từ <input type="date" name="from" value="'.$f.'"></label><label>Đến <input type="date" name="to" value="'.$t.'"></label>'.$extra.'<button class="btn">Lọc</button></form>'; }
