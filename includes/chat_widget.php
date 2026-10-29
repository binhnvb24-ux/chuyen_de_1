<?php
/**
 * FILE: includes/chat_widget.php
 * MÔ TẢ: Tiện ích (Widget) cửa sổ chat Trợ lý AI nổi ở góc phải dưới màn hình.
 * CHỨC NĂNG: Gửi câu hỏi của người dùng tới api/ai_chat.php bằng Fetch AJAX và hiển thị gợi ý đặt sân.
 */
$cw_base = function_exists('project_base') ? project_base() : '/quan-ly-san-bong/';
$cw_bot_name = function_exists('get_setting') ? (get_setting('ai_bot_name', 'Trợ lý AI Sân Bóng') ?: 'Trợ lý AI Sân Bóng') : 'Trợ lý AI Sân Bóng';
?>
<!-- 1. Giao diện HTML của nút bấm nổi (FAB) và khung cửa sổ Chat AI -->
<div class="ai-chat-widget" id="aiChatWidget">
  <button class="ai-chat-fab" type="button" id="aiChatToggleBtn" onclick="toggleAiChat()">
    <span class="ai-fab-pulse"></span>
    <span class="ai-fab-icon">🤖</span>
    <span class="ai-fab-text">Tư vấn AI 24/7</span>
  </button>

  <div class="ai-chat-window" id="aiChatWindow">
    <div class="ai-chat-header">
      <div class="ai-chat-brand">
        <div class="ai-avatar">🤖</div>
        <div>
          <b><?=e($cw_bot_name)?></b>
          <small><span class="ai-online-dot"></span> Đang trực tuyến • Tư vấn thông minh</small>
        </div>
      </div>
      <div class="ai-header-actions">
        <button type="button" title="Xóa đoạn chat" onclick="clearAiChat()">🗑</button>
        <button type="button" title="Thu gọn" onclick="toggleAiChat()">✕</button>
      </div>
    </div>

    <div class="ai-chat-messages" id="aiChatMessages">
      <div class="ai-msg bot">
        <div class="ai-bubble">
          👋 Xin chào! Mình là <b><?=e($cw_bot_name)?></b>.<br>
          Mình có thể tư vấn chọn sân 5/7/11 người, kiểm tra <b>khung giờ còn trống hôm nay</b>, tặng <b>mã Voucher giảm giá</b> và hướng dẫn <b>Thanh toán QR/MoMo Demo</b> cho bạn!
        </div>
      </div>
    </div>

    <!-- Các nút gợi ý câu hỏi nhanh -->
    <div class="ai-quick-prompts" id="aiQuickPrompts">
      <button type="button" onclick="sendQuickPrompt('Tư vấn sân 5 người giá rẻ')">⚽ Sân 5 giá rẻ</button>
      <button type="button" onclick="sendQuickPrompt('Sân nào còn giờ trống hôm nay?')">🕒 Giờ trống hôm nay</button>
      <button type="button" onclick="sendQuickPrompt('Cho mình xin mã giảm giá Voucher')">🎁 Mã Voucher</button>
      <button type="button" onclick="sendQuickPrompt('Hướng dẫn thanh toán Demo QR & MoMo')">💳 Thanh toán Demo</button>
      <button type="button" onclick="sendQuickPrompt('Kiểm tra đơn đặt sân của tôi')">📋 Đơn của tôi</button>
    </div>

    <form class="ai-chat-input-bar" onsubmit="handleAiSubmit(event)">
      <input type="text" id="aiChatInput" placeholder="Nhập câu hỏi (VD: tìm sân 7 người ở Thủ Đức)..." autocomplete="off">
      <button type="submit" id="aiChatSendBtn">➤</button>
    </form>
  </div>
</div>

<!-- 2. Định dạng CSS riêng cho Widget Chat AI -->
<style>
.ai-chat-widget {
  position: fixed;
  right: 22px;
  bottom: 22px;
  z-index: 9999;
  font-family: 'Be Vietnam Pro', system-ui, -apple-system, sans-serif;
}
.ai-chat-fab {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  border-radius: 999px;
  padding: 13px 20px;
  background: linear-gradient(135deg, #059669, #047857);
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
  box-shadow: 0 10px 28px rgba(5, 150, 105, 0.45);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.ai-chat-fab:hover {
  transform: translateY(-3px) scale(1.02);
  box-shadow: 0 14px 32px rgba(5, 150, 105, 0.55);
}
.ai-fab-icon {
  font-size: 20px;
  background: rgba(255,255,255,0.2);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: grid;
  place-items: center;
}
.ai-fab-pulse {
  position: absolute;
  top: -2px;
  right: -2px;
  width: 12px;
  height: 12px;
  background: #34d399;
  border: 2px solid #fff;
  border-radius: 50%;
}
.ai-chat-window {
  display: none;
  flex-direction: column;
  width: 375px;
  height: 530px;
  max-height: calc(100vh - 100px);
  margin-bottom: 12px;
  background: #ffffff;
  border-radius: 20px;
  border: 1px solid #d1fae5;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
  overflow: hidden;
  animation: aiSlideUp 0.25s ease;
}
@keyframes aiSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.ai-chat-window.open {
  display: flex;
}
.ai-chat-header {
  background: linear-gradient(135deg, #064e3b, #059669);
  color: #fff;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.ai-chat-brand {
  display: flex;
  align-items: center;
  gap: 10px;
}
.ai-avatar {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: rgba(255,255,255,0.18);
  display: grid;
  place-items: center;
  font-size: 21px;
}
.ai-chat-brand b {
  display: block;
  font-size: 14.5px;
  color: #fff;
}
.ai-chat-brand small {
  color: #a7f3d0;
  font-size: 11.5px;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 5px;
}
.ai-online-dot {
  width: 7px;
  height: 7px;
  background: #4ade80;
  border-radius: 50%;
  display: inline-block;
}
.ai-header-actions {
  display: flex;
  gap: 6px;
}
.ai-header-actions button {
  background: rgba(255,255,255,0.15);
  border: 0;
  color: #fff;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
}
.ai-header-actions button:hover {
  background: rgba(255,255,255,0.28);
}
.ai-chat-messages {
  flex: 1;
  padding: 14px;
  overflow-y: auto;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ai-msg {
  display: flex;
  flex-direction: column;
  max-width: 88%;
}
.ai-msg.user {
  align-self: flex-end;
}
.ai-msg.bot {
  align-self: flex-start;
}
.ai-bubble {
  padding: 10px 14px;
  border-radius: 14px;
  font-size: 13.2px;
  line-height: 1.55;
  word-wrap: break-word;
}
.ai-msg.user .ai-bubble {
  background: linear-gradient(135deg, #059669, #047857);
  color: #fff;
  border-bottom-right-radius: 4px;
}
.ai-msg.bot .ai-bubble {
  background: #ffffff;
  color: #1e293b;
  border: 1px solid #e2e8f0;
  border-bottom-left-radius: 4px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}
.ai-sug-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
}
.ai-sug-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  padding: 8px 10px;
  border-radius: 10px;
  font-size: 12px;
}
.ai-sug-card b {
  color: #065f46;
  display: block;
}
.ai-sug-card small {
  margin: 0;
  color: #047857;
  font-size: 11px;
}
.ai-sug-btn {
  background: #059669;
  color: #fff !important;
  padding: 5px 10px;
  border-radius: 6px;
  font-weight: 700;
  font-size: 11.5px;
  text-decoration: none;
  white-space: nowrap;
}
.ai-quick-prompts {
  display: flex;
  gap: 6px;
  padding: 8px 12px;
  background: #fff;
  border-top: 1px solid #f1f5f9;
  overflow-x: auto;
  scrollbar-width: none;
}
.ai-quick-prompts::-webkit-scrollbar { display: none; }
.ai-quick-prompts button {
  border: 1px solid #d1fae5;
  background: #f0fdf4;
  color: #065f46;
  border-radius: 999px;
  padding: 5px 11px;
  font-size: 11.5px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.ai-quick-prompts button:hover {
  background: #059669;
  color: #fff;
}
.ai-chat-input-bar {
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}
.ai-chat-input-bar input {
  flex: 1;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  padding: 9px 14px;
  font-size: 13px;
  outline: none;
  margin: 0;
}
.ai-chat-input-bar input:focus {
  border-color: #059669;
}
.ai-chat-input-bar button {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 0;
  background: linear-gradient(135deg, #059669, #047857);
  color: #fff;
  cursor: pointer;
  font-size: 15px;
  display: grid;
  place-items: center;
}
@media (max-width: 520px) {
  .ai-chat-widget { right: 10px; bottom: 10px; }
  .ai-chat-window { width: calc(100vw - 20px); height: 75vh; }
}
</style>

<!-- 3. Xử lý JavaScript cho khung Chat AI -->
<script>
const AI_API_URL = <?=json_encode($cw_base . 'api/ai_chat.php')?>;

// Chuyển đổi Markdown cơ bản sang HTML an toàn
function formatAiMarkdown(text) {
  let safe = text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
  safe = safe.replace(/\*\*(.*?)\*\*/g, '<b>$1</b>');
  safe = safe.replace(/\*(.*?)\*/g, '<i>$1</i>');
  safe = safe.replace(/_(.*?)_/g, '<i>$1</i>');
  safe = safe.replace(/`(.*?)`/g, '<code style="background:#ecfdf5;color:#065f46;padding:1px 5px;border-radius:4px;font-weight:700">$1</code>');
  safe = safe.replace(/\n/g, '<br>');
  return safe;
}

// Bật / tắt cửa sổ chat AI
function toggleAiChat() {
  const win = document.getElementById('aiChatWindow');
  win.classList.toggle('open');
  if (win.classList.contains('open')) {
    setTimeout(() => document.getElementById('aiChatInput')?.focus(), 100);
  }
}

// Thêm tin nhắn mới vào khung hội thoại kèm thẻ gợi ý sân (nếu có)
function appendAiMessage(role, text, suggestions = []) {
  const box = document.getElementById('aiChatMessages');
  const wrap = document.createElement('div');
  wrap.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');

  let html = '<div class="ai-bubble">' + formatAiMarkdown(text);
  if (suggestions && suggestions.length > 0) {
    html += '<div class="ai-sug-list">';
    suggestions.forEach(s => {
      html += `<div class="ai-sug-card">
        <div><b>⚽ ${s.name}</b><small>Sân ${s.type} người • ${s.price}/h</small></div>
        <a class="ai-sug-btn" href="${s.url}">Đặt ngay →</a>
      </div>`;
    });
    html += '</div>';
  }
  html += '</div>';
  wrap.innerHTML = html;
  box.appendChild(wrap);
  box.scrollTop = box.scrollHeight;
}

// Gửi nhanh câu hỏi mẫu khi bấm chip gợi ý
function sendQuickPrompt(text) {
  const inp = document.getElementById('aiChatInput');
  inp.value = text;
  handleAiSubmit(new Event('submit'));
}

// Xử lý sự kiện gửi tin nhắn lên máy chủ AI
async function handleAiSubmit(e) {
  if (e && e.preventDefault) e.preventDefault();
  const inp = document.getElementById('aiChatInput');
  const msg = inp.value.trim();
  if (!msg) return;
  inp.value = '';
  appendAiMessage('user', msg);

  const box = document.getElementById('aiChatMessages');
  const typing = document.createElement('div');
  typing.className = 'ai-msg bot';
  typing.innerHTML = '<div class="ai-bubble" style="color:#64748b"><i>⏳ AI đang phân tích dữ liệu sân trống...</i></div>';
  box.appendChild(typing);
  box.scrollTop = box.scrollHeight;

  try {
    const res = await fetch(AI_API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg })
    });
    const data = await res.json();
    typing.remove();
    appendAiMessage('bot', data.reply || 'Xin lỗi, mình chưa xử lý được câu hỏi này.', data.suggestions || []);
  } catch (err) {
    typing.remove();
    appendAiMessage('bot', '⚠️ Không thể kết nối máy chủ tư vấn. Vui lòng thử lại!');
  }
}

// Xóa lịch sử trò chuyện hiện tại
async function clearAiChat() {
  const fd = new FormData();
  fd.append('action', 'clear');
  await fetch(AI_API_URL, { method: 'POST', body: fd });
  document.getElementById('aiChatMessages').innerHTML = `
    <div class="ai-msg bot">
      <div class="ai-bubble">
        ✨ Đã làm mới cuộc trò chuyện! Bạn cần tư vấn tìm sân bóng, tra cứu giờ trống hay nhận mã giảm giá?
      </div>
    </div>`;
}
</script>
