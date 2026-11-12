<?php
/**
 * ============================================================================
 * FILE: includes/google_auth_widget.php
 * MÔ TẢ: Thành phần giao diện Nút "Đăng nhập bằng Google" chuẩn Google Identity
 *        Services (GIS) với GOOGLE_CLIENT_ID thật cho `login.php` & `register.php`.
 * ============================================================================
 */
require_once __DIR__ . '/../config/google_config.php';

$googleClientId = defined('GOOGLE_CLIENT_ID')
    ? GOOGLE_CLIENT_ID
    : '121784518063-m86pvv8cj7a0e3j69ki1c5ar48g1g8a7.apps.googleusercontent.com';

$isRegisterMode = (($googleBtnMode ?? 'login') === 'register');
$googleApiUrl = (function_exists('project_base') ? project_base() : '/quan-ly-san-bong/') . 'api/google_auth.php';
$sandboxAccounts = function_exists('get_google_sandbox_accounts') ? get_google_sandbox_accounts() : [];
?>
<!-- Thư viện chính thức Google Identity Services (GIS) -->
<script src="https://accounts.google.com/gsi/client" async defer></script>

<style>
  .g-auth-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 18px 0 14px;
    color: #64748b;
    font-size: 12.5px;
    font-weight: 600;
  }
  .g-auth-divider::before,
  .g-auth-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
  }
  .g-gsi-real-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 44px;
  }
  /* Popup dự phòng khi trình duyệt chặn 3rd-party cookie hoặc đang bật Sandbox */
  .g-chooser-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.62);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 16px;
  }
  .g-chooser-card {
    background: #ffffff;
    width: 100%;
    max-width: 430px;
    border-radius: 20px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.32);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    text-align: left;
  }
  .g-chooser-header {
    padding: 20px 24px 14px;
    border-bottom: 1px solid #f1f5f9;
    text-align: center;
  }
  .g-chooser-body {
    padding: 14px 20px 20px;
  }
  .g-acc-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    text-align: left;
    margin-bottom: 9px;
    transition: all 0.16s ease;
  }
  .g-acc-item:hover {
    background: #f0fdf4;
    border-color: #86efac;
  }
  .g-acc-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    border: 2px solid #dcfce7;
  }
  .g-acc-info {
    flex: 1;
    min-width: 0;
  }
  .g-acc-name {
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    display: block;
  }
  .g-acc-email {
    font-size: 12px;
    color: #475569;
    display: block;
  }
  .g-acc-badge {
    font-size: 10.5px;
    color: #15803d;
    font-weight: 600;
    display: block;
    margin-top: 2px;
  }
</style>

<!-- Vạch ngăn cách "Hoặc tiếp tục với" -->
<div class="g-auth-divider">
  <span>Hoặc tiếp tục với</span>
</div>

<!-- Nút Google Sign-In chính thức từ Google Identity Services (GIS) -->
<div class="g-gsi-real-wrap">
  <div id="g_id_onload"
       data-client_id="<?=htmlspecialchars($googleClientId, ENT_QUOTES, 'UTF-8')?>"
       data-context="<?=$isRegisterMode ? 'signup' : 'signin'?>"
       data-ux_mode="popup"
       data-callback="handleGoogleCredentialResponse"
       data-auto_prompt="false">
  </div>
  <div class="g_id_signin"
       data-type="standard"
       data-shape="rectangular"
       data-theme="outline"
       data-text="<?=$isRegisterMode ? 'signup_with' : 'signin_with'?>"
       data-size="large"
       data-logo_alignment="left"
       data-width="360">
  </div>
</div>

<div id="gAuthStatusAlert" style="display:none;margin-top:10px;padding:10px 12px;border-radius:10px;font-size:12.5px;font-weight:600"></div>

<?php if (defined('GOOGLE_SANDBOX_MODE') && GOOGLE_SANDBOX_MODE === true && !empty($sandboxAccounts)): ?>
<div class="g-chooser-backdrop" id="googleAccountChooserModal" onclick="if(event.target===this) closeGoogleChooserModal()">
  <div class="g-chooser-card">
    <div class="g-chooser-header">
      <h3 style="margin:0;font-size:18px;color:#0f172a">Chọn tài khoản Google</h3>
      <small style="color:#64748b;font-size:12px">để tiếp tục tới <b>Sân Bóng Pro</b></small>
    </div>
    <div class="g-chooser-body">
      <?php foreach ($sandboxAccounts as $sAcc): ?>
        <button type="button" class="g-acc-item"
                onclick='loginWithSandboxGoogle(<?=json_encode($sAcc, JSON_UNESCAPED_UNICODE)?>)'>
          <img class="g-acc-avatar" src="<?=htmlspecialchars($sAcc['avatar'], ENT_QUOTES, 'UTF-8')?>" alt="">
          <div class="g-acc-info">
            <span class="g-acc-name"><?=htmlspecialchars($sAcc['full_name'], ENT_QUOTES, 'UTF-8')?></span>
            <span class="g-acc-email"><?=htmlspecialchars($sAcc['email'], ENT_QUOTES, 'UTF-8')?></span>
            <span class="g-acc-badge">✔ <?=htmlspecialchars($sAcc['badge'], ENT_QUOTES, 'UTF-8')?></span>
          </div>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  const GOOGLE_AUTH_API_URL = <?=json_encode($googleApiUrl, JSON_UNESCAPED_SLASHES)?>;

  function showGoogleAuthStatus(msg, isError) {
    const box = document.getElementById('gAuthStatusAlert');
    if (!box) return;
    box.style.display = 'block';
    box.style.background = isError ? '#fef2f2' : '#f0fdf4';
    box.style.color = isError ? '#dc2626' : '#15803d';
    box.style.border = isError ? '1px solid #fecaca' : '1px solid #86efac';
    box.textContent = msg;
  }

  /**
   * Callback chính thức nhận JWT ID Token (`response.credential`) từ Google Identity Services
   */
  async function handleGoogleCredentialResponse(response) {
    if (!response || !response.credential) {
      showGoogleAuthStatus('Không nhận được mã xác thực từ Google.', true);
      return;
    }
    await sendGoogleAuthRequest({ credential: response.credential });
  }

  function openGoogleChooserModal() {
    const modal = document.getElementById('googleAccountChooserModal');
    if (modal) modal.style.display = 'flex';
  }

  function closeGoogleChooserModal() {
    const modal = document.getElementById('googleAccountChooserModal');
    if (modal) modal.style.display = 'none';
  }

  async function loginWithSandboxGoogle(acc) {
    closeGoogleChooserModal();
    await sendGoogleAuthRequest({
      sandbox: 1,
      google_id: acc.google_id,
      email: acc.email,
      full_name: acc.full_name,
      avatar: acc.avatar
    });
  }

  async function sendGoogleAuthRequest(payload) {
    showGoogleAuthStatus('⏳ Đang xác thực tài khoản Google và đồng bộ hồ sơ...', false);

    try {
      const res = await fetch(GOOGLE_AUTH_API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (data && data.success) {
        showGoogleAuthStatus('✔ ' + (data.message || 'Đăng nhập Google thành công! Đang chuyển hướng...'), false);
        window.location.href = data.redirect_url || 'index.php';
      } else {
        showGoogleAuthStatus('⚠️ ' + ((data && data.message) ? data.message : 'Xác thực Google thất bại.'), true);
      }
    } catch (err) {
      showGoogleAuthStatus('⚠️ Không thể kết nối tới máy chủ xác thực Google (' + GOOGLE_AUTH_API_URL + ').', true);
    }
  }
</script>
