/**
 * ============================================================================
 * FILE: assets/js/sport-effects.js
 * MÔ TẢ: Engine Hiệu Ứng Tương Tác Thể Thao (Sport Interactive Visual Effects)
 * BAO GỒM CÁC HIỆU ỨNG THỊ GIÁC:
 *   1. Pháo giấy chiến thắng (Canvas Confetti) & Bảng Điện Tử Truyền Hình (Broadcast Toast)
 *   2. Tương tác Sơ đồ Mặt Sân Cỏ 2D (Interactive 2D Hierarchical Pitch Map)
 *   3. Hiệu ứng Cuộn Trang Mượt (Scroll Reveal) & Đếm Số Tự Động (Animated Counter)
 *   4. Hiệu ứng Thẻ Cầu Thủ FIFA 3D Tilt & Ánh Kim Hologram (3D Tilt & Foil Glare)
 *   5. Sóng Radar Trực Tiếp (Live Radar Pulse) & Nhịp Tim Khẩn Cấp Đồng Hồ HOLD 10 Phút
 * ============================================================================
 */

(function () {
  'use strict';

  // ==========================================================================
  // PHẦN 1: HIỆU ỨNG PHÁO GIẤY CHIẾN THẮNG (HTML5 CANVAS CONFETTI)
  // ==========================================================================
  function launchSportConfetti() {
    var canvas = document.getElementById('sportConfettiCanvas');
    if (!canvas) {
      canvas = document.createElement('canvas');
      canvas.id = 'sportConfettiCanvas';
      document.body.appendChild(canvas);
    }

    var ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;

    var colors = ['#22c55e', '#4ade80', '#facc15', '#38bdf8', '#ffffff', '#10b981'];
    var particles = [];

    for (var i = 0; i < 110; i++) {
      particles.push({
        x: canvas.width * (0.2 + Math.random() * 0.6),
        y: canvas.height * 0.25,
        vx: (Math.random() - 0.5) * 14,
        vy: -Math.random() * 12 - 4,
        size: 6 + Math.random() * 6,
        color: colors[Math.floor(Math.random() * colors.length)],
        rot: Math.random() * Math.PI * 2,
        vRot: (Math.random() - 0.5) * 0.25,
        alpha: 1
      });
    }

    var frame = 0;
    function render() {
      frame++;
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      var alive = false;

      for (var i = 0; i < particles.length; i++) {
        var p = particles[i];
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.32;
        p.vx *= 0.99;
        p.rot += p.vRot;
        if (frame > 75) {
          p.alpha -= 0.022;
        }
        if (p.alpha > 0 && p.y < canvas.height + 30) {
          alive = true;
          ctx.save();
          ctx.globalAlpha = Math.max(0, p.alpha);
          ctx.translate(p.x, p.y);
          ctx.rotate(p.rot);
          ctx.fillStyle = p.color;
          ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
          ctx.restore();
        }
      }

      if (alive && frame < 150) {
        requestAnimationFrame(render);
      } else {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
    }

    requestAnimationFrame(render);
  }

  window.launchSportConfetti = launchSportConfetti;

  // ==========================================================================
  // PHẦN 2: BẢNG ĐIỆN TỬ TRUYỀN HÌNH (BROADCAST TOAST) KHI CÓ THÔNG BÁO FLASH
  // ==========================================================================
  function initFlashCelebration() {
    var alertEl = document.querySelector('.alert');
    if (!alertEl) return;

    var textEl = alertEl.querySelector('.alert-text');
    var msg = textEl ? textEl.textContent.trim() : alertEl.textContent.trim();
    if (!msg) return;

    var isCancel =
      alertEl.classList.contains('cancel') ||
      alertEl.classList.contains('err') ||
      /hủy|rút khỏi|báo nghỉ/i.test(msg);

    var isCelebration =
      !isCancel &&
      /thành công|chốt kèo|giữ chỗ|đăng kèo|xác nhận/i.test(msg);

    if (isCelebration) {
      launchSportConfetti();
    }

    // Hiển thị Broadcast Scoreboard Toast ở góc trên bên phải
    var toast = document.createElement('div');
    toast.className = 'broadcast-toast' + (isCancel ? ' is-cancel' : '');
    toast.innerHTML =
      '<div style="font-size:26px">' + (isCancel ? '❌' : '⚽') + '</div>' +
      '<div style="flex:1">' +
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">' +
          '<span class="broadcast-toast-badge">' + (isCancel ? 'VAR • THÔNG BÁO HỦY' : 'LIVE • SÂN BÓNG PRO') + '</span>' +
          '<small style="color:#94a3b8;font-size:11px;margin:0">Vừa xong</small>' +
        '</div>' +
        '<div style="font-size:13.5px;font-weight:700;line-height:1.4">' + msg + '</div>' +
      '</div>';

    document.body.appendChild(toast);
    setTimeout(function () {
      toast.classList.add('show');
    }, 60);

    setTimeout(function () {
      toast.classList.remove('show');
    }, 5200);
  }

  // ==========================================================================
  // PHẦN 3: CUỘN TRANG MƯỢT (SCROLL REVEAL) & ĐẾM SỐ TỰ ĐỘNG (COUNTER)
  // ==========================================================================
  function initScrollRevealAndCounters() {
    var targets = document.querySelectorAll('.hp-card, .sport-ticket, .hp-score-item, .match-card');
    if ('IntersectionObserver' in window && targets.length > 0) {
      var obs = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add('is-visible');
              obs.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.08 }
      );

      targets.forEach(function (el, idx) {
        el.classList.add('reveal-item');
        el.style.transitionDelay = Math.min((idx % 6) * 55, 250) + 'ms';
        obs.observe(el);
      });
    }

    // Hiệu ứng nhảy số trên Bảng điện tử Trang chủ (.hp-score-num)
    var scoreNums = document.querySelectorAll('.hp-score-num');
    scoreNums.forEach(function (el) {
      var raw = el.textContent.trim();
      if (/^\d+$/.test(raw)) {
        var target = parseInt(raw, 10);
        var padLen = raw.length;
        var current = 0;
        var step = Math.max(1, Math.ceil(target / 24));
        var timer = setInterval(function () {
          current += step;
          if (current >= target) {
            current = target;
            clearInterval(timer);
          }
          el.textContent = String(current).padStart(padLen, '0');
        }, 35);
      }
    });
  }

  // ==========================================================================
  // PHẦN 4: HIỆU ỨNG THẺ CẦU THỦ FIFA 3D TILT & ÁNH KIM HOLOGRAM
  // ==========================================================================
  function init3DCardTilt() {
    var cards = document.querySelectorAll('.hp-card, .sport-ticket');
    cards.forEach(function (card) {
      if (!card.querySelector('.holo-glare')) {
        var glare = document.createElement('div');
        glare.className = 'holo-glare';
        card.appendChild(glare);
      }

      card.addEventListener('mousemove', function (e) {
        var rect = card.getBoundingClientRect();
        var x = e.clientX - rect.left;
        var y = e.clientY - rect.top;
        var pctX = (x / rect.width) * 100;
        var pctY = (y / rect.height) * 100;

        var maxTilt = card.classList.contains('sport-ticket') ? 3.5 : 5.5;
        var rotateY = ((x / rect.width - 0.5) * maxTilt * 2).toFixed(2);
        var rotateX = ((0.5 - y / rect.height) * maxTilt * 2).toFixed(2);

        card.style.transform =
          'perspective(900px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateY(-4px)';
        card.style.setProperty('--glare-x', pctX.toFixed(1) + '%');
        card.style.setProperty('--glare-y', pctY.toFixed(1) + '%');
      });

      card.addEventListener('mouseleave', function () {
        card.style.transform = '';
      });
    });
  }

  // ==========================================================================
  // PHẦN 5: ĐỒNG HỒ GIỮ CHỖ HOLD 10 PHÚT - HIỆU ỨNG NHỊP TIM KHẨN CẤP
  // ==========================================================================
  function initHoldUrgencyEffect() {
    var box = document.getElementById('holdCountdownBox');
    if (!box) return;

    setInterval(function () {
      var text = box.textContent.trim();
      var parts = text.split(':');
      if (parts.length === 2) {
        var mins = parseInt(parts[0], 10);
        if (mins < 3) {
          box.classList.add('hold-urgent');
        }
      }
    }, 1000);
  }

  // ==========================================================================
  // PHẦN 6: ĐỒNG HỒ ĐIỆN TỬ TRỰC TIẾP TRÊN THANH LIVE ARENA
  // ==========================================================================
  function initLiveArenaClock() {
    var clockEl = document.getElementById('liveArenaClock');
    if (!clockEl) return;

    setInterval(function () {
      var now = new Date();
      var hh = String(now.getHours()).padStart(2, '0');
      var mm = String(now.getMinutes()).padStart(2, '0');
      var ss = String(now.getSeconds()).padStart(2, '0');
      clockEl.textContent = '🕒 ' + hh + ':' + mm + ':' + ss;
    }, 1000);
  }

  // Khởi chạy các hiệu ứng thị giác khi DOM sẵn sàng
  document.addEventListener('DOMContentLoaded', function () {
    initFlashCelebration();
    initScrollRevealAndCounters();
    init3DCardTilt();
    initHoldUrgencyEffect();
    initLiveArenaClock();
  });
})();
