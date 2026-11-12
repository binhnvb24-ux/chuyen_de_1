<?php
/**
 * FILE: includes/footer.php
 * MÔ TẢ: Giao diện chân trang (Footer 4 cột) cho khu vực Khách hàng và tích hợp Widget Chat AI.
 */
$foot_base = isset($base) ? $base : (function_exists('project_base') ? project_base() : '/quan-ly-san-bong/');
?>
  </main>
</div>

<!-- ==========================================================================
     CHÂN TRANG ĐẦY ĐỦ SÂN BÓNG PRO (FULL 4-COLUMN SPORT FOOTER)
     ========================================================================== -->
<footer class="sb-pro-footer" id="contact">
  <!-- Dải băng kêu gọi đặt sân nhanh phía trên Footer -->
  <div class="sb-foot-top-strip">
    <div class="sb-foot-top-inner">
      <div class="sb-foot-cta-text">
        <span class="cta-badge">🔥 Ưu đãi sân cỏ</span>
        <span>Nhập mã <b>SANBONG20</b> giảm ngay 20% khi đặt lịch thi đấu trực tuyến hôm nay!</span>
      </div>
      <div class="sb-foot-cta-actions">
        <a class="sb-foot-cta-btn" href="<?=$foot_base?>user/booking.php">⚡ Đặt sân ngay</a>
        <a class="sb-foot-cta-btn outline" href="<?=$foot_base?>user/matchmaking.php?view=create">+ Tạo kèo giao hữu</a>
      </div>
    </div>
  </div>

  <!-- Nội dung chính 4 cột của Footer -->
  <div class="sb-foot-main">
    <!-- Cột 1: Thương hiệu & Cam kết -->
    <div class="sb-foot-col">
      <a class="sb-foot-brand" href="<?=$foot_base?>index.php">
        <span class="logo-ball">⚽</span>
        <span>SÂN BÓNG</span>
        <span class="pro-pill">PRO</span>
      </a>
      <p class="sb-foot-desc">
        Nền tảng quản lý, đặt sân bóng đá nhân tạo theo sơ đồ trực quan 30 phút, đăng ký lịch cố định hàng tuần và cáp kèo tìm đối giao hữu tự động.
      </p>
      <div class="sb-foot-badges">
        <span>🌱 Cỏ chuẩn FIFA</span>
        <span>⏳ Giữ chỗ HOLD 10p</span>
        <span>🎫 Vé QR Check-in</span>
      </div>
    </div>

    <!-- Cột 2: Liên kết điều hướng nhanh -->
    <div class="sb-foot-col">
      <h4>🧭 Khám phá nhanh</h4>
      <ul class="sb-foot-links">
        <li><a href="<?=$foot_base?>index.php">🏠 Trang chủ & Danh sách sân</a></li>
        <li><a href="<?=$foot_base?>user/booking.php">📅 Đặt sân theo sơ đồ mặt sân</a></li>
        <li><a href="<?=$foot_base?>user/matchmaking.php">⚔️ Sàn Cáp Kèo / Tìm đối giao hữu</a></li>
        <li><a href="<?=$foot_base?>user/my-bookings.php">🎫 Vé điện tử & Lịch đặt của tôi</a></li>
        <li><a href="<?=$foot_base?>user/notifications.php">🔔 Trung tâm thông báo</a></li>
      </ul>
    </div>

    <!-- Cột 3: Thông tin liên hệ & Hỗ trợ 24/7 -->
    <div class="sb-foot-col">
      <h4>📞 Liên hệ & Hỗ trợ</h4>
      <div class="sb-foot-contact-list">
        <div class="sb-foot-contact-item">
          <span>📍</span>
          <span>Hệ thống cụm sân cỏ nhân tạo tại <b>TP. Hồ Chí Minh</b></span>
        </div>
        <div class="sb-foot-contact-item">
          <span>📞</span>
          <span>Hotline đặt sân: <b>0901.234.567</b></span>
        </div>
        <div class="sb-foot-contact-item">
          <span>✉️</span>
          <span>Email: <b>admin@sanbong.vn</b></span>
        </div>
        <div class="sb-foot-contact-item">
          <span>🕒</span>
          <span>Giờ mở cửa: <b>05:30 – 23:30</b> (Tất cả các ngày)</span>
        </div>
      </div>
      <button type="button" class="sb-foot-ai-btn" onclick="if(typeof toggleAiChat==='function') toggleAiChat();">
        💬 Nhờ Trợ lý AI tìm sân trống
      </button>
    </div>
  </div>

  <!-- Thanh bản quyền & Phương thức thanh toán ở đáy Footer -->
  <div class="sb-foot-bottom">
    <div class="sb-foot-bottom-inner">
      <span>© <?=date('Y')?> <b>Sân Bóng Pro</b> — Hệ thống đặt sân bóng đá trực tuyến tích hợp AI & Thanh toán QR tự động.</span>
      <div class="sb-foot-pay-tags">
        <span>🏦 VietQR 24/7</span>
        <span>📱 Ví MoMo</span>
        <span>💵 Tiền mặt tại sân</span>
      </div>
    </div>
  </div>
</footer>

<!-- Nhúng tiện ích Trợ lý AI tư vấn ở góc phải dưới màn hình -->
<?php if (is_file(__DIR__.'/chat_widget.php')) include __DIR__.'/chat_widget.php'; ?>
<!-- Nhúng Engine Hiệu Ứng Tương Tác Thể Thao -->
<script src="<?=$foot_base?>assets/js/sport-effects.js?v=<?=filemtime(__DIR__ . '/../assets/js/sport-effects.js')?>"></script>
</body>
</html>
