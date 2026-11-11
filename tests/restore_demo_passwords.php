<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
db_or_setup();
$hash = password_hash('123', PASSWORD_DEFAULT);
db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0')->execute([$hash]);
$stored = db()->query("SELECT password_hash, must_change_password FROM users WHERE username = 'fquiza'")->fetch();
if (!password_verify('123', $stored['password_hash']) || (int) $stored['must_change_password'] !== 0) {
    fwrite(STDERR, "Demo password restore failed\n");
    exit(1);
}
echo "demo passwords set to 123\n";
