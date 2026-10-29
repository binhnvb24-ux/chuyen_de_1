# HỆ THỐNG QUẢN LÝ & ĐẶT SÂN BÓNG TRỰC TUYẾN (BẢN FULL HOÀN CHỈNH + AI + THANH TOÁN DEMO)

## 1. Tính năng nổi bật trong bản hoàn chỉnh
- **Tích hợp Trợ lý AI Tư vấn 24/7 (`api/ai_chat.php`, `includes/chat_widget.php`)**:
  - Tự động đọc dữ liệu sân bóng, bảng giá, dịch vụ đi kèm, mã giảm giá và **kiểm tra giờ trống thực tế** từ MySQL để tư vấn cho khách.
  - Hỗ trợ chế độ kép: **Smart Local AI** (chạy ngay lập tức không cần API Key) và **Google Gemini AI** (cấu hình API Key trong trang Admin).
- **Cổng Thanh Toán Demo Trực Quan (`user/payment-demo.php`)**:
  - Hỗ trợ tạo mã **VietQR Napas247** tự động theo số tiền & mã đơn, **Ví điện tử MoMo**, **Cổng VNPay Demo (Thẻ ATM Test)** và **Tiền mặt**.
  - Tích hợp **Bảng điều khiển Giả lập Thanh toán 1-Click**: Bấm 1 nút để giả lập thanh toán thành công, tự động sinh mã giao dịch (`TXN...`), gạch nợ hóa đơn, tự động xác nhận lịch đặt sân và hỗ trợ in biên lai điện tử.
- **Đặt sân & Tính tiền Realtime (`user/booking.php`, `api/check_voucher.php`)**:
  - Tự động tính tổng tiền thuê sân + dịch vụ tiện ích + kiểm tra mã giảm giá Voucher ngay lập tức bằng AJAX không cần tải lại trang.
- **Đánh giá sân bóng (`user/field-detail.php`)**:
  - Khách hàng có thể chấm điểm sao và viết nhận xét thực tế cho từng sân bóng, tự động cập nhật điểm trung bình của sân.
- **Hệ thống Quản trị Admin toàn diện (`admin/*`)**:
  - Sửa toàn bộ lỗi đường dẫn cứng, đồng bộ thanh menu bên trái.
  - Quản lý Tổng quan (Chart.js), Lịch trực quan (FullCalendar), Quản lý Sân bóng, Quản lý Dịch vụ tiện ích (`admin/services.php`), Quản lý Đơn đặt & Đối soát Thanh toán, Quản lý Voucher, Quản lý Người dùng, Báo cáo & Doanh thu, Cài đặt AI & Ngân hàng.

## 2. Hướng dẫn cài đặt trên XAMPP
1. Giải nén file `quan-ly-san-bong-full-ai-payment.zip`.
2. Copy thư mục `quan-ly-san-bong` vào `C:\xampp\htdocs\` (Đường dẫn sẽ là `C:\xampp\htdocs\quan-ly-san-bong\`).
3. Mở **XAMPP Control Panel**, bật **Apache** và **MySQL**.
4. Truy cập `http://localhost/phpmyadmin`, chọn tab **Import** (Nhập) và chọn file `database.sql` trong thư mục dự án để tạo CSDL `quan_ly_san_bong` đầy đủ.
5. Truy cập hệ thống tại: `http://localhost/quan-ly-san-bong/`

## 3. Tài khoản đăng nhập dùng thử
- **Quản trị viên (Admin)**:
  - Tài khoản: `admin`
  - Mật khẩu: `password`
- **Khách hàng (User)**:
  - Tài khoản: `nguyenvana` hoặc `tranvanb`
  - Mật khẩu: `password`
