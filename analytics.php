<?php
require __DIR__ . '/app/bootstrap.php'; require_any_permission(['analytics.view', 'analytics.view_own']);
[$scopeSql,$scopeParams]=scope_clause($pdo,'r');
$from=$_GET['from']??date('Y-m-01'); $to=$_GET['to']??date('Y-m-t'); $rep=(int)($_GET['rep']??0); $doctor=trim($_GET['doctor']??'');
$where=[$scopeSql,'DATE(r.visit_datetime) BETWEEN ? AND ?']; $params=array_merge($scopeParams,[$from,$to]);
if($rep>0 && in_array($rep, visible_user_ids($pdo), true)){ $where[]='r.user_id=?'; $params[]=$rep; }
if($doctor!==''){ $where[]='r.doctor_name LIKE ?'; $params[]="%$doctor%"; }
$base=' FROM reports r JOIN users u ON u.id=r.user_id WHERE '.implode(' AND ',$where);
$stmt=$pdo->prepare('SELECT COUNT(*) total, SUM(status="approved") approved, SUM(status="pending") pending, SUM(status="needs_changes") changes, COUNT(DISTINCT doctor_name) doctors '.$base); $stmt->execute($params); $kpi=$stmt->fetch();
function rows(PDO $pdo,string $sql,array $params):array{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll();}
$byRep=rows($pdo,'SELECT u.name label, COUNT(*) total '.$base.' GROUP BY u.id,u.name ORDER BY total DESC LIMIT 8',$params);
$byStatus=rows($pdo,'SELECT r.status label, COUNT(*) total '.$base.' GROUP BY r.status ORDER BY total DESC',$params);
$byDoctor=rows($pdo,'SELECT r.doctor_name label, COUNT(*) total '.$base.' GROUP BY r.doctor_name ORDER BY total DESC LIMIT 8',$params);
$byDay=rows($pdo,'SELECT DATE(r.visit_datetime) label, COUNT(*) total '.$base.' GROUP BY DATE(r.visit_datetime) ORDER BY label ASC LIMIT 45',$params);
$max=max(1,...array_map(fn($x)=>(int)$x['total'],array_merge($byRep,$byStatus,$byDoctor,$byDay,[['total'=>1]])));
$users=fetch_users($pdo,true);
render_header('Analytics');
function chart(string $title,array $rows,int $max):void{ ?><div class="card"><div class="section-title"><div><span class="eyebrow">Smart KPI</span><h2><?= e($title) ?></h2></div></div><?php if(!$rows): ?><div class="empty">No data found. Try a wider filter range.</div><?php else: ?><div class="chart-bars"><?php foreach($rows as $r): ?><div class="bar-row"><strong><?= e(status_label($r['label'])!==$r['label']?status_label($r['label']):$r['label']) ?></strong><div class="bar-track"><div class="bar-fill" style="width:<?= max(5,((int)$r['total']/$max)*100) ?>%"></div></div><b><?= (int)$r['total'] ?></b></div><?php endforeach; ?></div><?php endif; ?></div><?php }
?>
<div class="hero"><div><span class="eyebrow">Analytics</span><h2>Filter-aware performance dashboard</h2><p>Charts update by date range, sales rep, and doctor search without fake SLA clutter.</p></div></div>
<form class="card filters" style="margin-bottom:18px"><div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div><div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div><div class="field"><label>Sales Rep</label><select name="rep"><option value="0">All visible reps</option><?php foreach($users as $u): if(!in_array((int)$u['id'],visible_user_ids($pdo),true)) continue; ?><option value="<?= (int)$u['id'] ?>" <?= $rep===(int)$u['id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Doctor</label><input class="input" name="doctor" value="<?= e($doctor) ?>" placeholder="Doctor name"></div><button class="btn primary">Apply</button></form>
<div class="grid grid-4" style="margin-bottom:18px"><div class="kpi"><span>Reports</span><strong><?= (int)$kpi['total'] ?></strong><small>Filtered</small></div><div class="kpi"><span>Approved</span><strong><?= (int)$kpi['approved'] ?></strong><small>Completed</small></div><div class="kpi"><span>Pending</span><strong><?= (int)$kpi['pending'] ?></strong><small>Review queue</small></div><div class="kpi"><span>Doctors</span><strong><?= (int)$kpi['doctors'] ?></strong><small>Unique coverage</small></div></div>
<div class="grid grid-2"><?php chart('Reports by Rep',$byRep,$max); chart('Status Breakdown',$byStatus,$max); chart('Top Doctors',$byDoctor,$max); chart('Reports Over Time',$byDay,$max); ?></div>
<?php render_footer(); ?>
