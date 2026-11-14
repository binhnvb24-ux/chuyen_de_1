<?php
/**
 * ============================================================================
 * FILE: includes/chat_widget.php
 * MÔ TẢ: Widget Chat Kép 2 Tab (🤖 Trợ lý AI | 💬 Chat Chủ Sân) theo chuẩn
 *        Dynamic Daylight Sport Pro (#059669 / #16a34a, font 'Be Vietnam Pro').
 * TÍNH NĂNG:
 *   - Tab 1 (🤖 Trợ lý AI): Hỏi đáp thông minh tìm sân trống, mã giảm giá qua api/ai_chat.php.
 *   - Tab 2 (💬 Chat Chủ Sân):
 *       + Thanh "Chọn sân muốn liên hệ" (Dropdown #ownerChatPitchSelect + Dải Quick-Switch Pills).
 *       + Hiển thị hội thoại trực tiếp với Chủ sân của từng Cụm sân (10 cụm sân TP.HCM).
 *       + Hỗ trợ hiển thị Thẻ Đặt Sân Đính Kèm (Embedded Pitch Card) có nút "Đặt Ngay".
 *       + Tự động đồng bộ ngữ cảnh sân đang xem (field-detail.php / booking.php) & Polling 4 giây.
 *   - Kéo thả di chuyển tự do (Draggable Chat Head): Giữ chuột vào nút tròn 💬 hoặc thanh Header
 *     để kéo di chuyển khung chat tới bất kỳ vị trí nào trên màn hình (lưu vị trí vào localStorage).
 * ============================================================================
 */
$cw_base = function_exists('project_base') ? project_base() : '/quan-ly-san-bong/';
$cw_bot_name = function_exists('get_setting') ? (get_setting('ai_bot_name', 'Trợ lý AI Sân Bóng') ?: 'Trợ lý AI Sân Bóng') : 'Trợ lý AI Sân Bóng';
$cw_user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
$cw_uid  = $cw_user ? (int)$cw_user['id'] : 0;

$cw_pitches = function_exists('get_owner_chat_pitches_catalog') ? get_owner_chat_pitches_catalog($cw_uid) : [];
$cw_unread_total = ($cw_uid > 0 && function_exists('get_customer_unread_chat_count')) ? get_customer_unread_chat_count($cw_uid) : 0;

// Tự động phát hiện ngữ cảnh sân hiện tại nếu người dùng đang ở field-detail.php hoặc booking.php
$cw_context_fid = 1;
if (!empty($f['id'])) {
    $cw_context_fid = (int)$f['id'];
} elseif (!empty($_GET['field_id'])) {
    $cw_context_fid = (int)$_GET['field_id'];
} elseif (!empty($_GET['id']) && strpos($_SERVER['SCRIPT_NAME'] ?? '', 'field-detail.php') !== false) {
    $cw_context_fid = (int)$_GET['id'];
}
if ($cw_context_fid > 10 && function_exists('resolve_owner_chat_field')) {
    $resolvedCtx = resolve_owner_chat_field($cw_context_fid);
    if ($resolvedCtx && !empty($resolvedCtx['id'])) {
        $cw_context_fid = (int)$resolvedCtx['id'];
    }
}
$cw_active_pitch = $cw_pitches[0] ?? [
    'id'         => 1,
    'name'       => 'Sân Banh Chảo Lửa',
    'short_name' => 'Sân Chảo Lửa',
    'district'   => 'Tân Bình',
];
foreach ($cw_pitches as $pItem) {
    if ((int)$pItem['id'] === $cw_context_fid) {
        $cw_active_pitch = $pItem;
        break;
    }
}
?>
<!-- ==========================================================================
     1. GIAO DIỆN WIDGET CHAT KÉP (🤖 TRỢ LÝ AI | 💬 CHAT CHỦ SÂN)
     ========================================================================== -->
<div class="ai-chat-widget" id="aiChatWidget">
  <!-- Nút bấm nổi hình tròn mở khung Chat (Có thể kéo thả di chuyển khắp màn hình) -->
  <button class="ai-chat-fab" type="button" id="aiChatToggleBtn" onclick="toggleAiChat()" title="Bấm để mở Chat • Giữ chuột kéo để di chuyển vị trí">
    <span class="ai-fab-pulse"></span>
    <span class="ai-fab-icon">💬</span>
    <span class="cw-fab-unread" id="cwFabUnreadBadge" style="<?=$cw_unread_total > 0 ? 'display:inline-flex' : 'display:none'?>">
      <?=$cw_unread_total?>
    </span>
  </button>

  <!-- Cửa sổ Chat chính -->
  <div class="ai-chat-window" id="aiChatWindow">

    <!-- 1.1. Header & Thanh Chuyển Đổi 2 Tab (Dual-Tab Switcher) -->
    <div class="ai-chat-header">
      <div class="cw-top-brand-row" id="cwDragHandleHeader" title="Giữ chuột kéo thanh này để di chuyển cửa sổ Chat">
        <div class="ai-chat-brand">
          <div class="ai-avatar" id="cwHeaderAvatar">🤖</div>
          <div>
            <b id="cwHeaderTitle"><?=e($cw_bot_name)?></b>
            <small id="cwHeaderSubtitle">
              <span class="ai-online-dot"></span>
              <span id="cwHeaderSubText">Đang trực tuyến • Tư vấn thông minh</span>
            </small>
          </div>
        </div>
        <div class="ai-header-actions">
          <button type="button" id="cwResetPosBtn" title="Đưa nút Chat về góc mặc định" onclick="resetChatWidgetPosition()" style="display:none">📍</button>
          <button type="button" id="cwClearAiBtn" title="Làm mới đoạn chat AI" onclick="clearAiChat()">🗑</button>
          <button type="button" title="Thu gọn cửa sổ chat" onclick="toggleAiChat()">✕</button>
        </div>
      </div>

      <!-- Thanh Dual-Tab Switcher: 🤖 Trợ lý AI | 💬 Chat Chủ Sân -->
      <div class="cw-dual-tabs" role="tablist">
        <button type="button" class="cw-tab-btn active" id="cwTabBtnAi" onclick="switchChatWidgetMode('ai')">
          <span>🤖 Trợ lý AI</span>
        </button>
        <button type="button" class="cw-tab-btn" id="cwTabBtnOwner" onclick="switchChatWidgetMode('owner')">
          <span>💬 Chat Chủ Sân</span>
          <span class="cw-tab-badge" id="cwOwnerUnreadBadge" style="<?=$cw_unread_total > 0 ? 'display:inline-flex' : 'display:none'?>">
            <?=$cw_unread_total?>
          </span>
        </button>
      </div>
    </div>

    <!-- ======================================================================
         PANE 1: 🤖 TRỢ LÝ AI (GIỮ NGUYÊN 100% TÍNH NĂNG CŨ)
         ====================================================================== -->
    <div class="cw-pane active" id="cwPaneAi">
      <div class="ai-chat-messages" id="aiChatMessages">
        <div class="ai-msg bot">
          <div class="ai-bubble">
            👋 Xin chào <b><?=e($cw_user['full_name'] ?? 'Bạn')?></b>! Mình là <b><?=e($cw_bot_name)?></b>.<br>
            Tối nay hệ thống đang có khung giờ đẹp còn trống, gợi ý nhanh cho đội bạn:
            <div style="margin-top:10px;background:#ecfdf5;border:1.5px solid #86efac;border-radius:12px;padding:10px 12px">
              <b style="display:block;color:#065f46;font-size:13px">⚽ <?=e($cw_active_pitch['name'])?> • <?=e($cw_active_pitch['district'])?></b>
              <small style="color:#047857;font-weight:600;margin:3px 0 8px;display:block">🕒 18:00 - 19:30 • Giữ sân 10 phút VietQR</small>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="<?=$cw_base?>user/booking.php?field_id=<?=(int)$cw_active_pitch['id']?>" style="display:inline-block;background:#16a34a;color:#fff !important;font-weight:700;font-size:11.5px;padding:6px 11px;border-radius:8px;text-decoration:none">
                  + Đặt sân nhanh →
                </a>
                <button type="button" onclick="openOwnerChatWidget(<?=(int)$cw_active_pitch['id']?>)" style="border:1px solid #059669;background:#ffffff;color:#059669;font-weight:700;font-size:11.5px;padding:5px 10px;border-radius:8px;cursor:pointer;font-family:inherit">
                  💬 Nhắn Chủ sân này
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="ai-quick-prompts" id="aiQuickPrompts">
        <button type="button" onclick="sendQuickPrompt('Tư vấn sân 5 người giá rẻ')">⚽ Sân 5 giá rẻ</button>
        <button type="button" onclick="sendQuickPrompt('Sân nào còn giờ trống hôm nay?')">🕒 Giờ trống hôm nay</button>
        <button type="button" onclick="sendQuickPrompt('Cho mình xin mưertu ã giảm giá Voucher')">🎁 Mã Voucher</button>
        <button type="button" onclick="sendQuickPrompt('Kiểm tra đơn đặt sân của tôi')">📋 Đơn của tôi</button>
      </div>

      <form class="ai-chat-input-bar" onsubmit="handleAiSubmit(event)">
        <input type="text" id="aiChatInput" placeholder="Nhập câu hỏi cho AI (VD: tìm sân 7 ở Tân Bình)..." autocomplete="off">
        <button type="submit" id="aiChatSendBtn" title="Gửi câu hỏi">➤</button>
      </form>
    </div>

    <!-- ======================================================================
         PANE 2: 💬 CHAT TRỰC TIẾP VỚI CHỦ SÂN (CÓ BỘ CHỌN SÂN MUỐN LIÊN HỆ)
         ====================================================================== -->
    <div class="cw-pane" id="cwPaneOwner">

      <!-- 2.1. Khung "Chọn Sân Muốn Liên Hệ" (Pitch Selector Bar) -->
      <div class="cw-pitch-selector-bar">
        <div class="cw-ps-label-row">
          <span class="cw-ps-label">Chọn sân muốn liên hệ</span>
          <span class="cw-ps-online"><i class="cw-green-dot"></i> Trực tuyến 24/7</span>
        </div>

        <!-- Dropdown chọn cụm sân (#ownerChatPitchSelect) -->
        <div class="cw-select-wrap">
          <span class="cw-select-Dot">🟢</span>
          <select id="ownerChatPitchSelect" onchange="selectOwnerChatPitch(this.value)" aria-label="Chọn sân muốn liên hệ">
            <?php foreach ($cw_pitches as $pOpt): ?>
              <option value="<?=(int)$pOpt['id']?>" <?=(int)$pOpt['id'] === (int)$cw_active_pitch['id'] ? 'selected' : ''?>>
                🏟 Chọn sân liên hệ: <?=e($pOpt['name'])?> (<?=e($pOpt['district'])?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Dải Chip Chuyển Nhanh Sân (Quick-Switch Pills) -->
        <div class="cw-pitch-pills" id="ownerChatPitchPills">
          <?php foreach ($cw_pitches as $pPill):
              $isCur = ((int)$pPill['id'] === (int)$cw_active_pitch['id']);
          ?>
            <button type="button"
                    class="cw-pitch-pill <?=$isCur ? 'active' : ''?>"
                    data-fid="<?=(int)$pPill['id']?>"
                    onclick="selectOwnerChatPitch(<?=(int)$pPill['id']?>)">
              <span>⚽ <?=e($pPill['short_name'])?><?=$isCur ? ' (Đang chọn)' : ''?></span>
              <?php if (!empty($pPill['unread_count'])): ?>
                <i class="cw-pill-unread"><?=(int)$pPill['unread_count']?></i>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- 2.2. Khung Hội Thoại với Chủ Sân (#ownerChatMessages) -->
      <div class="owner-chat-messages" id="ownerChatMessages">
        <div class="cw-loading-state">⏳ Đang kết nối trực tiếp với Ban quản lý sân...</div>
      </div>

      <!-- 2.3. Dải Nút Gợi Ý Nhanh cho Khách (#ownerQuickPrompts) -->
      <div class="ai-quick-prompts" id="ownerQuickPrompts">
        <button type="button" onclick="sendOwnerQuickPrompt('Còn trống ca 19h không chủ sân?')">Còn trống ca 19h không chủ sân?</button>
        <button type="button" onclick="sendOwnerQuickPrompt('Xin ảnh mặt cỏ thực tế')">Xin ảnh mặt cỏ thực tế</button>
        <button type="button" onclick="sendOwnerQuickPrompt('Giữ sân giúp mình 10 phút')">Giữ sân giúp mình 10 phút</button>
        <button type="button" onclick="sendOwnerQuickPrompt('Cho đội mình xin bảng giá thuê cố định tháng')">Giá đặt cố định tháng</button>
      </div>

      <!-- 2.4. Thanh Nhập Tin Nhắn & Đính Kèm Thẻ Sân -->
      <form class="ai-chat-input-bar cw-owner-input-bar" onsubmit="handleOwnerChatSubmit(event)">
        <button type="button"
                class="cw-attach-btn"
                title="Đính kèm Thẻ hỏi đặt sân nhanh (18:00 - 19:30)"
                onclick="sendOwnerPitchInquiryCard()">
          📎
        </button>
        <input type="text"
               id="ownerChatInput"
               placeholder="Nhập tin nhắn gửi Chủ sân..."
               autocomplete="off">
        <button type="submit" id="ownerChatSendBtn" title="Gửi tin nhắn cho Chủ sân">➤</button>
      </form>
    </div>

  </div>
</div>

<!-- ==========================================================================
     2. CSS SCOPED CHO WIDGET CHAT KÉP (DYNAMIC DAYLIGHT SPORT PRO)
     ========================================================================== -->
<style>
.ai-chat-widget {
  position: fixed;
  right: 22px;
  bottom: 22px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  font-family: 'Be Vietnam Pro', system-ui, -apple-system, sans-serif;
  user-select: none;
}
.ai-chat-widget.cw-dragging,
.ai-chat-widget.cw-dragging .ai-chat-fab,
.ai-chat-widget.cw-dragging .cw-top-brand-row {
  cursor: grabbing !important;
  transition: none !important;
}
.ai-chat-fab {
  position: relative;
  display: grid;
  place-items: center;
  width: 60px;
  height: 60px;
  border: 2.5px solid #ffffff;
  border-radius: 50%;
  padding: 0;
  background: linear-gradient(135deg, #059669, #047857);
  color: #fff;
  cursor: grab;
  touch-action: none;
  box-shadow: 0 10px 28px rgba(5, 150, 105, 0.48);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.ai-chat-fab:hover {
  transform: translateY(-3px) scale(1.06);
  box-shadow: 0 14px 32px rgba(5, 150, 105, 0.6);
}
.ai-fab-icon {
  font-size: 26px;
  line-height: 1;
  display: grid;
  place-items: center;
}
.ai-fab-pulse {
  position: absolute;
  bottom: 2px;
  right: 2px;
  width: 13px;
  height: 13px;
  background: #4ade80;
  border: 2.5px solid #fff;
  border-radius: 50%;
}
.cw-fab-unread {
  position: absolute;
  top: -4px;
  right: -4px;
  background: #ef4444;
  color: #ffffff;
  font-size: 11px;
  font-weight: 800;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  align-items: center;
  justify-content: center;
  border: 2px solid #ffffff;
  box-shadow: 0 2px 6px rgba(239, 68, 68, 0.45);
}

.ai-chat-window {
  display: none;
  flex-direction: column;
  width: 405px;
  height: 605px;
  max-height: calc(100vh - 96px);
  margin-bottom: 12px;
  background: #ffffff;
  border-radius: 20px;
  border: 1.5px solid #a7f3d0;
  box-shadow: 0 22px 55px rgba(15, 23, 42, 0.25);
  overflow: hidden;
  animation: aiSlideUp 0.24s ease;
}
@keyframes aiSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.ai-chat-window.open {
  display: flex;
}

/* Header & Dual-Tab Switcher */
.ai-chat-header {
  background: linear-gradient(135deg, #064e3b, #059669);
  color: #fff;
  padding: 12px 14px 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.cw-top-brand-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  cursor: grab;
  touch-action: none;
}
.ai-chat-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.ai-avatar {
  width: 36px;
  height: 36px;
  border-radius: 11px;
  background: rgba(255,255,255,0.18);
  display: grid;
  place-items: center;
  font-size: 19px;
  flex-shrink: 0;
}
.ai-chat-brand b {
  display: block;
  font-size: 14px;
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ai-chat-brand small {
  color: #d1fae5;
  font-size: 11px;
  margin: 1px 0 0;
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ai-online-dot {
  width: 7px;
  height: 7px;
  background: #4ade80;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
  box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.25);
}
.ai-header-actions {
  display: flex;
  gap: 5px;
  flex-shrink: 0;
}
.ai-header-actions button {
  background: rgba(255,255,255,0.16);
  border: 0;
  color: #fff;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  display: grid;
  place-items: center;
}
.ai-header-actions button:hover {
  background: rgba(255,255,255,0.28);
}

/* Dual-Tab Switcher */
.cw-dual-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  background: rgba(6, 78, 59, 0.55);
  padding: 4px;
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.16);
}
.cw-tab-btn {
  border: 0;
  background: transparent;
  color: #d1fae5;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 700;
  padding: 7px 10px;
  border-radius: 9px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.18s ease;
}
.cw-tab-btn:hover {
  color: #ffffff;
  background: rgba(255, 255, 255, 0.1);
}
.cw-tab-btn.active {
  background: #059669;
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.18);
  border: 1px solid #34d399;
}
.cw-tab-badge {
  background: #ef4444;
  color: #fff;
  font-size: 10.5px;
  font-weight: 800;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  align-items: center;
  justify-content: center;
}

/* Panes */
.cw-pane {
  display: none;
  flex-direction: column;
  flex: 1;
  min-height: 0;
}
.cw-pane.active {
  display: flex;
}

/* Pitch Selector Bar (Khung Chọn Sân Muốn Liên Hệ) */
.cw-pitch-selector-bar {
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  padding: 9px 12px;
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.cw-ps-label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
}
.cw-ps-label {
  font-weight: 800;
  color: #065f46;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.cw-ps-online {
  color: #15803d;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.cw-green-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #16a34a;
  display: inline-block;
}
.cw-select-wrap {
  position: relative;
  display: flex;
  align-items: center;
  background: #ffffff;
  border: 1.5px solid #34d399;
  border-radius: 10px;
  padding: 0 8px;
  box-shadow: 0 2px 6px rgba(5, 150, 105, 0.08);
}
.cw-select-Dot {
  font-size: 11px;
  margin-right: 4px;
  flex-shrink: 0;
}
.cw-select-wrap select {
  width: 100%;
  border: 0 !important;
  background: transparent !important;
  padding: 7px 4px !important;
  font-size: 12.2px !important;
  font-weight: 700 !important;
  color: #064e3b !important;
  font-family: inherit;
  outline: none;
  cursor: pointer;
  margin: 0 !important;
}

/* Quick-Switch Pills (Dải Chip Chuyển Nhanh Sân) */
.cw-pitch-pills {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
  padding-bottom: 2px;
}
.cw-pitch-pills::-webkit-scrollbar {
  display: none;
}
.cw-pitch-pill {
  border: 1px solid #a7f3d0;
  background: #ffffff;
  color: #065f46;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 11.2px;
  font-weight: 700;
  white-space: nowrap;
  cursor: pointer;
  font-family: inherit;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.16s ease;
}
.cw-pitch-pill:hover {
  border-color: #059669;
  background: #dcfce7;
}
.cw-pitch-pill.active {
  background: #059669;
  color: #ffffff;
  border-color: #047857;
  box-shadow: 0 3px 8px rgba(5, 150, 105, 0.28);
}
.cw-pill-unread {
  font-style: normal;
  background: #ef4444;
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  padding: 0 5px;
  border-radius: 999px;
}

/* Messages Stream (AI & Owner) */
.ai-chat-messages,
.owner-chat-messages {
  flex: 1;
  padding: 13px;
  overflow-y: auto;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  gap: 11px;
}
.cw-loading-state {
  text-align: center;
  color: #64748b;
  font-size: 12.5px;
  padding: 28px 14px;
}

/* Bubbles */
.ai-msg {
  display: flex;
  flex-direction: column;
  max-width: 88%;
}
.ai-msg.user { align-self: flex-end; }
.ai-msg.bot { align-self: flex-start; }
.ai-bubble {
  padding: 10px 14px;
  border-radius: 14px;
  font-size: 13px;
  line-height: 1.52;
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

/* Owner Chat Message Items */
.oc-msg-row {
  display: flex;
  gap: 8px;
  max-width: 90%;
  align-items: flex-end;
}
.oc-msg-row.owner {
  align-self: flex-start;
}
.oc-msg-row.customer {
  align-self: flex-end;
  flex-direction: row-reverse;
}
.oc-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: linear-gradient(135deg, #059669, #047857);
  color: #fff;
  font-size: 13px;
  font-weight: 800;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
}
.oc-bubble-wrap {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.oc-sender-name {
  font-size: 11px;
  font-weight: 700;
  color: #047857;
  margin-left: 2px;
}
.oc-bubble {
  padding: 10px 13px;
  border-radius: 14px;
  font-size: 13px;
  line-height: 1.5;
  word-wrap: break-word;
}
.oc-msg-row.owner .oc-bubble {
  background: #f1f5f9;
  color: #0f172a;
  border: 1px solid #e2e8f0;
  border-bottom-left-radius: 4px;
}
.oc-msg-row.customer .oc-bubble {
  background: #059669;
  color: #ffffff;
  border-bottom-right-radius: 4px;
  box-shadow: 0 3px 10px rgba(5, 150, 105, 0.22);
}
.oc-meta {
  font-size: 10.5px;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 4px;
}
.oc-msg-row.customer .oc-meta {
  justify-content: flex-end;
  color: #059669;
  font-weight: 600;
}

/* Embedded Pitch Card (Thẻ Sân Đính Kèm Trong Tin Nhắn) */
.oc-pitch-card {
  margin-top: 8px;
  background: #ffffff;
  border: 1.5px solid #86efac;
  border-radius: 12px;
  padding: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(5, 150, 105, 0.1);
  color: #0f172a;
}
.oc-pc-info {
  flex: 1;
  min-width: 0;
}
.oc-pc-title {
  display: block;
  font-size: 12.8px;
  font-weight: 800;
  color: #064e3b;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.oc-pc-sub {
  display: block;
  font-size: 11.5px;
  font-weight: 700;
  color: #059669;
  margin-bottom: 7px;
}
.oc-pc-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #16a34a;
  color: #ffffff !important;
  font-size: 11.5px;
  font-weight: 800;
  padding: 5px 12px;
  border-radius: 8px;
  text-decoration: none;
  transition: background 0.15s;
}
.oc-pc-btn:hover {
  background: #15803d;
}
.oc-pc-thumb {
  width: 64px;
  height: 58px;
  border-radius: 9px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid #d1fae5;
}

/* AI suggestions */
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
.ai-sug-card b { color: #065f46; display: block; }
.ai-sug-card small { margin: 0; color: #047857; font-size: 11px; }
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

/* Quick prompts & Input bar */
.ai-quick-prompts {
  display: flex;
  gap: 6px;
  padding: 7px 11px;
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
  font-size: 11.2px;
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
  align-items: center;
  gap: 7px;
  padding: 9px 11px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}
.ai-chat-input-bar input {
  flex: 1;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  padding: 9px 14px;
  font-size: 12.8px;
  outline: none;
  margin: 0;
  font-family: inherit;
}
.ai-chat-input-bar input:focus {
  border-color: #059669;
  box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.14);
}
.cw-attach-btn {
  width: 34px !important;
  height: 34px !important;
  border-radius: 50% !important;
  border: 1px solid #cbd5e1 !important;
  background: #f8fafc !important;
  color: #334155 !important;
  cursor: pointer;
  font-size: 15px !important;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.cw-attach-btn:hover {
  border-color: #059669 !important;
  background: #ecfdf5 !important;
}
.ai-chat-input-bar button[type="submit"] {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 0;
  background: linear-gradient(135deg, #059669, #047857);
  color: #fff;
  cursor: pointer;
  font-size: 14px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
@media (max-width: 520px) {
  .ai-chat-widget { right: 10px; bottom: 10px; }
  .ai-chat-window { width: calc(100vw - 20px); height: 78vh; }
}
</style>

<!-- ==========================================================================
     3. LOGIC JAVASCRIPT ĐIỀU KHIỂN WIDGET CHAT KÉP (AI + CHỦ SÂN + POLLING 4S)
     ========================================================================== -->
<script>
const AI_API_URL        = <?=json_encode($cw_base . 'api/ai_chat.php')?>;
const OWNER_CHAT_API    = <?=json_encode($cw_base . 'api/owner_chat.php')?>;
const CW_BOT_NAME       = <?=json_encode($cw_bot_name, JSON_UNESCAPED_UNICODE)?>;
let cwCurrentMode       = 'ai'; // 'ai' | 'owner'
let cwSelectedFieldId   = <?=(int)$cw_active_pitch['id']?>;
let cwSelectedFieldName = <?=json_encode($cw_active_pitch['name'], JSON_UNESCAPED_UNICODE)?>;
let cwPitchesCatalog    = <?=json_encode($cw_pitches, JSON_UNESCAPED_UNICODE)?>;
let cwOwnerPollTimer    = null;
let cwLastMsgSignature  = '';

function escapeHtmlCw(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatAiMarkdown(text) {
  let safe = escapeHtmlCw(text);
  safe = safe.replace(/\*\*(.*?)\*\*/g, '<b>$1</b>');
  safe = safe.replace(/\*(.*?)\*/g, '<i>$1</i>');
  safe = safe.replace(/_(.*?)_/g, '<i>$1</i>');
  safe = safe.replace(/`(.*?)`/g, '<code style="background:#ecfdf5;color:#065f46;padding:1px 5px;border-radius:4px;font-weight:700">$1</code>');
  safe = safe.replace(/\n/g, '<br>');
  return safe;
}

let cwSuppressClickOnce = false;

// Bật / tắt cửa sổ Chat
function toggleAiChat() {
  if (cwSuppressClickOnce) {
    cwSuppressClickOnce = false;
    return;
  }
  const win = document.getElementById('aiChatWindow');
  if (!win) return;
  win.classList.toggle('open');
  if (win.classList.contains('open')) {
    clampChatWidgetToViewport();
    if (cwCurrentMode === 'owner') {
      loadCustomerOwnerThread(cwSelectedFieldId, true);
      startOwnerChatPolling();
      setTimeout(() => document.getElementById('ownerChatInput')?.focus(), 100);
    } else {
      setTimeout(() => document.getElementById('aiChatInput')?.focus(), 100);
    }
  } else {
    stopOwnerChatPolling();
    clampChatWidgetToViewport();
  }
}

// Kéo thả di chuyển nút tròn & cửa sổ Chat khắp màn hình (lưu vị trí vào localStorage)
function applyChatWidgetPosition(rightPx, bottomPx, save = true) {
  const widget = document.getElementById('aiChatWidget');
  const resetBtn = document.getElementById('cwResetPosBtn');
  if (!widget) return;

  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const clampedRight  = Math.max(8, Math.min(vw - 68, Math.round(rightPx)));
  const clampedBottom = Math.max(8, Math.min(vh - 68, Math.round(bottomPx)));

  widget.style.left   = 'auto';
  widget.style.top    = 'auto';
  widget.style.right  = clampedRight + 'px';
  widget.style.bottom = clampedBottom + 'px';

  // Tự động đổi hướng mở khung chat sang phải nếu kéo nút tròn sang nửa bên trái màn hình
  if (clampedRight > vw / 2) {
    widget.style.alignItems = 'flex-start';
  } else {
    widget.style.alignItems = 'flex-end';
  }

  // Tự động đảo vị trí nút & cửa sổ nếu kéo lên sát mép trên màn hình
  if (clampedBottom > vh - 520) {
    widget.style.flexDirection = 'column-reverse';
  } else {
    widget.style.flexDirection = 'column';
  }

  if (resetBtn) {
    resetBtn.style.display = (Math.abs(clampedRight - 22) > 10 || Math.abs(clampedBottom - 22) > 10) ? 'grid' : 'none';
  }

  if (save) {
    try {
      localStorage.setItem('sb_chat_widget_pos', JSON.stringify({ right: clampedRight, bottom: clampedBottom }));
    } catch (e) {}
  }
}

function clampChatWidgetToViewport() {
  const widget = document.getElementById('aiChatWidget');
  if (!widget) return;
  const curRight  = parseFloat(widget.style.right) || 22;
  const curBottom = parseFloat(widget.style.bottom) || 22;
  applyChatWidgetPosition(curRight, curBottom, false);
}

function resetChatWidgetPosition() {
  try { localStorage.removeItem('sb_chat_widget_pos'); } catch (e) {}
  applyChatWidgetPosition(22, 22, false);
}

(function initDraggableChatWidget() {
  const widget = document.getElementById('aiChatWidget');
  const fabBtn = document.getElementById('aiChatToggleBtn');
  const hdrBar = document.getElementById('cwDragHandleHeader');
  if (!widget) return;

  // Khôi phục vị trí đã lưu
  try {
    const saved = JSON.parse(localStorage.getItem('sb_chat_widget_pos') || 'null');
    if (saved && typeof saved.right === 'number' && typeof saved.bottom === 'number') {
      applyChatWidgetPosition(saved.right, saved.bottom, false);
    }
  } catch (e) {}

  let isDragging = false;
  let startX = 0, startY = 0;
  let startRight = 22, startBottom = 22;
  let movedDist = 0;

  function onPointerDown(e) {
    if (e.target.closest('.ai-header-actions')) return;
    if (e.type === 'mousedown' && e.button !== 0) return;

    const pt = e.touches ? e.touches[0] : e;
    isDragging  = true;
    movedDist   = 0;
    startX      = pt.clientX;
    startY      = pt.clientY;
    startRight  = parseFloat(widget.style.right) || 22;
    startBottom = parseFloat(widget.style.bottom) || 22;
    widget.classList.add('cw-dragging');
  }

  function onPointerMove(e) {
    if (!isDragging) return;
    const pt = e.touches ? e.touches[0] : e;
    const dx = pt.clientX - startX;
    const dy = pt.clientY - startY;
    movedDist = Math.hypot(dx, dy);

    if (movedDist > 5) {
      if (e.cancelable) e.preventDefault();
      applyChatWidgetPosition(startRight - dx, startBottom - dy, false);
    }
  }

  function onPointerUp() {
    if (!isDragging) return;
    isDragging = false;
    widget.classList.remove('cw-dragging');
    if (movedDist > 5) {
      cwSuppressClickOnce = true;
      const curRight  = parseFloat(widget.style.right) || 22;
      const curBottom = parseFloat(widget.style.bottom) || 22;
      applyChatWidgetPosition(curRight, curBottom, true);
      setTimeout(() => { cwSuppressClickOnce = false; }, 250);
    }
  }

  [fabBtn, hdrBar].forEach(el => {
    if (!el) return;
    el.addEventListener('mousedown', onPointerDown);
    el.addEventListener('touchstart', onPointerDown, { passive: true });
  });

  window.addEventListener('mousemove', onPointerMove, { passive: false });
  window.addEventListener('touchmove', onPointerMove, { passive: false });
  window.addEventListener('mouseup', onPointerUp);
  window.addEventListener('touchend', onPointerUp);
  window.addEventListener('resize', clampChatWidgetToViewport);
})();

// Chuyển đổi giữa Tab 1 (🤖 Trợ lý AI) và Tab 2 (💬 Chat Chủ Sân)
function switchChatWidgetMode(mode) {
  cwCurrentMode = (mode === 'owner') ? 'owner' : 'ai';
  const btnAi      = document.getElementById('cwTabBtnAi');
  const btnOwner   = document.getElementById('cwTabBtnOwner');
  const paneAi     = document.getElementById('cwPaneAi');
  const paneOwner  = document.getElementById('cwPaneOwner');
  const avatarEl   = document.getElementById('cwHeaderAvatar');
  const titleEl    = document.getElementById('cwHeaderTitle');
  const subTextEl  = document.getElementById('cwHeaderSubText');
  const clearAiBtn = document.getElementById('cwClearAiBtn');

  if (cwCurrentMode === 'owner') {
    btnAi?.classList.remove('active');
    btnOwner?.classList.add('active');
    paneAi?.classList.remove('active');
    paneOwner?.classList.add('active');
    if (avatarEl) avatarEl.textContent = '🏟';
    if (titleEl) titleEl.textContent = 'Chat Trực Tiếp Chủ Sân';
    if (subTextEl) subTextEl.textContent = '(BQL ' + cwSelectedFieldName + ' - Đang online)';
    if (clearAiBtn) clearAiBtn.style.display = 'none';

    loadCustomerOwnerThread(cwSelectedFieldId, true);
    startOwnerChatPolling();
    setTimeout(() => document.getElementById('ownerChatInput')?.focus(), 80);
  } else {
    btnOwner?.classList.remove('active');
    btnAi?.classList.add('active');
    paneOwner?.classList.remove('active');
    paneAi?.classList.add('active');
    if (avatarEl) avatarEl.textContent = '🤖';
    if (titleEl) titleEl.textContent = CW_BOT_NAME;
    if (subTextEl) subTextEl.textContent = 'Đang trực tuyến • Tư vấn thông minh';
    if (clearAiBtn) clearAiBtn.style.display = 'grid';

    stopOwnerChatPolling();
    setTimeout(() => document.getElementById('aiChatInput')?.focus(), 80);
  }
}

// Hàm toàn cục: Mở thẳng Tab "💬 Chat Chủ Sân" từ nút trên thẻ sân hoặc trang chi tiết sân
function openOwnerChatWidget(fieldId, prefillText) {
  const win = document.getElementById('aiChatWindow');
  if (win && !win.classList.contains('open')) {
    win.classList.add('open');
  }
  if (fieldId && parseInt(fieldId, 10) > 0) {
    cwSelectedFieldId = parseInt(fieldId, 10);
  }
  switchChatWidgetMode('owner');
  selectOwnerChatPitch(cwSelectedFieldId);
  if (prefillText) {
    const inp = document.getElementById('ownerChatInput');
    if (inp) {
      inp.value = prefillText;
      inp.focus();
    }
  }
}

// Khi khách chọn sân trên Dropdown hoặc bấm vào Chip sân (Quick-Switch Pills)
function selectOwnerChatPitch(fieldId) {
  const fid = parseInt(fieldId, 10) || 1;
  cwSelectedFieldId = fid;

  const sel = document.getElementById('ownerChatPitchSelect');
  if (sel && String(sel.value) !== String(fid)) {
    sel.value = String(fid);
  }

  // Cập nhật trạng thái active trên dải Quick-Switch Pills
  const pillsWrap = document.getElementById('ownerChatPitchPills');
  if (pillsWrap) {
    pillsWrap.querySelectorAll('.cw-pitch-pill').forEach(btn => {
      const bFid = parseInt(btn.getAttribute('data-fid'), 10);
      const pObj = cwPitchesCatalog.find(p => parseInt(p.id, 10) === bFid);
      const shortLbl = pObj ? pObj.short_name : ('Sân #' + bFid);
      const isCur = (bFid === fid);
      btn.classList.toggle('active', isCur);
      const span = btn.querySelector('span');
      if (span) {
        span.textContent = '⚽ ' + shortLbl + (isCur ? ' (Đang chọn)' : '');
      }
      if (isCur) {
        btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      }
    });
  }

  const found = cwPitchesCatalog.find(p => parseInt(p.id, 10) === fid);
  if (found) {
    cwSelectedFieldName = found.name;
    const subTextEl = document.getElementById('cwHeaderSubText');
    if (subTextEl && cwCurrentMode === 'owner') {
      subTextEl.textContent = '(BQL ' + found.name + ' - Đang online)';
    }
  }

  cwLastMsgSignature = '';
  loadCustomerOwnerThread(fid, true);
}

// Render danh sách tin nhắn giữa Khách và Chủ sân (kèm Thẻ Đặt Sân Đính Kèm)
function renderOwnerChatMessages(messages, fieldData) {
  const box = document.getElementById('ownerChatMessages');
  if (!box) return;

  const sig = JSON.stringify((messages || []).map(m => [m.id, m.is_read, m.message]));
  if (sig === cwLastMsgSignature) {
    return;
  }
  cwLastMsgSignature = sig;

  if (!messages || messages.length === 0) {
    box.innerHTML = '<div class="cw-loading-state">Chưa có tin nhắn nào. Hãy gửi lời chào tới Chủ sân!</div>';
    return;
  }

  const ownerLabel = (fieldData && fieldData.owner_name) ? fieldData.owner_name : ('Chủ sân ' + cwSelectedFieldName);
  let html = '';

  messages.forEach(m => {
    const isCustomer = (m.sender_role === 'CUSTOMER');
    let cardHtml = '';
    if (m.pitch_card) {
      const c = m.pitch_card;
      cardHtml = `
        <div class="oc-pitch-card">
          <div class="oc-pc-info">
            <b class="oc-pc-title">🏟 ${escapeHtmlCw(c.name)}</b>
            <span class="oc-pc-sub">${escapeHtmlCw(c.pitch_code)} (${escapeHtmlCw(c.time_slot)}) • ${escapeHtmlCw(c.price)}</span>
            <a class="oc-pc-btn" href="${escapeHtmlCw(c.book_url)}">⚡ Đặt Ngay →</a>
          </div>
          <img class="oc-pc-thumb" src="${escapeHtmlCw(c.image)}" alt="${escapeHtmlCw(c.name)}" onerror="this.style.display='none'">
        </div>
      `;
    }

    if (isCustomer) {
      const readTxt = m.is_read ? '✓✓ Đã xem' : '✓✓ Đã gửi';
      html += `
        <div class="oc-msg-row customer">
          <div class="oc-bubble-wrap">
            <div class="oc-bubble">
              <div>${formatAiMarkdown(m.message)}</div>
              ${cardHtml}
            </div>
            <div class="oc-meta">
              <span>${escapeHtmlCw(m.time_label)}</span>
              <span>${readTxt}</span>
            </div>
          </div>
        </div>
      `;
    } else {
      html += `
        <div class="oc-msg-row owner">
          <div class="oc-avatar" title="${escapeHtmlCw(ownerLabel)}">🏟</div>
          <div class="oc-bubble-wrap">
            <span class="oc-sender-name">${escapeHtmlCw(ownerLabel)}</span>
            <div class="oc-bubble">
              <div>${formatAiMarkdown(m.message)}</div>
              ${cardHtml}
            </div>
            <div class="oc-meta">
              <span>${escapeHtmlCw(m.time_label)} • Đang trực tuyến</span>
            </div>
          </div>
        </div>
      `;
    }
  });

  box.innerHTML = html;
  box.scrollTop = box.scrollHeight;
}

// Cập nhật huy hiệu tin nhắn chưa đọc
function updateCustomerUnreadBadges(totalUnread, pitchesList) {
  const count = parseInt(totalUnread, 10) || 0;
  const fabBadge = document.getElementById('cwFabUnreadBadge');
  const tabBadge = document.getElementById('cwOwnerUnreadBadge');

  if (fabBadge) {
    fabBadge.style.display = count > 0 ? 'inline-flex' : 'none';
    fabBadge.textContent = count;
  }
  if (tabBadge) {
    tabBadge.style.display = count > 0 ? 'inline-flex' : 'none';
    tabBadge.textContent = count;
  }
  if (Array.isArray(pitchesList) && pitchesList.length > 0) {
    cwPitchesCatalog = pitchesList;
  }
}

// Tải luồng tin nhắn của sân đang chọn từ api/owner_chat.php
async function loadCustomerOwnerThread(fieldId, showLoading = false) {
  const fid = parseInt(fieldId || cwSelectedFieldId, 10) || 1;
  const box = document.getElementById('ownerChatMessages');
  if (showLoading && box && !cwLastMsgSignature) {
    box.innerHTML = '<div class="cw-loading-state">⏳ Đang tải cuộc trò chuyện với BQL sân...</div>';
  }

  try {
    const res = await fetch(OWNER_CHAT_API + '?action=load_customer_thread&field_id=' + encodeURIComponent(fid));
    const data = await res.json();
    if (!data || !data.ok) return;

    if (data.field) {
      cwSelectedFieldId = parseInt(data.field.id, 10);
      cwSelectedFieldName = data.field.name;
      const subTextEl = document.getElementById('cwHeaderSubText');
      if (subTextEl && cwCurrentMode === 'owner') {
        subTextEl.textContent = '(' + data.field.bql_title + ')';
      }
      const sel = document.getElementById('ownerChatPitchSelect');
      if (sel && String(sel.value) !== String(cwSelectedFieldId)) {
        sel.value = String(cwSelectedFieldId);
      }
    }

    updateCustomerUnreadBadges(data.total_unread || 0, data.pitches || []);
    renderOwnerChatMessages(data.messages || [], data.field || null);
  } catch (err) {
    // Giữ nguyên khung chat nếu mạng chập chờn
  }
}

// Gửi tin nhắn của Khách cho Chủ sân
async function handleOwnerChatSubmit(e, customText = null, attachCard = false) {
  if (e && e.preventDefault) e.preventDefault();
  const inp = document.getElementById('ownerChatInput');
  const msg = customText !== null ? customText : (inp ? inp.value.trim() : '');
  if (!msg && !attachCard) return;
  if (inp && customText === null) inp.value = '';

  try {
    const res = await fetch(OWNER_CHAT_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'send_customer_message',
        field_id: cwSelectedFieldId,
        message: msg,
        attach_pitch_card: attachCard,
        time_slot: '18:00 - 19:30'
      })
    });
    const data = await res.json();

    if (data && data.require_login) {
      const box = document.getElementById('ownerChatMessages');
      if (box) {
        const notice = document.createElement('div');
        notice.className = 'oc-msg-row owner';
        notice.innerHTML = `
          <div class="oc-avatar">🔒</div>
          <div class="oc-bubble-wrap">
            <span class="oc-sender-name">Hệ thống Sân Bóng Pro</span>
            <div class="oc-bubble" style="background:#fefce8;border-color:#fde047">
              <div>${escapeHtmlCw(data.message)}</div>
              <div style="margin-top:8px">
                <a class="oc-pc-btn" href="${escapeHtmlCw(data.login_url || 'login.php')}">🔑 Đăng nhập ngay →</a>
              </div>
            </div>
          </div>
        `;
        box.appendChild(notice);
        box.scrollTop = box.scrollHeight;
      }
      return;
    }

    if (data && data.ok) {
      cwLastMsgSignature = '';
      await loadCustomerOwnerThread(cwSelectedFieldId, false);
    }
  } catch (err) {}
}

function sendOwnerQuickPrompt(text) {
  handleOwnerChatSubmit(null, text, false);
}

function sendOwnerPitchInquiryCard() {
  handleOwnerChatSubmit(null, 'Chủ sân ơi, cho đội mình hỏi đặt sân khung giờ 18:00 - 19:30 tối nay còn trống không ạ?', true);
}

function startOwnerChatPolling() {
  stopOwnerChatPolling();
  cwOwnerPollTimer = setInterval(() => {
    const win = document.getElementById('aiChatWindow');
    if (win && win.classList.contains('open') && cwCurrentMode === 'owner') {
      loadCustomerOwnerThread(cwSelectedFieldId, false);
    }
  }, 4000);
}

function stopOwnerChatPolling() {
  if (cwOwnerPollTimer) {
    clearInterval(cwOwnerPollTimer);
    cwOwnerPollTimer = null;
  }
}

// ============================================================================
// GIỮ NGUYÊN CÁC HÀM CHAT TRỢ LÝ AI (TAB 1)
// ============================================================================
function appendAiMessage(role, text, suggestions = []) {
  const box = document.getElementById('aiChatMessages');
  if (!box) return;
  const wrap = document.createElement('div');
  wrap.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');

  let html = '<div class="ai-bubble">' + formatAiMarkdown(text);
  if (suggestions && suggestions.length > 0) {
    html += '<div class="ai-sug-list">';
    suggestions.forEach(s => {
      html += `<div class="ai-sug-card">
        <div><b>⚽ ${escapeHtmlCw(s.name)}</b><small>Sân ${escapeHtmlCw(s.type)} người • ${escapeHtmlCw(s.price)}/h</small></div>
        <a class="ai-sug-btn" href="${escapeHtmlCw(s.url)}">Đặt ngay →</a>
      </div>`;
    });
    html += '</div>';
  }
  html += '</div>';
  wrap.innerHTML = html;
  box.appendChild(wrap);
  box.scrollTop = box.scrollHeight;
}

function sendQuickPrompt(text) {
  const inp = document.getElementById('aiChatInput');
  if (!inp) return;
  inp.value = text;
  handleAiSubmit(new Event('submit'));
}

async function handleAiSubmit(e) {
  if (e && e.preventDefault) e.preventDefault();
  const inp = document.getElementById('aiChatInput');
  const msg = inp ? inp.value.trim() : '';
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

async function clearAiChat() {
  const fd = new FormData();
  fd.append('action', 'clear');
  await fetch(AI_API_URL, { method: 'POST', body: fd });
  const box = document.getElementById('aiChatMessages');
  if (box) {
    box.innerHTML = `
      <div class="ai-msg bot">
        <div class="ai-bubble">
          ✨ Đã làm mới cuộc trò chuyện! Bạn cần tư vấn tìm sân bóng, tra cứu giờ trống hay nhắn trực tiếp cho Chủ sân?
        </div>
      </div>`;
  }
}
</script>
