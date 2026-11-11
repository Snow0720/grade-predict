<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $next = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $hash = (string) ($student['password_hash'] ?? '');
    $currentOk = $hash !== ''
        ? password_verify($current, $hash)
        : ($current === student_default_password());

    if (!$currentOk) {
        flash('error', 'Your current password is not correct.');
    } elseif (strlen($next) < 6) {
        flash('error', 'Use at least 6 characters for your new password.');
    } elseif ($next !== $confirm) {
        flash('error', 'The new password and confirmation do not match.');
    } elseif ($next === student_default_password()) {
        flash('error', 'Choose a password other than the starting password.');
    } else {
        db()->prepare('UPDATE students SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $student['id']]);
        audit(null, 'password_change', 'student', (string) $student['id'], 'Student password updated');
        flash('ok', 'Password updated.');
    }
    redirect('student/profile.php');
}

$recovery = trim((string) ($student['email'] ?? ''));
layout_start('Profile', 'profile', [
    ['label' => 'Student Portal', 'href' => 'student/dashboard.php'],
    ['label' => 'Profile'],
]);
?>
<div class="split">
    <section class="card">
        <h2><?= icon('faculty') ?> Account</h2>
        <div class="kpis" style="margin-top:12px;">
            <article class="kpi">
                <span>Name</span>
                <strong style="font-size:16px;"><?= h($student['full_name']) ?></strong>
            </article>
            <article class="kpi">
                <span>Student ID</span>
                <strong class="mono" style="font-size:16px;"><?= h((string) $student['student_number']) ?></strong>
            </article>
            <article class="kpi">
                <span>Program</span>
                <strong style="font-size:16px;"><?= h((string) ($student['program_code'] ?? '')) ?> · <?= h((string) ($student['year_level'] ?? '')) ?></strong>
            </article>
            <article class="kpi">
                <span>Recovery email</span>
                <strong style="font-size:16px;"><?= $recovery !== '' ? h($recovery) : 'Not on file' ?></strong>
            </article>
        </div>
        <p class="hint">The college office saves your Gmail or school email when they create this account. That address receives the recovery code if you forget your password. Ask the office if it needs to be changed.</p>
        <?php if (student_uses_default_password($student)): ?>
            <div class="alert alert-warn">You are still using the starting password given by the office. Change it below.</div>
        <?php endif; ?>
    </section>
    <section class="card">
        <h2><?= icon('key') ?> Change password</h2>
        <p class="lede">The office creates accounts with the starting password 123. Replace it with one only you know.</p>
        <form method="post" action="<?= h(url('student/profile.php')) ?>">
            <?= csrf_field() ?>
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="6" required>
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="6" required>
            <div style="margin-top:16px;">
                <button class="btn btn-gold" type="submit">Update password</button>
            </div>
        </form>
    </section>
</div>
<?php layout_end(); ?>
