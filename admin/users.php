<?php
/**
 * ============================================================================
 * FILE: admin/users.php
 * MÔ TẢ: Trang Quản lý Người dùng dành cho Quản trị viên (Admin).
 * CẤU TRÚC CHÍNH:
 *   - Phần 1: Xử lý khóa/mở khóa tài khoản hoặc thay đổi phân quyền User/Admin (POST).
 *   - Phần 2: Truy vấn tìm kiếm tài khoản và lấy lịch sử đặt sân của khách (?view=ID).
 *   - Phần 3: Giao diện thanh tìm kiếm, hộp lịch sử đặt sân và bảng người dùng.
 * ============================================================================
 */

require_once '../includes/auth.php';

// Yêu cầu quyền Quản trị viên
$adm = require_admin();

// ----------------------------------------------------------------------------
// PHẦN 1: XỬ LÝ KHÓA / MỞ KHÓA TÀI KHOẢN HOẶC ĐỔI QUYỀN (POST)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    // Không cho phép Admin tự khóa hoặc tự hạ quyền của chính mình
    if ($id === (int)$adm['id']) {
        flash('err', 'Không thể tự thay đổi trạng thái hoặc quyền của chính tài khoản đang đăng nhập.');
    }
    // Đổi trạng thái Hoạt động (active) <-> Đã khóa (locked)
    elseif ($action === 'toggle') {
        $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'locked', 'active') WHERE id = ?");
        $stmt->execute([$id]);
        flash('ok', 'Đã thay đổi trạng thái hoạt động của tài khoản.');
    }
    // Đổi vai trò Quản trị viên (admin) <-> Khách hàng (user)
    elseif ($action === 'role') {
        $stmt = $pdo->prepare("UPDATE users SET role = IF(role = 'admin', 'user', 'admin') WHERE id = ?");
        $stmt->execute([$id]);
        flash('ok', 'Đã cập nhật phân quyền cho tài khoản.');
    }

    header('Location: users.php');
    exit;
}

// ----------------------------------------------------------------------------
// PHẦN 2: TRUY VẤN DANH SÁCH NGƯỜI DÙNG & LỊCH SỬ ĐẶT SÂN CỦA KHÁCH HÀNG
// ----------------------------------------------------------------------------

// 2.1. Tìm kiếm người dùng theo username, họ tên hoặc email kèm tổng số lượt đặt sân
$q = trim($_GET['q'] ?? '');
$s = $pdo->prepare("
    SELECT
        u.*,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.id) AS n
    FROM users u
    WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ?
    ORDER BY id ASC
");
$s->execute(["%$q%", "%$q%", "%$q%"]);
$rows = $s->fetchAll();

// 2.2. Nếu Admin bấm nút "Lịch sử" (?view=ID), truy vấn danh sách các đơn đặt của khách đó
$hist = [];
$hu   = null;

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
        ");
        $s->execute([$hu['id']]);
        $hist = $s->fetchAll();
    }
}

// Bảng tên trạng thái đơn đặt sân tiếng Việt
$lbl = [
    'pending'   => 'Chờ xác nhận',
    'confirmed' => 'Đã xác nhận',
    'cancelled' => 'Đã hủy',
    'completed' => 'Hoàn thành'
];

$page  = 'users';
$title = 'Quản lý người dùng';
include '../includes/admin_header.php';
?>

<!-- ==========================================================================
     PHẦN 3: GIAO DIỆN TÌM KIẾM, XEM LỊCH SỬ & BẢNG DANH SÁCH NGƯỜI DÙNG
     ========================================================================== -->

<!-- 3.1. Thanh tìm kiếm tài khoản -->
<form class="sbar" method="get" style="margin:0 0 14px">
  <input
    name="q"
    placeholder="Tìm theo tên đăng nhập, họ tên hoặc email..."
    value="<?=e($q)?>"
  >
  <button class="btn" type="submit">🔍 Tìm kiếm</button>
</form>

<!-- 3.2. Hộp hiển thị chi tiết lịch sử đặt sân của khách hàng đang chọn (?view=ID) -->
<?php if ($hu): ?>
  <div class="box" style="margin-bottom:14px">
    <div class="bh">
      <b>📋 Lịch sử đặt sân của khách hàng: <?=e($hu['full_name'])?> (SĐT: <?=e($hu['phone'])?>)</b>
      <a href="users.php" class="btn sm red">✕ Đóng</a>
    </div>

    <?php if (!$hist): ?>
      <div class="empty">Khách hàng này chưa có lịch đặt sân nào.</div>
    <?php else: ?>
      <div class="tw">
        <table>
          <tr>
            <th>Sân bóng</th>
            <th>Ngày thi đấu</th>
            <th>Khung giờ</th>
            <th>Tổng tiền</th>
            <th>Trạng thái</th>
          </tr>
          <?php foreach ($hist as $h): ?>
            <tr>
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

<!-- 3.3. Bảng danh sách toàn bộ tài khoản người dùng trong hệ thống -->
<div class="box">
  <?php if (!$rows): ?>
    <div class="empty">Không tìm thấy người dùng nào phù hợp.</div>
  <?php else: ?>
    <div class="tw">
      <table>
        <tr>
          <th>Tài khoản</th>
          <th>Họ và tên</th>
          <th>Email</th>
          <th>Số điện thoại</th>
          <th>Vai trò</th>
          <th>Trạng thái</th>
          <th>Lượt đặt</th>
          <th>Thao tác</th>
        </tr>
        <?php foreach ($rows as $r): $self = ($r['id'] == $adm['id']); ?>
          <tr>
            <td><b><?=e($r['username'])?></b></td>
            <td><?=e($r['full_name'])?></td>
            <td><?=e($r['email'])?></td>
            <td><?=e($r['phone'])?></td>
            <td>
              <span class="tag <?=$r['role'] === 'admin' ? 'blue' : 'green'?>">
                <?=$r['role'] === 'admin' ? 'Quản trị (Admin)' : 'Khách hàng (User)'?>
              </span>
            </td>
            <td>
              <span class="tag <?=$r['status'] === 'active' ? 'green' : 'gray'?>">
                <?=$r['status'] === 'active' ? 'Hoạt động' : 'Đã khóa'?>
              </span>
            </td>
            <td><b><?=$r['n']?></b> đơn</td>
            <td>
              <!-- Nút xem lịch sử đặt sân của khách hàng -->
              <a class="btn sm bl" href="?view=<?=$r['id']?>">📋 Lịch sử</a>

              <!-- Nếu không phải là chính tài khoản Admin đang đăng nhập thì hiện nút Khóa & Đổi quyền -->
              <?php if (!$self): ?>
                <?php
                $actions = [
                    [
                        'toggle',
                        $r['status'] === 'active' ? '🔒 Khóa' : '🔓 Mở khóa',
                        'red',
                        'Xác nhận thay đổi trạng thái hoạt động của tài khoản này?'
                    ],
                    [
                        'role',
                        $r['role'] === 'admin' ? '⬇ Hạ xuống User' : '⬆ Lên Admin',
                        'pu',
                        'Xác nhận thay đổi phân quyền của tài khoản này?'
                    ]
                ];
                foreach ($actions as $b):
                ?>
                  <form class="inl" method="post" onsubmit="return confirm('<?=$b[3]?>')">
                    <?=csrf_field()?>
                    <input type="hidden" name="id" value="<?=$r['id']?>">
                    <input type="hidden" name="action" value="<?=$b[0]?>">
                    <button class="btn sm <?=$b[2]?>" type="submit"><?=$b[1]?></button>
                  </form>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include '../includes/admin_footer.php'; ?>
