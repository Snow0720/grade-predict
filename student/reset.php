<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';

db_or_setup();
if (current_student()) {
    redirect('student/dashboard.php');
}

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$student = student_from_reset_token($token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $student) {
    require_csrf();
    $next = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (strlen($next) < 6) {
        $error = 'Use at least 6 characters for your new password.';
    } elseif ($next !== $confirm) {
        $error = 'The new password and confirmation do not match.';
    } elseif ($next === student_default_password()) {
        $error = 'Choose a password other than the starting password.';
    } else {
        db()->prepare('UPDATE students SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $student['id']]);
        db()->prepare('UPDATE student_password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([(int) $student['reset_id']]);
        audit(null, 'student_password_reset', 'student', (string) $student['id'], 'Password reset from recovery email');
        flash('ok', 'Password updated. Sign in with your new password.');
        redirect('student/login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password · <?= h(SCHOOL_NAME) ?></title>
    <link rel="icon" type="image/x-icon" href="<?= h(url('img/newest-favicon.ico')) ?>">
    <?php pwa_tags(); ?>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/app.css')) ?>">
</head>
<body class="auth auth-student">
<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-mark">
            <img src="<?= h(url('img/Newest Logo.webp')) ?>" alt="School Logo">
            <div>
                <strong>Systems Plus</strong>
                <span>Computer College · <?= h(SCHOOL_ESTABLISHED) ?></span>
            </div>
        </div>
        <div class="auth-copy">
            <p class="auth-kicker">Student portal</p>
            <h1 style="font-size: clamp(28px, 3.2vw, 42px); line-height: 1.15; margin: 12px 0 16px;">Choose a new password.</h1>
            <p>This link came from the recovery email on your student account.</p>
        </div>
        <p class="auth-year">Systems Plus Computer College</p>
    </aside>
    <main class="auth-main">
        <form class="auth-form" method="post" action="<?= h(url('student/reset.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <p class="auth-kicker">Password recovery</p>
            <h2>New password</h2>
            <?php if (!$student): ?>
                <div class="alert alert-error">This reset link is invalid or has expired. Request a new one from the sign-in page.</div>
                <p class="auth-foot"><a href="<?= h(url('student/forgot.php')) ?>">Request a new link</a></p>
            <?php else: ?>
                <p class="lede">Set a new password for <?= h((string) $student['student_number']) ?>.</p>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?= h($error) ?></div>
                <?php endif; ?>
                <div class="auth-field">
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="6" required>
                </div>
                <div class="auth-field">
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="6" required>
                </div>
                <button class="btn btn-gold" type="submit">Save password</button>
            <?php endif; ?>
        </form>
    </main>
</div>
</body>
</html>
