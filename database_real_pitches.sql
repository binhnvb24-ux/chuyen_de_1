-- ============================================================================
-- FILE: database_real_pitches.sql
-- MÔ TẢ: Nâng cấp bảng `fields` (bổ sung tọa độ GPS chuẩn xác 7 chữ số thập phân,
--        cơ cấu sân con, khung giá, số điện thoại, Google Maps URL kèm Place ID)
--        và nạp dữ liệu 10 CỤM SÂN BÓNG THỰC TẾ TẠI TP.HCM.
-- ============================================================================

USE quan_ly_san_bong;

-- 1. CÂU LỆNH ALTER TABLE (Thêm các cột phục vụ Bản đồ & Chi tiết cụm sân thực tế)
ALTER TABLE fields
  ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) DEFAULT 10.7744400,
  ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) DEFAULT 106.6925000,
  ADD COLUMN IF NOT EXISTS sub_pitches VARCHAR(255) DEFAULT '4 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
  ADD COLUMN IF NOT EXISTS normal_price INT DEFAULT 180000,
  ADD COLUMN IF NOT EXISTS peak_price INT DEFAULT 320000,
  ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(30) DEFAULT '0901.234.567',
  ADD COLUMN IF NOT EXISTS google_maps_url VARCHAR(255) DEFAULT '';

-- 2. CÂU LỆNH INSERT / UPDATE 10 CỤM SÂN BÓNG CHUẨN VỊ TRÍ THỰC TẾ TP.HCM
INSERT INTO fields (
  id, name, type, address, district, price_per_hour, normal_price, peak_price,
  latitude, longitude, sub_pitches, contact_phone, google_maps_url,
  description, status, image, rating, review_count
) VALUES
-- ĐIỂM DỪNG 1: Sân Banh Chảo Lửa (Chuẩn tọa độ 30-38 Phan Thúc Duyện, P.4, Tân Bình)
(
  1,
  'Sân Banh Chảo Lửa',
  '5',
  '30 - 38 Đ. Phan Thúc Duyện, Phường 4 (Tân Sơn Nhất), Quận Tân Bình, TP.HCM',
  'Tân Bình',
  180000, 180000, 320000,
  10.8058333, 106.6583333,
  '8 sân 5 người, 4 sân 7 người liên hoàn (Ghép thành sân 11 người tiêu chuẩn)',
  '0777 700 000',
  'https://www.google.com/maps/dir/?api=1&destination=10.8058333,106.6583333&destination_place_id=ChIJB5tp0zspdTERarJg-FmXD5Q',
  'Điểm dừng 1: Một trong những "thánh địa" bóng đá phong trào lớn và sôi động nhất thành phố ngay sát Công viên Hoàng Văn Thụ & Sân bay Tân Sơn Nhất (4.3/5 - 3.600+ đánh giá). Cụm sân có quy mô lớn gồm nhiều sân 5 và 7 người liên hoàn, ghép thành sân 11 người tiêu chuẩn.',
  'active',
  'assets/images/field-1.jpg',
  4.3, 3601
),
-- ĐIỂM DỪNG 2: Sân Bóng Đá K34 (Chuẩn tọa độ A75/6E/65 Bạch Đằng, P.2, Tân Bình)
(
  2,
  'Sân Bóng Đá K34',
  '7',
  'A75/6E/65 Đ. Bạch Đằng, Phường 2 (Tân Sơn Hòa), Quận Tân Bình, TP.HCM',
  'Tân Bình',
  160000, 160000, 300000,
  10.8141200, 106.6698500,
  'Hệ thống 4 sân 5 người & 2 sân 7 người cỏ nhân tạo có mái che thoáng mát',
  '0908 787 798',
  'https://www.google.com/maps/dir/?api=1&destination=10.8141200,106.6698500&destination_place_id=ChIJNc6f9x4pdTERXLf34VRBOdw',
  'Điểm dừng 2: Nổi tiếng với hệ thống sân cỏ nhân tạo có mái che cực kỳ thoáng mát tại khu Bạch Đằng Tân Sơn Nhất, giúp các đội bóng thi đấu bất chấp thời tiết nắng mưa.',
  'active',
  'assets/images/field-2.jpg',
  4.2, 850
),
-- ĐIỂM DỪNG 3: Sân vận động Gia Định (Chuẩn tọa độ 2A Phan Chu Trinh, P.12, Bình Thạnh)
(
  3,
  'Sân vận động Gia Định',
  '11',
  '2A Phan Chu Trinh, Phường 12, Quận Bình Thạnh, TP.HCM',
  'Bình Thạnh',
  170000, 170000, 310000,
  10.8145300, 106.7023700,
  'Sân 11 người chuẩn thi đấu + Cụm 6 sân 5 - 7 người vệ tinh xung quanh (Mở cửa: 05:30 – 22:00)',
  '028 3841 2635',
  'https://www.google.com/maps/dir/?api=1&destination=10.8145300,106.7023700&destination_place_id=ChIJx6YjBscpdTERlrJ806lpemA',
  'Điểm dừng 3: Tổ hợp sân vận động lớn của Trung tâm TDTT Quận Bình Thạnh (2A Phan Chu Trinh), có mặt sân 11 người chuẩn thi đấu quốc gia, khán đài mái che hơn 1.000 chỗ ngồi và cụm sân 5 - 7 người vệ tinh.',
  'active',
  'assets/images/field-3.jpg',
  4.3, 1240
),
-- ĐIỂM DỪNG 4: Sân bóng đá mini Nhà Tập Luyện Phú Thọ (Chuẩn tọa độ 219 Lý Thường Kiệt / Số 1 Lữ Gia, P.15, Q.11)
(
  4,
  'Sân bóng đá mini Nhà Tập Luyện Phú Thọ',
  '5',
  '219 Đ. Lý Thường Kiệt (Cổng số 2 Lữ Gia), Phường 15, Quận 11, TP.HCM',
  'Quận 11',
  150000, 150000, 280000,
  10.7686111, 106.6575000,
  'Khu liên hợp thể thao 6 sân 5 người, 2 sân 7 người đạt chuẩn (Mở cửa: 05:00 – 23:00)',
  '028 3865 0930',
  'https://www.google.com/maps/dir/?api=1&destination=10.7686111,106.6575000&destination_place_id=ChIJ1_6sKeoudTERto25faHCNlg',
  'Điểm dừng 4: Nằm trong khu liên hợp thể thao Nhà Tập Luyện & Nhà Thi Đấu Phú Thọ (giáp hai mặt đường Lý Thường Kiệt và Lữ Gia), mặt cỏ đạt chuẩn, dễ dàng kết nối giao thông từ Quận 10, Quận 11, Tân Bình.',
  'active',
  'assets/images/field-1.jpg',
  4.4, 960
),
-- ĐIỂM DỪNG 5: Sân banh Tao Đàn (Chuẩn tọa độ 01 Huyền Trân Công Chúa / 55C Nguyễn Thị Minh Khai, Bến Thành, Q.1)
(
  5,
  'Sân banh Tao Đàn',
  '7',
  '01 Huyền Trân Công Chúa (55C Nguyễn Thị Minh Khai), Phường Bến Thành, Quận 1, TP.HCM',
  'Quận 1',
  200000, 200000, 360000,
  10.7744400, 106.6925000,
  'Mặt sân 11 người chia tách linh hoạt thành 3 sân 7 người và 6 sân 5 người',
  '028 3822 4158',
  'https://www.google.com/maps/dir/?api=1&destination=10.7744400,106.6925000&destination_place_id=ChIJm5DtejkvdTERIsG8l9bQ6ic',
  'Điểm dừng 5: Nằm ngay "lá phổi xanh" Công viên Văn hóa Tao Đàn trung tâm Quận 1 (phía sau Dinh Độc Lập), bao quanh bởi cây cổ thụ mát mẻ, có mặt sân 11 người chia tách linh hoạt thành các sân 7 người và 5 người.',
  'active',
  'assets/images/field-2.jpg',
  4.2, 1450
),
-- ĐIỂM DỪNG 6: Sân vận động Trường Đại học Tôn Đức Thắng (Chuẩn tọa độ 19 Nguyễn Hữu Thọ / Cổng 7 Đường D6, Tân Phong, Q.7)
(
  6,
  'Sân vận động Trường Đại học Tôn Đức Thắng',
  '11',
  '19 Nguyễn Hữu Thọ (Cổng 7 Đường D6), Phường Tân Phong, Quận 7, TP.HCM',
  'Quận 7',
  190000, 190000, 350000,
  10.7326800, 106.6997500,
  'Sân vận động chuẩn FIFA 2 sao (Sân 11 người + Cụm sân 5 - 7 người & Khán đài lớn)',
  '028 3775 5035',
  'https://www.google.com/maps/dir/?api=1&destination=10.7326800,106.6997500&destination_place_id=ChIJz0OOpr0vdTERrYFEd4OJMaM',
  'Điểm dừng 6: Sân vận động đạt chuẩn FIFA 2 sao nằm trong khuôn viên chính Đại học Tôn Đức Thắng (19 Nguyễn Hữu Thọ - Đường D6, Quận 7) với mặt cỏ cực đẹp, khán đài lớn và đường chạy điền kinh bao quanh.',
  'active',
  'assets/images/field-3.jpg',
  4.7, 2150
),
-- CỤM SÂN 7: Sân bóng Phú Lợi - Phạm Văn Đồng (Hiệp Bình Chánh, TP. Thủ Đức)
(
  7,
  'Sân bóng Phú Lợi - Phạm Văn Đồng',
  '7',
  '420 Phạm Văn Đồng (579 Kha Vạn Cân, KP8), Phường Hiệp Bình Chánh, TP. Thủ Đức, TP.HCM',
  'TP. Thủ Đức',
  150000, 150000, 270000,
  10.8286400, 106.7219500,
  '6 sân 5 người, 2 sân 7 người (Ghép được 1 sân 11 người)',
  '0933 088 088',
  'https://www.google.com/maps/dir/?api=1&destination=10.8286400,106.7219500&destination_place_id=ChIJfyYy40gndTERwGnfVlIS3ak',
  'Cụm sân lớn trục đại lộ Phạm Văn Đồng – Hiệp Bình Chánh (TP. Thủ Đức), mặt cỏ mới thay êm ái, thoát nước nhanh, bãi đỗ ô tô & xe máy rộng rãi.',
  'active',
  'assets/images/field-1.jpg',
  4.6, 420
),
-- CỤM SÂN 8: Sân bóng Bình Quới - Thanh Đa (426 Bình Quới, P.28, Bình Thạnh)
(
  8,
  'Sân bóng Bình Quới - Thanh Đa',
  '5',
  '426 Bình Quới, Cư xá Thanh Đa, Phường 28, Quận Bình Thạnh, TP.HCM',
  'Bình Thạnh',
  130000, 130000, 240000,
  10.8189200, 106.7314500,
  '6 sân 5 người, 3 sân 7 người (Ghép được 1 sân 11 người ven sông)',
  '0903 006 630',
  'https://www.google.com/maps/dir/?api=1&destination=10.8189200,106.7314500&destination_place_id=ChIJzUwJAi4mdTERzfFbs4Gu9f4',
  'Cụm sân ven sông mát mẻ tại bán đảo Bình Quới – Thanh Đa (Quận Bình Thạnh), không gian thoáng đãng, giá thuê cực kỳ hợp lý cho sinh viên và đội bóng văn phòng.',
  'active',
  'assets/images/field-2.jpg',
  4.5, 310
),
-- CỤM SÂN 9: Sân bóng Vạn Phúc City (Nguyễn Thị Nhung, KĐT Vạn Phúc, Hiệp Bình Phước, TP. Thủ Đức)
(
  9,
  'Sân bóng Vạn Phúc City (TP. Thủ Đức)',
  '7',
  'Đường Nguyễn Thị Nhung, Khu đô thị Vạn Phúc, Quốc lộ 13, Phường Hiệp Bình Phước, TP. Thủ Đức, TP.HCM',
  'TP. Thủ Đức',
  160000, 160000, 290000,
  10.8408500, 106.7114200,
  '6 sân 5 người, 3 sân 7 người, 1 sân 11 người ven sông Sài Gòn',
  '0979 120 174',
  'https://www.google.com/maps/dir/?api=1&destination=10.8408500,106.7114200&destination_place_id=ChIJzVASg28odTERxeoRPd6pbBQ',
  'Tổ hợp sân bóng hiện đại bậc nhất TP. Thủ Đức nằm trên trục Nguyễn Thị Nhung trong KĐT Vạn Phúc City, hệ thống đèn LED chống lóa và phòng tắm nước nóng.',
  'active',
  'assets/images/field-3.jpg',
  4.6, 520
),
-- CỤM SÂN 10: Sân bóng Đạt Đức (5A Nguyễn Văn Lượng, P.16, Gò Vấp)
(
  10,
  'Sân bóng Đạt Đức (Thống Nhất - Gò Vấp)',
  '5',
  '5A Đ. Nguyễn Văn Lượng, Phường 16, Quận Gò Vấp, TP.HCM',
  'Gò Vấp',
  140000, 140000, 250000,
  10.8393600, 106.6684000,
  '8 sân 5 người, 4 sân 7 người (Ghép được 2 sân 11 người chuẩn thi đấu)',
  '0919 516 516',
  'https://www.google.com/maps/dir/?api=1&destination=10.8393600,106.6684000&destination_place_id=ChIJE0aVQakpdTERDxOOnhDUprY',
  'Cụm Sân vận động Đạt Đức trung tâm Quận Gò Vấp (5A Nguyễn Văn Lượng, cạnh Trung tâm TDTT Gò Vấp & đối diện Lotte Mart), mặt cỏ chuẩn thi đấu, đường biên rộng 3m.',
  'active',
  'assets/images/field-1.jpg',
  4.5, 640
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
