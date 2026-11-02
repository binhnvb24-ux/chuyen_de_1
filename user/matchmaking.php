<?php
/**
 * FILE: user/matchmaking.php
 * MÔ TẢ: Sàn Cáp Kèo & Tìm Đối Giao Hữu / Tuyển Cầu Thủ Phủi (Matchmaking Lobby).
 * CHỨC NĂNG: Đăng kèo tìm đối thủ (5v5, 7v7, 11v11) hoặc tuyển thêm người đá ghép,
 *            nhận kèo trực tuyến, gửi thông báo tự động cho đội chủ nhà và lọc kèo theo trình độ.
 */
require_once '../includes/auth.php';
$u = current_user();
if ($u && $u['role'] === 'admin') { header('Location: ../admin/dashboard.php'); exit; }

// 1. Xử lý các thao tác: Đăng kèo mới, Nhận kèo (Bắt đối) hoặc Hủy kèo (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = require_login();
  csrf_check();
  $act = $_POST['action'] ?? '';

  // 1a. Đăng kèo tìm đối / tuyển người mới
  if ($act === 'create_match') {
    $fid = (int)($_POST['field_id'] ?? 0);
    $team = trim($_POST['team_name'] ?? '');
    $date = $_POST['match_date'] ?? '';
    $st = $_POST['start_time'] ?? '18:00';
    $en = $_POST['end_time'] ?? '19:30';
    $type = in_array($_POST['match_type'] ?? '', ['tim_doi','tuyen_nguoi']) ? $_POST['match_type'] : 'tim_doi';
    $lvl = in_array($_POST['skill_level'] ?? '', ['vui_ve','phong_trao','ban_chuyen']) ? $_POST['skill_level'] : 'phong_trao';
    $spots = max(1, min(15, (int)($_POST['spots_needed'] ?? 1)));
    $fee = trim($_POST['fee_share'] ?? 'Chia đôi 50/50') ?: 'Chia đôi 50/50';
    $phone = trim($_POST['contact_phone'] ?? $u['phone']);
    $note = trim($_POST['note'] ?? '');

    if (!$fid || $team === '' || $date < date('Y-m-d')) {
      flash('err', 'Vui lòng nhập tên đội bóng, chọn sân và ngày thi đấu hợp lệ.');
    } else {
      $pdo->prepare("INSERT INTO match_requests(creator_id,field_id,team_name,match_date,start_time,end_time,skill_level,match_type,spots_needed,fee_share,contact_phone,note,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'open')")
          ->execute([$u['id'], $fid, $team, $date, $st, $en, $lvl, $type, $spots, $fee, $phone, $note]);
      flash('ok', "🔥 Đã đăng kèo của đội '$team' lên Sàn Cáp Kèo thành công!");
    }
    header('Location: matchmaking.php');
    exit;
  }

  // 1b. Nhận kèo / Bắt đối giao hữu
  if ($act === 'accept_match') {
    $mid = (int)($_POST['match_id'] ?? 0);
    $opp_name = trim($_POST['opponent_name'] ?? ('FC ' . $u['full_name']));
    $opp_phone = trim($_POST['opponent_phone'] ?? $u['phone']);

    $stmt = $pdo->prepare("SELECT m.*, f.name fname FROM match_requests m JOIN fields f ON f.id=m.field_id WHERE m.id=? AND m.status='open'");
    $stmt->execute([$mid]);
    $m = $stmt->fetch();

    if (!$m) {
      flash('err', 'Kèo này không tồn tại hoặc đã có đội khác nhận.');
    } elseif ((int)$m['creator_id'] === (int)$u['id']) {
      flash('err', 'Bạn không thể tự nhận kèo do chính đội mình đăng.');
    } elseif ($opp_name === '' || $opp_phone === '') {
      flash('err', 'Vui lòng nhập Tên đội nhận kèo và Số điện thoại liên hệ.');
    } else {
      $pdo->prepare("UPDATE match_requests SET status='matched', opponent_id=?, opponent_name=?, opponent_phone=? WHERE id=?")
          ->execute([$u['id'], $opp_name, $opp_phone, $mid]);
      // Gửi thông báo cho cả đội chủ nhà và đội nhận kèo
      notify($m['creator_id'], "⚔️ Kèo tại {$m['fname']} ngày ".date('d/m/Y',strtotime($m['match_date']))." đã được đội '$opp_name' (SĐT: $opp_phone) nhận kèo!");
      notify($u['id'], "🤝 Bạn đã chốt kèo giao hữu với đội '{$m['team_name']}' (SĐT: {$m['contact_phone']}) tại {$m['fname']} ngày ".date('d/m/Y',strtotime($m['match_date'])).".");
      flash('ok', "🎉 Chốt kèo thành công! Hãy liên hệ đội chủ nhà {$m['team_name']} qua SĐT {$m['contact_phone']}.");
    }
    header('Location: matchmaking.php');
    exit;
  }

  // 1c. Hủy kèo do mình tạo
  if ($act === 'cancel_match') {
    $mid = (int)($_POST['match_id'] ?? 0);
    $pdo->prepare("UPDATE match_requests SET status='cancelled' WHERE id=? AND creator_id=?")->execute([$mid, $u['id']]);
    flash('ok', 'Đã hủy kèo giao hữu của bạn.');
    header('Location: matchmaking.php');
    exit;
  }
}

// 2. Truy vấn danh sách sân và danh sách Kèo Giao Hữu theo bộ lọc
$fields = $pdo->query("SELECT * FROM fields WHERE status='active' ORDER BY name")->fetchAll();
$f_type = $_GET['match_type'] ?? '';
$f_lvl = $_GET['skill_level'] ?? '';
$f_status = $_GET['status'] ?? 'open';

$sql = "SELECT m.*, f.name fname, f.type ftype, f.district, f.address, u.full_name creator_name 
        FROM match_requests m 
        JOIN fields f ON f.id = m.field_id 
        JOIN users u ON u.id = m.creator_id 
        WHERE m.status <> 'cancelled'";
$params = [];
if ($f_status === 'open') { $sql .= " AND m.status='open'"; }
elseif ($f_status === 'matched') { $sql .= " AND m.status='matched'"; }
if (in_array($f_type, ['tim_doi','tuyen_nguoi'])) { $sql .= " AND m.match_type=?"; $params[] = $f_type; }
if (in_array($f_lvl, ['vui_ve','phong_trao','ban_chuyen'])) { $sql .= " AND m.skill_level=?"; $params[] = $f_lvl; }
$sql .= " ORDER BY m.status ASC, m.match_date ASC, m.start_time ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$lvlMap = [
  'vui_ve'     => ['😊 Vui vẻ / Rèn sức khỏe', 'green'],
  'phong_trao' => ['⚽ Phong trào', 'blue'],
  'ban_chuyen' => ['🔥 Bán chuyên / Đối cứng', 'amber']
];

$active = 'match';
$title = 'Sàn Cáp Kèo & Tìm Đối Giao Hữu';
include '../includes/header.php';
?>

<!-- 3. Banner Tiêu đề Sàn Cáp Kèo -->
<div class="sport-page-head">
  <div>
    <h2>⚔️ SÀN CÁP KÈO & TÌM ĐỐI GIAO HỮU</h2>
    <small>Nơi các FC giao lưu cọ xát 5v5, 7v7, 11v11 hoặc tuyển thêm cầu thủ phủi còn thiếu cho trận đấu</small>
  </div>
  <button type="button" class="btn" onclick="document.getElementById('newMatchBox').style.display = document.getElementById('newMatchBox').style.display === 'none' ? 'block' : 'none'">
    + Đăng Kèo Tìm Đối Mới
  </button>
</div>

<!-- 4. Biểu mẫu Đăng Kèo Mới (Ẩn/Hiện linh hoạt) -->
<div class="box" id="newMatchBox" style="display:<?=isset($_GET['new'])?'block':'none'?>;border:2px solid #16a34a;background:#f0fdf4">
  <h3 style="margin:0 0 14px;font-family:var(--font-sport);font-size:19px;color:#14532d">🔥 ĐĂNG KÈO TÌM ĐỐI THỦ / TUYỂN CẦU THỦ</h3>
  <?php if (!$u): ?>
    <div class="empty">Vui lòng <a href="../login.php"><b>Đăng nhập</b></a> để đăng kèo giao hữu cho đội bóng của bạn.</div>
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
          <?php foreach($fields as $f): ?>
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
            <?php for($h=6;$h<=22;$h++): $v=sprintf('%02d:00',$h); ?><option value="<?=$v?>" <?=$h==18?'selected':''?>><?=$v?></option><?php endfor; ?>
          </select>
        </div>
        <div>
          <label style="font-size:12.5px;font-weight:700">Kết thúc</label>
          <select name="end_time" style="width:100%;padding:10px;border-radius:8px;border:1.5px solid #cbd5e1">
            <?php for($h=7;$h<=23;$h++): $v=sprintf('%02d:00',$h); ?><option value="<?=$v?>" <?=$h==19?'selected':''?>><?=$v?></option><?php endfor; ?>
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

<!-- 5. Thanh Bộ lọc Kèo Giao Hữu -->
<div class="box" style="padding:16px 20px">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <select name="status" style="padding:10px 12px;border-radius:8px;border:1.5px solid #cbd5e1;font-weight:600">
      <option value="open" <?=$f_status==='open'?'selected':''?>>🟢 Kèo đang chờ đối</option>
      <option value="matched" <?=$f_status==='matched'?'selected':''?>>🤝 Kèo đã bắt đối thành công</option>
      <option value="all" <?=$f_status==='all'?'selected':''?>>📋 Tất cả kèo</option>
    </select>
    <select name="match_type" style="padding:10px 12px;border-radius:8px;border:1.5px solid #cbd5e1">
      <option value="">Tất cả hình thức</option>
      <option value="tim_doi" <?=$f_type==='tim_doi'?'selected':''?>>⚔️ Tìm đối cáp kèo</option>
      <option value="tuyen_nguoi" <?=$f_type==='tuyen_nguoi'?'selected':''?>>🏃 Tuyển thêm cầu thủ</option>
    </select>
    <select name="skill_level" style="padding:10px 12px;border-radius:8px;border:1.5px solid #cbd5e1">
      <option value="">Mọi trình độ</option>
      <option value="vui_ve" <?=$f_lvl==='vui_ve'?'selected':''?>>😊 Vui vẻ / Văn phòng</option>
      <option value="phong_trao" <?=$f_lvl==='phong_trao'?'selected':''?>>⚽ Phong trào</option>
      <option value="ban_chuyen" <?=$f_lvl==='ban_chuyen'?'selected':''?>>🔥 Bán chuyên</option>
    </select>
    <button class="btn sm" style="padding:10px 18px">🔍 Lọc kèo</button>
    <a href="matchmaking.php" style="font-size:13px;font-weight:600;margin-left:auto">Làm mới bộ lọc</a>
  </form>
</div>

<!-- 6. Danh sách các Thẻ Kèo Thi Đấu (Match Cards) -->
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
      $isOwner = ($u && (int)$u['id'] === (int)$m['creator_id']);
      $isOpen = ($m['status'] === 'open');
    ?>
      <div class="match-card">
        <div class="match-card-top">
          <span style="font-family:var(--font-sport);font-weight:700;font-size:13.5px;color:#4ade80">
            <?=$m['match_type']==='tim_doi' ? '⚔️ CÁP KÈO ĐỐI KHÁNG' : '🏃 TUYỂN THÊM '.$m['spots_needed'].' CẦU THỦ'?>
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
          <div style="font-size:13.5px"><b>🏟 Sân đấu:</b> <?=e($m['fname'])?> <span class="tag green" style="font-size:11px">Sân <?=$m['ftype']?>v<?=$m['ftype']?></span></div>
          <div style="font-size:13px;color:#475569">📍 <?=e($m['address'])?></div>
          <div style="font-size:13.5px"><b>📅 Thời gian:</b> <?=date('d/m/Y', strtotime($m['match_date']))?> • <b style="color:#16a34a"><?=substr($m['start_time'],0,5)?> - <?=substr($m['end_time'],0,5)?></b></div>
          <div style="font-size:13px"><b>💰 Tiền sân:</b> <span style="color:#0f172a;font-weight:700"><?=e($m['fee_share'])?></span></div>
          <div style="font-size:13px"><b>📞 Liên hệ Đội trưởng:</b> <a href="tel:<?=e($m['contact_phone'])?>" style="font-weight:800"><?=e($m['contact_phone'])?></a></div>

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
            <?php elseif ($isOpen && $isOwner): ?>
              <form method="post" onsubmit="return confirm('Bạn muốn hủy kèo giao hữu này?')" style="display:flex;justify-content:space-between;align-items:center">
                <?=csrf_field()?>
                <input type="hidden" name="action" value="cancel_match">
                <input type="hidden" name="match_id" value="<?=$m['id']?>">
                <span class="tag blue">Kèo của đội bạn</span>
                <button type="submit" class="btn sm red">✕ Hủy kèo</button>
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

