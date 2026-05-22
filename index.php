<?php
require __DIR__ . '/app/bootstrap.php';
require_login();

[$scopeSql, $scopeParams] = scope_clause($pdo, 'r');
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$monthStartDate = $month . '-01';
$start = $monthStartDate . ' 00:00:00';
$end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
$prevMonth = date('Y-m', strtotime($monthStartDate . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStartDate . ' +1 month'));
$params = array_merge($scopeParams, [$start, $end]);

$stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(status='approved') approved, SUM(status='pending') pending, COUNT(DISTINCT doctor_name) doctors FROM reports r WHERE $scopeSql AND visit_datetime >= ? AND visit_datetime < ?");
$stmt->execute($params);
$kpi = $stmt->fetch() ?: ['total'=>0,'approved'=>0,'pending'=>0,'doctors'=>0];

$stmt = $pdo->prepare("SELECT r.*, u.name rep FROM reports r JOIN users u ON u.id=r.user_id WHERE $scopeSql ORDER BY r.visit_datetime DESC LIMIT 8");
$stmt->execute($scopeParams);
$recent = $stmt->fetchAll();

[$eventScopeSql, $eventScopeParams] = scope_clause($pdo, 'e');
$eventCols = table_columns($pdo, 'events');
$eventStartColumn = in_array('start', $eventCols, true) ? 'start' : (in_array('start_datetime', $eventCols, true) ? 'start_datetime' : 'visit_datetime');
$eventEndColumn = in_array('end', $eventCols, true) ? 'end' : (in_array('end_datetime', $eventCols, true) ? 'end_datetime' : null);
$eventDescriptionColumn = in_array('description', $eventCols, true) ? 'description' : (in_array('notes', $eventCols, true) ? 'notes' : null);
$selectEnd = $eventEndColumn ? "e.`$eventEndColumn` AS task_end" : "NULL AS task_end";
$selectDescription = $eventDescriptionColumn ? "e.`$eventDescriptionColumn` AS task_notes" : "'' AS task_notes";
$joinDoctor = column_exists($pdo, 'events', 'doctor_id') && table_columns($pdo, 'doctors_masterlist');

$sql = "SELECT e.*, e.`$eventStartColumn` AS task_start, $selectEnd, $selectDescription, u.name AS rep_name";
$sql .= $joinDoctor ? ", d.dr_name, d.speciality, d.hospital_address, d.place" : ", NULL AS dr_name, NULL AS speciality, NULL AS hospital_address, NULL AS place";
$sql .= " FROM events e JOIN users u ON u.id = e.user_id ";
if ($joinDoctor) $sql .= "LEFT JOIN doctors_masterlist d ON d.id = e.doctor_id ";
$sql .= "WHERE $eventScopeSql AND e.`$eventStartColumn` >= ? AND e.`$eventStartColumn` < ? ORDER BY e.`$eventStartColumn` ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($eventScopeParams, [$start, $end]));

$events = [];
foreach ($stmt->fetchAll() as $ev) {
    $day = date('Y-m-d', strtotime((string)$ev['task_start']));
    $doctorName = trim((string)($ev['dr_name'] ?? ''));
    $fallbackDoctor = preg_replace('/^visit:\s*/i', '', (string)($ev['title'] ?? ''));
    $taskData = [
        'id' => (int)$ev['id'],
        'title' => (string)($ev['title'] ?? 'Task'),
        'start' => date('M d, Y g:i A', strtotime((string)$ev['task_start'])),
        'end' => !empty($ev['task_end']) ? date('M d, Y g:i A', strtotime((string)$ev['task_end'])) : '',
        'rep' => (string)($ev['rep_name'] ?? ''),
        'doctor' => $doctorName !== '' ? $doctorName : trim((string)$fallbackDoctor),
        'speciality' => (string)($ev['speciality'] ?? ''),
        'city' => (string)($ev['city'] ?? ($ev['place'] ?? '')),
        'hospital' => (string)($ev['hospital_name'] ?? ($ev['hospital_address'] ?? '')),
        'purpose' => (string)($ev['purpose'] ?? ''),
        'medicine' => (string)($ev['medicine_name'] ?? ''),
        'notes' => (string)($ev['task_notes'] ?? ($ev['summary'] ?? '')),
        'reportUrl' => 'report_form.php?task=' . (int)$ev['id'],
        'taskUrl' => 'tasks.php?view=' . (int)$ev['id'],
    ];
    $ev['task_json'] = json_encode($taskData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $events[$day][] = $ev;
}

$stmt = $pdo->prepare("SELECT u.name, COUNT(*) total FROM reports r JOIN users u ON u.id=r.user_id WHERE $scopeSql AND visit_datetime >= ? AND visit_datetime < ? GROUP BY u.id,u.name ORDER BY total DESC LIMIT 6");
$stmt->execute($params);
$repBars = $stmt->fetchAll();
$maxRep = max(1, ...array_map(fn($r)=>(int)$r['total'], $repBars ?: [['total'=>1]]));

$first = new DateTime($month . '-01');
$daysInMonth = (int)$first->format('t');
$offset = (int)$first->format('w');
$today = date('Y-m-d');

render_header('Dashboard');
?>
<div class="dashboard-hero reveal">
  <div>
    <span class="eyebrow">Live Workspace</span>
    <h2>Sales command center</h2>
    <p>Track reports, scheduled visits, doctor coverage, and team movement in one clean workspace.</p>
  </div>
  <div class="actions">
    <a class="btn ghost" href="tasks.php">Task Center</a>
    <a class="btn primary" href="report_form.php">Create Report</a>
  </div>
</div>

<div class="grid grid-4 dashboard-kpis reveal stagger-1" style="margin-bottom:18px">
  <div class="kpi"><span>Reports</span><strong><?= (int)$kpi['total'] ?></strong><small>This selected month</small></div>
  <div class="kpi"><span>Approved</span><strong><?= (int)$kpi['approved'] ?></strong><small>Completed reports</small></div>
  <div class="kpi"><span>Pending</span><strong><?= (int)$kpi['pending'] ?></strong><small>Need manager review</small></div>
  <div class="kpi"><span>Doctor Coverage</span><strong><?= (int)$kpi['doctors'] ?></strong><small>Unique doctors visited</small></div>
</div>

<div class="dashboard-layout">
  <div class="card calendar-card reveal stagger-2">
    <div class="section-title calendar-title">
      <div>
        <span class="eyebrow">Work Calendar</span>
        <h2><?= e(date('F Y', strtotime($start))) ?></h2>
        <p>Tap any task to preview details and generate a report directly.</p>
      </div>
      <div class="calendar-controls">
        <a class="btn small ghost" href="index.php?month=<?= e($prevMonth) ?>">Prev</a>
        <form><input class="input month-picker" type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()"></form>
        <a class="btn small ghost" href="index.php?month=<?= e($nextMonth) ?>">Next</a>
      </div>
    </div>
    <div class="calendar calendar-large">
      <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?><div class="cal-head"><?= $d ?></div><?php endforeach; ?>
      <?php for($i=0;$i<$offset;$i++): ?><div class="cal-day muted-day"></div><?php endfor; ?>
      <?php for($d=1;$d<=$daysInMonth;$d++): $date=$month.'-'.str_pad((string)$d,2,'0',STR_PAD_LEFT); ?>
        <div class="cal-day <?= $date === $today ? 'today' : '' ?>">
          <div class="day-num"><?= $d ?></div>
          <?php foreach(array_slice($events[$date] ?? [],0,4) as $ev): ?>
            <button class="cal-item" type="button" data-task-open data-task='<?= e($ev['task_json']) ?>'><?= e($ev['title']) ?></button>
          <?php endforeach; ?>
          <?php if(count($events[$date] ?? []) > 4): ?><span class="cal-more">+<?= count($events[$date]) - 4 ?> more</span><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <aside class="dashboard-side">
    <div class="card reveal stagger-3">
      <div class="section-title">
        <div><span class="eyebrow">Analytics Preview</span><h2>Reports by rep</h2></div>
        <a class="btn small" href="analytics.php">Open Analytics</a>
      </div>
      <?php if(!$repBars): ?>
        <div class="empty">No reports yet for this month.</div>
      <?php else: ?>
        <div class="chart-bars"><?php foreach($repBars as $bar): ?><div class="bar-row"><strong><?= e($bar['name']) ?></strong><div class="bar-track"><div class="bar-fill" style="width:<?= max(5, ((int)$bar['total']/$maxRep)*100) ?>%"></div></div><b><?= (int)$bar['total'] ?></b></div><?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <div class="card reveal stagger-4">
      <div class="section-title">
        <div><span class="eyebrow">Latest Reports</span><h2>Recent activity</h2></div>
        <a class="btn small" href="reports.php">View all</a>
      </div>
      <?php if(!$recent): ?>
        <div class="empty">No recent reports found.</div>
      <?php else: ?>
        <div class="detail-list compact-list"><?php foreach(array_slice($recent,0,5) as $r): ?><a class="detail" href="report_view.php?id=<?= (int)$r['id'] ?>"><span><?= e(date('M d, Y g:i A', strtotime($r['visit_datetime']))) ?> · <?= e($r['rep']) ?></span><strong><?= e($r['doctor_name']) ?></strong><p class="muted" style="margin:4px 0 0"><?= e($r['hospital_name']) ?></p></a><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  </aside>
</div>

<div class="modal-backdrop" data-task-modal hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle">
    <button class="modal-close" type="button" data-close-task-modal aria-label="Close task preview">×</button>
    <span class="eyebrow">Calendar Task</span>
    <h2 id="taskModalTitle" data-task-modal-title>Task Details</h2>
    <div class="modal-meta" data-task-modal-meta></div>
    <div class="modal-details" data-task-modal-details></div>
    <div class="modal-actions">
      <a class="btn ghost" data-task-modal-view href="tasks.php">Open Task Center</a>
      <a class="btn primary" data-task-modal-report href="report_form.php">Generate Report</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
