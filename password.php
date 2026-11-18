<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

db_or_setup();
$user = current_user();
$side = (($_GET['side'] ?? $_POST['side'] ?? $_SESSION['portal'] ?? '') === 'admin') ? 'admin' : 'professor';
if (!$user) {
    redirect(login_path($side));
}
$side = ($user['portal'] ?? '') === 'admin' ? 'admin' : 'professor';
$availablePortals = account_portals($user);
$personDetails = account_person_details($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'update_profile') {
        try {
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $portal = (string) ($_POST['portal'] ?? '');
            $length = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if ($username === '' || $length($username) > 60 || preg_match('/[\s\x00-\x1F\x7F]/u', $username)) {
                throw new RuntimeException('Enter a username of up to 60 characters, without spaces.');
            }
            if ($name === '' || $length($name) > 150 || $length($title) > 20) {
                throw new RuntimeException('Enter your name (up to 150 characters) and a title of up to 20 characters.');
            }
            if ($email !== '' && (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new RuntimeException('Enter a valid email address of up to 150 characters.');
            }
            if (!isset($availablePortals[$portal])) {
                throw new RuntimeException('Choose a portal available to your assigned roles.');
            }
            if (!password_verify((string) ($_POST['profile_password'] ?? ''), $user['password_hash'])) {
                throw new RuntimeException('Enter your current password to save account changes.');
            }
            $existing = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $existing->execute([$username, (int) $user['id']]);
            if ($existing->fetch()) {
                throw new RuntimeException('That username is already in use. Choose another username.');
            }
            db()->prepare('UPDATE users SET username = ?, full_name = ?, title = ?, email = ? WHERE id = ?')
                ->execute([$username, $name, $title, $email !== '' ? $email : null, (int) $user['id']]);
            audit((int) $user['id'], 'account_profile_update', 'user', (string) $user['id'], 'Updated own account profile');
            if ($portal !== $side) {
                $_SESSION = [];
                session_regenerate_id(true);
                redirect(login_path($portal));
            }
            flash('ok', 'Your account details have been updated. Use your saved username next time you sign in.');
        } catch (Throwable $e) {
            flash('error', $e instanceof PDOException ? 'Your account could not be saved. The username may already be in use.' : $e->getMessage());
        }
        redirect('password.php', ['side' => $side]);
    }
    $current = (string) ($_POST['current_password'] ?? '');
    $next = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        flash('error', 'The current password is not correct.');
    } elseif (strlen($next) < 8) {
        flash('error', 'Use at least 8 characters for the new password.');
    } elseif ($next !== $confirm) {
        flash('error', 'The new password and confirmation do not match.');
    } else {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
        audit((int) $user['id'], 'password_change', 'user', (string) $user['id'], 'Password updated');
        flash('ok', 'Password updated.');
        redirect(($user['portal'] ?? '') === 'admin' ? 'admin/dashboard.php' : 'professor/dashboard.php');
    }
    redirect('password.php', ['side' => $side]);
}

layout_start('My Account', 'password', [['label' => 'My Account']]);
?>
<div class="page-head"><div><h1>My Account</h1><p class="lede">Your account information and sign-in security.</p></div></div>
<div class="account-layout">
    <section class="card account-profile" aria-labelledby="account-details-title">
        <div class="account-profile-head">
            <span class="account-avatar" aria-hidden="true"><?= icon('users') ?></span>
            <div><h2 id="account-details-title"><?= h(account_office_label($user)) ?></h2><p><?= h(account_header_caption($user)) ?></p></div>
        </div>
        <form class="account-profile-form" method="post" action="<?= h(url('password.php', ['side' => $side])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="account-field"><label for="account-role">Role / Office</label><input id="account-role" value="<?= h(account_office_label($user)) ?>" readonly aria-readonly="true"></div>
            <div class="account-field"><label for="account-title">Title / Prefix</label><input id="account-title" name="title" value="<?= h((string) ($personDetails['title'] ?? '')) ?>" maxlength="20" placeholder="e.g. Ms."></div>
            <div class="account-field"><label for="account-name">Name</label><input id="account-name" name="full_name" value="<?= h($personDetails['full_name']) ?>" maxlength="150" autocomplete="name" required></div>
            <div class="account-field"><label for="account-username">Username</label><input id="account-username" name="username" value="<?= h($user['username']) ?>" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
            <div class="account-field"><label for="account-email">Email address</label><input id="account-email" name="email" type="email" value="<?= h((string) ($user['email'] ?? '')) ?>" maxlength="150" autocomplete="email" placeholder="you@example.com"></div>
            <div class="account-field"><label for="account-portal">Portal</label><select id="account-portal" name="portal" aria-describedby="account-portal-hint" required>
                <?php foreach ($availablePortals as $portalCode => $portalName): ?><option value="<?= h($portalCode) ?>" <?= $portalCode === $side ? 'selected' : '' ?>><?= h($portalName) ?></option><?php endforeach; ?>
            </select><p class="hint" id="account-portal-hint">Portals follow your assigned roles. Switching portals asks you to sign in again.</p></div>
            <div class="account-field"><label for="profile-password">Current password</label><div class="password-wrap"><input id="profile-password" name="profile_password" type="password" autocomplete="current-password" required><button class="password-toggle" type="button" aria-label="Show current password" aria-controls="profile-password" aria-pressed="false"><span class="eye-open"><?= icon('eye') ?></span><span class="eye-shut"><?= icon('eye-off') ?></span></button></div><p class="hint">Confirm your password to save your account details.</p></div>
            <div class="account-form-actions"><button class="btn btn-gold" type="submit"><?= icon('check') ?> Save account</button></div>
        </form>
        <dl class="account-details">
            <div><dt>Account status</dt><dd><span class="account-status">Active</span></dd></div>
        </dl>
    </section>
    <section class="card account-security" aria-labelledby="account-security-title">
        <div class="account-section-head"><span class="account-security-icon" aria-hidden="true"><?= icon('key') ?></span><div><h2 id="account-security-title">Change password</h2><p>Choose a password you do not use for other accounts.</p></div></div>
        <form id="account-password-form" class="account-password-form" method="post" action="<?= h(url('password.php', ['side' => $side])) ?>" data-device-password="<?= h($user['username']) ?>" data-device-portal="<?= h($side) ?>">
            <?= csrf_field() ?>
            <?php foreach ([
                ['current_password', 'Current password', 'current-password'],
                ['new_password', 'New password', 'new-password'],
                ['confirm_password', 'Confirm new password', 'new-password'],
            ] as [$field, $label, $autocomplete]): ?>
                <div class="account-field">
                    <label for="<?= h($field) ?>"><?= h($label) ?></label>
                    <div class="password-wrap">
                        <input id="<?= h($field) ?>" name="<?= h($field) ?>" type="password" autocomplete="<?= h($autocomplete) ?>" <?= $field !== 'current_password' ? 'minlength="8" aria-describedby="password-requirements"' : '' ?> required>
                        <button class="password-toggle" type="button" aria-label="Show <?= h(strtolower($label)) ?>" aria-controls="<?= h($field) ?>" aria-pressed="false"><span class="eye-open"><?= icon('eye') ?></span><span class="eye-shut"><?= icon('eye-off') ?></span></button>
                    </div>
                </div>
            <?php endforeach; ?>
            <ul class="password-requirements" id="password-requirements" aria-live="polite">
                <li id="password-length-rule"><span class="requirement-dot" aria-hidden="true"></span>At least 8 characters</li>
                <li id="password-match-rule"><span class="requirement-dot" aria-hidden="true"></span>New passwords match</li>
            </ul>
            <div class="account-form-actions"><button class="btn btn-gold" type="submit"><?= icon('check') ?> Update password</button></div>
        </form>
    </section>
</div>
<?php layout_end('assets/js/account.js'); ?>
