-- =============================================================================
-- FILE: database_multirole_upgrade.sql
-- MÔ TẢ: Script nâng cấp Cơ sở dữ liệu cho Hệ thống Quản lý Tài khoản Đa vai trò
--        (CUSTOMER - PITCH_OWNER - ADMIN), Ví nội bộ, Điểm uy tín (Reputation),
--        Hồ sơ Cầu thủ và Luồng Đăng ký Đối tác Mở sân (Partner Applications).
-- =============================================================================

USE quan_ly_san_bong;

-- -----------------------------------------------------------------------------
-- 1. MỞ RỘNG BẢNG NGƯỜI DÙNG (`users`) & TẠO BẢNG HỒ SƠ CẦU THỦ (`user_profiles`)
-- -----------------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('user','pitch_owner','admin') NOT NULL DEFAULT 'user',
  ADD COLUMN IF NOT EXISTS account_role ENUM('CUSTOMER','PITCH_OWNER','ADMIN') NOT NULL DEFAULT 'CUSTOMER' AFTER role,
  ADD COLUMN IF NOT EXISTS account_status ENUM('ACTIVE','WARNING','BANNED') NOT NULL DEFAULT 'ACTIVE' AFTER status,
  ADD COLUMN IF NOT EXISTS partner_status ENUM('NONE','PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'NONE' AFTER account_status,
  ADD COLUMN IF NOT EXISTS wallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER partner_status,
  ADD COLUMN IF NOT EXISTS reputation_score INT NOT NULL DEFAULT 100 AFTER wallet_balance,
  ADD COLUMN IF NOT EXISTS loyalty_points INT NOT NULL DEFAULT 0 AFTER reputation_score,
  ADD COLUMN IF NOT EXISTS google_id VARCHAR(100) NULL DEFAULT NULL UNIQUE AFTER loyalty_points,
  ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) NULL DEFAULT NULL AFTER google_id,
  ADD COLUMN IF NOT EXISTS auth_provider ENUM('local','google') NOT NULL DEFAULT 'local' AFTER avatar,
  MODIFY COLUMN password VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS preferred_position VARCHAR(60) NOT NULL DEFAULT 'Tiền vệ' AFTER auth_provider,
  ADD COLUMN IF NOT EXISTS skill_level VARCHAR(60) NOT NULL DEFAULT 'Vui vẻ' AFTER preferred_position,
  ADD COLUMN IF NOT EXISTS team_name VARCHAR(120) NOT NULL DEFAULT '' AFTER skill_level,
  ADD COLUMN IF NOT EXISTS preferred_districts VARCHAR(255) NOT NULL DEFAULT '' AFTER team_name;

-- Bảng `user_profiles` (Lưu mở rộng thông tin cầu thủ / đội bóng liên kết 1-1 với `users`)
CREATE TABLE IF NOT EXISTS user_profiles (
  user_id INT UNSIGNED PRIMARY KEY,
  preferred_position ENUM('Thủ môn','Hậu vệ','Tiền vệ','Tiền đạo','Đa năng') NOT NULL DEFAULT 'Tiền vệ',
  skill_level ENUM('Vui vẻ','Phong trào','Bán chuyên') NOT NULL DEFAULT 'Vui vẻ',
  team_name VARCHAR(120) NOT NULL DEFAULT '',
  preferred_districts VARCHAR(255) NOT NULL DEFAULT '',
  jersey_number TINYINT UNSIGNED NULL DEFAULT 10,
  bio VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_uprof_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. BẢNG LỊCH SỬ VÍ NỘI BỘ & ĐIỂM UY TÍN (`wallet_transactions` & `user_logs`)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NULL DEFAULT NULL,
  tx_type ENUM('REFUND','PAYMENT','DEPOSIT','REPUTATION','LOYALTY','ADMIN_ADJUST') NOT NULL DEFAULT 'REFUND',
  amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Số tiền cộng (+) hoặc trừ (-)',
  balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Số dư ví sau giao dịch',
  reputation_delta INT NOT NULL DEFAULT 0 COMMENT 'Điểm uy tín cộng (+) hoặc trừ (-)',
  reputation_after INT NOT NULL DEFAULT 100 COMMENT 'Điểm uy tín sau biến động',
  loyalty_delta INT NOT NULL DEFAULT 0 COMMENT 'Điểm tích lũy cộng (+) hoặc trừ (-)',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Admin thao tác (nếu có)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wt_user_created (user_id, created_at),
  CONSTRAINT fk_wt_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(60) NOT NULL DEFAULT 'REPUTATION_CHANGE',
  reputation_delta INT NOT NULL DEFAULT 0,
  wallet_delta DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  performed_by INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ulogs_user (user_id, created_at),
  CONSTRAINT fk_ulogs_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. BẢNG HỒ SƠ ĐĂNG KÝ HỢP TÁC MỞ SÂN (`partner_applications`)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partner_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  venue_name VARCHAR(180) NOT NULL,
  venue_address VARCHAR(255) NOT NULL,
  district VARCHAR(100) NOT NULL DEFAULT 'Quận 1',
  city VARCHAR(100) NOT NULL DEFAULT 'TP. Hồ Chí Minh',
  latitude DECIMAL(10,7) NOT NULL DEFAULT 10.7768890,
  longitude DECIMAL(10,7) NOT NULL DEFAULT 106.7008060,
  pitch_summary VARCHAR(255) NOT NULL DEFAULT '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
  cnt_pitch_5 TINYINT UNSIGNED NOT NULL DEFAULT 4,
  cnt_pitch_7 TINYINT UNSIGNED NOT NULL DEFAULT 2,
  cnt_pitch_11 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  price_normal INT UNSIGNED NOT NULL DEFAULT 160000,
  price_peak INT UNSIGNED NOT NULL DEFAULT 290000,
  contact_phone VARCHAR(30) NOT NULL DEFAULT '',
  venue_images TEXT NULL COMMENT 'JSON hoặc danh sách đường dẫn ảnh thực tế sân bóng',
  tax_code VARCHAR(60) NOT NULL DEFAULT '',
  business_license_url VARCHAR(255) NOT NULL DEFAULT '',
  id_card_front_url VARCHAR(255) NOT NULL DEFAULT '',
  id_card_back_url VARCHAR(255) NOT NULL DEFAULT '',
  bank_name VARCHAR(80) NOT NULL DEFAULT 'MB Bank',
  bank_account_number VARCHAR(60) NOT NULL DEFAULT '',
  bank_account_holder VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  rejection_reason VARCHAR(255) NULL DEFAULT NULL,
  reviewed_by INT UNSIGNED NULL DEFAULT NULL,
  reviewed_at DATETIME NULL DEFAULT NULL,
  approved_field_id INT UNSIGNED NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pa_user (user_id),
  INDEX idx_pa_status (status),
  CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. BẢNG CỤM SÂN (`venues`) & MỞ RỘNG BẢNG SÂN CON (`fields` / `pitches`)
--    & KHUNG GIÁ LINH HOẠT (`pricing_rules` / `pitch_pricing`)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS venues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Liên kết users.id (PITCH_OWNER)',
  name VARCHAR(180) NOT NULL,
  address VARCHAR(255) NOT NULL DEFAULT '',
  district VARCHAR(100) NOT NULL DEFAULT '',
  city VARCHAR(100) NOT NULL DEFAULT 'TP. Hồ Chí Minh',
  latitude DECIMAL(10,7) NOT NULL DEFAULT 10.7768890,
  longitude DECIMAL(10,7) NOT NULL DEFAULT 106.7008060,
  open_time TIME NOT NULL DEFAULT '05:30:00',
  close_time TIME NOT NULL DEFAULT '23:00:00',
  amenities VARCHAR(255) NOT NULL DEFAULT 'wifi,parking,canteen,shoes' COMMENT 'Wifi, Bãi xe, Căng tin, Thuê giày...',
  images TEXT NULL COMMENT 'JSON mảng đường dẫn ảnh thực tế',
  contact_phone VARCHAR(30) NOT NULL DEFAULT '',
  description TEXT NULL,
  status ENUM('DRAFT','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_venues_owner (owner_id),
  INDEX idx_venues_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fields
  ADD COLUMN IF NOT EXISTS venue_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS owner_id INT UNSIGNED NULL DEFAULT NULL AFTER venue_id,
  ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED NULL DEFAULT NULL AFTER owner_id,
  ADD COLUMN IF NOT EXISTS pitch_code VARCHAR(20) NULL DEFAULT NULL AFTER parent_id,
  ADD COLUMN IF NOT EXISTS pitch_type VARCHAR(10) AS (CONCAT('PITCH_', `type`)) VIRTUAL AFTER `type`,
  ADD INDEX IF NOT EXISTS idx_fields_venue (venue_id),
  ADD INDEX IF NOT EXISTS idx_fields_owner (owner_id),
  ADD INDEX IF NOT EXISTS idx_fields_parent (parent_id);

CREATE TABLE IF NOT EXISTS pricing_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_id INT UNSIGNED NOT NULL,
  day_type ENUM('WEEKDAY', 'WEEKEND', 'HOLIDAY') NOT NULL DEFAULT 'WEEKDAY',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  price_per_hour INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'Giờ tiêu chuẩn',
  INDEX idx_pr_field_day (field_id, day_type, start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS booking_source ENUM('ONLINE','WALK_IN') NOT NULL DEFAULT 'ONLINE' AFTER note,
  ADD COLUMN IF NOT EXISTS walkin_name VARCHAR(120) NULL DEFAULT NULL AFTER booking_source,
  ADD COLUMN IF NOT EXISTS walkin_phone VARCHAR(30) NULL DEFAULT NULL AFTER walkin_name,
  ADD COLUMN IF NOT EXISTS checked_in_at DATETIME NULL DEFAULT NULL AFTER walkin_phone,
  ADD COLUMN IF NOT EXISTS no_show TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_at;

CREATE OR REPLACE VIEW pitches AS
  SELECT f.id, f.venue_id, f.owner_id, f.parent_id AS parent_pitch_id, f.pitch_code, f.name,
         CONCAT('PITCH_', f.type) AS pitch_type, f.price_per_hour,
         UPPER(f.status) AS status, f.image, f.created_at
  FROM fields f;

CREATE OR REPLACE VIEW pitch_pricing AS
  SELECT pr.id, pr.field_id AS pitch_id, pr.day_type, pr.start_time, pr.end_time,
         pr.price_per_hour AS price, pr.label,
         CASE WHEN pr.start_time >= '17:00:00' AND pr.end_time <= '20:30:00' THEN 1 ELSE 0 END AS is_golden_hour
  FROM pricing_rules pr;

-- -----------------------------------------------------------------------------
-- 5. KHỞI TẠO 6 TÀI KHOẢN ĐỐI TÁC CHỦ SÂN (`PITCH_OWNER`) CHO CÁC SÂN HIỆN HỮU
--    (Mật khẩu đăng nhập mặc định cho cả 6 tài khoản: 123456)
-- -----------------------------------------------------------------------------
INSERT INTO users (
  username, password, full_name, email, phone,
  role, account_role, status, account_status, partner_status,
  wallet_balance, reputation_score, loyalty_points,
  preferred_position, skill_level, team_name, preferred_districts
) VALUES
  ('chusan_chaolua', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Trần Minh Quân (Chủ sân Chảo Lửa)', 'chaolua.partner@sanbong.vn', '0777700000', 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', 2850000.00, 100, 450, 'Tiền vệ', 'Bán chuyên', 'BQL Sân Banh Chảo Lửa', 'Tân Bình, Phú Nhuận'),
  ('chusan_k34',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lê Hoàng Nam (Chủ sân K34)',       'k34.partner@sanbong.vn',     '0908787798', 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', 1920000.00, 100, 320, 'Tiền vệ', 'Bán chuyên', 'BQL Cụm Sân K34 & Đạt Đức', 'Tân Bình, Gò Vấp'),
  ('chusan_giadinh', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nguyễn Quốc Tuấn (Chủ sân Gia Định)','giadinh.partner@sanbong.vn','0918412635', 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', 3400000.00, 100, 510, 'Tiền vệ', 'Bán chuyên', 'BQL Sân Vận Động Gia Định', 'Bình Thạnh, Gò Vấp'),
  ('chusan_phutho',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Phạm Gia Huy (Chủ sân Phú Thọ)',   'phutho.partner@sanbong.vn',  '0903865093', 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', 1650000.00, 100, 280, 'Tiền vệ', 'Bán chuyên', 'BQL Sân Bóng Nhà Tập Luyện Phú Thọ', 'Quận 11, Quận 10'),
  ('chusan_taodan',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Võ Thành Đạt (Chủ sân Tao Đàn)',   'taodan.partner@sanbong.vn',  '0908224158', 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', 4100000.00, 100, 620, 'Tiền vệ', 'Bán chuyên', 'BQL Sân Banh Tao Đàn Quận 1', 'Quận 1, Quận 3'),
  ('chusan_tdtu',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Đặng Đình Khôi (Chủ sân Tôn Đức Thắng & Thủ Đức)', 'tdtu.partner@sanbong.vn', '0903775503', 'pitch_owner', 'PITCH_OWNER', 'active', 'ACTIVE', 'APPROVED', 3750000.00, 100, 590, 'Tiền vệ', 'Bán chuyên', 'BQL Cụm Sân Nam Sài Gòn & Thủ Đức', 'Quận 7, TP. Thủ Đức')
ON DUPLICATE KEY UPDATE
  role = 'pitch_owner',
  account_role = 'PITCH_OWNER',
  status = 'active',
  account_status = 'ACTIVE',
  partner_status = 'APPROVED';

-- Gán quyền sở hữu `owner_id` cho các cụm sân hiện hữu
UPDATE fields SET owner_id = (SELECT id FROM users WHERE username = 'chusan_chaolua' LIMIT 1) WHERE id IN (1);
UPDATE fields SET owner_id = (SELECT id FROM users WHERE username = 'chusan_k34'     LIMIT 1) WHERE id IN (2, 10);
UPDATE fields SET owner_id = (SELECT id FROM users WHERE username = 'chusan_giadinh' LIMIT 1) WHERE id IN (3, 8);
UPDATE fields SET owner_id = (SELECT id FROM users WHERE username = 'chusan_phutho'  LIMIT 1) WHERE id IN (4);
UPDATE fields SET owner_id = (SELECT id FROM users WHERE username = 'chusan_taodan'  LIMIT 1) WHERE id IN (5);
UPDATE fields SET owner_id = (SELECT id FROM users WHERE username = 'chusan_tdtu'    LIMIT 1) WHERE id IN (6, 7, 9);

-- Đồng bộ `owner_id` xuống các sân con (Sân 5 / Sân 7 thành phần) theo sân Cha
UPDATE fields c
INNER JOIN fields p ON c.parent_id = p.id
SET c.owner_id = p.owner_id
WHERE p.owner_id IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 6. PHÂN QUYỀN MÃ GIẢM GIÁ (`vouchers`) CHO TỪNG TÀI KHOẢN ĐỐI TÁC CHỦ SÂN
--    Mỗi Chủ sân (`owner_id`) tự phát hành & quản lý Voucher cho Cụm sân (`venue_id`)
--    hoặc Sân con (`field_id`) thuộc quyền sở hữu của mình.
-- -----------------------------------------------------------------------------
ALTER TABLE vouchers
  ADD COLUMN IF NOT EXISTS owner_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Chủ sân phát hành (users.id)' AFTER id,
  ADD COLUMN IF NOT EXISTS venue_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Cụm sân áp dụng (venues.id)' AFTER owner_id,
  ADD COLUMN IF NOT EXISTS field_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID Sân cụ thể áp dụng (fields.id)' AFTER venue_id,
  ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL DEFAULT NULL COMMENT 'Mô tả chương trình ưu đãi của Chủ sân' AFTER code,
  ADD INDEX IF NOT EXISTS idx_vouchers_owner (owner_id),
  ADD INDEX IF NOT EXISTS idx_vouchers_venue (venue_id);

-- -----------------------------------------------------------------------------
-- 7. BẢNG TIN NHẮN TRỰC TIẾP GIỮA KHÁCH HÀNG & CHỦ SÂN (`owner_chat_messages`)
--    Hỗ trợ gửi tin nhắn văn bản & đính kèm Thẻ báo giá / Đặt sân nhanh (`pitch_card_json`)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS owner_chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL COMMENT 'ID khách đặt sân - liên kết users.id',
  owner_id INT UNSIGNED NOT NULL COMMENT 'ID chủ sân sở hữu sân đó - liên kết users.id',
  field_id INT UNSIGNED NOT NULL COMMENT 'ID cụm sân/sân bóng khách chọn liên hệ - liên kết fields.id',
  sender_id INT UNSIGNED NOT NULL COMMENT 'ID người gửi tin nhắn',
  sender_role ENUM('CUSTOMER', 'OWNER') NOT NULL DEFAULT 'CUSTOMER',
  message TEXT NOT NULL COMMENT 'Nội dung tin nhắn',
  pitch_card_json TEXT NULL COMMENT 'JSON chứa thông tin thẻ đặt sân nhanh: field_id, name, pitch_code, time_slot, price, image, book_url',
  is_read TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0: Chưa xem, 1: Đã xem',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ocm_cust_field (customer_id, field_id, created_at),
  INDEX idx_ocm_owner_unread (owner_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. BẢNG BỘ NHỚ ĐỆM ĐÁNH GIÁ SÂN BÓNG TỪ GOOGLE MAPS (`google_maps_reviews_cache`)
--    Lưu điểm sao Google Maps, tổng số lượt đánh giá và danh sách bài nhận xét
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS google_maps_reviews_cache (
  field_id INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'ID sân bóng - liên kết fields.id',
  place_id VARCHAR(120) DEFAULT NULL COMMENT 'Google Maps Place ID chuẩn của sân bóng',
  google_rating DECIMAL(3,1) NOT NULL DEFAULT 4.8 COMMENT 'Điểm trung bình trên Google Maps (thang 5 sao)',
  google_total_reviews INT UNSIGNED NOT NULL DEFAULT 120 COMMENT 'Tổng số lượt đánh giá trên Google Maps',
  google_maps_place_url VARCHAR(500) DEFAULT NULL COMMENT 'Đường dẫn mở trực tiếp địa điểm sân trên Google Maps',
  source_mode VARCHAR(30) NOT NULL DEFAULT 'google_maps_sync',
  reviews_json MEDIUMTEXT NOT NULL COMMENT 'Chuỗi JSON lưu danh sách các bài đánh giá từ Google Maps',
  synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

