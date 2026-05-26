<?php
require __DIR__ . '/app/bootstrap.php';

require_login();
verify_csrf();

function expense_tables_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM expense_reports LIMIT 1');
        $pdo->query('SELECT 1 FROM expense_items LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function money_fmt($value): string
{
    return '₱' . number_format((float)$value, 2);
}

function expense_status_label(string $status): string
{
    return ['pending' => 'Pending', 'approved' => 'Approved', 'needs_changes' => 'Needs Changes'][$status] ?? $status;
}

function expense_scope_clause(PDO $pdo, string $alias = 'er'): array
{
    $u = current_user();
    if (!$u) return ['1=0', []];

    $prefix = $alias ? $alias . '.' : '';

    // Expense visibility rule:
    // Managers and district managers must be able to see every employee expense report.
    // This does not rely on district_manager_id because some production DB dumps do not have that column.
    if (in_array($u['role'] ?? '', ['manager', 'district_manager'], true)) {
        return ['1=1', []];
    }

    return [$prefix . 'user_id = ?', [(int)$u['id']]];
}

function clean_amount($value): float
{
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    return round((float)str_replace(',', '', $value), 2);
}

function month_start(string $value): string
{
    $value = trim($value);
    if ($value === '') return date('Y-m-01');
    if (preg_match('/^\d{4}-\d{2}$/', $value)) return $value . '-01';
    $time = strtotime($value);
    return $time ? date('Y-m-01', $time) : date('Y-m-01');
}

function save_receipt_file(string $field): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $allowed = ['jpg','jpeg','png','webp','pdf'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    return save_uploaded_file($field, 'uploads/expenses');
}

$ready = expense_tables_ready($pdo);
if (!$ready) {
    render_header('Expense Reports');
    ?>
    <div class="hero">
        <div>
            <span class="eyebrow">Setup Needed</span>
            <h2>Expense Report tables are not installed yet.</h2>
            <p>Import the SQL migration below in phpMyAdmin, then reload this page.</p>
        </div>
    </div>
    <div class="card">
        <div class="section-title">
            <div><span class="eyebrow">Migration</span><h2>Required SQL file</h2></div>
        </div>
        <p class="muted">Import this file into the <strong>pharmastar_reports</strong> database:</p>
        <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:18px;border-radius:18px;overflow:auto">database/migrations/2026_05_25_create_expense_reports.sql</pre>
    </div>
    <?php
    render_footer();
    exit;
}

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['expense_action'] ?? '') === 'save_report') {
    $reportId = (int)($_POST['id'] ?? 0);
    $reportMonth = month_start($_POST['report_month'] ?? '');
    $title = trim($_POST['title'] ?? 'Liquidation of Expenses');
    $title = $title !== '' ? $title : 'Liquidation of Expenses';

    $dates = $_POST['expense_date'] ?? [];
    $particulars = $_POST['particulars'] ?? [];
    $gasoline = $_POST['gasoline'] ?? [];
    $toll = $_POST['toll'] ?? [];
    $parking = $_POST['parking'] ?? [];
    $transportation = $_POST['transportation'] ?? [];
    $representation = $_POST['representation'] ?? [];
    $accommodation = $_POST['accommodation'] ?? [];
    $others = $_POST['others'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    $existingReceipts = $_POST['existing_receipt_path'] ?? [];

    $items = [];
    $grandTotal = 0.0;
    $rowCount = max(count($particulars), count($dates));

    for ($i = 0; $i < $rowCount; $i++) {
        $desc = trim((string)($particulars[$i] ?? ''));
        $hasMoney = clean_amount($gasoline[$i] ?? 0) + clean_amount($toll[$i] ?? 0) + clean_amount($parking[$i] ?? 0) + clean_amount($transportation[$i] ?? 0) + clean_amount($representation[$i] ?? 0) + clean_amount($accommodation[$i] ?? 0) + clean_amount($others[$i] ?? 0);
        if ($desc === '' && $hasMoney <= 0) continue;

        $receipt = save_receipt_file('receipt_' . $i) ?: trim((string)($existingReceipts[$i] ?? ''));
        $row = [
            'expense_date' => trim((string)($dates[$i] ?? '')) ?: null,
            'particulars' => $desc,
            'gasoline' => clean_amount($gasoline[$i] ?? 0),
            'toll' => clean_amount($toll[$i] ?? 0),
            'parking' => clean_amount($parking[$i] ?? 0),
            'transportation' => clean_amount($transportation[$i] ?? 0),
            'representation' => clean_amount($representation[$i] ?? 0),
            'accommodation' => clean_amount($accommodation[$i] ?? 0),
            'others' => clean_amount($others[$i] ?? 0),
            'remarks' => trim((string)($remarks[$i] ?? '')),
            'receipt_path' => $receipt ?: null,
        ];
        $row['total'] = $row['gasoline'] + $row['toll'] + $row['parking'] + $row['transportation'] + $row['representation'] + $row['accommodation'] + $row['others'];
        $grandTotal += $row['total'];
        $items[] = $row;
    }

    if (!$items) {
        flash('error', 'Please add at least one expense row before saving.');
        header('Location: expenses.php?action=' . ($reportId ? 'edit&id=' . $reportId : 'new'));
        exit;
    }

    if ($reportId > 0) {
        [$scopeSql, $scopeParams] = expense_scope_clause($pdo, 'er');
        $check = $pdo->prepare("SELECT er.* FROM expense_reports er WHERE er.id = ? AND $scopeSql LIMIT 1");
        $check->execute(array_merge([$reportId], $scopeParams));
        if (!$check->fetch()) {
            http_response_code(403);
            exit('Expense report not found or not allowed.');
        }
        $pdo->prepare('UPDATE expense_reports SET report_month = ?, title = ?, total_amount = ?, updated_at = NOW() WHERE id = ?')->execute([$reportMonth, $title, $grandTotal, $reportId]);
        $pdo->prepare('DELETE FROM expense_items WHERE expense_report_id = ?')->execute([$reportId]);
    } else {
        $pdo->prepare('INSERT INTO expense_reports (user_id, report_month, title, total_amount) VALUES (?, ?, ?, ?)')->execute([(int)$u['id'], $reportMonth, $title, $grandTotal]);
        $reportId = (int)$pdo->lastInsertId();
    }

    $insert = $pdo->prepare('INSERT INTO expense_items (expense_report_id, expense_date, particulars, gasoline, toll, parking, transportation, representation, accommodation, others, total, remarks, receipt_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $insert->execute([$reportId, $item['expense_date'], $item['particulars'], $item['gasoline'], $item['toll'], $item['parking'], $item['transportation'], $item['representation'], $item['accommodation'], $item['others'], $item['total'], $item['remarks'], $item['receipt_path']]);
    }

    flash('success', 'Expense report saved.');
    header('Location: expenses.php?action=view&id=' . $reportId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['expense_action'] ?? '') === 'manager_review' && is_manager()) {
    $reportId = (int)($_POST['id'] ?? 0);
    $status = normalize_status($_POST['status'] ?? 'pending');
    $comment = trim($_POST['manager_comment'] ?? '');
    [$scopeSql, $scopeParams] = expense_scope_clause($pdo, 'er');
    $stmt = $pdo->prepare("UPDATE expense_reports er SET er.status = ?, er.manager_comment = ?, er.updated_at = NOW() WHERE er.id = ? AND $scopeSql");
    $stmt->execute(array_merge([$status, $comment, $reportId], $scopeParams));
    flash('success', 'Expense review saved.');
    header('Location: expenses.php?action=view&id=' . $reportId);
    exit;
}

render_header('Expense Reports');

if ($action === 'new' || $action === 'edit') {
    $report = [
        'id' => 0,
        'title' => 'Liquidation of Expenses',
        'report_month' => date('Y-m-01'),
        'status' => 'pending',
        'total_amount' => 0,
    ];
    $items = [];

    if ($action === 'edit' && $id > 0) {
        [$scopeSql, $scopeParams] = expense_scope_clause($pdo, 'er');
        $stmt = $pdo->prepare("SELECT er.*, u.name rep FROM expense_reports er JOIN users u ON u.id = er.user_id WHERE er.id = ? AND $scopeSql LIMIT 1");
        $stmt->execute(array_merge([$id], $scopeParams));
        $report = $stmt->fetch();
        if (!$report) { http_response_code(404); exit('Expense report not found.'); }
        $itemStmt = $pdo->prepare('SELECT * FROM expense_items WHERE expense_report_id = ? ORDER BY expense_date ASC, id ASC');
        $itemStmt->execute([$id]);
        $items = $itemStmt->fetchAll();
    }

    $rowTarget = max(5, count($items) + 2);
    ?>
    <div class="expense-shell">
        <div class="hero">
            <div>
                <span class="eyebrow"><?= $action === 'edit' ? 'Edit Expense Report' : 'New Expense Report' ?></span>
                <h2>Liquidation of Expenses</h2>
                <p>Built from the current sales rep expense sheet format, but cleaner and tablet-friendly.</p>
            </div>
            <div class="actions"><a class="btn ghost" href="expenses.php">Back to Expenses</a></div>
        </div>

        <form class="card" method="post" enctype="multipart/form-data" data-expense-form>
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="expense_action" value="save_report">
            <input type="hidden" name="id" value="<?= (int)$report['id'] ?>">

            <div class="expense-form-head">
                <div class="field">
                    <label>Report Title</label>
                    <input name="title" value="<?= e($report['title']) ?>" placeholder="Liquidation of Expenses">
                </div>
                <div class="field">
                    <label>Report Month</label>
                    <input type="month" name="report_month" value="<?= e(date('Y-m', strtotime($report['report_month']))) ?>">
                </div>
                <div class="field">
                    <label>Prepared By</label>
                    <input value="<?= e($u['name'] ?? 'Current User') ?>" disabled>
                </div>
            </div>

            <div class="expense-builder" data-expense-builder>
                <div class="expense-builder-head">
                    <div>
                        <span class="eyebrow">Expense Entries</span>
                        <h3>Add expenses by row</h3>
                        <p>Each card is one expense entry. This layout is built to fit tablets cleanly without sideways scrolling.</p>
                    </div>
                    <button type="button" class="btn ghost" data-add-expense-row>Add Row</button>
                </div>

                <div class="expense-row-list">
                    <?php for ($i = 0; $i < $rowTarget; $i++): $item = $items[$i] ?? []; ?>
                        <div class="expense-entry-card" data-expense-row>
                            <div class="expense-entry-top">
                                <div class="field expense-date-field">
                                    <label>Date</label>
                                    <input class="expense-date" type="date" name="expense_date[]" value="<?= e($item['expense_date'] ?? '') ?>">
                                </div>
                                <div class="field expense-particulars-field">
                                    <label>Particulars</label>
                                    <textarea class="expense-particulars" name="particulars[]" placeholder="Example: Angkas going to hospital"><?= e($item['particulars'] ?? '') ?></textarea>
                                </div>
                                <div class="expense-card-total">
                                    <span>Row Total</span>
                                    <strong class="expense-row-total">₱0.00</strong>
                                </div>
                            </div>

                            <div class="expense-amount-grid">
                                <?php foreach ([
                                    'gasoline' => 'Gasoline',
                                    'toll' => 'Toll',
                                    'parking' => 'Parking',
                                    'transportation' => 'Transportation',
                                    'representation' => 'Representation',
                                    'accommodation' => 'Accommodation',
                                    'others' => 'Others',
                                ] as $col => $label): ?>
                                    <div class="field compact-money-field">
                                        <label><?= e($label) ?></label>
                                        <input data-expense-amount type="number" step="0.01" min="0" name="<?= e($col) ?>[]" value="<?= e((string)($item[$col] ?? '')) ?>" placeholder="0.00">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="expense-entry-bottom">
                                <div class="field expense-remarks-field">
                                    <label>Remarks</label>
                                    <textarea class="expense-remarks" name="remarks[]" placeholder="Optional note"><?= e($item['remarks'] ?? '') ?></textarea>
                                </div>

                                <div class="field expense-receipt-field">
                                    <label>Receipt</label>
                                    <input type="hidden" name="existing_receipt_path[]" value="<?= e($item['receipt_path'] ?? '') ?>">
                                    <label class="receipt-upload-box">
                                        <input type="file" name="receipt_<?= $i ?>" accept="image/*,.pdf">
                                        <span>Upload receipt</span>
                                        <small>Image or PDF</small>
                                    </label>
                                    <?php if (!empty($item['receipt_path'])): ?>
                                        <div class="expense-file-note"><a target="_blank" href="<?= e($item['receipt_path']) ?>">Current receipt</a></div>
                                    <?php endif; ?>
                                </div>

                                <div class="expense-remove-wrap">
                                    <button type="button" class="btn small ghost" data-remove-expense-row>Remove</button>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="expense-totals-panel">
                    <div class="expense-total-card"><span>Gasoline</span><strong data-total-col="gasoline">₱0.00</strong></div>
                    <div class="expense-total-card"><span>Toll</span><strong data-total-col="toll">₱0.00</strong></div>
                    <div class="expense-total-card"><span>Parking</span><strong data-total-col="parking">₱0.00</strong></div>
                    <div class="expense-total-card"><span>Transportation</span><strong data-total-col="transportation">₱0.00</strong></div>
                    <div class="expense-total-card"><span>Representation</span><strong data-total-col="representation">₱0.00</strong></div>
                    <div class="expense-total-card"><span>Accommodation</span><strong data-total-col="accommodation">₱0.00</strong></div>
                    <div class="expense-total-card"><span>Others</span><strong data-total-col="others">₱0.00</strong></div>
                    <div class="expense-total-card grand"><span>Grand Total</span><strong data-grand-total>₱0.00</strong></div>
                </div>
            </div>

            <div class="expense-actions-row">
                <button type="button" class="btn ghost" data-add-expense-row>Add Expense Row</button>
                <div class="actions">
                    <a class="btn ghost" href="expenses.php">Cancel</a>
                    <button class="btn primary">Save Expense Report</button>
                </div>
            </div>
        </form>
    </div>

    <template id="expense-row-template">
        <div class="expense-entry-card" data-expense-row>
            <div class="expense-entry-top">
                <div class="field expense-date-field">
                    <label>Date</label>
                    <input class="expense-date" type="date" name="expense_date[]">
                </div>
                <div class="field expense-particulars-field">
                    <label>Particulars</label>
                    <textarea class="expense-particulars" name="particulars[]" placeholder="Example: Parking at hospital"></textarea>
                </div>
                <div class="expense-card-total">
                    <span>Row Total</span>
                    <strong class="expense-row-total">₱0.00</strong>
                </div>
            </div>

            <div class="expense-amount-grid">
                <div class="field compact-money-field"><label>Gasoline</label><input data-expense-amount type="number" step="0.01" min="0" name="gasoline[]" placeholder="0.00"></div>
                <div class="field compact-money-field"><label>Toll</label><input data-expense-amount type="number" step="0.01" min="0" name="toll[]" placeholder="0.00"></div>
                <div class="field compact-money-field"><label>Parking</label><input data-expense-amount type="number" step="0.01" min="0" name="parking[]" placeholder="0.00"></div>
                <div class="field compact-money-field"><label>Transportation</label><input data-expense-amount type="number" step="0.01" min="0" name="transportation[]" placeholder="0.00"></div>
                <div class="field compact-money-field"><label>Representation</label><input data-expense-amount type="number" step="0.01" min="0" name="representation[]" placeholder="0.00"></div>
                <div class="field compact-money-field"><label>Accommodation</label><input data-expense-amount type="number" step="0.01" min="0" name="accommodation[]" placeholder="0.00"></div>
                <div class="field compact-money-field"><label>Others</label><input data-expense-amount type="number" step="0.01" min="0" name="others[]" placeholder="0.00"></div>
            </div>

            <div class="expense-entry-bottom">
                <div class="field expense-remarks-field">
                    <label>Remarks</label>
                    <textarea class="expense-remarks" name="remarks[]" placeholder="Optional note"></textarea>
                </div>
                <div class="field expense-receipt-field">
                    <label>Receipt</label>
                    <input type="hidden" name="existing_receipt_path[]" value="">
                    <label class="receipt-upload-box">
                        <input type="file" name="receipt_new[]" accept="image/*,.pdf">
                        <span>Upload receipt</span>
                        <small>Image or PDF</small>
                    </label>
                    <div class="expense-file-note">New rows can save without receipt, or attach one later after saving.</div>
                </div>
                <div class="expense-remove-wrap">
                    <button type="button" class="btn small ghost" data-remove-expense-row>Remove</button>
                </div>
            </div>
        </div>
    </template>
    <?php
} elseif ($action === 'view' && $id > 0) {
    [$scopeSql, $scopeParams] = expense_scope_clause($pdo, 'er');
    $stmt = $pdo->prepare("SELECT er.*, u.name rep, u.email rep_email FROM expense_reports er JOIN users u ON u.id = er.user_id WHERE er.id = ? AND $scopeSql LIMIT 1");
    $stmt->execute(array_merge([$id], $scopeParams));
    $report = $stmt->fetch();
    if (!$report) { http_response_code(404); exit('Expense report not found.'); }
    $itemStmt = $pdo->prepare('SELECT * FROM expense_items WHERE expense_report_id = ? ORDER BY expense_date ASC, id ASC');
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll();
    $categoryTotals = ['gasoline'=>0,'toll'=>0,'parking'=>0,'transportation'=>0,'representation'=>0,'accommodation'=>0,'others'=>0];
    foreach ($items as $item) foreach ($categoryTotals as $key => $_) $categoryTotals[$key] += (float)$item[$key];
    ?>
    <div class="expense-shell">
        <div class="expense-toolbar no-print">
            <div>
                <span class="eyebrow">Expense Report #<?= (int)$report['id'] ?></span>
                <h2 style="margin:.2rem 0 0"><?= e($report['title']) ?></h2>
            </div>
            <div class="actions">
                <a class="btn ghost" href="expenses.php">Back</a>
                <a class="btn ghost" href="expenses.php?action=edit&id=<?= (int)$report['id'] ?>">Edit</a>
                <button class="btn primary" onclick="window.print()">Export to PDF / Print</button>
            </div>
        </div>

        <div class="expense-print-area">
            <article class="expense-report-sheet">
                <header class="expense-report-header">
                    <div>
                        <span class="eyebrow" style="color:rgba(255,255,255,.82)">Liquidation of Expenses</span>
                        <h2><?= e($report['title']) ?></h2>
                        <p><?= e($report['rep']) ?> &middot; <?= e(date('F Y', strtotime($report['report_month']))) ?></p>
                    </div>
                    <div style="text-align:right">
                        <strong>Report #<?= (int)$report['id'] ?></strong><br>
                        <span><?= e(expense_status_label($report['status'])) ?></span><br>
                        <span>Total: <?= money_fmt($report['total_amount']) ?></span>
                    </div>
                </header>
                <div class="expense-report-body">
                    <div class="expense-meta-grid">
                        <div class="detail"><span>Employee Name</span><strong><?= e($report['rep']) ?></strong><p class="muted"><?= e($report['rep_email']) ?></p></div>
                        <div class="detail"><span>Report Month</span><strong><?= e(date('F Y', strtotime($report['report_month']))) ?></strong></div>
                        <div class="detail"><span>Status</span><strong><?= e(expense_status_label($report['status'])) ?></strong></div>
                    </div>
                    <div class="expense-table-wrap">
                        <table class="expense-print-table">
                            <thead>
                                <tr><th>Date</th><th>Particulars</th><th>Gasoline</th><th>Toll</th><th>Parking</th><th>Transportation</th><th>Representation</th><th>Accommodation</th><th>Others</th><th>Total</th><th>Remarks / Receipt</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= $item['expense_date'] ? e(date('M d, Y', strtotime($item['expense_date']))) : '' ?></td>
                                    <td><?= e($item['particulars']) ?></td>
                                    <?php foreach (['gasoline','toll','parking','transportation','representation','accommodation','others','total'] as $col): ?>
                                        <td class="num"><?= (float)$item[$col] > 0 ? money_fmt($item[$col]) : '-' ?></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <?= nl2br(e((string)$item['remarks'])) ?>
                                        <?php if (!empty($item['receipt_path'])): ?><br><a class="receipt-pill no-print" target="_blank" href="<?= e($item['receipt_path']) ?>">Open receipt</a><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2">TOTAL</td>
                                    <?php foreach (['gasoline','toll','parking','transportation','representation','accommodation','others'] as $col): ?>
                                        <td class="num"><?= money_fmt($categoryTotals[$col]) ?></td>
                                    <?php endforeach; ?>
                                    <td class="num"><?= money_fmt($report['total_amount']) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if (!empty($report['manager_comment'])): ?>
                        <div class="detail"><span>Manager Comment</span><p><?= nl2br(e($report['manager_comment'])) ?></p></div>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <?php if (is_manager()): ?>
            <form class="card no-print" method="post">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="expense_action" value="manager_review">
                <input type="hidden" name="id" value="<?= (int)$report['id'] ?>">
                <div class="section-title"><div><span class="eyebrow">Review</span><h2>Manager Action</h2></div></div>
                <div class="expense-status-form">
                    <div class="field"><label>Status</label><select name="status"><?php foreach (['pending','approved','needs_changes'] as $s): ?><option value="<?= e($s) ?>" <?= $report['status']===$s?'selected':'' ?>><?= e(expense_status_label($s)) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Comment</label><textarea name="manager_comment" rows="3"><?= e($report['manager_comment']) ?></textarea></div>
                    <button class="btn primary">Save Review</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php
} else {
    [$scopeSql, $scopeParams] = expense_scope_clause($pdo, 'er');
    $month = trim($_GET['month'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $where = [$scopeSql];
    $params = $scopeParams;
    if ($month !== '') { $where[] = 'DATE_FORMAT(er.report_month, "%Y-%m") = ?'; $params[] = $month; }
    if (in_array($status, ['pending','approved','needs_changes'], true)) { $where[] = 'er.status = ?'; $params[] = $status; }
    $sql = 'SELECT er.*, u.name rep FROM expense_reports er JOIN users u ON u.id = er.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY er.report_month DESC, er.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
    $total = array_sum(array_map(static fn($r) => (float)$r['total_amount'], $reports));
    $pending = count(array_filter($reports, static fn($r) => $r['status'] === 'pending'));
    $approved = count(array_filter($reports, static fn($r) => $r['status'] === 'approved'));
    ?>
    <div class="expense-shell">
        <div class="hero">
            <div>
                <span class="eyebrow">Expense Center</span>
                <h2>Liquidation of Expenses</h2>
                <p>Submit transportation, gasoline, toll, parking, representation, accommodation, and other field expenses.</p>
            </div>
            <div class="actions"><a class="btn primary" href="expenses.php?action=new">New Expense Report</a></div>
        </div>

        <div class="expense-summary-grid">
            <div class="expense-kpi"><span>Total Reports</span><strong><?= count($reports) ?></strong></div>
            <div class="expense-kpi"><span>Total Amount</span><strong><?= money_fmt($total) ?></strong></div>
            <div class="expense-kpi"><span>Pending</span><strong><?= $pending ?></strong></div>
            <div class="expense-kpi"><span>Approved</span><strong><?= $approved ?></strong></div>
        </div>

        <form class="card filters" method="get">
            <div class="field"><label>Month</label><input type="month" name="month" value="<?= e($month) ?>"></div>
            <div class="field"><label>Status</label><select name="status"><option value="">All Status</option><?php foreach (['pending','approved','needs_changes'] as $s): ?><option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e(expense_status_label($s)) ?></option><?php endforeach; ?></select></div>
            <div class="filter-action"><button class="btn primary">Filter</button></div>
            <div class="filter-action"><a class="btn ghost" href="expenses.php">Reset</a></div>
        </form>

        <div class="card">
            <div class="section-title"><div><span class="eyebrow">Reports</span><h2>Expense report list</h2></div></div>
            <?php if (!$reports): ?>
                <div class="empty">No expense reports found. Create the first one to start tracking sales rep liquidation reports.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Month</th><th>Employee</th><th>Title</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td><?= e(date('F Y', strtotime($report['report_month']))) ?></td>
                                <td><?= e($report['rep']) ?></td>
                                <td><?= e($report['title']) ?></td>
                                <td><?= money_fmt($report['total_amount']) ?></td>
                                <td><span class="badge <?= e($report['status']) ?>"><?= e(expense_status_label($report['status'])) ?></span></td>
                                <td><?= e(date('M d, Y', strtotime($report['created_at']))) ?></td>
                                <td><div class="actions"><a class="btn small" href="expenses.php?action=view&id=<?= (int)$report['id'] ?>">View</a><a class="btn small ghost" href="expenses.php?action=edit&id=<?= (int)$report['id'] ?>">Edit</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<script>
(function(){
  const form = document.querySelector('[data-expense-form]');
  if(!form) return;
  const rowList = form.querySelector('.expense-row-list');
  const template = document.getElementById('expense-row-template');
  const peso = new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'});
  const amountNames = ['gasoline','toll','parking','transportation','representation','accommodation','others'];
  function amount(input){ return Number.parseFloat(input.value || '0') || 0; }
  function refreshNames(){
    rowList.querySelectorAll('[data-expense-row]').forEach((row, idx)=>{
      const file = row.querySelector('input[type="file"]');
      if(file) file.name = 'receipt_' + idx;
    });
  }
  function calculate(){
    const colTotals = Object.fromEntries(amountNames.map(name=>[name,0]));
    let grand = 0;
    rowList.querySelectorAll('[data-expense-row]').forEach(row=>{
      let rowTotal = 0;
      amountNames.forEach(name=>{
        const input = row.querySelector(`[name="${name}[]"]`);
        const value = input ? amount(input) : 0;
        colTotals[name] += value;
        rowTotal += value;
      });
      grand += rowTotal;
      const totalCell = row.querySelector('.expense-row-total');
      if(totalCell) totalCell.textContent = peso.format(rowTotal);
    });
    amountNames.forEach(name=>{
      const cell = form.querySelector(`[data-total-col="${name}"]`);
      if(cell) cell.textContent = peso.format(colTotals[name]);
    });
    const grandCell = form.querySelector('[data-grand-total]');
    if(grandCell) grandCell.textContent = peso.format(grand);
  }
  form.addEventListener('input', calculate);
  form.addEventListener('click', function(e){
    const remove = e.target.closest('[data-remove-expense-row]');
    if(remove){
      const rows = rowList.querySelectorAll('[data-expense-row]');
      if(rows.length > 1) remove.closest('[data-expense-row]').remove();
      refreshNames(); calculate();
    }
    if(e.target.closest('[data-add-expense-row]')){
      const row = template.content.firstElementChild.cloneNode(true);
      rowList.appendChild(row);
      refreshNames(); calculate();
    }
  });
  refreshNames(); calculate();
})();
</script>
<?php render_footer(); ?>
