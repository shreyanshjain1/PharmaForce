<?php
require __DIR__ . '/app/bootstrap.php'; require_login();
$q=trim($_GET['q']??''); $class=$_GET['class']??''; $place=trim($_GET['place']??'');
$where=[];$params=[]; if($q!==''){ $where[]='(dr_name LIKE ? OR speciality LIKE ? OR hospital_address LIKE ?)'; $like="%$q%"; array_push($params,$like,$like,$like); } if(in_array($class,['A','B','C'],true)){ $where[]='class=?'; $params[]=$class; } if($place!==''){ $where[]='place LIKE ?'; $params[]="%$place%"; }
$sql='SELECT * FROM doctors_masterlist'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY dr_name ASC LIMIT 300'; $stmt=$pdo->prepare($sql); $stmt->execute($params); $doctors=$stmt->fetchAll();
render_header('Doctors');
?>
<div class="hero"><div><span class="eyebrow">Masterlist</span><h2>Doctor coverage directory</h2><p>Search doctors, hospitals, places, and class levels from the existing production table.</p></div></div>
<form class="card filters" style="margin-bottom:18px"><div class="field"><label>Search</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Doctor, hospital, specialty"></div><div class="field"><label>Class</label><select name="class"><option value="">All</option><?php foreach(['A','B','C'] as $c): ?><option <?= $class===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?></select></div><div class="field"><label>Place</label><input class="input" name="place" value="<?= e($place) ?>"></div><button class="btn primary">Search</button></form>
<div class="grid grid-3"><?php foreach($doctors as $d): ?><div class="doctor-card"><div><span class="badge">Class <?= e($d['class']) ?></span></div><strong><?= e($d['dr_name']) ?></strong><span class="muted"><?= e($d['speciality']) ?></span><span><?= e($d['hospital_address']) ?></span><span class="muted"><?= e($d['place']) ?> · <?= e($d['contact_no']) ?></span><a class="btn small" href="report_form.php?doctor=<?= (int)$d['id'] ?>">Create Report</a></div><?php endforeach; ?></div>
<?php render_footer(); ?>
