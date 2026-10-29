-- =============================================================================
-- FILE: database.sql
-- MÔ TẢ: Kịch bản khởi tạo Cơ sở dữ liệu MySQL cho Hệ thống Quản lý Sân Bóng
-- TÍNH NĂNG: Quản lý người dùng, sân bóng, đặt sân, dịch vụ đi kèm, mã giảm giá,
--            thanh toán Demo (VietQR/MoMo/VNPay), đánh giá, thông báo và AI Chat.
-- Tài khoản mẫu (Mật khẩu dùng chung: password):
--   + Quản trị viên: admin / password
--   + Khách hàng 1:  nguyenvana / password
--   + Khách hàng 2:  tranvanb / password
-- =============================================================================

-- 1. KHỞI TẠO DATABASE & XÓA BẢNG CŨ (NẾU CÓ) ĐỂ LÀM MỚI DỮ LIỆU
CREATE DATABASE IF NOT EXISTS quan_ly_san_bong CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quan_ly_san_bong;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS ai_chat_logs;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS booking_services;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS vouchers;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS fields;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

-- 2. BẢNG USERS: Lưu thông tin tài khoản người dùng (Khách hàng & Quản trị viên)
CREATE TABLE users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(60) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 full_name VARCHAR(120) NOT NULL,
 phone VARCHAR(30) NOT NULL DEFAULT '',
 email VARCHAR(160) NOT NULL UNIQUE,
 role ENUM('user','admin') NOT NULL DEFAULT 'user',
 status ENUM('active','locked') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. BẢNG FIELDS: Lưu thông tin danh sách sân bóng (Sân 5, 7, 11 người)
CREATE TABLE fields (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL,
 type ENUM('5','7','11') NOT NULL DEFAULT '5',
 address VARCHAR(255) NOT NULL,
 district VARCHAR(100) NOT NULL DEFAULT '',
 price_per_hour INT UNSIGNED NOT NULL,
 description TEXT NULL,
 status ENUM('active','maintenance') NOT NULL DEFAULT 'active',
 image VARCHAR(500) NOT NULL DEFAULT '',
 rating DECIMAL(2,1) NOT NULL DEFAULT 4.8,
 review_count INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_fields_status_type(status,type),
 INDEX idx_fields_district(district)
) ENGINE=InnoDB;

-- 4. BẢNG VOUCHERS: Lưu danh sách mã giảm giá khuyến mãi (theo % hoặc tiền cố định)
CREATE TABLE vouchers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(50) NOT NULL UNIQUE,
 discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
 discount_value INT UNSIGNED NOT NULL,
 min_order INT UNSIGNED NOT NULL DEFAULT 0,
 max_discount INT UNSIGNED NOT NULL DEFAULT 0,
 usage_limit INT UNSIGNED NOT NULL DEFAULT 100,
 used_count INT UNSIGNED NOT NULL DEFAULT 0,
 expired_at DATE NOT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 5. BẢNG SERVICES: Lưu danh sách dịch vụ tiện ích đi kèm (thuê bóng, áo bib, nước, trọng tài...)
CREATE TABLE services (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 description VARCHAR(255) NOT NULL DEFAULT '',
 price INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6. BẢNG BOOKINGS: Lưu thông tin đơn đặt sân của khách hàng
CREATE TABLE bookings (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 field_id INT UNSIGNED NOT NULL,
 booking_date DATE NOT NULL,
 start_time TIME NOT NULL,
 end_time TIME NOT NULL,
 total_price INT UNSIGNED NOT NULL DEFAULT 0,
 voucher_id INT UNSIGNED NULL,
 note VARCHAR(255) NOT NULL DEFAULT '',
 status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_booking_date_status(booking_date,status),
 INDEX idx_booking_field_date(field_id,booking_date),
 INDEX idx_booking_user(user_id),
 CONSTRAINT fk_bookings_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
 CONSTRAINT fk_bookings_field FOREIGN KEY(field_id) REFERENCES fields(id) ON UPDATE CASCADE ON DELETE RESTRICT,
 CONSTRAINT fk_bookings_voucher FOREIGN KEY(voucher_id) REFERENCES vouchers(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 7. BẢNG BOOKING_SERVICES: Bảng trung gian lưu chi tiết các dịch vụ đi kèm theo từng đơn đặt sân
CREATE TABLE booking_services (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id INT UNSIGNED NOT NULL,
 service_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 price INT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT fk_bs_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT fk_bs_service FOREIGN KEY(service_id) REFERENCES services(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8. BẢNG PAYMENTS: Lưu thông tin giao dịch thanh toán của từng đơn đặt sân
CREATE TABLE payments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id INT UNSIGNED NOT NULL,
 method ENUM('cash','bank','transfer','qr','momo','vnpay') NOT NULL DEFAULT 'cash',
 amount INT UNSIGNED NOT NULL DEFAULT 0,
 transaction_code VARCHAR(80) NULL,
 status ENUM('pending','paid','refunded','failed') NOT NULL DEFAULT 'pending',
 paid_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_payments_booking(booking_id),
 CONSTRAINT fk_payments_booking FOREIGN KEY(booking_id) REFERENCES bookings(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. BẢNG REVIEWS: Lưu đánh giá số sao và nhận xét của khách hàng cho từng sân bóng
CREATE TABLE reviews (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id INT UNSIGNED NULL,
 user_id INT UNSIGNED NOT NULL,
 field_id INT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
 comment TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_reviews_field(field_id),
 CONSTRAINT fk_reviews_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
 CONSTRAINT fk_reviews_field FOREIGN KEY(field_id) REFERENCES fields(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. BẢNG NOTIFICATIONS: Lưu thông báo hệ thống gửi tới người dùng
CREATE TABLE notifications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 message TEXT NOT NULL,
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notifications_user_read(user_id,is_read),
 CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. BẢNG AI_CHAT_LOGS: Lưu lịch sử hội thoại giữa người dùng và Trợ lý AI
CREATE TABLE ai_chat_logs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 session_id VARCHAR(100) NOT NULL DEFAULT '',
 role ENUM('user','assistant') NOT NULL DEFAULT 'user',
 message TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ai_session(session_id)
) ENGINE=InnoDB;

-- 12. BẢNG SYSTEM_SETTINGS: Lưu cấu hình hệ thống (Gemini API Key, tên AI, thông tin ngân hàng VietQR)
CREATE TABLE system_settings (
 setting_key VARCHAR(80) PRIMARY KEY,
 setting_value TEXT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================================================
-- DỮ LIỆU MẪU (SEED DATA)
-- =============================================================================

-- Thêm tài khoản mẫu (Mật khẩu hash chuẩn của 'password' là $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi)
INSERT INTO users(username,password,full_name,phone,email,role,status) VALUES
('admin','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Quản trị viên','0901234567','admin@sanbong.vn','admin','active'),
('nguyenvana','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Nguyễn Văn A','0912345678','nguyenvana@gmail.com','user','active'),
('tranvanb','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Trần Văn B','0987654321','tranvanb@gmail.com','user','active');

-- Thêm 6 sân bóng mẫu thuộc các quận tại TP.HCM
INSERT INTO fields(name,type,address,district,price_per_hour,description,status,image,rating,review_count) VALUES
('Sân Minh Phát','5','125 Nguyễn Văn Cừ, Quận 1, TP. HCM','Quận 1',120000,'Sân cỏ nhân tạo tiêu chuẩn FIFA, hệ thống đèn LED chống chói hiện đại, có căng-tin và bãi giữ xe miễn phí.','active','https://images.unsplash.com/photo-1459865264687-595d652de67e?w=900',4.8,120),
('Sân Phú Lợi','7','88 Phạm Văn Đồng, TP. Thủ Đức, TP. HCM','Thủ Đức',150000,'Sân 7 người rộng rãi, mặt cỏ mới thay êm ái, thoát nước cực nhanh khi trời mưa, phù hợp đá giao hữu và giải phong trào.','active','https://images.unsplash.com/photo-1522778119026-d647f0596c20?w=900',4.7,98),
('Sân Galaxy Arena','5','42 Đường D2, Quận Bình Thạnh, TP. HCM','Bình Thạnh',130000,'Mặt sân đẹp, lưới chắn mới, có khu vực khán đài có mái che và phòng thay đồ sạch sẽ cho các đội bóng.','active','https://images.unsplash.com/photo-1518604666860-9ed391f76460?w=900',4.9,76),
('Sân Thiên Phúc Stadium','11','210 Tô Ký, Quận 12, TP. HCM','Quận 12',220000,'Sân kích thước lớn 11 người tiêu chuẩn thi đấu, có khán đài, phòng y tế và hỗ trợ trọng tài chuyên nghiệp.','active','https://images.unsplash.com/photo-1508098682722-e99c43a406b2?w=900',4.6,64),
('Sân K34 Sport','7','15 Cộng Hòa, Quận Tân Bình, TP. HCM','Tân Bình',160000,'Cụm sân trung tâm Tân Bình, giao thông thuận tiện, có dịch vụ quay livestream trận đấu và cho thuê giày đá bóng.','active','https://images.unsplash.com/photo-1575361204480-aadea25e6e68?w=900',4.8,85),
('Sân Nam Sài Gòn','5','60 Nguyễn Thị Thập, Quận 7, TP. HCM','Quận 7',140000,'Không gian thoáng mát ven sông, cỏ nhân tạo cao cấp nhập khẩu, có khu vực BBQ và nước giải khát sau trận.','active','https://images.unsplash.com/photo-1551958219-acbc608c6377?w=900',4.7,52);

-- Thêm các dịch vụ tiện ích đi kèm mẫu
INSERT INTO services(name,description,price,status) VALUES
('Thuê áo Bib phân đội','Bộ 7-10 áo lưới phân biệt đội thi đấu giặt sạch thơm',20000,'active'),
('Thuê bóng thi đấu','Bóng Động Lực / Molten tiêu chuẩn thi đấu',30000,'active'),
('Thùng trà đá / Nước suối','Nước uống giải khát ướp lạnh cho cả đội (12 chai)',50000,'active'),
('Thuê trọng tài bắt trận','Trọng tài phong trào nhiều kinh nghiệm điều khiển trận đấu (1 giờ)',100000,'active'),
('Quay video Highlight trận đấu','Camera cố định góc rộng lưu lại bàn thắng đẹp mắt',80000,'active');

-- Thêm các mã giảm giá (Voucher) mẫu
INSERT INTO vouchers(code,discount_type,discount_value,min_order,max_discount,usage_limit,used_count,expired_at,status) VALUES
('SANBONG10','percent',10,100000,50000,100,2,'2030-12-31','active'),
('GIAM30K','fixed',30000,150000,30000,50,1,'2030-12-31','active'),
('TANTHU20','percent',20,120000,60000,200,0,'2030-12-31','active');

-- Thêm cấu hình mặc định cho Trợ lý AI và Ngân hàng VietQR
INSERT INTO system_settings(setting_key, setting_value) VALUES
('gemini_api_key', ''),
('ai_bot_name', 'Trợ lý AI Sân Bóng'),
('bank_id', 'MB'),
('bank_account_no', '0901234567'),
('bank_account_name', 'HE THONG SAN BONG PRO'),
('auto_confirm_paid', '1');

-- Thêm đánh giá mẫu cho các sân bóng
INSERT INTO reviews(user_id,field_id,rating,comment) VALUES
(2,1,5,'Mặt cỏ rất êm, đèn sáng rõ, chủ sân nhiệt tình hỗ trợ anh em!'),
(3,2,5,'Sân 7 rộng thoáng, đá buổi tối rất mát. Đặt qua web thanh toán QR cái được xác nhận liền.'),
(2,3,5,'Khu vực Bình Thạnh thì sân Galaxy là đỉnh nhất tầm giá, phòng thay đồ sạch sẽ.');
