<?php
require __DIR__ . '/app/bootstrap.php'; require_login();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  if (!is_manager()) { http_response_code(403); exit('Forbidden'); }
  $id=(int)($_POST['id']??0); $action=$_POST['action']??'';
  if ($action==='status') { $status=normalize_status($_POST['status']??'pending'); $comment=trim($_POST['manager_comment']??''); $stmt=$pdo->prepare('UPDATE reports SET status=?, manager_comment=? WHERE id=?'); $stmt->execute([$status,$comment,$id]); flash('success','Report status updated.'); }
  if ($action==='delete' && is_top_manager()) { $stmt=$pdo->prepare('DELETE FROM reports WHERE id=?'); $stmt->execute([$id]); flash('success','Report deleted.'); }
  header('Location: reports.php'); exit;
}
[$scopeSql,$scopeParams]=scope_clause($pdo,'r');
$where=[$scopeSql]; $params=$scopeParams;
$q=trim($_GET['q']??''); $status=$_GET['status']??''; $from=$_GET['from']??''; $to=$_GET['to']??''; $rep=(int)($_GET['rep']??0);
if($q!==''){ $where[]='(r.doctor_name LIKE ? OR r.hospital_name LIKE ? OR r.purpose LIKE ? OR r.medicine_name LIKE ?)'; $like="%$q%"; array_push($params,$like,$like,$like,$like); }
if(in_array($status,['pending','approved','needs_changes'],true)){ $where[]='r.status=?'; $params[]=$status; }
if($from!==''){ $where[]='r.visit_datetime >= ?'; $params[]=$from.' 00:00:00'; }
if($to!==''){ $where[]='r.visit_datetime <= ?'; $params[]=$to.' 23:59:59'; }
if($rep>0 && in_array($rep,visible_user_ids($pdo),true)){ $where[]='r.user_id=?'; $params[]=$rep; }
$sql='SELECT r.*,u.name rep FROM reports r JOIN users u ON u.id=r.user_id WHERE '.implode(' AND ',$where).' ORDER BY r.visit_datetime DESC LIMIT 250';
$stmt=$pdo->prepare($sql); $stmt->execute($params); $reports=$stmt->fetchAll(); $users=fetch_users($pdo,true);
render_header('Reports Workspace');
?>
<div class="hero"><div><span class="eyebrow">Reports</span><h2>Sales visit reports</h2><p>Search, review, approve, and manage submitted reports from the field team.</p></div><a class="btn primary" href="report_form.php">Add New Report</a></div>
<div class="card" style="margin-bottom:18px"><form class="filters"><div class="field"><label>Search</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Doctor, hospital, medicine..."></div><div class="field"><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach(['pending','approved','needs_changes'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= status_label($s) ?></option><?php endforeach; ?></select></div><div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div><div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div><button class="btn primary">Filter</button></form></div>
<div class="card">
  <div class="section-title">
    <div><span class="eyebrow">Results</span><h2><?= count($reports) ?> reports found</h2></div>
    <a class="btn ghost" href="reports.php">Reset</a>
  </div>
  <?php if (!$reports): ?>
    <div class="empty reports-empty">
      <p class="empty-note">No reports match your current filters. Try resetting the filters or create a new field visit report.</p>
      <div class="empty-actions">
        <a class="btn primary" href="report_form.php">Add New Report</a>
        <a class="btn ghost" href="reports.php">Reset Filters</a>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Doctor / Hospital</th><th>Rep</th><th>Purpose</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($reports as $r): ?><tr><td><?= e(date('M d, Y g:i A',strtotime($r['visit_datetime']))) ?></td><td><strong><?= e($r['doctor_name']) ?></strong><br><span class="muted"><?= e($r['hospital_name']) ?></span></td><td><?= e($r['rep']) ?></td><td><?= e($r['purpose']) ?><br><span class="muted"><?= e($r['medicine_name']) ?></span></td><td><span class="badge <?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span></td><td><div class="actions"><a class="btn small" href="report_view.php?id=<?= (int)$r['id'] ?>">View</a><a class="btn small" href="report_form.php?id=<?= (int)$r['id'] ?>">Edit</a></div></td></tr><?php endforeach; ?></tbody></table><?php foreach($reports as $r): ?><div class="mobile-card"><strong><?= e($r['doctor_name']) ?></strong><span class="muted"><?= e($r['hospital_name']) ?></span><span class="badge <?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span><div class="actions"><a class="btn small" href="report_view.php?id=<?= (int)$r['id'] ?>">View</a><a class="btn small" href="report_form.php?id=<?= (int)$r['id'] ?>">Edit</a></div></div><?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
