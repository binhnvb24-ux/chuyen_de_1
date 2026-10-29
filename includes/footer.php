<?php
/**
 * FILE: includes/footer.php
 * MÔ TẢ: Giao diện chân trang (Footer) cho khu vực Khách hàng và tích hợp Widget Chat AI.
 */
?>
  </main>
</div>
<footer class="user-portal-footer">
  <div class="up-foot-container">
    <span>© <?=date('Y')?> Sân Bóng - Hệ thống đặt sân trực tuyến tích hợp AI & Thanh toán thông minh</span>
    <a href="<?=$base?>index.php">Về trang chủ</a>
  </div>
</footer>
<!-- Nhúng tiện ích Trợ lý AI tư vấn ở góc phải dưới màn hình -->
<?php if (is_file(__DIR__.'/chat_widget.php')) include __DIR__.'/chat_widget.php'; ?>
</body>
</html>
