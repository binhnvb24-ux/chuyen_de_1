# ⚽ MASTER PROMPT TOÀN DỰ ÁN: SÂN BÓNG PRO (15 MÀN HÌNH KHÁCH HÀNG + 10 MÀN HÌNH ADMIN + 5 THUẬT TOÁN LÕI)

Tài liệu này lưu trữ toàn bộ **Master Prompt Thiết Kế Giao Diện & Kiến Trúc** được ánh xạ trực tiếp với các tệp mã nguồn trong dự án `quan-ly-san-bong`.

---

## 1. Hệ Thống Thiết Kế & Biến CSS Chuẩn (`assets/css/style.css` & `assets/css/effects.css`)
- **Font chữ**:
  - Font nội dung chính (`--font-sans`): `'Be Vietnam Pro'` (`400, 500, 600, 700, 800`).
  - Font tiêu đề thể thao & số liệu (`--font-sport`): `'Chakra Petch'` (`600, 700, italic 700`).
- **Bảng màu Phân hệ Khách hàng (`body.user-portal` / `body.hp`)**:
  - Nền trang (`--bg-body`): `#F4F7F5` kết hợp ánh xanh bạc hà `#ECFDF5` / `#F0FDF4`.
  - Nền thẻ (`--bg-surface`): `#FFFFFF`, viền `--border: #DCE5DF`, bo góc `12px / 16px / 22px`.
  - Màu chủ đạo (`--primary`): `#16A34A`, hover `#15803D`, nút chính gradient `linear-gradient(135deg, #22c55e, #15803d)`.
- **Bảng màu Phân hệ Quản trị Admin (`body.adm` & `body.adm.light-mode`)**:
  - Mặc định Dark Emerald (`#090F1D` / `#0D1627` / `#131F35`), nút chuyển đổi Sáng/Tối (`☀️` / `🌙`) sang Light Mode.

---

## 2. Bảng Ánh Xạ 15 Màn Hình Khách Hàng Với File Mã Nguồn

| Mã màn hình | File PHP tương ứng | Chức năng & Trạng thái hiển thị |
|---|---|---|
| `01_trang_chu` | [index.php](file:///c:/xampp/htdocs/quan-ly-san-bong/index.php) (Đầu trang) | Hero Banner CR7 `"SIUUU! ⚡7"`, thanh lọc nhanh, 4 thẻ KPI, danh sách Sân nổi bật |
| `02_trang_chu_toan_trang` | [index.php](file:///c:/xampp/htdocs/quan-ly-san-bong/index.php) (Toàn trang) | Danh sách sân, Danh mục 5/7/11, Cáp kèo preview, Bản tin bóng đá 24/7 RSS, Voucher, Lịch đặt gần đây |
| `03_dang_nhap` | [login.php](file:///c:/xampp/htdocs/quan-ly-san-bong/login.php) | Poster CR7 `"SIUUU! ⚡7"` bên trái + Form `.auth-box` & `.demo-hint` bên phải trên nền xanh bạc hà |
| `04_dang_ky` | [register.php](file:///c:/xampp/htdocs/quan-ly-san-bong/register.php) | Poster CR7 `"SIUUU! ⚡7"` bên trái + Form đăng ký 2 cột bên phải |
| `05_chi_tiet_san` | [user/field-detail.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/field-detail.php) | Chi tiết Sân Minh Phát (Ảnh mặt cỏ, tiện ích FIFA, đánh giá sao & khung chọn ngày/giờ gợi ý) |
| `06_dat_san_so_do` | [user/booking.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/booking.php) | Bước 1 đặt sân: Stepper 3 bước, Sơ đồ 2D Ghép/Tách sân (`5A, 5B, 5C ↔ 7A ↔ 11A`), lưới chọn ô 30 phút |
| `07_dat_san_toan_bo_flow` | [user/booking.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/booking.php) | Sơ đồ 2D + Lưới 30p + Chọn 5 dịch vụ đi kèm + Mã giảm giá Voucher + Đặt lịch cố định tuần (4/8/12 tuần) |
| `08_thanh_toan_vietqr` | [user/payment-demo.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/payment-demo.php) | Bước 2 giữ chỗ HOLD 10 phút: Mã QR VietQR Napas247 (`MB - 0901234567`) & nút xác nhận 1-Click |
| `09_ve_dien_tu_qr` | [user/payment-demo.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/payment-demo.php) | Bước 3 hoàn tất: Vé Đặt Sân Điện Tử QR (`OFFICIAL MATCH PASS #SBP-xxxx`) kèm QR Check-in tại sân |
| `10_lich_dat_cua_toi` | [user/my-bookings.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/my-bookings.php) | 4 thẻ KPI trạng thái, bộ lọc tab (`Tất cả`, `Sắp tới`, `Hoàn thành`, `Đã hủy`), bảng đơn đặt & bảng kèo |
| `11_san_cap_keo` | [user/matchmaking.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/matchmaking.php) | Bảng "Kèo giao hữu của đội bạn", bộ lọc tìm kèo và lưới thẻ "Kèo giao hữu đang mở" |
| `12_tao_keo_giao_huu` | [user/matchmaking.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/matchmaking.php) | Form 2 cột "Đăng kèo giao hữu mới" (60% Trái) + Thẻ "Xem trước kèo (Live Preview)" (40% Phải) |
| `13_ho_so_ca_nhan` | [user/profile.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/profile.php) | Quản lý tài khoản 2 cột: "📝 Thông tin liên hệ" & "🔐 Đổi mật khẩu bảo vệ" |
| `14_trung_tam_thong_bao` | [user/notifications.php](file:///c:/xampp/htdocs/quan-ly-san-bong/user/notifications.php) | Danh sách thông báo đặt sân (xanh bạc hà) & thông báo hủy kèo/hủy sân (đỏ nhạt `#fef2f2`) |
| `15_tro_ly_ai_chat` | [includes/chat_widget.php](file:///c:/xampp/htdocs/quan-ly-san-bong/includes/chat_widget.php) | Cửa sổ Trợ lý Sân Bóng (AI Chat) kèm thẻ gợi ý đặt nhanh Sân Minh Phát - Sân 5A |

---

## 3. Bảng Ánh Xạ 10 Màn Hình Quản Trị Admin (`admin/*`)

1. [admin/dashboard.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/dashboard.php) (`🏠 Tổng quan`): 4 thẻ KPI doanh thu & đơn đặt, biểu đồ đường doanh thu 7 ngày và bảng đơn mới nhất.
2. [admin/calendar.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/calendar.php) (`📅 Xem lịch trực quan`): Lịch trực quan theo ngày/tuần phân màu theo trạng thái.
3. [admin/bookings.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/bookings.php) (`📋 Đặt sân & Thanh toán`): Duyệt đơn, Thu tiền, Hoàn thành, Hủy/Hoàn tiền.
4. [admin/fields.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/fields.php) (`🏟 Quản lý sân bóng`): Quản lý 6 cụm sân & cấu trúc Cha-Con (`5A, 5B, 5C ↔ 7A ↔ 11A`).
5. [admin/services.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/services.php) (`🥤 Dịch vụ tiện ích`): Quản lý 5 dịch vụ đi kèm.
6. [admin/vouchers.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/vouchers.php) (`🎟 Mã giảm giá Voucher`): Quản lý các mã `SANBONG10`, `GIAM30K`, `TANTHU20`.
7. [admin/users.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/users.php) (`👥 Quản lý người dùng`): Danh sách cầu thủ & khóa/mở khóa tài khoản.
8. [admin/revenue.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/revenue.php) (`📈 Thống kê doanh thu`): Báo cáo doanh thu theo Ngày / Tháng / Năm.
9. [admin/reports.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/reports.php) (`📊 Báo cáo hiệu suất`): Biểu đồ Top sân đặt nhiều nhất & tỷ lệ trạng thái đơn.
10. [admin/settings.php](file:///c:/xampp/htdocs/quan-ly-san-bong/admin/settings.php) (`⚙ Cài đặt AI & VietQR`): Cấu hình Gemini AI & tài khoản thụ hưởng VietQR (`MB - 0901234567`).
