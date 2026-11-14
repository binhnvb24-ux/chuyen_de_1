<?php
/**
 * ============================================================================
 * FILE: admin/revenue.php
 * MÔ TẢ: Trang Thống kê Dòng tiền Admin Thu hộ, Doanh thu Phí sàn & Đối soát Chủ sân.
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xác định khoảng ngày lọc, tỷ lệ phí sàn và nhóm dữ liệu theo Ngày / Tháng / Năm.
 *   - Phần 2: Truy vấn Bảng Đối Soát Doanh Thu & Phí Sàn Theo Từng Chủ Sân.
 *   - Phần 3: Xuất báo cáo doanh thu & phí sàn ra file Excel/CSV khi có tham số ?export=csv.
 *   - Phần 4: Giao diện 4 thẻ KPI dòng tiền, biểu đồ Chart.js, bảng theo kỳ & bảng đối soát Chủ sân.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XÁC ĐỊNH KHOẢNG THỜI GIAN LỌC, TỶ LỆ PHÍ SÀN & CHẾ ĐỘ NHÓM
// ----------------------------------------------------------------------------
[$from, $to] = get_range();
$feePct      = get_platform_fee_percent();
$ownerPct    = 100 - $feePct;

$modes = [
    'day'   => ['%Y-%m-%d', 'Ngày'],
    'month' => ['%Y-%m',    'Tháng'],
    'year'  => ['%Y',       'Năm']
];

$mode = isset($modes[$_GET['mode'] ?? '']) ? $_GET['mode'] : 'day';
$fmt  = $modes[$mode][0];

// Quy tắc tính doanh thu: Chỉ tính các đơn Đã xác nhận (confirmed) và Hoàn thành (completed)
$s = $pdo->prepare("
    SELECT
        DATE_FORMAT(booking_date, '$fmt') AS g,
        COUNT(*) AS n,
        SUM(total_price) AS t
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
      AND booking_date BETWEEN ? AND ?
    GROUP BY g
    ORDER BY g ASC
");
$s->execute([$from, $to]);
$rows = $s->fetchAll();

$totalGross    = (int)array_sum(array_column($rows, 't'));
$cnt           = (int)array_sum(array_column($rows, 'n'));
$totalAdminFee = (int)round($totalGross * ($feePct / 100));
$totalOwnerNet = max(0, $totalGross - $totalAdminFee);

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN BẢNG ĐỐI SOÁT DÒNG TIỀN & PHÍ SÀN THEO TỪNG CHỦ SÂN TRONG KỲ
// ----------------------------------------------------------------------------
$stOwnerRec = $pdo->prepare("
    SELECT
        u.id AS owner_id,
        u.full_name,
        u.username,
        u.phone,
        COUNT(DISTINCT f.id) AS pitch_count,
        COUNT(b.id) AS booking_count,
        COALESCE(SUM(b.total_price), 0) AS gross_revenue,
        (SELECT CONCAT(pa.bank_name, ' • ', pa.bank_account_number, ' (', pa.bank_account_holder, ')')
         FROM partner_applications pa
         WHERE pa.user_id = u.id AND pa.status = 'APPROVED'
         ORDER BY pa.id DESC LIMIT 1) AS bank_info
    FROM users u
    INNER JOIN fields f ON f.owner_id = u.id
    LEFT JOIN bookings b
        ON b.field_id = f.id
       AND b.status IN ('confirmed', 'completed')
       AND b.booking_date BETWEEN ? AND ?
    WHERE u.account_role = 'PITCH_OWNER' OR u.role = 'pitch_owner' OR f.owner_id IS NOT NULL
    GROUP BY u.id, u.full_name, u.username, u.phone
    ORDER BY gross_revenue DESC, pitch_count DESC
");
$stOwnerRec->execute([$from, $to]);
$ownerReconcileRows = $stOwnerRec->fetchAll();

// ----------------------------------------------------------------------------
// PHẦN 3: XUẤT BÁO CÁO DOANH THU & ĐỐI SOÁT PHÍ SÀN RA FILE EXCEL / CSV (?export=csv)
// ----------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bao-cao-doanh-thu-phi-san-' . $from . '-den-' . $to . '.csv"');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        $modes[$mode][1],
        'Số đơn thành công',
        'Tổng tiền về TK Admin (100%)',
        "Phí sàn Admin hưởng ({$feePct}%)",
        "Doanh thu thực trả Chủ sân ({$ownerPct}%)"
    ]);

    foreach ($rows as $r) {
        $gAmt = (int)$r['t'];
        $fAmt = (int)round($gAmt * ($feePct / 100));
        $nAmt = max(0, $gAmt - $fAmt);
        fputcsv($out, [$r['g'], $r['n'], $gAmt, $fAmt, $nAmt]);
    }

    fputcsv($out, ['TỔNG CỘNG', $cnt, $totalGross, $totalAdminFee, $totalOwnerNet]);
    fclose($out);
    exit;
}

$mode_options = '';
foreach ($modes as $k => $v) {
    $selected      = ($k === $mode) ? ' selected' : '';
    $mode_options .= '<option value="' . $k . '"' . $selected . '>Theo ' . mb_strtolower($v[1]) . '</option>';
}

$extra  = '<select name="mode">' . $mode_options . '</select>';
$extra .= ' <a class="btn bl" href="?from=' . urlencode($from) . '&to=' . urlencode($to) . '&mode=' . urlencode($mode) . '&export=csv">📥 Xuất Đối Soát Excel (CSV)</a>';

$page  = 'revenue';
$title = 'Thống kê Dòng tiền Admin & Đối soát Phí sàn (' . $feePct . '%)';
include '../includes/admin_header.php';

echo range_form($extra);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- 4.1. Bốn thẻ thống kê Dòng tiền Admin thu hộ, Phí sàn và Thực trả Chủ sân -->
<div class="stats">
  <div class="stat">
    <span class="ic">🏦</span>
    <div>
      <small>Tổng dòng tiền về TK Admin (100%)</small>
      <b><?=money($totalGross)?></b>
    </div>
  </div>

  <div class="stat">
    <span class="ic">💎</span>
    <div>
      <small>Doanh thu Phí sàn Admin (<?=$feePct?>%)</small>
      <b style="color:#f59e0b"><?=money($totalAdminFee)?></b>
    </div>
  </div>

  <div class="stat">
    <span class="ic">🏟</span>
    <div>
      <small>Thực trả các Chủ sân (<?=$ownerPct?>%)</small>
      <b style="color:#10b981"><?=money($totalOwnerNet)?></b>
    </div>
  </div>

  <div class="stat">
    <span class="ic">📅</span>
    <div>
      <small>Số lượt đặt thành công</small>
      <b><?=$cnt?> đơn</b>
    </div>
  </div>
</div>

<!-- 4.2. Biểu đồ cột và bảng chi tiết doanh thu & phí sàn -->
<?php if (!$rows): ?>
  <div class="box">
    <div class="empty">Không có dữ liệu doanh thu trong khoảng thời gian này.</div>
  </div>
<?php else: ?>
  <div class="two">
    <div class="box">
      <div class="bh">
        <b>📊 Biểu đồ Tổng thu vs Thực trả Chủ sân theo <?=mb_strtolower($modes[$mode][1])?></b>
      </div>
      <div style="height:280px">
        <canvas id="ch"></canvas>
      </div>
    </div>

    <div class="box">
      <div class="bh">
        <b>📋 Bảng kê Dòng tiền & Khấu trừ Phí sàn (<?=$feePct?>%)</b>
      </div>
      <div class="tw">
        <table>
          <tr>
            <th><?=$modes[$mode][1]?></th>
            <th>Số đơn</th>
            <th>Về TK Admin (100%)</th>
            <th>Phí sàn (<?=$feePct?>%)</th>
            <th>Trả Chủ sân (<?=$ownerPct?>%)</th>
          </tr>
          <?php foreach ($rows as $r):
              $gAmt = (int)$r['t'];
              $fAmt = (int)round($gAmt * ($feePct / 100));
              $nAmt = max(0, $gAmt - $fAmt);
          ?>
            <tr>
              <td><b><?=e($r['g'])?></b></td>
              <td><?=$r['n']?> đơn</td>
              <td><b><?=money($gAmt)?></b></td>
              <td style="color:#f59e0b"><b><?=money($fAmt)?></b></td>
              <td style="color:#10b981"><b><?=money($nAmt)?></b></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <script>
    new Chart(document.getElementById('ch'), {
      type: 'bar',
      data: {
        labels: <?=json_encode(array_column($rows, 'g'))?>,
        datasets: [
          {
            label: 'Tổng thu về TK Admin (100%)',
            data: <?=json_encode(array_map('intval', array_column($rows, 't')))?>,
            backgroundColor: '#3b82f6',
            borderRadius: 6
          },
          {
            label: 'Thực trả Chủ sân (<?=$ownerPct?>%)',
            data: <?=json_encode(array_map(fn($x) => max(0, (int)$x - (int)round(((int)$x) * ($feePct / 100))), array_column($rows, 't')))?>,
            backgroundColor: '#10b981',
            borderRadius: 6
          }
        ]
      },
      options: {
        maintainAspectRatio: false
      }
    });
  </script>
<?php endif; ?>

<!-- 4.3. Bảng Đối soát Doanh thu & Phí sàn theo từng Chủ sân Đối tác -->
<div class="box" style="margin-top:18px;border-left:4px solid #10b981">
  <div class="bh">
    <b>🤝 Bảng Đối Soát Doanh Thu & Phí Sàn Theo Từng Chủ Sân (Kỳ: <?=e($from)?> → <?=e($to)?>)</b>
    <a href="settings.php" class="btn sm bl">⚙ Chỉnh tỷ lệ Phí sàn (Hiện tại: <?=$feePct?>%)</a>
  </div>
  <div class="tw">
    <table>
      <tr>
        <th>Chủ sân Đối tác</th>
        <th>Số sân</th>
        <th>Đơn thành công</th>
        <th>Tổng tiền về TK Admin (100%)</th>
        <th>Phí sàn Admin giữ (<?=$feePct?>%)</th>
        <th>Chủ sân thực nhận (<?=$ownerPct?>%)</th>
        <th>Tài khoản Ngân hàng nhận đối soát</th>
      </tr>
      <?php foreach ($ownerReconcileRows as $orc):
          $oGross = (int)$orc['gross_revenue'];
          $oFee   = (int)round($oGross * ($feePct / 100));
          $oNet   = max(0, $oGross - $oFee);
      ?>
        <tr>
          <td>
            <b><?=e($orc['full_name'])?></b>
            <small style="display:block">@<?=e($orc['username'])?> • 📞 <?=e($orc['phone'])?></small>
          </td>
          <td><b><?=(int)$orc['pitch_count']?></b> sân</td>
          <td><b><?=(int)$orc['booking_count']?></b> đơn</td>
          <td><b><?=money($oGross)?></b></td>
          <td style="color:#f59e0b"><b><?=money($oFee)?></b></td>
          <td><b style="color:#10b981;font-size:15px"><?=money($oNet)?></b></td>
          <td><small><?=e($orc['bank_info'] ?: 'Nhận qua tài khoản đăng ký đối tác')?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
