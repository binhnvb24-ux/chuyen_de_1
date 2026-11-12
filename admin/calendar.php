<?php
/**
 * FILE: admin/calendar.php
 * MÔ TẢ: Trang xem lịch đặt sân trực quan theo Tuần / Tháng / Ngày.
 * CHỨC NĂNG: Biểu diễn toàn bộ các ca đặt sân trên lịch bằng thư viện FullCalendar.
 */
require_once '../includes/auth.php';
$adm = require_admin();

// Lấy danh sách lượt đặt để truyền vào FullCalendar
$stmt = $pdo->query("SELECT b.id, f.name fname, u.full_name, b.booking_date, b.start_time, b.end_time, b.status 
                     FROM bookings b JOIN fields f ON f.id=b.field_id JOIN users u ON u.id=b.user_id 
                     WHERE b.status IN('confirmed','pending','completed')");
$events = [];
$colors = ['pending' => '#3b82f6', 'confirmed' => '#16a34a', 'completed' => '#059669'];

foreach($stmt->fetchAll() as $r){
  $events[] = [
    'title' => $r['fname'] . ' - ' . $r['full_name'],
    'start' => $r['booking_date'] . 'T' . $r['start_time'],
    'end'   => $r['booking_date'] . 'T' . $r['end_time'],
    'color' => $colors[$r['status']] ?? '#6b7280',
    'url'   => 'bookings.php?q=' . urlencode($r['fname'])
  ];
}

$page = 'calendar'; $title = 'Lịch trực quan'; include '../includes/admin_header.php';
?>

<!-- Import FullCalendar Library -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>

<div class="box">
  <div id='calendar' style="max-width: 100%; height: 650px;"></div>
</div>

<!-- Khởi tạo và hiển thị lịch FullCalendar -->
<script>
  document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
      initialView: 'timeGridWeek',
      locale: 'vi',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay'
      },
      events: <?=json_encode($events)?>
    });
    calendar.render();
  });
</script>

<?php include '../includes/admin_footer.php'; ?>
