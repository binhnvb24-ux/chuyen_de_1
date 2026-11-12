-- ============================================================================
-- FILE: database_real_pitches.sql
-- MÔ TẢ: Nâng cấp bảng `fields` (bổ sung tọa độ GPS, cơ cấu sân con, khung giá,
--        số điện thoại, Google Maps URL) và nạp dữ liệu 6 ĐIỂM DỪNG SÂN BÓNG
--        NỔI TIẾNG NHẤT TP.HCM + 4 CỤM SÂN VỆ TINH.
-- ============================================================================

USE quan_ly_san_bong;

-- 1. CÂU LỆNH ALTER TABLE (Thêm các cột phục vụ Bản đồ & Chi tiết cụm sân thực tế)
ALTER TABLE fields
  ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) DEFAULT 10.7768890,
  ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) DEFAULT 106.7008060,
  ADD COLUMN IF NOT EXISTS sub_pitches VARCHAR(255) DEFAULT '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
  ADD COLUMN IF NOT EXISTS normal_price INT DEFAULT 250000,
  ADD COLUMN IF NOT EXISTS peak_price INT DEFAULT 420000,
  ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(30) DEFAULT '0901.234.567',
  ADD COLUMN IF NOT EXISTS google_maps_url VARCHAR(255) DEFAULT '';

-- 2. CÂU LỆNH INSERT / UPDATE 6 ĐIỂM DỪNG SÂN BÓNG NỔI TIẾNG TP.HCM + 4 CỤM SÂN VỆ TINH
INSERT INTO fields (
  id, name, type, address, district, price_per_hour, normal_price, peak_price,
  latitude, longitude, sub_pitches, contact_phone, google_maps_url,
  description, status, image, rating, review_count
) VALUES
-- ĐIỂM DỪNG 1: Sân Banh Chảo Lửa
(
  1,
  'Sân Banh Chảo Lửa',
  '7',
  '38 Đ. Phan Thúc Duyện, Tân Sơn Nhất, Quận Tân Bình, TP.HCM',
  'Tân Bình',
  300000, 300000, 520000,
  10.8016800, 106.6626100,
  '8 sân 5 người, 4 sân 7 người liên hoàn (Ghép thành sân 11 người tiêu chuẩn)',
  '0777 700 000',
  'https://www.google.com/maps/dir/?api=1&destination=10.8016800,106.6626100',
  'Điểm dừng 1: Một trong những "thánh địa" bóng đá phong trào lớn và sôi động nhất thành phố (4.3/5 - 3.600+ đánh giá). Cụm sân có quy mô lớn gồm nhiều sân 5 người và 7 người liên hoàn, có thể ghép thành sân 11 người tiêu chuẩn.',
  'active',
  'assets/images/field-1.jpg',
  4.8, 3600
),
-- ĐIỂM DỪNG 2: Sân Bóng Đá K34
(
  2,
  'Sân Bóng Đá K34',
  '5',
  'Bạch Đằng, Tân Sơn Hòa, Quận Tân Bình, TP.HCM',
  'Tân Bình',
  260000, 260000, 440000,
  10.8132500, 106.6694200,
  '6 sân 5 người & 2 sân 7 người có mái che thoáng mát',
  '0908 787 798',
  'https://www.google.com/maps/dir/?api=1&destination=10.8132500,106.6694200',
  'Điểm dừng 2: Nổi tiếng với hệ thống sân cỏ nhân tạo có mái che cực kỳ thoáng mát, giúp các đội bóng thi đấu bất chấp thời tiết nắng mưa.',
  'active',
  'assets/images/field-2.jpg',
  4.7, 420
),
-- ĐIỂM DỪNG 3: Sân vận động Gia Định
(
  3,
  'Sân vận động Gia Định',
  '11',
  '2A Phan Chu Trinh, Phường 12, Quận Bình Thạnh, TP.HCM',
  'Bình Thạnh',
  350000, 350000, 600000,
  10.8087500, 106.6961500,
  '1 sân 11 người chuẩn thi đấu + 6 sân 5 - 7 người vệ tinh xung quanh',
  '028 3841 2635',
  'https://www.google.com/maps/dir/?api=1&destination=10.8087500,106.6961500',
  'Điểm dừng 3: Tổ hợp sân vận động lớn (Giờ mở cửa: 05:30 – 22:00) có cả mặt sân 11 người chuẩn thi đấu và cụm các sân 5 - 7 người vệ tinh xung quanh, khuôn viên rộng rãi.',
  'active',
  'assets/images/field-3.jpg',
  4.8, 580
),
-- ĐIỂM DỪNG 4: Sân bóng đá mini Nhà Tập Luyện Phú Thọ
(
  4,
  'Sân bóng đá mini Nhà Tập Luyện Phú Thọ',
  '5',
  '219 Đ. Lý Thường Kiệt, Phường 15, Quận 11, TP.HCM',
  'Quận 11',
  250000, 250000, 420000,
  10.7694500, 106.6579200,
  '6 sân 5 người, 2 sân 7 người (Khu liên hợp thể thao Phú Thọ)',
  '028 3865 0930',
  'https://www.google.com/maps/dir/?api=1&destination=10.7694500,106.6579200',
  'Điểm dừng 4: Nằm trong khu liên hợp thể thao Sân vận động Phú Thọ (Giờ mở cửa: 05:00 – 23:00), mặt cỏ đạt chuẩn, dễ dàng kết nối giao thông từ các khu vực trung tâm.',
  'active',
  'assets/images/field-1.jpg',
  4.9, 640
),
-- ĐIỂM DỪNG 5: Sân banh Tao Đàn
(
  5,
  'Sân banh Tao Đàn',
  '7',
  '1 Huyền Trân Công Chúa, Phường Bến Thành, Quận 1, TP.HCM',
  'Quận 1',
  320000, 320000, 550000,
  10.7751200, 106.6928500,
  '1 sân 11 người chia tách linh hoạt thành 3 sân 7 người & 6 sân 5 người',
  '028 3822 4158',
  'https://www.google.com/maps/dir/?api=1&destination=10.7751200,106.6928500',
  'Điểm dừng 5: Nằm ngay "lá phổi xanh" trung tâm thành phố, bao quanh bởi cây cổ thụ mát mẻ, có mặt sân 11 người chia tách linh hoạt thành các sân 7 người và 5 người.',
  'active',
  'assets/images/field-2.jpg',
  4.8, 720
),
-- ĐIỂM DỪNG 6: Sân vận động Trường Đại học Tôn Đức Thắng
(
  6,
  'Sân vận động Trường Đại học Tôn Đức Thắng',
  '11',
  '19 Nguyễn Hữu Thọ (Đường D6), Phường Tân Hưng, Quận 7, TP.HCM',
  'Quận 7',
  400000, 400000, 700000,
  10.7326500, 106.6997800,
  'Sân vận động 11 người chuẩn FIFA 2 sao + Cụm sân 5 & 7 người sinh viên',
  '028 3775 5035',
  'https://www.google.com/maps/dir/?api=1&destination=10.7326500,106.6997800',
  'Điểm dừng 6: Sân vận động đạt chuẩn FIFA 2 sao (Đánh giá 4.7/5) với mặt cỏ cực đẹp, khán đài lớn và đường chạy điền kinh bao quanh, thường xuyên tổ chức các giải sinh viên và phong trào lớn.',
  'active',
  'assets/images/field-3.jpg',
  5.0, 950
),
-- CỤM SÂN 7: Sân bóng Phú Lợi - Phạm Văn Đồng
(
  7,
  'Sân bóng Phú Lợi - Phạm Văn Đồng',
  '5',
  '420 Phạm Văn Đồng, Phường Hiệp Bình Chánh, TP. Thủ Đức, TP.HCM',
  'Thủ Đức',
  220000, 220000, 360000,
  10.8286400, 106.7219500,
  '5 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
  '0902 456 789',
  'https://www.google.com/maps/dir/?api=1&destination=10.8286400,106.7219500',
  'Nằm ngay đại lộ Phạm Văn Đồng giao thông thuận tiện từ Bình Thạnh, Gò Vấp và Thủ Đức. Bãi giữ xe ô tô miễn phí.',
  'active',
  'assets/images/field-1.jpg',
  4.8, 134
),
-- CỤM SÂN 8: Sân bóng Bình Quới - Thanh Đa
(
  8,
  'Sân bóng Bình Quới - Thanh Đa',
  '5',
  '412 Bình Quới, Phường 28, Quận Bình Thạnh, TP.HCM',
  'Bình Thạnh',
  200000, 200000, 330000,
  10.8189200, 106.7314500,
  '4 sân 5 người, 1 sân 7 người (Không gian ven sông thoáng mát)',
  '0918 223 344',
  'https://www.google.com/maps/dir/?api=1&destination=10.8189200,106.7314500',
  'Không gian ven sông Sài Gòn mát mẻ quanh năm tại bán đảo Thanh Đa, thích hợp đá giao hữu cuối tuần.',
  'active',
  'assets/images/field-2.jpg',
  4.7, 92
),
-- CỤM SÂN 9: Sân bóng Vạn Phúc City
(
  9,
  'Sân bóng Vạn Phúc City',
  '7',
  'Khu đô thị Vạn Phúc, Quốc lộ 13, Phường Hiệp Bình Phước, TP. Thủ Đức, TP.HCM',
  'Thủ Đức',
  250000, 250000, 420000,
  10.8401500, 106.7108200,
  '6 sân 5 người, 3 sân 7 người (Ghép được 1 sân 11 người)',
  '0989 112 233',
  'https://www.google.com/maps/dir/?api=1&destination=10.8401500,106.7108200',
  'Tổ hợp sân bóng cao cấp nằm trong Khu đô thị Vạn Phúc City, mặt cỏ mới 100%, phòng thay đồ có máy nước nóng.',
  'active',
  'assets/images/field-3.jpg',
  4.9, 178
),
-- CỤM SÂN 10: Sân bóng Đạt Đức - Gò Vấp
(
  10,
  'Sân bóng Đạt Đức - Gò Vấp',
  '7',
  '5A Nguyễn Văn Lượng, Phường 16, Quận Gò Vấp, TP.HCM',
  'Gò Vấp',
  230000, 230000, 380000,
  10.8384200, 106.6689100,
  '6 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
  '0906 554 433',
  'https://www.google.com/maps/dir/?api=1&destination=10.8384200,106.6689100',
  'Cụm sân truyền thống của giới phủi Gò Vấp, mặt sân rộng, đường biên an toàn 3m, đèn chiếu sáng ban đêm cực nét.',
  'active',
  'assets/images/field-1.jpg',
  4.8, 129
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type),
  address = VALUES(address),
  district = VALUES(district),
  price_per_hour = VALUES(price_per_hour),
  normal_price = VALUES(normal_price),
  peak_price = VALUES(peak_price),
  latitude = VALUES(latitude),
  longitude = VALUES(longitude),
  sub_pitches = VALUES(sub_pitches),
  contact_phone = VALUES(contact_phone),
  google_maps_url = VALUES(google_maps_url),
  description = VALUES(description),
  image = VALUES(image),
  rating = VALUES(rating),
  review_count = VALUES(review_count);
