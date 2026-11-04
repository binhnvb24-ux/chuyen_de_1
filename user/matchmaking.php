<?php
/**
 * ============================================================================
 * FILE: user/matchmaking.php
 * MÔ TẢ: Sàn Cáp Kèo & Tìm Đối Giao Hữu / Tuyển Cầu Thủ Phủi (Matchmaking Lobby).
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý Đăng kèo mới, Nhận kèo (Bắt đối), Hủy nhận kèo và Hủy kèo (POST).
 *   - Phần 2: Truy vấn danh sách "Kèo của đội tôi" (Đã đăng & Đã nhận) + Danh sách sàn.
 *   - Phần 3: Giao diện Banner & Biểu mẫu Đăng kèo mới.
 *   - Phần 4: Khu vực "KÈO GIAO HỮU CỦA ĐỘI BẠN" (Xem lại kèo đã nhận / đã đăng).
 *   - Phần 5: Bộ lọc và Danh sách toàn bộ kèo trên Sàn Cáp Kèo.
 * ============================================================================
 */

require_once '../includes/auth.php';
$u = current_user();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ ĐĂNG KÈO MỚI, NHẬN KÈO, HỦY NHẬN KÈO HOẶC HỦY KÈO (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = require_login();
    csrf_check();
    $act = $_POST['action'] ?? '';

    // 1.1. Đăng kèo tìm đối / tuyển người mới
    if ($act === 'create_match') {
        $fid   = (int)($_POST['field_id'] ?? 0);
        $team  = trim($_POST['team_name'] ?? '');
        $date  = $_POST['match_date'] ?? '';
        $st    = $_POST['start_time'] ?? '18:00';
        $en    = $_POST['end_time'] ?? '19:30';
        $type  = in_array($_POST['match_type'] ?? '', ['tim_doi', 'tuyen_nguoi']) ? $_POST['match_type'] : 'tim_doi';
        $lvl   = in_array($_POST['skill_level'] ?? '', ['vui_ve', 'phong_trao', 'ban_chuyen']) ? $_POST['skill_level'] : 'phong_trao';
        $spots = max(1, min(15, (int)($_POST['spots_needed'] ?? 1)));
        $fee   = trim($_POST['fee_share'] ?? 'Chia đôi 50/50') ?: 'Chia đôi 50/50';
        $phone = trim($_POST['contact_phone'] ?? $u['phone']);
        $note  = trim($_POST['note'] ?? '');

        if (!$fid || $team === '' || $date < date('Y-m-d')) {
            flash('err', 'Vui lòng nhập tên đội bóng, chọn sân và ngày thi đấu hợp lệ.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO match_requests (
                    creator_id, field_id, team_name, match_date, start_time, end_time,
                    skill_level, match_type, spots_needed, fee_share, contact_phone, note, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')
            ");
            $stmt->execute([$u['id'], $fid, $team, $date, $st, $en, $lvl, $type, $spots, $fee, $phone, $note]);

            // Lưu thông báo vào trung tâm thông báo của người đăng
            $formattedDate = date('d/m/Y', strtotime($date));
            notify(
                $u['id'],
                "🔥 Bạn đã đăng kèo giao hữu cho đội '$team' ngày {$formattedDate} (" . substr($st, 0, 5) . " - " . substr($en, 0, 5) . ") lên Sàn Cáp Kèo."
            );
            flash('ok', "🔥 Đã đăng kèo của đội '$team' lên Sàn Cáp Kèo thành công!");
        }
        header('Location: matchmaking.php');
        exit;
    }

    // 1.2. Nhận kèo / Bắt đối giao hữu
    if ($act === 'accept_match') {
        $mid       = (int)($_POST['match_id'] ?? 0);
        $opp_name  = trim($_POST['opponent_name'] ?? ('FC ' . $u['full_name']));
        $opp_phone = trim($_POST['opponent_phone'] ?? $u['phone']);

        $stmt = $pdo->prepare("
            SELECT m.*, f.name AS fname
            FROM match_requests m
            JOIN fields f ON f.id = m.field_id
            WHERE m.id = ? AND m.status = 'open'
        ");
        $stmt->execute([$mid]);
        $m = $stmt->fetch();

        if (!$m) {
            flash('err', 'Kèo này không tồn tại hoặc đã có đội khác nhận.');
        } elseif ((int)$m['creator_id'] === (int)$u['id']) {
            flash('err', 'Bạn không thể tự nhận kèo do chính đội mình đăng.');
        } elseif ($opp_name === '' || $opp_phone === '') {
            flash('err', 'Vui lòng nhập Tên đội nhận kèo và Số điện thoại liên hệ.');
        } else {
            $pdo->prepare("
                UPDATE match_requests
                SET status = 'matched',
                    opponent_id = ?,
                    opponent_name = ?,
                    opponent_phone = ?
                WHERE id = ?
            ")->execute([$u['id'], $opp_name, $opp_phone, $mid]);

            // Gửi thông báo cho cả đội chủ nhà và đội nhận kèo
            $formattedDate = date('d/m/Y', strtotime($m['match_date']));
            notify(
                $m['creator_id'],
                "⚔️ Kèo tại {$m['fname']} ngày {$formattedDate} đã được đội '$opp_name' (SĐT: $opp_phone) nhận kèo!"
            );
            notify(
                $u['id'],
                "🤝 Bạn đã chốt kèo giao hữu với đội '{$m['team_name']}' (SĐT: {$m['contact_phone']}) tại {$m['fname']} ngày {$formattedDate}."
            );
            flash(
                'ok',
                "🎉 Chốt kèo thành công! Kèo đã được lưu vào mục 'Kèo Giao Hữu Của Đội Bạn' ngay bên dưới."
            );
        }
        header('Location: matchmaking.php#my-matches');
        exit;
    }

    // 1.3. Hủy nhận kèo (Dành cho đội khách muốn rút lui để mở lại kèo cho đội khác)
    if ($act === 'leave_match') {
        $mid = (int)($_POST['match_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT m.*, f.name AS fname
            FROM match_requests m
            JOIN fields f ON f.id = m.field_id
            WHERE m.id = ? AND m.opponent_id = ? AND m.status = 'matched'
        ");
        $stmt->execute([$mid, $u['id']]);
        $m = $stmt->fetch();

        if ($m) {
            $pdo->prepare("
                UPDATE match_requests
                SET status = 'open',
                    opponent_id = NULL,
                    opponent_name = NULL,
                    opponent_phone = NULL
                WHERE id = ?
            ")->execute([$mid]);

            $formattedDate = date('d/m/Y', strtotime($m['match_date']));
            $timeRange     = substr($m['start_time'], 0, 5) . ' - ' . substr($m['end_time'], 0, 5);

            // Gửi thông báo cho đội chủ nhà (mở lại kèo)
            notify(
                $m['creator_id'],
                "⚠️ Đội '{$m['opponent_name']}' đã báo bận và hủy nhận kèo tại {$m['fname']} ngày {$formattedDate} ({$timeRange}). Kèo của bạn đã được mở lại trên sàn!"
            );

            // Gửi thông báo xác nhận hủy cho chính đội vừa bấm hủy nhận kèo
            notify(
                $u['id'],
                "↩ Bạn đã hủy nhận kèo giao hữu với đội '{$m['team_name']}' tại {$m['fname']} ngày {$formattedDate} ({$timeRange})."
            );

            flash(
                'cancel',
                "Đã hủy nhận kèo giao hữu với đội '{$m['team_name']}' ngày {$formattedDate} và gửi thông báo cho đội chủ nhà."
            );
        } else {
            flash('err', 'Không tìm thấy kèo giao hữu hợp lệ để hủy nhận.');
        }
        $redirect = ($_POST['redirect_to'] ?? '') === 'my-bookings' ? 'my-bookings.php' : 'matchmaking.php';
        header("Location: $redirect");
        exit;
    }

    // 1.4. Hủy kèo do chính đội mình đăng
    if ($act === 'cancel_match') {
        $mid = (int)($_POST['match_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT m.*, f.name AS fname
            FROM match_requests m
            JOIN fields f ON f.id = m.field_id
            WHERE m.id = ? AND m.creator_id = ? AND m.status <> 'cancelled'
        ");
        $stmt->execute([$mid, $u['id']]);
        $m = $stmt->fetch();

        if ($m) {
            $pdo->prepare("UPDATE match_requests SET status = 'cancelled' WHERE id = ? AND creator_id = ?")
                ->execute([$mid, $u['id']]);

            $formattedDate = date('d/m/Y', strtotime($m['match_date']));
            $timeRange     = substr($m['start_time'], 0, 5) . ' - ' . substr($m['end_time'], 0, 5);

            // Gửi thông báo xác nhận hủy kèo cho chính người đăng kèo
            notify(
                $u['id'],
                "❌ Bạn đã hủy kèo giao hữu của đội '{$m['team_name']}' tại {$m['fname']} ngày {$formattedDate} ({$timeRange})."
            );

            // Nếu kèo này đã có đối thủ nhận trước đó -> Gửi thông báo báo hủy cho cả đội đối thủ
            if (!empty($m['opponent_id'])) {
                notify(
                    $m['opponent_id'],
                    "⚠️ Đội chủ nhà '{$m['team_name']}' đã hủy kèo giao hữu tại {$m['fname']} ngày {$formattedDate} ({$timeRange})."
                );
            }

            flash(
                'cancel',
                "Đã hủy kèo giao hữu của đội '{$m['team_name']}' tại {$m['fname']} ngày {$formattedDate} thành công!"
            );
        } else {
            flash('err', 'Kèo không tồn tại hoặc đã được hủy trước đó.');
        }
        $redirect = ($_POST['redirect_to'] ?? '') === 'my-bookings' ? 'my-bookings.php' : 'matchmaking.php';
        header("Location: $redirect");
        exit;
    }
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN "KÈO CỦA ĐỘI BẠN" (ĐÃ NHẬN + ĐÃ ĐĂNG) & DANH SÁCH TRÊN SÀN
// ----------------------------------------------------------------------------
$fields = $pdo->query("SELECT * FROM fields WHERE status = 'active' ORDER BY name")->fetchAll();

// 2.1. Lấy danh sách các kèo mà người dùng đang đăng nhập ĐÃ NHẬN hoặc ĐÃ ĐĂNG
$myMatches = [];
if ($u) {
    $stmtMy = $pdo->prepare("
        SELECT m.*, f.name AS fname, f.type AS ftype, f.district, f.address, u.full_name AS creator_name
        FROM match_requests m
        JOIN fields f ON f.id = m.field_id
        JOIN users u ON u.id = m.creator_id
        WHERE m.status <> 'cancelled'
          AND (m.creator_id = ? OR m.opponent_id = ?)
        ORDER BY m.match_date DESC, m.start_time ASC
    ");
    $stmtMy->execute([$u['id'], $u['id']]);
    $myMatches = $stmtMy->fetchAll();
}

// 2.2. Lấy danh sách kèo trên toàn Sàn Cáp Kèo theo bộ lọc (Mặc định hiển thị tất cả kèo đang mở & đã chốt)
$f_type   = $_GET['match_type'] ?? '';
$f_lvl    = $_GET['skill_level'] ?? '';
$f_status = $_GET['status'] ?? 'all';

$sql = "
    SELECT m.*, f.name AS fname, f.type AS ftype, f.district, f.address, u.full_name AS creator_name
    FROM match_requests m
    JOIN fields f ON f.id = m.field_id
    JOIN users u ON u.id = m.creator_id
    WHERE m.status <> 'cancelled'
";
$params = [];

if ($f_status === 'open') {
    $sql .= " AND m.status = 'open'";
} elseif ($f_status === 'matched') {
    $sql .= " AND m.status = 'matched'";
} elseif ($f_status === 'mine' && $u) {
    $sql .= " AND (m.creator_id = ? OR m.opponent_id = ?)";
    $params[] = $u['id'];
    $params[] = $u['id'];
}

if (in_array($f_type, ['tim_doi', 'tuyen_nguoi'])) {
    $sql .= " AND m.match_type = ?";
    $params[] = $f_type;
}
if (in_array($f_lvl, ['vui_ve', 'phong_trao', 'ban_chuyen'])) {
    $sql .= " AND m.skill_level = ?";
    $params[] = $f_lvl;
}

$sql .= " ORDER BY m.status ASC, m.match_date ASC, m.start_time ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$lvlMap = [
    'vui_ve'     => ['😊 Vui vẻ / Rèn sức khỏe', 'green'],
    'phong_trao' => ['⚽ Phong trào',             'blue'],
    'ban_chuyen' => ['🔥 Bán chuyên / Đối cứng',  'amber']
];

$active = 'match';
$title  = 'Sàn Cáp Kèo & Tìm Đối Giao Hữu';
include '../includes/header.php';
?>

<!-- ==========================================================================
     PHẦN 3: BANNER TIÊU ĐỀ & BIỂU MẪU ĐĂNG KÈO MỚI
     ========================================================================== -->
<div class="sport-page-head">
  <div>
    <h2>⚔️ SÀN CÁP KÈO & TÌM ĐỐI GIAO HỮU</h2>
    <small>Nơi các FC giao lưu cọ xát 5v5, 7v7, 11v11 hoặc tuyển thêm cầu thủ phủi còn thiếu cho trận đấu</small>
  </div>
  <div style="display:flex;gap:10px">
    <?php if ($u && count($myMatches) > 0): ?>
      <a href="#my-matches" class="btn sm bl">⭐ Kèo của tôi (<?=count($myMatches)?>)</a>
    <?php endif; ?>
    <button
      type="button"
      class="btn"
      onclick="document.getElementById('newMatchBox').style.display = document.getElementById('newMatchBox').style.display === 'none' ? 'block' : 'none'"
    >
      + Đăng Kèo Tìm Đối Mới
    </button>
  </div>
</div>

<!-- Biểu mẫu Đăng Kèo Mới (Ẩn/Hiện linh hoạt) -->
<div class="box" id="newMatchBox" style="display:<?=isset($_GET['new']) ? 'block' : 'none'?>;border:2px solid #16a34a;background:#f0fdf4">
  <h3 style="margin:0 0 14px;font-family:var(--font-sport);font-size:19px;color:#14532d">
    🔥 ĐĂNG KÈO TÌM ĐỐI THỦ / TUYỂN CẦU THỦ
  </h3>
  <?php if (!$u): ?>
    <div class="empty">
      Vui lòng <a href="../login.php"><b>Đăng nhập</b></a> để đăng kèo giao hữu cho đội bóng của bạn.
    </div>
  <?php else: ?>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="create_match">
      <div>
        <label style="font-size:12.5px;font-weight:700">Tên đội bóng (FC) *</label>
        <input name="team_name" required value="FC <?=e($u['full_name'])?>" placeholder="VD: FC Anh Em Quận 7" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">Chọn Sân Thi Đấu *</label>
        <select name="field_id" required style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
          <?php foreach ($fields as $f): ?>
            <option value="<?=$f['id']?>"><?=e($f['name'])?> (Sân <?=$f['type']?> - <?=e($f['district'])?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">Ngày thi đấu *</label>
        <input type="date" name="match_date" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div>
          <label style="font-size:12.5px;font-weight:700">Bắt đầu</label>
          <select name="start_time" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
            <?php for ($t = strtotime('06:00'); $t <= strtotime('22:00'); $t += 1800): $v = date('H:i', $t); ?>
              <option value="<?=$v?>" <?=$v === '18:00' ? 'selected' : ''?>><?=$v?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label style="font-size:12.5px;font-weight:700">Kết thúc</label>
          <select name="end_time" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
            <?php for ($t = strtotime('07:00'); $t <= strtotime('23:00'); $t += 1800): $v = date('H:i', $t); ?>
              <option value="<?=$v?>" <?=$v === '19:30' ? 'selected' : ''?>><?=$v?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">Loại kèo</label>
        <select name="match_type" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
          <option value="tim_doi">⚔️ Tìm đối thủ cáp kèo</option>
          <option value="tuyen_nguoi">🏃 Tuyển thêm cầu thủ đá ghép</option>
        </select>
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">Trình độ mong muốn</label>
        <select name="skill_level" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
          <option value="phong_trao">⚽ Phong trào</option>
          <option value="vui_ve">😊 Vui vẻ / Văn phòng</option>
          <option value="ban_chuyen">🔥 Bán chuyên / Đối cứng</option>
        </select>
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">Số đội / Số người cần thêm</label>
        <input type="number" name="spots_needed" min="1" max="15" value="1" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">Thỏa thuận tiền sân</label>
        <input name="fee_share" value="Chia đôi 50/50" placeholder="VD: 50/50, 70/30, 35k/người..." style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:700">SĐT Đội trưởng liên hệ *</label>
        <input name="contact_phone" required value="<?=e($u['phone'])?>" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
      </div>
      <div style="grid-column:1 / -1">
        <label style="font-size:12.5px;font-weight:700">Lời nhắn cho đối thủ (Màu áo, lưu ý Fair-play...)</label>
        <input name="note" placeholder="VD: Đội mặc áo trắng, đá vui vẻ không xoạc bóng..." style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
      </div>
      <div style="grid-column:1 / -1;display:flex;gap:10px">
        <button type="submit" class="btn">🚀 Đăng Kèo Lên Sàn Ngay</button>
        <button type="button" class="btn outline" onclick="document.getElementById('newMatchBox').style.display='none'">Đóng</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- ==========================================================================
     PHẦN 4: DANH SÁCH KÈO GIAO HỮU CỦA ĐỘI BẠN (KÈO ĐÃ NHẬN & KÈO ĐÃ ĐĂNG)
     Giúp người chơi dễ dàng xem lại thông tin đối thủ sau khi bấm Nhận Kèo!
     ========================================================================== -->
<?php if ($u && $myMatches): ?>
  <div class="box" id="my-matches" style="border:2px solid #2563eb;background:#f8fafc;margin-bottom:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <div>
        <h3 style="margin:0;font-family:var(--font-sport);font-size:19px;color:#1e3a8a">
          ⭐ KÈO GIAO HỮU CỦA ĐỘI BẠN (ĐÃ NHẬN KÈO & ĐÃ ĐĂNG)
        </h3>
        <small style="color:#475569;margin:2px 0 0">
          Danh sách các trận giao hữu mà đội bạn đã bắt đối hoặc đăng tìm đối trên hệ thống
        </small>
      </div>
      <span class="tag blue"><?=count($myMatches)?> kèo của bạn</span>
    </div>

    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Vai trò của bạn</th>
            <th>Trận đấu (Chủ nhà vs Đối thủ)</th>
            <th>Sân thi đấu</th>
            <th>Ngày & Giờ đá</th>
            <th>Tiền sân</th>
            <th>Liên hệ Đối phương</th>
            <th>Trạng thái & Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myMatches as $mm):
              $amCreator  = ((int)$mm['creator_id'] === (int)$u['id']);
              $amOpponent = ((int)$mm['opponent_id'] === (int)$u['id']);
          ?>
            <tr>
              <td>
                <?php if ($amCreator): ?>
                  <span class="tag green">🛡️ Đội chủ nhà (Bạn đăng)</span>
                <?php else: ?>
                  <span class="tag blue">⚔️ Đội nhận kèo (Khách)</span>
                <?php endif; ?>
              </td>
              <td>
                <b><?=e($mm['team_name'])?></b>
                <span style="color:#dc2626;font-weight:800"> VS </span>
                <?php if ($mm['status'] === 'matched'): ?>
                  <b style="color:#1d4ed8"><?=e($mm['opponent_name'])?></b>
                <?php else: ?>
                  <i style="color:#d97706">Đang chờ đối...</i>
                <?php endif; ?>
              </td>
              <td>
                <b><?=e($mm['fname'])?></b>
                <small>Sân <?=$mm['ftype']?> • <?=e($mm['district'])?></small>
              </td>
              <td>
                <b><?=date('d/m/Y', strtotime($mm['match_date']))?></b>
                <small style="color:#16a34a;font-weight:700"><?=substr($mm['start_time'], 0, 5)?> - <?=substr($mm['end_time'], 0, 5)?></small>
              </td>
              <td><b><?=e($mm['fee_share'])?></b></td>
              <td>
                <?php if ($amOpponent): ?>
                  <small style="margin:0">Đội chủ nhà:</small>
                  <a href="tel:<?=e($mm['contact_phone'])?>" style="font-weight:800;color:#059669">📞 <?=e($mm['contact_phone'])?></a>
                <?php elseif ($mm['status'] === 'matched'): ?>
                  <small style="margin:0">Đội nhận kèo:</small>
                  <a href="tel:<?=e($mm['opponent_phone'])?>" style="font-weight:800;color:#1d4ed8">📞 <?=e($mm['opponent_phone'])?></a>
                <?php else: ?>
                  <small>Chưa có đội nhận</small>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($mm['status'] === 'matched'): ?>
                  <span class="tag green">✅ Đã chốt kèo</span>
                <?php else: ?>
                  <span class="tag amber">⏳ Đang tìm đối</span>
                <?php endif; ?>

                <div style="margin-top:6px">
                  <?php if ($amOpponent && $mm['status'] === 'matched'): ?>
                    <form method="post" class="inl" onsubmit="return confirm('Bạn muốn hủy nhận kèo này để nhường lại cho đội khác?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="leave_match">
                      <input type="hidden" name="match_id" value="<?=$mm['id']?>">
                      <button type="submit" class="btn sm red">↩ Hủy nhận kèo</button>
                    </form>
                  <?php elseif ($amCreator): ?>
                    <form method="post" class="inl" onsubmit="return confirm('Bạn muốn hủy kèo giao hữu do đội mình đăng?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="cancel_match">
                      <input type="hidden" name="match_id" value="<?=$mm['id']?>">
                      <button type="submit" class="btn sm red">✕ Hủy kèo</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- ==========================================================================
     PHẦN 5: THANH BỘ LỌC & DANH SÁCH TẤT CẢ CÁC KÈO TRÊN SÀN
     ========================================================================== -->
<div class="box" style="padding:16px 20px">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <select name="status" style="padding:10px 12px;border-radius:8px;border:1.5px solid #cbd5e1;font-weight:600">
      <option value="all" <?=$f_status === 'all' ? 'selected' : ''?>>📋 Tất cả kèo (Đang chờ & Đã chốt)</option>
      <option value="open" <?=$f_status === 'open' ? 'selected' : ''?>>🟢 Chỉ kèo đang chờ đối</option>
      <option value="matched" <?=$f_status === 'matched' ? 'selected' : ''?>>🤝 Kèo đã bắt đối thành công</option>
      <?php if ($u): ?>
        <option value="mine" <?=$f_status === 'mine' ? 'selected' : ''?>>⭐ Kèo của đội tôi (Đã đăng / Đã nhận)</option>
      <?php endif; ?>
    </select>

    <select name="match_type" style="padding:10px 12px;border-radius:8px;border:1.5px solid #cbd5e1">
      <option value="">Tất cả hình thức</option>
      <option value="tim_doi" <?=$f_type === 'tim_doi' ? 'selected' : ''?>>⚔️ Tìm đối cáp kèo</option>
      <option value="tuyen_nguoi" <?=$f_type === 'tuyen_nguoi' ? 'selected' : ''?>>🏃 Tuyển thêm cầu thủ</option>
    </select>

    <select name="skill_level" style="padding:10px 12px;border-radius:8px;border:1.5px solid #cbd5e1">
      <option value="">Mọi trình độ</option>
      <option value="vui_ve" <?=$f_lvl === 'vui_ve' ? 'selected' : ''?>>😊 Vui vẻ / Văn phòng</option>
      <option value="phong_trao" <?=$f_lvl === 'phong_trao' ? 'selected' : ''?>>⚽ Phong trào</option>
      <option value="ban_chuyen" <?=$f_lvl === 'ban_chuyen' ? 'selected' : ''?>>🔥 Bán chuyên</option>
    </select>

    <button class="btn sm" style="padding:10px 18px" type="submit">🔍 Lọc kèo</button>
    <a href="matchmaking.php" style="font-size:13px;font-weight:600;margin-left:auto">Làm mới bộ lọc</a>
  </form>
</div>

<!-- Danh sách các Thẻ Kèo Thi Đấu (Match Cards) -->
<?php if (!$matches): ?>
  <div class="box" style="text-align:center;padding:48px 20px">
    <div style="font-size:48px;margin-bottom:10px">⚽</div>
    <h3 style="margin:0 0 6px;font-family:var(--font-sport)">CHƯA CÓ KÈO PHÙ HỢP VỚI BỘ LỌC</h3>
    <small>Hãy là đội bóng đầu tiên đăng kèo giao hữu hôm nay!</small>
  </div>
<?php else: ?>
  <div class="match-grid">
    <?php foreach ($matches as $m):
        [$lvlTxt, $lvlCl] = $lvlMap[$m['skill_level']] ?? ['Phong trào', 'blue'];
        $isOwner    = ($u && (int)$u['id'] === (int)$m['creator_id']);
        $isOpponent = ($u && (int)$u['id'] === (int)($m['opponent_id'] ?? 0));
        $isOpen     = ($m['status'] === 'open');
    ?>
      <div class="match-card" style="<?=($isOwner || $isOpponent) ? 'border:2px solid #2563eb;' : ''?>">
        <div class="match-card-top">
          <span style="font-family:var(--font-sport);font-weight:700;font-size:13.5px;color:#4ade80;display:inline-flex;align-items:center">
            <?php if ($isOpen): ?><i class="radar-dot"></i><?php endif; ?>
            <?=$m['match_type'] === 'tim_doi' ? '⚔️ CÁP KÈO ĐỐI KHÁNG' : '🏃 TUYỂN THÊM ' . $m['spots_needed'] . ' CẦU THỦ'?>
          </span>
          <span class="tag <?=$lvlCl?>"><?=$lvlTxt?></span>
        </div>

        <div class="match-vs-box">
          <div class="match-team">
            <div class="match-team-shield">🛡️</div>
            <b style="font-size:14px;color:#0f172a;display:block"><?=e($m['team_name'])?></b>
            <small style="margin:0">Đội chủ nhà</small>
          </div>
          <div class="vs-circle">VS</div>
          <div class="match-team">
            <?php if ($m['status'] === 'matched'): ?>
              <div class="match-team-shield" style="background:#dbeafe;color:#1d4ed8;border-color:#93c5fd">⚔️</div>
              <b style="font-size:14px;color:#1d4ed8;display:block"><?=e($m['opponent_name'])?></b>
              <small style="margin:0;color:#16a34a;font-weight:700">✔ Đã chốt kèo</small>
            <?php else: ?>
              <div class="match-team-shield wait">❓</div>
              <b style="font-size:14px;color:#d97706;display:block">Đang chờ đối...</b>
              <small style="margin:0"><?=e($m['fee_share'])?></small>
            <?php endif; ?>
          </div>
        </div>

        <div class="match-body">
          <div style="font-size:13.5px">
            <b>🏟 Sân đấu:</b> <?=e($m['fname'])?>
            <span class="tag green" style="font-size:11px">Sân <?=$m['ftype']?>v<?=$m['ftype']?></span>
          </div>
          <div style="font-size:13px;color:#475569">📍 <?=e($m['address'])?></div>
          <div style="font-size:13.5px">
            <b>📅 Thời gian:</b> <?=date('d/m/Y', strtotime($m['match_date']))?>
            • <b style="color:#16a34a"><?=substr($m['start_time'], 0, 5)?> - <?=substr($m['end_time'], 0, 5)?></b>
          </div>
          <div style="font-size:13px">
            <b>💰 Tiền sân:</b> <span style="color:#0f172a;font-weight:700"><?=e($m['fee_share'])?></span>
          </div>
          <div style="font-size:13px">
            <b>📞 SĐT Đội chủ nhà:</b>
            <a href="tel:<?=e($m['contact_phone'])?>" style="font-weight:800"><?=e($m['contact_phone'])?></a>
          </div>
          <?php if ($m['status'] === 'matched' && !empty($m['opponent_phone'])): ?>
            <div style="font-size:13px">
              <b>📞 SĐT Đội nhận kèo:</b>
              <a href="tel:<?=e($m['opponent_phone'])?>" style="font-weight:800;color:#1d4ed8"><?=e($m['opponent_phone'])?></a>
            </div>
          <?php endif; ?>

          <?php if (!empty($m['note'])): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:8px 10px;border-radius:8px;font-size:12.5px;color:#334155">
              💬 <i>"<?=e($m['note'])?>"</i>
            </div>
          <?php endif; ?>

          <div style="margin-top:auto;padding-top:12px;border-top:1px solid #f1f5f9">
            <?php if ($isOpen && !$isOwner): ?>
              <?php if ($u): ?>
                <form method="post" style="display:flex;flex-direction:column;gap:8px">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="accept_match">
                  <input type="hidden" name="match_id" value="<?=$m['id']?>">
                  <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:6px">
                    <input name="opponent_name" required placeholder="Tên FC của bạn..." value="FC <?=e($u['full_name'])?>" style="padding:7px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:12.5px">
                    <input name="opponent_phone" required placeholder="SĐT của bạn..." value="<?=e($u['phone'])?>" style="padding:7px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:12.5px">
                  </div>
                  <button type="submit" class="btn sm" style="width:100%;padding:9px">🤝 Bắt Kèo / Nhận Giao Hữu Ngay</button>
                </form>
              <?php else: ?>
                <a href="../login.php" class="btn sm" style="width:100%">🔑 Đăng nhập để Nhận Kèo</a>
              <?php endif; ?>
            <?php elseif ($isOwner): ?>
              <form method="post" onsubmit="return confirm('Bạn muốn hủy kèo giao hữu này?')" style="display:flex;justify-content:space-between;align-items:center">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="cancel_match">
                <input type="hidden" name="match_id" value="<?=$m['id']?>">
                <span class="tag blue">🛡️ Kèo của đội bạn</span>
                <button type="submit" class="btn sm red">✕ Hủy kèo</button>
              </form>
            <?php elseif ($isOpponent): ?>
              <form method="post" onsubmit="return confirm('Bạn muốn hủy nhận kèo này?')" style="display:flex;justify-content:space-between;align-items:center">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="leave_match">
                <input type="hidden" name="match_id" value="<?=$m['id']?>">
                <span class="tag green">⚔️ Đội bạn đã nhận kèo</span>
                <button type="submit" class="btn sm red">↩ Rút kèo</button>
              </form>
            <?php else: ?>
              <div style="text-align:center;padding:6px;background:#ecfdf5;border-radius:8px;color:#15803d;font-weight:700;font-size:12.5px">
                ✅ Hai đội đã cáp kèo thành công!
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
