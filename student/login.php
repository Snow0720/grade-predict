<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/captcha.php';

db_or_setup();
$error = '';
if (current_student()) {
    redirect('student/dashboard.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!captcha_valid()) {
        $error = 'Check “I’m not a robot”, then sign in.';
    } else {
        $error = attempt_student_login();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student sign in · <?= h(SCHOOL_NAME) ?></title>
    <link rel="icon" href="<?= h(url('img/logo.png')) ?>">
    <?php pwa_tags(); ?>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/app.css')) ?>">
</head>
<body class="auth auth-student">
<div class="auth-shell">
    <aside class="auth-aside">
        <div class="auth-mark">
            <img src="<?= h(url('img/logo.png')) ?>" alt="School Logo">
            <div>
                <strong>Systems Plus</strong>
                <span>Computer College · <?= h(SCHOOL_ESTABLISHED) ?></span>
            </div>
        </div>
        <div class="auth-copy">
            <p class="auth-kicker">Student portal</p>
            <h1 style="font-size: clamp(28px, 3.2vw, 42px); line-height: 1.15; margin: 12px 0 16px;">
                Monitor your grades<br>&amp; honor standing.
            </h1>
            <p>The college office creates your account. Grades stay hidden until your professor releases them. Honor standing shows only whether you are at risk or not at risk.</p>
        </div>
        <p class="auth-year">Systems Plus Computer College</p>
    </aside>
    <main class="auth-main">
        <form class="auth-form" method="post" action="<?= h(url('student/login.php')) ?>">
            <?= csrf_field() ?>
            <p class="auth-kicker">Student sign in</p>
            <h2>Welcome student</h2>
            <p class="lede">Sign in with your official SPCC Student ID Number and password to access your academic standing.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if (($_GET['error'] ?? '') === 'portal'): ?>
                <div class="alert alert-error">Please sign in to access your student portal.</div>
            <?php endif; ?>
            <?php foreach (pull_flashes() as $flash): ?>
                <div class="alert alert-<?= h($flash['type'] === 'ok' ? 'ok' : 'error') ?>"><?= h($flash['message']) ?></div>
            <?php endforeach; ?>

            <div class="auth-field">
                <label for="student_number">Student ID Number</label>
                <input id="student_number" name="student_number" type="text" autocomplete="username" placeholder="e.g. 04-24-32733" required autofocus>
            </div>
            <div class="auth-field">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Password" required>
                    <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-shut" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-3.2 4.2"/><path d="M6.1 6.1C3.5 8 2 12 2 12s3.5 7 10 7a10.8 10.8 0 0 0 4.1-.8"/></svg>
                    </button>
                </div>
            </div>
            <?= captcha_field() ?>
            <button class="btn btn-gold" type="submit">Login</button>
            <p class="auth-foot"><a href="<?= h(url('student/forgot.php')) ?>">Forgot password?</a></p>
            <p class="auth-foot"><a href="<?= h(url('index.php')) ?>">College home</a></p>
        </form>
    </main>
</div>
<script src="<?= h(asset_url('assets/js/drafts.js')) ?>"></script>
</body>
</html>
