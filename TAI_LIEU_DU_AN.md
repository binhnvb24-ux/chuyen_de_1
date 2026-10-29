# 📘 TÀI LIỆU TỔNG QUAN & CHÚ THÍCH TOÀN BỘ DỰ ÁN QUẢN LÝ SÂN BÓNG

Tài liệu này giải thích chi tiết cấu trúc thư mục, cơ sở dữ liệu, luồng nghiệp vụ và chức năng của từng file trong dự án **Hệ thống Quản lý & Đặt Sân Bóng Trực Tuyến (Tích hợp AI & Thanh toán Demo)**.

---

## 1. Cấu trúc thư mục dự án

```text
quan-ly-san-bong/
├── database.sql                 # Kịch bản tạo CSDL MySQL (11 bảng) & dữ liệu mẫu
├── README.md                    # Hướng dẫn cài đặt nhanh và tài khoản dùng thử
├── TAI_LIEU_DU_AN.md            # Tài liệu giải thích chi tiết từng phần của dự án
├── index.php                    # Trang chủ: Tìm kiếm, lọc sân, danh mục sân, lịch đặt gần đây
├── login.php                    # Trang đăng nhập (phân luồng Khách hàng / Admin)
├── register.php                 # Trang đăng ký tài khoản khách hàng mới
├── logout.php                   # Xử lý đăng xuất và hủy phiên làm việc (Session)
│
├── config/
│   └── database.php             # Khởi tạo kết nối CSDL MySQL bằng PDO ($pdo)
│
├── includes/
│   ├── auth.php                 # Thư viện lõi: Session, CSRF, XSS, phân quyền, cài đặt, tự nâng cấp DB
│   ├── header.php               # Giao diện phần đầu (Topbar + Sidebar trái) cho khu vực Khách hàng
│   ├── footer.php               # Giao diện chân trang Khách hàng & nhúng Widget Chat AI
│   ├── admin_header.php         # Giao diện phần đầu (Sidebar + Topbar) cho khu vực Quản trị (Admin)
│   ├── admin_footer.php         # Đóng khung giao diện trang Quản trị
│   └── chat_widget.php          # Giao diện & JS của cửa sổ Trợ lý AI nổi ở góc phải màn hình
│
├── user/
│   ├── booking.php              # Đặt sân trực tuyến, chọn dịch vụ, áp mã Voucher & tính tiền Realtime
│   ├── payment-demo.php         # Cổng Thanh Toán Demo (VietQR, MoMo, VNPay, Giả lập 1-Click) & Biên lai
│   ├── my-bookings.php          # Quản lý lịch đặt sân của khách & hủy đơn (trước 2 giờ)
│   ├── field-detail.php         # Xem chi tiết sân bóng, lịch trống hôm nay & gửi đánh giá sao
│   ├── profile.php              # Cập nhật thông tin cá nhân & đổi mật khẩu khách hàng
│   └── notifications.php        # Trung tâm thông báo của khách hàng
│
├── api/
│   ├── ai_chat.php              # API xử lý câu hỏi AI (kết hợp dữ liệu DB thực tế + Gemini / Local AI)
│   └── check_voucher.php        # API kiểm tra mã giảm giá Voucher và tính số tiền giảm qua AJAX
│
├── admin/
│   ├── dashboard.php            # Bảng điều khiển trung tâm: Thống kê tổng quan & biểu đồ 7 ngày
│   ├── calendar.php             # Lịch đặt sân trực quan theo Tuần/Tháng/Ngày (FullCalendar)
│   ├── bookings.php             # Quản lý đơn đặt sân, duyệt/hủy đơn & xác nhận thu tiền
│   ├── fields.php               # Quản lý sân bóng (Thêm, sửa, xóa, upload ảnh sân)
│   ├── services.php             # Quản lý dịch vụ tiện ích đi kèm (Thuê bóng, áo Bib, nước, trọng tài)
│   ├── vouchers.php             # Quản lý phát hành và xóa mã giảm giá Voucher
│   ├── users.php                # Quản lý người dùng, xem lịch sử đặt sân, khóa tài khoản, đổi quyền
│   ├── revenue.php              # Thống kê doanh thu theo Ngày / Tháng / Năm (Biểu đồ cột Chart.js)
│   ├── reports.php              # Báo cáo Top 5 sân đặt nhiều nhất & tỉ lệ trạng thái đơn (Biểu đồ tròn)
│   └── settings.php             # Cấu hình Trợ lý AI (Gemini API Key), tài khoản VietQR & hồ sơ Admin
│
└── assets/
    ├── css/
    │   ├── style.css            # CSS nền tảng dùng chung (biến màu, nút, form, bảng, trang user/auth)
    │   ├── home.css             # CSS chuyên biệt cho Trang chủ (index.php)
    │   └── admin.css            # CSS giao diện Dark Emerald cho khu vực Quản trị (admin/*)
    └── images/uploads/          # Thư mục lưu trữ ảnh sân bóng do Admin tải lên
```

---

## 2. Giải thích Cơ sở dữ liệu (`database.sql`)

Hệ thống sử dụng CSDL **`quan_ly_san_bong`** gồm **11 bảng** có ràng buộc khóa ngoại (`FOREIGN KEY`) chặt chẽ:

| STT | Tên bảng | Chức năng chính |
| :--- | :--- | :--- |
| 1 | `users` | Lưu thông tin tài khoản (`username`, `password` mã hóa Bcrypt, `full_name`, `phone`, `email`, phân quyền `role`: `user`/`admin`, trạng thái `status`: `active`/`locked`). |
| 2 | `fields` | Lưu thông tin sân bóng (`name`, loại sân `type`: `'5'`,`'7'`,`'11'`, `address`, `district`, `price_per_hour`, `status`, `image`, điểm trung bình `rating`, `review_count`). |
| 3 | `vouchers` | Lưu mã giảm giá (`code`, loại giảm `discount_type`: `percent`/`fixed`, `discount_value`, `min_order`, `max_discount`, `usage_limit`, `used_count`, `expired_at`). |
| 4 | `services` | Lưu các dịch vụ tiện ích đi kèm (`name`, `description`, `price`, `status`). |
| 5 | `bookings` | Lưu đơn đặt sân của khách (`user_id`, `field_id`, `booking_date`, `start_time`, `end_time`, `total_price`, `voucher_id`, `note`, `status`: `pending`/`confirmed`/`cancelled`/`completed`). |
| 6 | `booking_services` | Bảng chi tiết lưu các dịch vụ khách đã chọn kèm theo từng đơn đặt sân (`booking_id`, `service_id`, `quantity`, `price`). |
| 7 | `payments` | Lưu giao dịch thanh toán của đơn đặt sân (`booking_id`, `method`: `cash`/`bank`/`momo`/`vnpay`, `amount`, `transaction_code`, `status`: `pending`/`paid`/`refunded`/`failed`, `paid_at`). |
| 8 | `reviews` | Lưu đánh giá sao (`1-5`) và nhận xét của khách hàng cho từng sân bóng. |
| 9 | `notifications` | Lưu thông báo tự động gửi cho người dùng khi đặt sân, thanh toán, duyệt/hủy đơn. |
| 10 | `ai_chat_logs` | Lưu lịch sử hội thoại giữa người dùng và Trợ lý AI theo `session_id`. |
| 11 | `system_settings` | Lưu cấu hình động của hệ thống (`gemini_api_key`, `ai_bot_name`, `bank_id`, `bank_account_no`, `bank_account_name`, `auto_confirm_paid`). |

---

## 3. Giải thích chi tiết từng nhóm File mã nguồn

### 3.1. Nhóm Cấu hình & Thư viện dùng chung (`config/`, `includes/`)
* **`config/database.php`**: Kết nối MySQL qua PDO với charset `utf8mb4`, bật chế độ báo lỗi `ERRMODE_EXCEPTION` và trả về mảng kết hợp `FETCH_ASSOC`.
* **`includes/auth.php`**:
  * `e($s)`: Chống lỗ hổng XSS khi in dữ liệu ra HTML.
  * `money($n)`: Định dạng số tiền sang chuẩn VNĐ (VD: `150.000đ`).
  * `csrf_token()`, `csrf_field()`, `csrf_check()`: Tạo và kiểm tra mã chống giả mạo truy vấn (CSRF) cho mọi biểu mẫu `POST`.
  * `ensure_upgraded_schema()`: Tự động bổ sung bảng/cột còn thiếu để tránh lỗi khi chạy trên bản CSDL cũ.
  * `get_setting($key)` / `set_setting($key, $val)`: Đọc và lưu cấu hình hệ thống trong bảng `system_settings`.
  * `current_user()`, `require_login()`, `require_admin()`: Kiểm tra trạng thái đăng nhập và phân quyền bảo vệ các trang nội bộ.
  * `flash($type, $msg)` & `notify($uid, $msg)`: Hiển thị thông báo nhanh trên giao diện và ghi thông báo vào CSDL.
* **`includes/chat_widget.php`**: Hiển thị nút bấm nổi **"Tư vấn AI 24/7"** và khung chat. Sử dụng `fetch()` gửi câu hỏi đến `api/ai_chat.php` và hiển thị câu trả lời kèm nút **"Đặt ngay"** dẫn thẳng tới sân bóng gợi ý.

### 3.2. Nhóm Trang gốc & Xác thực (`index.php`, `login.php`, `register.php`, `logout.php`)
* **`index.php`**:
  * Hỗ trợ tìm kiếm theo từ khóa, lọc theo Quận/Huyện, Loại sân (5/7/11 người), Mức giá tối đa, và **kiểm tra sân trống theo Ngày + Giờ** bằng truy vấn `NOT EXISTS`.
  * Kiểm tra thời gian thực (`CURDATE()`, `CURTIME()`) để gắn nhãn **"Đang sử dụng"** hoặc **"Đang trống"** trên từng thẻ sân bóng.
* **`login.php` & `register.php`**:
  * Kiểm tra định dạng tên đăng nhập, số điện thoại 10 số, email, mật khẩu tối thiểu 6 ký tự.
  * Sử dụng `password_hash()` và `password_verify()` chuẩn bảo mật PHP, chống tấn công Session Fixation bằng `session_regenerate_id(true)`.

### 3.3. Nhóm Chức năng Khách hàng (`user/`)
* **`user/booking.php`**:
  * **Phía Client (JavaScript)**: Hàm `recalcPrice()` và `applyVoucher()` tự động tính số giờ đá, cộng tiền thuê sân + tiền dịch vụ đi kèm, gọi API kiểm tra mã giảm giá và hiển thị hóa đơn dự kiến ngay lập tức.
  * **Phía Server (PHP + MySQL Transaction)**: Sử dụng `$pdo->beginTransaction()` và `SELECT ... FOR UPDATE` để khóa bản ghi, kiểm tra trùng khung giờ (`start_time < $en AND end_time > $st`), tính tổng tiền, trừ lượt dùng Voucher, tạo đơn trong bảng `bookings`, lưu dịch vụ vào `booking_services` và tạo giao dịch `payments`.
* **`user/payment-demo.php`**:
  * Tự động ghép chuỗi URL tạo ảnh mã QR chuyển khoản ngân hàng theo chuẩn **VietQR Napas247** (`img.vietqr.io`) với đúng số tiền và nội dung `DATSAN{id}`.
  * Cung cấp nút **"Giả lập Thanh toán Thành công (1-Click)"** giúp mô phỏng ngân hàng báo có: tự động sinh mã giao dịch `TXN...`, cập nhật `payments.status = 'paid'`, tự động duyệt đơn `bookings.status = 'confirmed'` và cho phép in biên lai điện tử.
* **`user/my-bookings.php`**: Hiển thị danh sách đơn đặt sân của khách. Cho phép hủy đơn nếu trạng thái là `pending`/`confirmed` và thời gian hiện tại cách giờ bóng lăn tối thiểu **2 giờ** (`7200` giây).
* **`user/field-detail.php`**: Xem chi tiết sân, các khung giờ đã kín trong ngày hôm nay và gửi đánh giá sao (tự động tính lại `AVG(rating)` cập nhật vào bảng `fields`).

### 3.4. Nhóm API Backend (`api/`)
* **`api/check_voucher.php`**: Kiểm tra mã Voucher (tồn tại, trạng thái `active`, chưa hết hạn, còn lượt sử dụng, đạt giá trị đơn tối thiểu) và trả về số tiền được giảm dưới dạng JSON.
* **`api/ai_chat.php`**:
  * Đọc toàn bộ danh sách sân, lịch bận hôm nay & ngày mai, mã giảm giá, dịch vụ đi kèm và lịch đặt của khách.
  * Nếu Admin có nhập **Google Gemini API Key**, hệ thống gửi ngữ cảnh thực tế lên mô hình `gemini-2.5-flash` để trả lời tự nhiên.
  * Nếu không có API Key, hệ thống tự động dùng **Smart Local AI** (nhận diện ý định câu hỏi bằng biểu thức chính quy tiếng Việt) để tư vấn sân theo loại 5/7/11, lọc theo quận, báo giờ trống hôm nay/ngày mai, tặng mã Voucher và kiểm tra đơn hàng.

### 3.5. Nhóm Chức năng Quản trị viên (`admin/`)
* **`admin/dashboard.php`**: Tổng hợp 4 thẻ chỉ số quan trọng, vẽ biểu đồ đường (Line Chart) doanh thu 7 ngày gần nhất và liệt kê 6 đơn đặt mới nhất.
* **`admin/calendar.php`**: Nhúng thư viện **FullCalendar v6**, hiển thị trực quan lịch đặt sân theo màu sắc trạng thái (`pending`: xanh dương, `confirmed`: xanh lá, `completed`: ngọc bích).
* **`admin/bookings.php`**: Lọc đơn đặt sân, cho phép Admin bấm **Thu tiền**, **Xác nhận**, **Hoàn thành** hoặc **Từ chối/Hủy** (tự động đổi trạng thái thanh toán sang `refunded` nếu khách đã trả tiền).
* **`admin/fields.php`**, **`admin/services.php`**, **`admin/vouchers.php`**, **`admin/users.php`**: Quản lý toàn diện danh mục Sân bóng (kèm upload ảnh an toàn kiểm tra MIME type), Dịch vụ tiện ích, Mã giảm giá và Tài khoản người dùng.
* **`admin/revenue.php` & `admin/reports.php`**: Thống kê doanh thu theo Ngày/Tháng/Năm (chỉ tính các đơn `confirmed` và `completed`) và báo cáo Top 5 sân bóng có hiệu suất cao nhất bằng biểu đồ Chart.js.
* **`admin/settings.php`**: Cho phép Admin thay đổi tên Bot AI, nhập Gemini API Key, cấu hình mã ngân hàng + số tài khoản VietQR và bật/tắt chế độ tự động duyệt đơn khi thanh toán Demo thành công.

