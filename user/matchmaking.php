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
     PHẦN 3: TIÊU ĐỀ TRANG SÀN CÁP KÈO & KÈO CỦA ĐỘI BẠN (SCREEN 11)
     ========================================================================== -->
<div class="sport-page-head">
  <div>
    <h2>Sàn cáp kèo & tìm đối giao hữu</h2>
    <small>Nơi các FC giao lưu cọ xát 5v5, 7v7, 11v11 hoặc tuyển thêm cầu thủ</small>
  </div>
  <div>
    <a class="btn sm" href="#create-match-form">+ ĐĂNG KÈO TÌM ĐỐI</a>
  </div>
</div>

<!-- 3.1. Hộp Kèo giao hữu của đội bạn (Screen 11) -->
<?php if ($u): ?>
  <div class="box" id="my-matches">
    <div class="bh">
      <b>⭐ Kèo giao hữu của đội bạn</b>
      <a href="#create-match-form" style="font-size:12.5px;font-weight:700;color:#16a34a">+ Tạo kèo mới</a>
    </div>

    <?php if (!$myMatches): ?>
      <div class="empty" style="padding:18px">
        Đội bạn chưa đăng hoặc nhận kèo giao hữu nào.
        <a href="#create-match-form"><b>+ Đăng kèo tìm đối ngay →</b></a>
      </div>
    <?php else: ?>
      <div class="tw" style="margin-bottom:0">
        <table>
          <thead>
            <tr>
              <th>Vai trò</th>
              <th>Trận đấu</th>
              <th>Sân & Thời gian</th>
              <th>Giờ bóng lăn</th>
              <th>Tiền sân</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
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
                    <span class="tag green">Đội chủ nhà</span>
                  <?php else: ?>
                    <span class="tag blue">Đội nhận kèo</span>
                  <?php endif; ?>
                </td>
                <td>
                  <b><?=e($mm['team_name'])?></b>
                  <small style="margin:2px 0 0">
                    <?php if ($mm['status'] === 'matched'): ?>
                      VS <b style="color:#1d4ed8"><?=e($mm['opponent_name'])?></b> (📞 <?=e($amCreator ? $mm['opponent_phone'] : $mm['contact_phone'])?>)
                    <?php else: ?>
                      VS <i>Đang tìm đối...</i>
                    <?php endif; ?>
                  </small>
                </td>
                <td>
                  <b><?=e($mm['fname'])?></b>
                  <small style="margin:2px 0 0">Sân <?=$mm['ftype']?> • <?=e($mm['district'])?></small>
                </td>
                <td>
                  <b><?=date('d/m/Y', strtotime($mm['match_date']))?></b>
                  <small style="color:#16a34a;font-weight:700;margin:2px 0 0"><?=substr($mm['start_time'], 0, 5)?> - <?=substr($mm['end_time'], 0, 5)?></small>
                </td>
                <td><b><?=e($mm['fee_share'])?></b></td>
                <td>
                  <?php if ($mm['status'] === 'matched'): ?>
                    <span class="tag green">Đã chốt đối</span>
                  <?php else: ?>
                    <span class="tag amber">Đang tìm đối</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($amOpponent && $mm['status'] === 'matched'): ?>
                    <form method="post" class="inl" onsubmit="return confirm('Bạn muốn hủy nhận kèo này để nhường lại cho đội khác?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="leave_match">
                      <input type="hidden" name="match_id" value="<?=$mm['id']?>">
                      <button type="submit" class="tag red" style="cursor:pointer;border:1px solid #fecaca">HỦY KÈO</button>
                    </form>
                  <?php elseif ($amCreator): ?>
                    <form method="post" class="inl" onsubmit="return confirm('Bạn muốn hủy kèo giao hữu do đội mình đăng?')">
                      <?=csrf_field()?>
                      <input type="hidden" name="action" value="cancel_match">
                      <input type="hidden" name="match_id" value="<?=$mm['id']?>">
                      <button type="submit" class="tag red" style="cursor:pointer;border:1px solid #fecaca">HỦY KÈO</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ==========================================================================
     PHẦN 4: HỘP TÌM KÈO PHÙ HỢP & DANH SÁCH KÈO ĐANG MỞ (SCREEN 11)
     ========================================================================== -->
<div class="box">
  <div class="bh">
    <b>🔎 Tìm kèo phù hợp</b>
  </div>
  <form method="get" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:14px;align-items:end">
    <div>
      <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px">Trạng thái</label>
      <select name="status" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1;font-weight:600">
        <option value="all" <?=$f_status === 'all' ? 'selected' : ''?>>Tất cả kèo</option>
        <option value="open" <?=$f_status === 'open' ? 'selected' : ''?>>Chỉ kèo đang mở</option>
        <option value="matched" <?=$f_status === 'matched' ? 'selected' : ''?>>Kèo đã bắt đối</option>
        <?php if ($u): ?>
          <option value="mine" <?=$f_status === 'mine' ? 'selected' : ''?>>Kèo của đội tôi</option>
        <?php endif; ?>
      </select>
    </div>

    <div>
      <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px">Hình thức</label>
      <select name="match_type" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1;font-weight:600">
        <option value="">Tất cả hình thức</option>
        <option value="tim_doi" <?=$f_type === 'tim_doi' ? 'selected' : ''?>>Tìm đối giao hữu</option>
        <option value="tuyen_nguoi" <?=$f_type === 'tuyen_nguoi' ? 'selected' : ''?>>Tuyển thêm cầu thủ</option>
      </select>
    </div>

    <div>
      <label style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px">Trình độ</label>
      <select name="skill_level" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1;font-weight:600">
        <option value="">Mọi trình độ</option>
        <option value="vui_ve" <?=$f_lvl === 'vui_ve' ? 'selected' : ''?>>Vui vẻ / Văn phòng</option>
        <option value="phong_trao" <?=$f_lvl === 'phong_trao' ? 'selected' : ''?>>Phong trào</option>
        <option value="ban_chuyen" <?=$f_lvl === 'ban_chuyen' ? 'selected' : ''?>>Bán chuyên</option>
      </select>
    </div>

    <button class="btn" style="padding:10px 20px" type="submit">🔍 LỌC KÈO</button>
  </form>
</div>

<!-- 4.2. Khung Danh sách Kèo giao hữu đang mở (Screen 11) -->
<div class="box">
  <div class="bh">
    <b>⚔️ Kèo giao hữu đang mở</b>
    <a href="matchmaking.php" style="font-size:12px;color:#64748b">Làm mới</a>
  </div>

  <?php if (!$matches): ?>
    <div class="empty" style="padding:32px">
      Chưa có kèo phù hợp với bộ lọc. Hãy đăng kèo mới ngay bên dưới!
    </div>
  <?php else: ?>
    <div class="match-grid">
      <?php foreach ($matches as $m):
          [$lvlTxt, $lvlCl] = $lvlMap[$m['skill_level']] ?? ['Phong trào', 'blue'];
          $isOwner    = ($u && (int)$u['id'] === (int)$m['creator_id']);
          $isOpponent = ($u && (int)$u['id'] === (int)($m['opponent_id'] ?? 0));
          $isOpen     = ($m['status'] === 'open');
      ?>
        <div class="match-card" style="<?=($isOwner || $isOpponent) ? 'border-color:#16a34a;' : ''?>">
          <div class="match-body" style="padding:16px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
              <div>
                <b style="font-size:15px;color:#0f172a;display:block">⚽ <?=e($m['team_name'])?></b>
                <small style="color:#64748b;margin:2px 0 8px">
                  <?=e($m['district'])?> • Sân <?=$m['ftype']?> người (<?=e($m['fname'])?>)
                </small>
              </div>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
              <span class="tag <?=$isOpen ? 'amber' : 'green'?>">
                <?=$isOpen ? 'Đang tìm đối' : '✔ Đã chốt (' . e($m['opponent_name']) . ')'?>
              </span>
              <span class="tag green">Trình độ: <?=e(preg_replace('/^[^\s]+\s/', '', $lvlTxt))?></span>
            </div>

            <div style="font-size:12.5px;color:#334155;line-height:1.6">
              <div>📅 <b>Ngày đá:</b> <?=date('d/m/Y', strtotime($m['match_date']))?></div>
              <div>💰 <b>Chia tiền:</b> <?=e($m['fee_share'])?> • 📞 <b><?=e($m['contact_phone'])?></b></div>
              <?php if (!empty($m['note'])): ?>
                <div style="color:#64748b;font-style:italic;margin-top:3px">"<?=e($m['note'])?>"</div>
              <?php endif; ?>
            </div>

            <div style="margin-top:auto;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
              <b style="font-size:14px;color:#16a34a">
                <?=substr($m['start_time'], 0, 5)?> - <?=substr($m['end_time'], 0, 5)?>
              </b>

              <?php if ($isOpen && !$isOwner): ?>
                <?php if ($u): ?>
                  <form method="post" style="display:flex;gap:6px;align-items:center">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="accept_match">
                    <input type="hidden" name="match_id" value="<?=$m['id']?>">
                    <input type="hidden" name="opponent_name" value="FC <?=e($u['full_name'])?>">
                    <input type="hidden" name="opponent_phone" value="<?=e($u['phone'])?>">
                    <button type="submit" class="btn sm">NHẬN KÈO</button>
                  </form>
                <?php else: ?>
                  <a href="../login.php" class="btn sm">NHẬN KÈO</a>
                <?php endif; ?>
              <?php elseif ($isOwner): ?>
                <form method="post" class="inl" onsubmit="return confirm('Bạn muốn hủy kèo giao hữu này?')">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="cancel_match">
                  <input type="hidden" name="match_id" value="<?=$m['id']?>">
                  <button type="submit" class="tag red" style="cursor:pointer;border:1px solid #fecaca">HỦY KÈO</button>
                </form>
              <?php elseif ($isOpponent): ?>
                <form method="post" class="inl" onsubmit="return confirm('Bạn muốn rút khỏi kèo giao hữu này?')">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="leave_match">
                  <input type="hidden" name="match_id" value="<?=$m['id']?>">
                  <button type="submit" class="tag red" style="cursor:pointer;border:1px solid #fecaca">RÚT KÈO</button>
                </form>
              <?php else: ?>
                <span class="tag green">Đã đủ đội</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ==========================================================================
     PHẦN 5: BIỂU MẪU ĐĂNG KÈO GIAO HỮU MỚI 2 CỘT (SCREEN 12)
     ========================================================================== -->
<div class="sport-page-head" id="create-match-form" style="margin-top:26px">
  <div>
    <h2>Đăng kèo giao hữu mới</h2>
    <small>Tạo hồ sơ trận đấu để các đội bóng khác bắt đối nhanh chóng</small>
  </div>
  <div>
    <a href="#" style="font-size:12.5px;font-weight:700;color:#2563eb">Quay lại ↑</a>
  </div>
</div>

<?php if (!$u): ?>
  <div class="box">
    <div class="empty">
      Vui lòng <a href="../login.php"><b>Đăng nhập</b></a> để đăng kèo giao hữu cho đội bóng của bạn.
    </div>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:1.45fr 1fr;gap:22px;align-items:start" id="newMatchBox">
    <!-- Cột trái: Biểu mẫu Thông tin kèo đấu (Screen 12) -->
    <div class="box" style="margin:0">
      <div class="bh">
        <b>📝 Thông tin kèo đấu</b>
      </div>

      <form method="post" id="createMatchForm" oninput="updateMatchLivePreview()" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="create_match">
        <input type="hidden" name="spots_needed" value="1">

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Tên đội bóng</label>
          <input id="inpTeamName" name="team_name" required value="FC <?=e($u['full_name'])?>" placeholder="FC Anh Em Quận 1" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
        </div>

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Tiêu đề kèo giao hữu</label>
          <input id="inpTitleHint" placeholder="Giao hữu nhẹ nhàng sau giờ làm" value="Giao hữu nhẹ nhàng sau giờ làm" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
        </div>

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Hình thức</label>
          <select id="inpMatchType" name="match_type" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
            <option value="tim_doi">Tìm đối giao hữu</option>
            <option value="tuyen_nguoi">Tuyển thêm cầu thủ</option>
          </select>
        </div>

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Sân bóng thi đấu</label>
          <select id="inpFieldId" name="field_id" required style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
            <?php foreach ($fields as $f): ?>
              <option
                value="<?=$f['id']?>"
                data-label="<?=e($f['name'])?>"
                data-img="<?=e(img_src($f['image']))?>"
                data-type="Sân <?=$f['type']?>"
              >
                <?=e($f['name'])?> (Sân <?=$f['type']?> - <?=e($f['district'])?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Ngày đá</label>
          <input id="inpMatchDate" type="date" name="match_date" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Giờ bắt đầu</label>
            <select id="inpStartTime" name="start_time" style="width:100%;padding:10px;border-radius:8px;border:1px solid #cbd5e1">
              <?php for ($t = strtotime('06:00'); $t <= strtotime('22:00'); $t += 1800): $v = date('H:i', $t); ?>
                <option value="<?=$v?>" <?=$v === '19:00' ? 'selected' : ''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Giờ kết thúc</label>
            <select id="inpEndTime" name="end_time" style="width:100%;padding:10px;border-radius:8px;border:1px solid #cbd5e1">
              <?php for ($t = strtotime('07:00'); $t <= strtotime('23:00'); $t += 1800): $v = date('H:i', $t); ?>
                <option value="<?=$v?>" <?=$v === '20:30' ? 'selected' : ''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Tỷ lệ chia tiền sân</label>
          <input id="inpFeeShare" name="fee_share" value="Chia đôi 50/50" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
        </div>

        <div>
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Trình độ mong muốn</label>
          <select id="inpSkillLevel" name="skill_level" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
            <option value="phong_trao">Trung bình - Khá</option>
            <option value="vui_ve">Vui vẻ / Văn phòng</option>
            <option value="ban_chuyen">Bán chuyên / Đối cứng</option>
          </select>
        </div>

        <div style="grid-column:1 / -1">
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Số điện thoại liên hệ</label>
          <input id="inpPhone" name="contact_phone" required value="<?=e($u['phone'])?>" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1">
        </div>

        <div style="grid-column:1 / -1">
          <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px">Lời nhắn cho đối thủ</label>
          <textarea id="inpNote" name="note" rows="2" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1;font-family:inherit">Đội mình đá vui vẻ, lịch sự. Có sẵn nước uống miễn phí!</textarea>
        </div>

        <div style="grid-column:1 / -1">
          <button type="submit" class="btn" style="padding:12px 22px">+ ĐĂNG KÈO TÌM ĐỐI →</button>
        </div>
      </form>
    </div>

    <!-- Cột phải: Xem trước thẻ kèo (Screen 12) -->
    <div class="box" style="margin:0">
      <div class="bh">
        <b>👁 Xem trước thẻ kèo</b>
      </div>

      <div style="position:relative;height:145px;border-radius:12px;overflow:hidden;margin-bottom:12px;background:#e2e8f0">
        <img id="prvImg" src="<?=e(img_src($fields[0]['image'] ?? ''))?>" alt="" style="width:100%;height:100%;object-fit:cover">
        <span style="position:absolute;top:10px;left:10px;background:#16a34a;color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px">
          ● Đang trống sân
        </span>
      </div>

      <b id="prvTeam" style="font-size:16px;color:#0f172a;display:block;margin-bottom:6px">FC <?=e($u['full_name'])?></b>

      <div style="display:flex;gap:6px;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #f1f5f9">
        <span class="tag amber">Đang tìm đối</span>
        <span id="prvPitchType" class="tag green">Sân <?=e($fields[0]['type'] ?? '5')?></span>
      </div>

      <div style="font-size:13px;color:#334155;display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
        <div>📅 <b id="prvDate"><?=date('d/m/Y')?></b> • <b id="prvTime" style="color:#16a34a">19:00 - 20:30</b></div>
        <div>📍 <b id="prvField"><?=e($fields[0]['name'] ?? 'Sân Minh Phát')?></b></div>
        <div>💰 <b id="prvFee">Chia đôi 50/50</b></div>
        <div>🏆 Trình độ: <b id="prvSkill">Trung bình - Khá</b></div>
      </div>

      <div style="background:#f0fdf4;border:1px solid #dcfce7;border-radius:10px;padding:12px;font-size:11.5px;color:#15803d;line-height:1.5">
        💡 Kèo sẽ xuất hiện trên sàn ngay sau khi đăng. Đối thủ có thể bấm nhận kèo tức thì!
      </div>
    </div>
  </div>

  <script>
    function updateMatchLivePreview() {
      var team = document.getElementById('inpTeamName');
      var fieldSel = document.getElementById('inpFieldId');
      var dateEl = document.getElementById('inpMatchDate');
      var stEl = document.getElementById('inpStartTime');
      var enEl = document.getElementById('inpEndTime');
      var feeEl = document.getElementById('inpFeeShare');
      var skillEl = document.getElementById('inpSkillLevel');

      if (team) document.getElementById('prvTeam').textContent = team.value || 'FC Đội Chủ Nhà';
      if (fieldSel && fieldSel.selectedOptions[0]) {
        var opt = fieldSel.selectedOptions[0];
        document.getElementById('prvField').textContent = opt.getAttribute('data-label') || opt.textContent;
        if (opt.getAttribute('data-img')) document.getElementById('prvImg').src = opt.getAttribute('data-img');
        if (opt.getAttribute('data-type')) document.getElementById('prvPitchType').textContent = opt.getAttribute('data-type');
      }
      if (stEl && enEl) document.getElementById('prvTime').textContent = stEl.value + ' - ' + enEl.value;
      if (dateEl && dateEl.value) {
        var p = dateEl.value.split('-');
        if (p.length === 3) document.getElementById('prvDate').textContent = p[2] + '/' + p[1] + '/' + p[0];
      }
      if (feeEl) document.getElementById('prvFee').textContent = feeEl.value || 'Chia đôi 50/50';
      if (skillEl && skillEl.selectedOptions[0]) {
        document.getElementById('prvSkill').textContent = skillEl.selectedOptions[0].textContent;
      }
    }
  </script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
