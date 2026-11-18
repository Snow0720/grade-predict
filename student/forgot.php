<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/captcha.php';
require_once __DIR__ . '/../includes/mail.php';

db_or_setup();
if (current_student()) {
    redirect('student/dashboard.php');
}

if (isset($_GET['cancel'])) {
    unset($_SESSION['recovery_student_number']);
    redirect('student/forgot.php');
}

$error = '';
$sent = false;
$pendingNumber = trim((string) ($_SESSION['recovery_student_number'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? 'send');
    if ($action === 'verify') {
        $pendingNumber = trim((string) ($_POST['student_number'] ?? $pendingNumber));
        $next = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        try {
            $student = student_for_reset_code($pendingNumber, (string) ($_POST['code'] ?? ''));
            if (strlen($next) < 6) {
                throw new RuntimeException('Use at least 6 characters for your new password.');
            }
            if ($next !== $confirm) {
                throw new RuntimeException('The new password and confirmation do not match.');
            }
            if ($next === student_default_password()) {
                throw new RuntimeException('Choose a password other than the starting password.');
            }
            db()->prepare('UPDATE students SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $student['id']]);
            db()->prepare('UPDATE student_password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([(int) $student['reset_id']]);
            audit(null, 'student_password_reset', 'student', (string) $student['id'], 'Password reset from recovery code');
            unset($_SESSION['recovery_student_number']);
            flash('ok', 'Password updated. Sign in with your new password.');
            redirect('student/login.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif (!captcha_valid()) {
        $error = 'Check “I’m not a robot”, then continue.';
    } elseif (!mail_is_configured()) {
        $error = 'Password recovery is not set up yet. Ask the college office to add the Gmail mail settings, or to reset your password to 123.';
    } else {
        $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
        try {
            ensure_student_auth_columns();
            $stmt = db()->prepare('SELECT * FROM students WHERE student_number = ?');
            $stmt->execute([$studentNumber]);
            $student = $stmt->fetch();
            if ($student && trim((string) ($student['email'] ?? '')) !== '') {
                issue_student_reset($student);
                audit(null, 'student_password_reset_sent', 'student', (string) $student['id'], 'Recovery code sent');
            }
            $_SESSION['recovery_student_number'] = $studentNumber;
            $pendingNumber = $studentNumber;
            $sent = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · <?= h(SCHOOL_NAME) ?></title>
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
            <h1 style="font-size: clamp(28px, 3.2vw, 42px); line-height: 1.15; margin: 12px 0 16px;">Reset your password by email.</h1>
            <p>A 6-digit code is sent to the Gmail or school email saved on your account. Enter that code here.</p>
        </div>
        <p class="auth-year">Systems Plus Computer College</p>
    </aside>
    <main class="auth-main">
        <form class="auth-form" method="post" action="<?= h(url('student/forgot.php')) ?>">
            <?= csrf_field() ?>
            <p class="auth-kicker">Password recovery</p>
            <h2>Forgot password</h2>
            <?php if ($pendingNumber !== ''): ?>
                <p class="lede">Enter the code from the email, then choose a new password. The code expires in 15 minutes.</p>
            <?php else: ?>
                <p class="lede">Enter your student ID number. If a recovery email is on file, a 6-digit code is sent there.</p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if ($sent): ?>
                <div class="alert alert-ok">If that student number has a recovery email, the code was sent. It expires in 15 minutes.</div>
            <?php endif; ?>
            <?php if ($pendingNumber !== ''): ?>
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="student_number" value="<?= h($pendingNumber) ?>">
                <div class="auth-field">
                    <label id="code-label">Code from email</label>
                    <div class="code-boxes" role="group" aria-labelledby="code-label">
                        <?php for ($digit = 1; $digit <= 6; $digit++): ?>
                            <input class="code-box" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="<?= $digit === 1 ? 'one-time-code' : 'off' ?>" aria-label="Digit <?= $digit ?>" <?= $digit === 1 ? 'autofocus' : '' ?>>
                        <?php endfor; ?>
                    </div>
                    <input id="code" name="code" type="hidden" value="">
                    <p class="hint">If your phone or browser offers the code from the email, accept it and the boxes fill in.</p>
                </div>
                <div class="auth-field">
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="6" required>
                </div>
                <div class="auth-field">
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="6" required>
                </div>
                <button class="btn btn-gold" type="submit">Save password</button>
                <p class="auth-foot"><a href="<?= h(url('student/forgot.php', ['cancel' => 1])) ?>">Request a new code</a></p>
            <?php else: ?>
                <input type="hidden" name="action" value="send">
                <div class="auth-field">
                    <label for="student_number">Student ID Number</label>
                    <input id="student_number" name="student_number" type="text" autocomplete="username" placeholder="e.g. 04-24-32733" required autofocus>
                </div>
                <?= captcha_field() ?>
                <button class="btn btn-gold" type="submit">Send code</button>
            <?php endif; ?>
            <p class="auth-foot"><a href="<?= h(url('student/login.php')) ?>">Back to sign in</a></p>
        </form>
    </main>
</div>
<script src="<?= h(asset_url('assets/js/drafts.js')) ?>"></script>
<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.code-box'));
    var hidden = document.getElementById('code');
    var form = hidden ? hidden.form : null;
    if (!boxes.length || !hidden || !form) return;

    function digitsOf(value) {
        return String(value || '').replace(/\D/g, '').slice(0, boxes.length);
    }

    function sync() {
        hidden.value = boxes.map(function (box) { return box.value.replace(/\D/g, ''); }).join('');
    }

    function fill(start, digits) {
        digits.split('').forEach(function (digit, index) {
            if (boxes[start + index]) boxes[start + index].value = digit;
        });
        sync();
        var next = boxes[Math.min(start + digits.length, boxes.length - 1)];
        if (next) next.focus();
    }

    boxes.forEach(function (box, index) {
        box.addEventListener('input', function () {
            var digits = digitsOf(box.value);
            if (digits.length > 1) {
                box.value = '';
                fill(index, digits);
                return;
            }
            box.value = digits;
            sync();
            if (digits && boxes[index + 1]) boxes[index + 1].focus();
        });
        box.addEventListener('keydown', function (event) {
            if (event.key === 'Backspace' && box.value === '' && boxes[index - 1]) {
                boxes[index - 1].focus();
                boxes[index - 1].value = '';
                sync();
            }
            if (event.key === 'ArrowLeft' && boxes[index - 1]) boxes[index - 1].focus();
            if (event.key === 'ArrowRight' && boxes[index + 1]) boxes[index + 1].focus();
        });
        box.addEventListener('paste', function (event) {
            var text = (event.clipboardData || window.clipboardData).getData('text');
            var digits = digitsOf(text);
            if (!digits) return;
            event.preventDefault();
            fill(index, digits);
        });
        box.addEventListener('focus', function () { box.select(); });
    });

    form.addEventListener('submit', function (event) {
        sync();
        if (hidden.value.length !== boxes.length) {
            event.preventDefault();
            boxes[0].focus();
        }
    });
})();
</script>
</body>
</html>
