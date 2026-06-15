<?php
require __DIR__ . '/app/bootstrap.php';

require_permission('tasks.create');
verify_csrf();

function pd_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (column_exists($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function pd_event_columns(PDO $pdo): array
{
    static $columns = null;
    if ($columns === null) {
        $columns = table_columns($pdo, 'events');
    }
    return $columns;
}

function pd_doctor_columns(PDO $pdo): array
{
    return [
        'id' => pd_column($pdo, 'doctors_masterlist', ['id']),
        'name' => pd_column($pdo, 'doctors_masterlist', ['dr_name', 'doctor_name', 'name']),
        'specialty' => pd_column($pdo, 'doctors_masterlist', ['speciality', 'specialty']),
        'hospital' => pd_column($pdo, 'doctors_masterlist', ['hospital_address', 'hospital', 'clinic', 'clinic_address']),
        'area' => pd_column($pdo, 'doctors_masterlist', ['place', 'area', 'city', 'territory']),
        'class' => pd_column($pdo, 'doctors_masterlist', ['class', 'classification']),
        'contact' => pd_column($pdo, 'doctors_masterlist', ['contact_no', 'contact_number', 'phone', 'mobile']),
        'email' => pd_column($pdo, 'doctors_masterlist', ['email', 'doctor_email']),
    ];
}

function pd_doctor_value(array $doctor, ?string $column, string $fallback = ''): string
{
    if (!$column) return $fallback;
    $value = trim((string)($doctor[$column] ?? ''));
    return $value !== '' ? $value : $fallback;
}

function pd_datetime(string $date, string $time): string
{
    $date = trim($date) !== '' ? trim($date) : date('Y-m-d');
    $time = trim($time) !== '' ? trim($time) : '09:00';
    if (strlen($time) === 5) $time .= ':00';
    return $date . ' ' . $time;
}

function pd_end_datetime(string $start, int $minutes = 30): string
{
    $timestamp = strtotime($start);
    if (!$timestamp) $timestamp = time();
    return date('Y-m-d H:i:s', $timestamp + ($minutes * 60));
}

function pd_make_event(PDO $pdo, array $data): int
{
    $columns = pd_event_columns($pdo);
    $values = [];

    $map = [
        'user_id' => $data['user_id'],
        'doctor_id' => $data['doctor_id'],
        'title' => $data['title'],
        'description' => $data['notes'],
        'notes' => $data['notes'],
        'city' => $data['area'],
        'hospital_name' => $data['hospital'],
        'purpose' => $data['purpose'],
        'medicine_name' => $data['medicine'],
        'summary' => $data['summary'],
        'remarks' => $data['remarks'],
        'start' => $data['start'],
        'end' => $data['end'],
        'start_datetime' => $data['start'],
        'end_datetime' => $data['end'],
        'visit_datetime' => $data['start'],
        'all_day' => 0,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    foreach ($map as $column => $value) {
        if (in_array($column, $columns, true)) {
            $values[$column] = $value;
        }
    }

    return insert_dynamic($pdo, 'events', $values);
}

function pd_safe_time(?string $value, string $fallback): string
{
    $value = trim((string)$value);
    if (preg_match('/^\d{2}:\d{2}$/', $value)) return $value;
    return $fallback;
}

$doctorColumns = pd_doctor_columns($pdo);
$areaColumn = $doctorColumns['area'];
$nameColumn = $doctorColumns['name'];
$hospitalColumn = $doctorColumns['hospital'];
$specialtyColumn = $doctorColumns['specialty'];
$classColumn = $doctorColumns['class'];

if (!$nameColumn) {
    render_header('Plan Your Day');
    ?>
    <div class="hero">
        <div>
            <span class="eyebrow">Setup Needed</span>
            <h2>Doctor masterlist columns were not detected.</h2>
            <p>The planner needs a doctor name column in doctors_masterlist.</p>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}

$selectedDate = trim((string)($_GET['date'] ?? $_POST['plan_date'] ?? date('Y-m-d')));
$selectedArea = trim((string)($_GET['area'] ?? $_POST['selected_area'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$areas = [];
if ($areaColumn) {
    try {
        $areas = $pdo->query("SELECT DISTINCT `{$areaColumn}` area_name FROM doctors_masterlist WHERE `{$areaColumn}` IS NOT NULL AND `{$areaColumn}` <> '' ORDER BY `{$areaColumn}` ASC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $areas = [];
    }
}

$createdIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['plan_action'] ?? '') === 'create_day_plan') {
    $planDate = trim((string)($_POST['plan_date'] ?? date('Y-m-d')));
    $duration = max(15, min(180, (int)($_POST['default_duration'] ?? 30)));
    $purposeDefault = trim((string)($_POST['default_purpose'] ?? 'Doctor Visit'));
    $medicineDefault = trim((string)($_POST['default_medicine'] ?? ''));
    $areaDefault = trim((string)($_POST['selected_area'] ?? ''));

    $selectedDoctors = array_map('intval', $_POST['selected_doctor_ids'] ?? []);
    $doctorTimes = $_POST['doctor_time'] ?? [];
    $doctorPurposes = $_POST['doctor_purpose'] ?? [];
    $doctorMedicines = $_POST['doctor_medicine'] ?? [];
    $doctorNotes = $_POST['doctor_notes'] ?? [];

    $createdCount = 0;
    $skippedCount = 0;

    foreach ($selectedDoctors as $doctorId) {
        if ($doctorId <= 0) continue;

        $stmt = $pdo->prepare("SELECT * FROM doctors_masterlist WHERE id = ? LIMIT 1");
        $stmt->execute([$doctorId]);
        $doctor = $stmt->fetch();

        if (!$doctor) {
            $skippedCount++;
            continue;
        }

        $doctorName = pd_doctor_value($doctor, $nameColumn, 'Doctor Visit');
        $hospital = pd_doctor_value($doctor, $hospitalColumn);
        $area = pd_doctor_value($doctor, $areaColumn, $areaDefault);
        $specialty = pd_doctor_value($doctor, $specialtyColumn);
        $class = pd_doctor_value($doctor, $classColumn);

        $time = pd_safe_time($doctorTimes[$doctorId] ?? '', '09:00');
        $start = pd_datetime($planDate, $time);
        $end = pd_end_datetime($start, $duration);

        $purpose = trim((string)($doctorPurposes[$doctorId] ?? ''));
        $purpose = $purpose !== '' ? $purpose : $purposeDefault;
        $medicine = trim((string)($doctorMedicines[$doctorId] ?? ''));
        $medicine = $medicine !== '' ? $medicine : $medicineDefault;
        $notes = trim((string)($doctorNotes[$doctorId] ?? ''));

        $title = 'Visit: ' . $doctorName;
        $summary = trim(implode(' • ', array_filter([$specialty, $class, $area])));

        $createdIds[] = pd_make_event($pdo, [
            'user_id' => (int)current_user()['id'],
            'doctor_id' => $doctorId,
            'title' => $title,
            'notes' => $notes,
            'area' => $area,
            'hospital' => $hospital,
            'purpose' => $purpose,
            'medicine' => $medicine,
            'summary' => $summary,
            'remarks' => 'Created from Plan Your Day',
            'start' => $start,
            'end' => $end,
        ]);
        $createdCount++;
    }

    $otherDoctorIds = array_map('intval', $_POST['other_doctor_id'] ?? []);
    $otherTimes = $_POST['other_time'] ?? [];
    $otherPurposes = $_POST['other_purpose'] ?? [];
    $otherMedicines = $_POST['other_medicine'] ?? [];
    $otherNotes = $_POST['other_notes'] ?? [];

    foreach ($otherDoctorIds as $index => $doctorId) {
        if ($doctorId <= 0) continue;

        $stmt = $pdo->prepare("SELECT * FROM doctors_masterlist WHERE id = ? LIMIT 1");
        $stmt->execute([$doctorId]);
        $doctor = $stmt->fetch();

        if (!$doctor) continue;

        $doctorName = pd_doctor_value($doctor, $nameColumn, 'Doctor Visit');
        $hospital = pd_doctor_value($doctor, $hospitalColumn);
        $area = pd_doctor_value($doctor, $areaColumn, 'Outside selected area');
        $specialty = pd_doctor_value($doctor, $specialtyColumn);
        $class = pd_doctor_value($doctor, $classColumn);

        $time = pd_safe_time($otherTimes[$index] ?? '', '09:00');
        $start = pd_datetime($planDate, $time);
        $end = pd_end_datetime($start, $duration);

        $purpose = trim((string)($otherPurposes[$index] ?? ''));
        $purpose = $purpose !== '' ? $purpose : $purposeDefault;
        $medicine = trim((string)($otherMedicines[$index] ?? ''));
        $medicine = $medicine !== '' ? $medicine : $medicineDefault;
        $notes = trim((string)($otherNotes[$index] ?? ''));

        $createdIds[] = pd_make_event($pdo, [
            'user_id' => (int)current_user()['id'],
            'doctor_id' => $doctorId,
            'title' => 'Visit: ' . $doctorName,
            'notes' => $notes,
            'area' => $area,
            'hospital' => $hospital,
            'purpose' => $purpose,
            'medicine' => $medicine,
            'summary' => trim(implode(' • ', array_filter([$specialty, $class, $area]))),
            'remarks' => 'Created from Plan Your Day - other area doctor',
            'start' => $start,
            'end' => $end,
        ]);
        $createdCount++;
    }

    $unlistedNames = $_POST['unlisted_name'] ?? [];
    $unlistedHospitals = $_POST['unlisted_hospital'] ?? [];
    $unlistedAreas = $_POST['unlisted_area'] ?? [];
    $unlistedSpecialties = $_POST['unlisted_specialty'] ?? [];
    $unlistedTimes = $_POST['unlisted_time'] ?? [];
    $unlistedPurposes = $_POST['unlisted_purpose'] ?? [];
    $unlistedMedicines = $_POST['unlisted_medicine'] ?? [];
    $unlistedNotes = $_POST['unlisted_notes'] ?? [];

    $unlistedCount = max(count($unlistedNames), count($unlistedHospitals), count($unlistedTimes));

    for ($i = 0; $i < $unlistedCount; $i++) {
        $doctorName = trim((string)($unlistedNames[$i] ?? ''));
        $hospital = trim((string)($unlistedHospitals[$i] ?? ''));
        $area = trim((string)($unlistedAreas[$i] ?? ''));
        $specialty = trim((string)($unlistedSpecialties[$i] ?? ''));

        if ($doctorName === '' && $hospital === '') continue;
        if ($doctorName === '') $doctorName = 'Unlisted Doctor';

        $time = pd_safe_time($unlistedTimes[$i] ?? '', '09:00');
        $start = pd_datetime($planDate, $time);
        $end = pd_end_datetime($start, $duration);

        $purpose = trim((string)($unlistedPurposes[$i] ?? ''));
        $purpose = $purpose !== '' ? $purpose : $purposeDefault;
        $medicine = trim((string)($unlistedMedicines[$i] ?? ''));
        $medicine = $medicine !== '' ? $medicine : $medicineDefault;
        $notes = trim((string)($unlistedNotes[$i] ?? ''));

        $createdIds[] = pd_make_event($pdo, [
            'user_id' => (int)current_user()['id'],
            'doctor_id' => null,
            'title' => 'Visit: ' . $doctorName,
            'notes' => trim($notes . "\n\nUnlisted doctor: " . $doctorName . ($specialty ? "\nSpecialty: " . $specialty : '')),
            'area' => $area !== '' ? $area : $areaDefault,
            'hospital' => $hospital,
            'purpose' => $purpose,
            'medicine' => $medicine,
            'summary' => trim(implode(' • ', array_filter([$specialty, 'Unlisted', $area]))),
            'remarks' => 'Created from Plan Your Day - unlisted doctor',
            'start' => $start,
            'end' => $end,
        ]);
        $createdCount++;
    }

    audit_log($pdo, 'day_plan_created', 'task', null, [
        'plan_date' => $planDate,
        'selected_area' => $areaDefault,
        'created_count' => $createdCount,
        'skipped_count' => $skippedCount,
        'event_ids' => $createdIds,
    ]);

    if ($createdCount > 0) {
        flash('success', $createdCount . ' visit' . ($createdCount === 1 ? '' : 's') . ' added to your day plan.');
        header('Location: tasks.php?from=' . urlencode($planDate) . '&to=' . urlencode($planDate));
        exit;
    }

    flash('error', 'No visits were created. Select at least one doctor or add an unlisted doctor.');
    header('Location: plan_day.php?date=' . urlencode($planDate) . '&area=' . urlencode($areaDefault));
    exit;
}

$doctors = [];
if ($selectedArea !== '' && $areaColumn) {
    $select = ['id'];
    foreach (array_filter([$nameColumn, $specialtyColumn, $hospitalColumn, $areaColumn, $classColumn]) as $column) {
        if (!in_array($column, $select, true)) $select[] = $column;
    }

    $sql = 'SELECT `' . implode('`, `', $select) . '` FROM doctors_masterlist WHERE `' . $areaColumn . '` = ?';
    $params = [$selectedArea];

    if ($search !== '') {
        $searchParts = [];
        foreach (array_filter([$nameColumn, $specialtyColumn, $hospitalColumn, $classColumn]) as $column) {
            $searchParts[] = "`{$column}` LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($searchParts) $sql .= ' AND (' . implode(' OR ', $searchParts) . ')';
    }

    $sql .= ' ORDER BY `' . $nameColumn . '` ASC LIMIT 250';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $doctors = $stmt->fetchAll();
}

$otherDoctors = [];
try {
    $select = ['id'];
    foreach (array_filter([$nameColumn, $specialtyColumn, $hospitalColumn, $areaColumn, $classColumn]) as $column) {
        if (!in_array($column, $select, true)) $select[] = $column;
    }
    $sql = 'SELECT `' . implode('`, `', $select) . '` FROM doctors_masterlist';
    $params = [];
    if ($selectedArea !== '' && $areaColumn) {
        $sql .= ' WHERE (`' . $areaColumn . '` <> ? OR `' . $areaColumn . '` IS NULL)';
        $params[] = $selectedArea;
    }
    $sql .= ' ORDER BY `' . $nameColumn . '` ASC LIMIT 600';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $otherDoctors = $stmt->fetchAll();
} catch (Throwable $e) {
    $otherDoctors = [];
}

render_header('Plan Your Day');
?>

<style>
.plan-shell{display:grid;gap:22px}
.plan-card{padding:22px}
.plan-setup{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:18px;align-items:end}
.plan-control-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:18px;align-items:end}
.plan-shell .field{display:flex;flex-direction:column;gap:8px;margin:0}
.plan-shell .field label{font-size:12px;line-height:1;font-weight:950;text-transform:uppercase;letter-spacing:.06em;color:#0f766e;margin:0}
.plan-shell input,
.plan-shell select,
.plan-shell textarea{width:100%;min-height:54px;border:1px solid rgba(15,118,110,.18);border-radius:16px;background:#fff;color:#0f172a;font-size:14px;font-weight:750;padding:0 16px;box-shadow:inset 0 1px 0 rgba(255,255,255,.8);outline:none;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
.plan-shell input[type="date"],
.plan-shell input[type="time"]{font-variant-numeric:tabular-nums}
.plan-shell input::placeholder{color:#94a3b8;font-weight:700}
.plan-shell input:focus,
.plan-shell select:focus,
.plan-shell textarea:focus{border-color:#0f766e;box-shadow:0 0 0 4px rgba(15,118,110,.11);background:#ffffff}
.plan-shell select{appearance:auto}
.plan-shell .btn{min-height:54px;border-radius:16px;padding:0 22px;white-space:nowrap}
.plan-section{border:1px solid rgba(15,118,110,.12);border-radius:28px;background:#fff;box-shadow:0 16px 32px rgba(15,118,110,.055);overflow:hidden}
.plan-section-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;padding:20px 22px;border-bottom:1px solid rgba(15,118,110,.1);background:linear-gradient(135deg,#f8fffd,#fff)}
.plan-section-head h3{margin:0;color:#082f2b;font-size:21px;letter-spacing:-.03em}
.plan-section-head p{margin:7px 0 0;color:#64748b;font-weight:750}
.plan-doctor-list{display:grid;gap:12px;padding:18px}
.plan-doctor-card{display:grid;grid-template-columns:auto 1.35fr 1fr 1fr;gap:16px;align-items:start;padding:16px;border:1px solid rgba(15,118,110,.13);border-radius:22px;background:#fbfffe}
.plan-doctor-card:hover{border-color:rgba(15,118,110,.28);box-shadow:0 12px 24px rgba(15,118,110,.06)}
.plan-doctor-main strong{display:block;color:#082f2b;font-size:15px}
.plan-doctor-main span{display:block;margin-top:5px;color:#64748b;font-weight:750;font-size:12px;line-height:1.4}
.plan-mini-grid{display:grid;grid-template-columns:1fr;gap:10px}
.plan-check{width:22px;height:22px;margin-top:12px;accent-color:#0f766e}
.plan-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.plan-extra-list{display:grid;gap:12px;padding:18px}
.plan-extra-row{display:grid;grid-template-columns:1.2fr .7fr .8fr .8fr 1fr auto;gap:12px;align-items:end;padding:14px;border:1px solid rgba(15,118,110,.13);border-radius:22px;background:#fbfffe}
.plan-extra-row .btn{min-height:46px}
.plan-help{padding:16px 18px;border-radius:20px;background:#ecfdf5;color:#0f766e;font-weight:850;line-height:1.5}
.plan-empty{padding:32px;text-align:center;color:#64748b;font-weight:850}
.plan-submit-card{display:flex;gap:10px;align-items:center;justify-content:flex-end;padding:18px}
@media(max-width:1180px){.plan-setup,.plan-control-row{grid-template-columns:1fr 1fr}.plan-doctor-card{grid-template-columns:auto 1fr}.plan-mini-grid{grid-column:2}.plan-extra-row{grid-template-columns:1fr 1fr}}
@media(max-width:720px){.plan-setup,.plan-control-row,.plan-doctor-card,.plan-extra-row{grid-template-columns:1fr}.plan-card{padding:18px}.plan-check{width:28px;height:28px;margin-top:0}.plan-mini-grid{grid-column:auto}.plan-section-head{display:block}.plan-toolbar{margin-top:12px}.plan-shell .btn{width:100%}.plan-submit-card{display:grid}}
</style>

<div class="hero">
    <div>
        <span class="eyebrow">Daily Visit Planning</span>
        <h2>Plan Your Day</h2>
        <p>Select an area, pick doctors from the masterlist, add other-area doctors or unlisted doctors, then create your whole day plan in one submit.</p>
    </div>
    <div class="actions">
        <a class="btn ghost" href="tasks.php">View Tasks</a>
        <a class="btn ghost" href="doctors.php">Doctor Masterlist</a>
    </div>
</div>

<div class="plan-shell">
    <section class="card plan-card">
        <form method="get" class="plan-setup">
            <div class="field">
                <label>Visit Date</label>
                <input type="date" name="date" value="<?= e($selectedDate) ?>">
            </div>
            <div class="field">
                <label>Area / Place</label>
                <?php if ($areas): ?>
                    <select name="area" required>
                        <option value="">Select area</option>
                        <?php foreach ($areas as $area): ?>
                            <option value="<?= e($area) ?>" <?= $selectedArea === $area ? 'selected' : '' ?>><?= e($area) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input name="area" value="<?= e($selectedArea) ?>" placeholder="No area list found">
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Search within area</label>
                <input name="q" value="<?= e($search) ?>" placeholder="Doctor, hospital, specialty">
            </div>
            <button class="btn primary">Show Doctors</button>
        </form>
    </section>

    <form method="post" id="planDayForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="plan_action" value="create_day_plan">
        <input type="hidden" name="plan_date" value="<?= e($selectedDate) ?>">
        <input type="hidden" name="selected_area" value="<?= e($selectedArea) ?>">

        <section class="card plan-card">
            <div class="plan-control-row">
                <div class="field">
                    <label>Default Purpose</label>
                    <input name="default_purpose" value="Doctor Visit">
                </div>
                <div class="field">
                    <label>Default Medicine / Product</label>
                    <input name="default_medicine" placeholder="Optional">
                </div>
                <div class="field">
                    <label>Default Duration</label>
                    <select name="default_duration">
                        <option value="15">15 minutes</option>
                        <option value="30" selected>30 minutes</option>
                        <option value="45">45 minutes</option>
                        <option value="60">1 hour</option>
                    </select>
                </div>
                <button type="submit" class="btn primary">Create Day Plan</button>
            </div>
        </section>

        <br>

        <section class="plan-section">
            <div class="plan-section-head">
                <div>
                    <h3>Doctors in <?= e($selectedArea ?: 'selected area') ?></h3>
                    <p><?= $selectedArea ? count($doctors) . ' doctors found. Tick the doctors you want to visit.' : 'Select an area first to load doctors.' ?></p>
                </div>
                <div class="plan-toolbar">
                    <button type="button" class="btn small ghost" data-plan-select-all>Select All</button>
                    <button type="button" class="btn small ghost" data-plan-clear>Clear</button>
                </div>
            </div>

            <?php if (!$selectedArea): ?>
                <div class="plan-empty">Choose an area above to start planning.</div>
            <?php elseif (!$doctors): ?>
                <div class="plan-empty">No doctors found for this area/search. Use “other area” or “unlisted doctor” below.</div>
            <?php else: ?>
                <div class="plan-doctor-list">
                    <?php foreach ($doctors as $index => $doctor): ?>
                        <?php
                            $doctorId = (int)$doctor['id'];
                            $suggestedTime = date('H:i', strtotime('09:00 +' . ($index * 30) . ' minutes'));
                            $doctorName = pd_doctor_value($doctor, $nameColumn, 'Doctor');
                            $hospital = pd_doctor_value($doctor, $hospitalColumn);
                            $specialty = pd_doctor_value($doctor, $specialtyColumn);
                            $class = pd_doctor_value($doctor, $classColumn);
                            $area = pd_doctor_value($doctor, $areaColumn);
                        ?>
                        <article class="plan-doctor-card">
                            <input class="plan-check" type="checkbox" name="selected_doctor_ids[]" value="<?= $doctorId ?>">
                            <div class="plan-doctor-main">
                                <strong><?= e($doctorName) ?></strong>
                                <span><?= e(trim(implode(' • ', array_filter([$specialty, $class])))) ?></span>
                                <span><?= e(trim(implode(' • ', array_filter([$hospital, $area])))) ?></span>
                            </div>
                            <div class="plan-mini-grid">
                                <div class="field">
                                    <label>Visit Time</label>
                                    <input type="time" name="doctor_time[<?= $doctorId ?>]" value="<?= e($suggestedTime) ?>">
                                </div>
                                <div class="field">
                                    <label>Purpose</label>
                                    <input name="doctor_purpose[<?= $doctorId ?>]" placeholder="Use default">
                                </div>
                            </div>
                            <div class="plan-mini-grid">
                                <div class="field">
                                    <label>Medicine / Product</label>
                                    <input name="doctor_medicine[<?= $doctorId ?>]" placeholder="Use default">
                                </div>
                                <div class="field">
                                    <label>Notes</label>
                                    <input name="doctor_notes[<?= $doctorId ?>]" placeholder="Optional">
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <br>

        <section class="plan-section">
            <div class="plan-section-head">
                <div>
                    <h3>Add doctor from another area</h3>
                    <p>Use this when your main area is selected, but you also need to visit another masterlist doctor.</p>
                </div>
                <button type="button" class="btn small ghost" data-add-other-doctor>Add Row</button>
            </div>
            <div class="plan-extra-list" id="otherDoctorRows">
                <div class="plan-help">Click “Add Row” to include doctors from outside the selected area.</div>
            </div>
        </section>

        <br>

        <section class="plan-section">
            <div class="plan-section-head">
                <div>
                    <h3>Add unlisted doctor</h3>
                    <p>Create a visit for a doctor who is not in the masterlist yet.</p>
                </div>
                <button type="button" class="btn small ghost" data-add-unlisted>Add Row</button>
            </div>
            <div class="plan-extra-list" id="unlistedRows">
                <div class="plan-help">Click “Add Row” to add a new/unlisted doctor visit without saving them to the masterlist yet.</div>
            </div>
        </section>

        <br>

        <div class="card plan-submit-card">
            <button type="submit" class="btn primary">Create Day Plan</button>
            <a class="btn ghost" href="tasks.php">Cancel</a>
        </div>
    </form>
</div>

<template id="otherDoctorTemplate">
    <div class="plan-extra-row">
        <div class="field">
            <label>Doctor</label>
            <select name="other_doctor_id[]">
                <option value="">Select doctor</option>
                <?php foreach ($otherDoctors as $doctor): ?>
                    <?php
                        $label = trim(implode(' • ', array_filter([
                            pd_doctor_value($doctor, $nameColumn),
                            pd_doctor_value($doctor, $hospitalColumn),
                            pd_doctor_value($doctor, $areaColumn),
                        ])));
                    ?>
                    <option value="<?= (int)$doctor['id'] ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>Time</label><input type="time" name="other_time[]" value="09:00"></div>
        <div class="field"><label>Purpose</label><input name="other_purpose[]" placeholder="Use default"></div>
        <div class="field"><label>Medicine</label><input name="other_medicine[]" placeholder="Use default"></div>
        <div class="field"><label>Notes</label><input name="other_notes[]" placeholder="Optional"></div>
        <button type="button" class="btn small ghost" data-remove-plan-row>Remove</button>
    </div>
</template>

<template id="unlistedTemplate">
    <div class="plan-extra-row">
        <div class="field"><label>Doctor Name</label><input name="unlisted_name[]" placeholder="Doctor name"></div>
        <div class="field"><label>Hospital</label><input name="unlisted_hospital[]" placeholder="Clinic / hospital"></div>
        <div class="field"><label>Area</label><input name="unlisted_area[]" value="<?= e($selectedArea) ?>" placeholder="Area"></div>
        <div class="field"><label>Time</label><input type="time" name="unlisted_time[]" value="09:00"></div>
        <div class="field"><label>Purpose</label><input name="unlisted_purpose[]" placeholder="Use default"></div>
        <div class="field"><label>Medicine</label><input name="unlisted_medicine[]" placeholder="Use default"></div>
        <div class="field"><label>Specialty</label><input name="unlisted_specialty[]" placeholder="Optional"></div>
        <div class="field"><label>Notes</label><input name="unlisted_notes[]" placeholder="Optional"></div>
        <button type="button" class="btn small ghost" data-remove-plan-row>Remove</button>
    </div>
</template>

<script>
document.addEventListener('click', function (event) {
    if (event.target.matches('[data-plan-select-all]')) {
        document.querySelectorAll('input[name="selected_doctor_ids[]"]').forEach(function (input) {
            input.checked = true;
        });
    }

    if (event.target.matches('[data-plan-clear]')) {
        document.querySelectorAll('input[name="selected_doctor_ids[]"]').forEach(function (input) {
            input.checked = false;
        });
    }

    if (event.target.matches('[data-add-other-doctor]')) {
        const list = document.getElementById('otherDoctorRows');
        const template = document.getElementById('otherDoctorTemplate');
        const help = list.querySelector('.plan-help');
        if (help) help.remove();
        list.appendChild(template.content.cloneNode(true));
    }

    if (event.target.matches('[data-add-unlisted]')) {
        const list = document.getElementById('unlistedRows');
        const template = document.getElementById('unlistedTemplate');
        const help = list.querySelector('.plan-help');
        if (help) help.remove();
        list.appendChild(template.content.cloneNode(true));
    }

    if (event.target.matches('[data-remove-plan-row]')) {
        event.target.closest('.plan-extra-row')?.remove();
    }
});
</script>

<?php render_footer(); ?>
