<?php require __DIR__ . '/app/bootstrap.php'; require_login(); $u=current_user(); render_header('Profile'); ?>
<div class="hero"><div><span class="eyebrow">Account</span><h2><?= e($u['name']) ?></h2><p><?= e($u['email']) ?> · <?= e(role_label($u['role'])) ?></p></div><a class="btn danger" href="logout.php">Logout</a></div>
<div class="card"><div class="section-title"><div><span class="eyebrow">System Notes</span><h2>New app shell</h2></div></div><p class="muted">This build uses the existing production tables: users, reports, events, doctors_masterlist, and report_client_map. SLA and global quick-task clutter were removed.</p></div>
<?php render_footer(); ?>
