<?php
/**
 * ============================================================================
 * FILE: admin/users.php
 * MÔ TẢ: Module 1 - Quản lý Tài khoản Đa vai trò (CUSTOMER / PITCH_OWNER / ADMIN),
 *        Trạng thái tài khoản (ACTIVE / WARNING / BANNED), Ví nội bộ & Điểm Uy Tín.
 * CHỨC NĂNG:
 *   - Lọc người dùng theo Vai trò (CUSTOMER, PITCH_OWNER, ADMIN) và Trạng thái.
 *   - Khóa / Mở khóa tài khoản vi phạm (BANNED <-> ACTIVE).
 *   - Cấp / Hạ quyền tài khoản (CUSTOMER <-> PITCH_OWNER <-> ADMIN).
 *   - Điều chỉnh Điểm Uy Tín (+/- điểm) và Số dư Ví nội bộ kèm Lý do đối soát
 *     (VD: "Chủ sân báo khách bùng kèo", "Hoàn điểm do sự cố thời tiết").
 *   - Xem lịch sử đặt sân & Nhật ký biến động Ví / Điểm uy tín (`user_logs`).
 * ============================================================================
 */

require_once '../includes/auth.php';
$adm = require_admin();

// ----------------------------------------------------------------------------
// 1. XỬ LÝ CÁC THAO TÁC QUẢN TRỊ TÀI KHOẢN & ĐIỂM UY TÍN (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id <= 0) {
        flash('err', 'Tài khoản không hợp lệ.');
        header('Location: users.php');
        exit;
    }

    // 1.1. Điều chỉnh Điểm Uy Tín (+/-) và/hoặc Số dư Ví nội bộ kèm lý do
    if ($action === 'adjust_reputation') {
        $repDelta    = (int)($_POST['reputation_delta'] ?? 0);
        $walletDelta = (float)($_POST['wallet_delta'] ?? 0);
        $reason      = trim($_POST['reason'] ?? '');

        if (($repDelta === 0 && abs($walletDelta) < 0.01) || $reason === '') {
            flash('err', 'Vui lòng nhập mức điểm/số dư cần điều chỉnh (khác 0) và ghi rõ lý do.');
        } else {
            $res = record_wallet_and_reputation(
                $id,
                'ADMIN_ADJUST',
                $walletDelta,
                $repDelta,
                0,
                "Admin điều chỉnh: {$reason}",
                null,
                (int)$adm['id']
            );
            if ($res !== false) {
                $msgParts = [];
                if ($repDelta !== 0) {
                    $msgParts[] = ($repDelta > 0 ? "+{$repDelta}" : (string)$repDelta) . " Điểm Uy Tín (Mới: {$res['reputation_score']}/100)";
                }
                if (abs($walletDelta) >= 0.01) {
                    $msgParts[] = ($walletDelta > 0 ? '+' : '-') . money(abs($walletDelta)) . " Ví nội bộ";
                }
                notify($id, "⚖️ Quản trị viên đã cập nhật tài khoản của bạn (" . implode(', ', $msgParts) . "). Lý do: {$reason}");
                flash('ok', "Đã cập nhật thành công: " . implode(' • ', $msgParts));
            } else {
                flash('err', 'Không thể điều chỉnh: tài khoản không tồn tại.');
            }
        }
        header('Location: users.php?view=' . $id);
        exit;
    }

    // Không cho phép Admin tự khóa hoặc tự hạ quyền của chính mình
    if ($id === (int)$adm['id']) {
        flash('err', 'Không thể tự thay đổi trạng thái hoặc quyền của chính tài khoản đang đăng nhập.');
        header('Location: users.php');
        exit;
    }

    // 1.2. Khóa / Mở khóa tài khoản (ACTIVE <-> BANNED, đồng bộ cột status active <-> locked)
    if ($action === 'toggle') {
        $stU = $pdo->prepare("SELECT status, account_status, reputation_score FROM users WHERE id = ?");
        $stU->execute([$id]);
        $targetU = $stU->fetch();

        if ($targetU) {
            $isCurrentlyBanned = ($targetU['status'] === 'locked' || ($targetU['account_status'] ?? '') === 'BANNED');
            if ($isCurrentlyBanned) {
                $newLegacyStatus = 'active';
                $newAccStatus    = ((int)($targetU['reputation_score'] ?? 100) < 80) ? 'WARNING' : 'ACTIVE';
                $msg             = '🔓 Đã mở khóa tài khoản thành công.';
            } else {
                $newLegacyStatus = 'locked';
                $newAccStatus    = 'BANNED';
                $msg             = '🔒 Đã khóa (BANNED) tài khoản vi phạm.';
            }
            $pdo->prepare("UPDATE users SET status = ?, account_status = ? WHERE id = ?")
                ->execute([$newLegacyStatus, $newAccStatus, $id]);
            flash('ok', $msg);
        }
    }
    // 1.3. Thay đổi vai trò tài khoản (CUSTOMER / PITCH_OWNER / ADMIN)
    elseif ($action === 'set_role') {
        $newRoleCode = strtoupper(trim($_POST['account_role'] ?? 'CUSTOMER'));
        if (in_array($newRoleCode, ['CUSTOMER', 'PITCH_OWNER', 'ADMIN'], true)) {
            $legacyRole = ($newRoleCode === 'ADMIN') ? 'admin' : (($newRoleCode === 'PITCH_OWNER') ? 'pitch_owner' : 'user');
            $partnerSt  = ($newRoleCode === 'PITCH_OWNER') ? 'APPROVED' : 'NONE';
            $pdo->prepare("UPDATE users SET role = ?, account_role = ?, partner_status = IF(? = 'PITCH_OWNER', 'APPROVED', partner_status) WHERE id = ?")
                ->execute([$legacyRole, $newRoleCode, $newRoleCode, $id]);
            flash('ok', "Đã cập nhật vai trò tài khoản thành {$newRoleCode}.");
        }
    }
    // 1.4. Giữ tương thích với action='role' cũ
    elseif ($action === 'role') {
        $pdo->prepare("
            UPDATE users
            SET role = IF(role = 'admin', 'user', 'admin'),
                account_role = IF(role = 'admin', 'CUSTOMER', 'ADMIN')
            WHERE id = ?
        ")->execute([$id]);
        flash('ok', 'Đã cập nhật phân quyền cho tài khoản.');
    }

    header('Location: users.php');
    exit;
}

// ----------------------------------------------------------------------------
// 2. TRUY VẤN LỌC NGƯỜI DÙNG THEO VAI TRÒ & TRẠNG THÁI
// ----------------------------------------------------------------------------
$q            = trim($_GET['q'] ?? '');
$roleFilter   = strtoupper(trim($_GET['role_filter'] ?? 'ALL'));
$statusFilter = strtoupper(trim($_GET['status_filter'] ?? 'ALL'));

$sql = "
    SELECT
        u.*,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.id) AS n,
        (SELECT COUNT(*) FROM fields WHERE owner_id = u.id) AS owned_fields_count
    FROM users u
    WHERE (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
";
$params = ["%$q%", "%$q%", "%$q%", "%$q%"];

if (in_array($roleFilter, ['CUSTOMER', 'PITCH_OWNER', 'ADMIN'], true)) {
    $sql .= " AND u.account_role = ?";
    $params[] = $roleFilter;
}
if (in_array($statusFilter, ['ACTIVE', 'WARNING', 'BANNED'], true)) {
    $sql .= " AND u.account_status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY u.id ASC";
$s = $pdo->prepare($sql);
$s->execute($params);
$rows = $s->fetchAll();

// 2.2. Xem chi tiết lịch sử đặt sân, điều chỉnh Điểm uy tín & Nhật ký biến động (?view=ID)
$hist     = [];
$userLogs = [];
$hu       = null;

if (isset($_GET['view'])) {
    $view_id = (int)$_GET['view'];
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$view_id]);
    $hu = $s->fetch();

    if ($hu) {
        $s = $pdo->prepare("
            SELECT b.*, f.name
            FROM bookings b
            JOIN fields f ON f.id = b.field_id
            WHERE b.user_id = ?
            ORDER BY b.booking_date DESC
            LIMIT 25
        ");
        $s->execute([$hu['id']]);
        $hist = $s->fetchAll();

        $sLog = $pdo->prepare("
            SELECT * FROM user_logs
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 25
        ");
        $sLog->execute([$hu['id']]);
        $userLogs = $sLog->fetchAll();
    }
}

$lbl = [
    'pending'   => 'Chờ xác nhận',
    'confirmed' => 'Đã xác nhận',
    'cancelled' => 'Đã hủy',
    'completed' => 'Hoàn thành'
];

$page  = 'users';
$title = 'Quản lý Tài khoản Đa vai trò & Điểm Uy Tín';
include '../includes/admin_header.php';
?>

<!-- 3. THANH TÌM KIẾM & BỘ LỌC VAI TRÒ / TRẠNG THÁI -->
<div class="box" style="margin-bottom:16px;padding:16px">
  <form method="get" style="display:grid;grid-template-columns:1.5fr 1fr 1fr auto;gap:10px;align-items:center">
    <input
      name="q"
      placeholder="Tìm username, họ tên, SĐT hoặc email..."
      value="<?=e($q)?>"
      style="margin:0"
    >
    <select name="role_filter" style="margin:0" onchange="this.form.submit()">
      <option value="ALL" <?=$roleFilter === 'ALL' ? 'selected' : ''?>>👥 Tất cả vai trò (Role)</option>
      <option value="CUSTOMER" <?=$roleFilter === 'CUSTOMER' ? 'selected' : ''?>>⚽ Khách hàng (CUSTOMER)</option>
      <option value="PITCH_OWNER" <?=$roleFilter === 'PITCH_OWNER' ? 'selected' : ''?>>🏟 Chủ sân đối tác (PITCH_OWNER)</option>
      <option value="ADMIN" <?=$roleFilter === 'ADMIN' ? 'selected' : ''?>>🛡 Quản trị viên (ADMIN)</option>
    </select>
    <select name="status_filter" style="margin:0" onchange="this.form.submit()">
      <option value="ALL" <?=$statusFilter === 'ALL' ? 'selected' : ''?>>⚡ Tất cả trạng thái</option>
      <option value="ACTIVE" <?=$statusFilter === 'ACTIVE' ? 'selected' : ''?>>🟢 Hoạt động tốt (ACTIVE)</option>
      <option value="WARNING" <?=$statusFilter === 'WARNING' ? 'selected' : ''?>>🟠 Cảnh báo uy tín (WARNING)</option>
      <option value="BANNED" <?=$statusFilter === 'BANNED' ? 'selected' : ''?>>🔴 Đã khóa (BANNED)</option>
    </select>
    <button class="btn" type="submit">🔍 Lọc</button>
  </form>
</div>

<!-- 4. HỘP ĐIỀU CHỈNH ĐIỂM UY TÍN / VÍ NỘI BỘ & LỊCH SỬ CHI TIẾT CỦA TÀI KHOẢN (?view=ID) -->
<?php if ($hu):
    $huRepTier = get_reputation_tier((int)($hu['reputation_score'] ?? 100));
?>
  <div class="box" style="margin-bottom:18px;border:2px solid #10b981">
    <div class="bh">
      <div>
        <b>⚖️ Quản trị Hồ sơ & Điểm Uy Tín: <?=e($hu['full_name'])?> (@<?=e($hu['username'])?> • SĐT: <?=e($hu['phone'])?>)</b>
      </div>
      <a href="users.php" class="btn sm red">✕ Đóng</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:18px;align-items:start;margin-bottom:16px">
      <!-- Form Cộng / Trừ Điểm Uy Tín & Số dư Ví -->
      <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.3);border-radius:12px;padding:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <div>
            <small style="margin:0">ĐIỂM UY TÍN HIỆN TẠI</small>
            <b style="font-size:24px;color:<?=$huRepTier['color']?>"><?=(int)($hu['reputation_score'] ?? 100)?> / 100</b>
            <span class="tag <?=$huRepTier['badge']?>"><?=$huRepTier['label']?></span>
          </div>
          <div style="text-align:right">
            <small style="margin:0">SỐ DƯ VÍ NỘI BỘ</small>
            <b style="font-size:20px;color:#10b981"><?=money($hu['wallet_balance'] ?? 0)?></b>
          </div>
        </div>

        <form method="post" class="form">
          <?=csrf_field()?>
          <input type="hidden" name="id" value="<?=$hu['id']?>">
          <input type="hidden" name="action" value="adjust_reputation">

          <div class="two" style="margin:0">
            <div>
              <label>Cộng / Trừ Điểm Uy Tín (+/-)</label>
              <input type="number" name="reputation_delta" value="0" min="-100" max="100" placeholder="VD: -15 hoặc +10">
            </div>
            <div>
              <label>Cộng / Trừ Ví Nội Bộ (VNĐ)</label>
              <input type="number" name="wallet_delta" value="0" step="10000" placeholder="VD: 100000 hoặc -50000">
            </div>
          </div>

          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
            <button type="button" class="btn sm gray" onclick="this.form.reputation_delta.value=-15;this.form.reason.value='Chủ sân báo khách bùng kèo / hủy sát giờ không báo trước'">-15đ (Bùng kèo)</button>
            <button type="button" class="btn sm gray" onclick="this.form.reputation_delta.value=10;this.form.reason.value='Hoàn điểm uy tín do sự cố thời tiết / sân bảo trì'">+10đ (Hoàn điểm)</button>
            <button type="button" class="btn sm gray" onclick="this.form.reputation_delta.value=5;this.form.reason.value='Thưởng khách hàng đặt sân văn minh, đúng giờ'">+5đ (Thưởng uy tín)</button>
          </div>

          <label>Lý do điều chỉnh (Bắt buộc ghi nhật ký đối soát) *</label>
          <input name="reason" required placeholder="Nhập lý do cộng/trừ điểm hoặc số dư ví...">

          <button type="submit" class="btn" style="width:100%">💾 Lưu Điều Chỉnh Điểm Uy Tín & Ví</button>
        </form>
      </div>

      <!-- Bảng Nhật ký biến động Ví & Điểm uy tín (user_logs) -->
      <div>
        <b style="display:block;margin-bottom:8px">📜 Nhật ký Biến động Điểm Uy Tín & Ví nội bộ (<?=count($userLogs)?>)</b>
        <?php if (!$userLogs): ?>
          <div class="empty">Chưa có biến động Điểm uy tín hoặc Ví nội bộ nào.</div>
        <?php else: ?>
          <div class="tw" style="max-height:260px;overflow-y:auto">
            <table>
              <tr>
                <th>Thời gian</th>
                <th>Loại</th>
                <th>Điểm UT</th>
                <th>Biến động Ví</th>
                <th>Lý do</th>
              </tr>
              <?php foreach ($userLogs as $lg): ?>
                <tr>
                  <td><small><?=date('d/m H:i', strtotime($lg['created_at']))?></small></td>
                  <td><span class="tag blue" style="font-size:10.5px"><?=e($lg['action_type'])?></span></td>
                  <td>
                    <?php if ((int)$lg['reputation_delta'] !== 0): ?>
                      <b style="color:<?=(int)$lg['reputation_delta'] > 0 ? '#10b981' : '#ef4444'?>">
                        <?=(int)$lg['reputation_delta'] > 0 ? '+' . (int)$lg['reputation_delta'] : (int)$lg['reputation_delta']?>đ
                      </b>
                    <?php else: ?>
                      <small>-</small>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (abs((float)$lg['wallet_delta']) >= 0.01): ?>
                      <b style="color:<?=(float)$lg['wallet_delta'] > 0 ? '#10b981' : '#ef4444'?>">
                        <?=(float)$lg['wallet_delta'] > 0 ? '+' : '-'?><?=money(abs((float)$lg['wallet_delta']))?>
                      </b>
                    <?php else: ?>
                      <small>-</small>
                    <?php endif; ?>
                  </td>
                  <td><small><?=e($lg['reason'])?></small></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Bảng lịch sử đặt sân của tài khoản đang xem -->
    <b style="display:block;margin-bottom:8px">📋 Lịch sử Đặt sân của khách hàng (<?=count($hist)?> đơn gần nhất)</b>
    <?php if (!$hist): ?>
      <div class="empty">Khách hàng này chưa có lịch đặt sân nào.</div>
    <?php else: ?>
      <div class="tw">
        <table>
          <tr>
            <th>Mã đơn</th>
            <th>Sân bóng</th>
            <th>Ngày thi đấu</th>
            <th>Khung giờ</th>
            <th>Tổng tiền</th>
            <th>Trạng thái</th>
          </tr>
          <?php foreach ($hist as $h): ?>
            <tr>
              <td><b>#<?=$h['id']?></b></td>
              <td><b><?=e($h['name'])?></b></td>
              <td><?=date('d/m/Y', strtotime($h['booking_date']))?></td>
              <td><?=substr($h['start_time'], 0, 5)?> - <?=substr($h['end_time'], 0, 5)?></td>
              <td><?=money($h['total_price'])?></td>
              <td><?=$lbl[$h['status']] ?? $h['status']?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- 5. BẢNG DANH SÁCH TOÀN BỘ TÀI KHOẢN NGƯỜI DÙNG TRONG HỆ THỐNG -->
<div class="box">
  <?php if (!$rows): ?>
    <div class="empty">Không tìm thấy người dùng nào phù hợp.</div>
  <?php else: ?>
    <div class="tw">
      <table>
        <tr>
          <th>Tài khoản & Liên hệ</th>
          <th>Vai trò (Role)</th>
          <th>Trạng thái</th>
          <th>Điểm Uy Tín</th>
          <th>Ví Nội Bộ</th>
          <th>Hoạt động</th>
          <th>Thao tác Quản trị</th>
        </tr>
        <?php foreach ($rows as $r):
            $self    = ($r['id'] == $adm['id']);
            $rCode   = get_user_role_code($r);
            $repVal  = (int)($r['reputation_score'] ?? 100);
            $repTier = get_reputation_tier($repVal);
            $accSt   = strtoupper($r['account_status'] ?? ($r['status'] === 'active' ? 'ACTIVE' : 'BANNED'));
        ?>
          <tr>
            <td>
              <b><?=e($r['full_name'])?></b> <small style="display:inline">(@<?=e($r['username'])?>)</small>
              <small style="display:block">📞 <?=e($r['phone'])?> • ✉ <?=e($r['email'])?></small>
              <?php if (!empty($r['team_name']) || !empty($r['preferred_position'])): ?>
                <small style="color:#10b981;font-weight:700">
                  ⚽ <?=e($r['team_name'] ?: 'Cầu thủ tự do')?> <?= !empty($r['preferred_position']) ? '• ' . e($r['preferred_position']) : '' ?>
                </small>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($rCode === 'ADMIN'): ?>
                <span class="tag blue">🛡 ADMIN</span>
              <?php elseif ($rCode === 'PITCH_OWNER'): ?>
                <span class="tag green">🏟 PITCH_OWNER</span>
                <small style="display:block;margin-top:2px">Sở hữu: <b><?=(int)$r['owned_fields_count']?></b> sân</small>
              <?php else: ?>
                <span class="tag gray">⚽ CUSTOMER</span>
                <?php if (($r['partner_status'] ?? 'NONE') === 'PENDING'): ?>
                  <a href="partners.php?status=PENDING" class="tag amber" style="margin-top:3px;display:inline-block;text-decoration:none">⏳ Chờ duyệt mở sân</a>
                <?php endif; ?>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($accSt === 'BANNED' || $r['status'] === 'locked'): ?>
                <span class="tag red">🔴 BANNED (Đã khóa)</span>
              <?php elseif ($accSt === 'WARNING'): ?>
                <span class="tag amber">🟠 WARNING (Cảnh báo)</span>
              <?php else: ?>
                <span class="tag green">🟢 ACTIVE</span>
              <?php endif; ?>
            </td>

            <td>
              <span class="tag <?=$repTier['badge']?>">
                ⭐ <?=$repVal?>/100 (<?=$repTier['label']?>)
              </span>
            </td>

            <td>
              <b style="color:#10b981"><?=money($r['wallet_balance'] ?? 0)?></b>
              <small style="display:block">🎁 <?=(int)($r['loyalty_points'] ?? 0)?> điểm</small>
            </td>

            <td><b><?=$r['n']?></b> đơn đặt</td>

            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <a class="btn sm bl" href="?view=<?=$r['id']?>">⚖️ Uy tín / Ví / Lịch sử</a>

                <?php if (!$self): ?>
                  <form class="inl" method="post" onsubmit="return confirm('Xác nhận Khóa / Mở khóa tài khoản này?')">
                    <?=csrf_field()?>
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <input type="hidden" name="action" value="toggle">
                    <button class="btn sm <?=$r['status'] === 'active' ? 'red' : ''?>" type="submit">
                      <?=$r['status'] === 'active' ? '🔒 Khóa' : '🔓 Mở khóa'?>
                    </button>
                  </form>

                  <form class="inl" method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <input type="hidden" name="action" value="set_role">
                    <select name="account_role" onchange="if(confirm('Đổi vai trò tài khoản này?')) this.form.submit();" style="padding:5px 8px;font-size:12px;width:auto;margin:0">
                      <option value="CUSTOMER" <?=$rCode === 'CUSTOMER' ? 'selected' : ''?>>CUSTOMER</option>
                      <option value="PITCH_OWNER" <?=$rCode === 'PITCH_OWNER' ? 'selected' : ''?>>PITCH_OWNER</option>
                      <option value="ADMIN" <?=$rCode === 'ADMIN' ? 'selected' : ''?>>ADMIN</option>
                    </select>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include '../includes/admin_footer.php'; ?>
