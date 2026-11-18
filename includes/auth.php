<?php
declare(strict_types=1);

function office_roles(): array
{
    return ['system_admin', 'program_chair', 'college_oic', 'osa'];
}

function college_wide_roles(): array
{
    return ['system_admin', 'college_oic', 'osa'];
}

function is_view_only_oic(array $user): bool
{
    return in_array('college_oic', $user['roles'] ?? [], true);
}

/** OIC may browse student records and grades, but cannot submit any record changes. */
function oic_request_allowed(string $path, string $method, string $view = ''): bool
{
    if ($path === 'logout.php' && in_array($method, ['GET', 'POST'], true)) {
        return true;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return false;
    }
    return ($path === 'admin/students.php' && $view === '')
        || ($path === 'admin/student.php' && $view === '')
        || ($path === 'admin/users.php' && in_array($view, ['students', 'student-record'], true));
}

function enforce_oic_view_only(array $user): void
{
    if (!is_view_only_oic($user) || PHP_SAPI === 'cli') {
        return;
    }
    $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', dirname(__DIR__)) . '/';
    $path = str_starts_with(strtolower($file), strtolower($root)) ? substr($file, strlen($root)) : '';
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($path, ['admin/dashboard.php', 'admin/login.php'], true) && in_array($method, ['GET', 'HEAD'], true)) {
        redirect(home_path('admin', $user));
    }
    if (!oic_request_allowed($path, $method, (string) ($_GET['view'] ?? ''))) {
        http_response_code(403);
        exit('College OIC access is view-only: student list and grades.');
    }
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([(int) $_SESSION['uid']]);
    $row = $stmt->fetch();
    if (!$row) {
        $_SESSION = [];
        return null;
    }
    $roles = db()->prepare(
        'SELECT r.code, r.name, ur.program_id, p.code AS program_code
         FROM user_roles ur
         JOIN roles r ON r.id = ur.role_id
         LEFT JOIN programs p ON p.id = ur.program_id
         WHERE ur.user_id = ?'
    );
    $roles->execute([(int) $row['id']]);
    $row['role_rows'] = $roles->fetchAll();
    $row['roles'] = array_column($row['role_rows'], 'code');
    $row['portal'] = $_SESSION['portal'] ?? 'admin';
    $row['role_label'] = role_label($row);
    enforce_oic_view_only($row);
    return $user = $row;
}

function role_label(array $user): string
{
    $short = [
        'system_admin' => 'SysAd',
    ];
    $names = [];
    foreach ($user['role_rows'] as $role) {
        $code = (string) ($role['code'] ?? '');
        $program = trim((string) ($role['program_code'] ?? ''));
        $names[] = $code === 'program_chair' && $program !== ''
            ? $program . ' Program Chair'
            : ($short[$code] ?? (string) $role['name']);
    }
    return $names === [] ? 'User' : implode(', ', $names);
}

function display_name(array $person): string
{
    $title = trim((string) ($person['title'] ?? ''));
    $name = trim((string) ($person['full_name'] ?? ''));
    return trim($title . ' ' . $name);
}

function can(array $user, string $permission): bool
{
    if (is_view_only_oic($user)) {
        return $permission === 'students_read';
    }
    // Secretary (OSA) cannot access Reports or Grade Review Queue.
    if (
        in_array($permission, ['reports', 'queue'], true)
        && in_array('osa', $user['roles'] ?? [], true)
    ) {
        return false;
    }
    if ($permission === 'reports' && in_array('program_chair', $user['roles'] ?? [], true)) {
        return false;
    }
    $offices = office_roles();
    $map = [
        'manage_users' => $offices,
        'manage_faculty' => $offices,
        'manage_academics' => $offices,
        'import' => [],
        'rules' => $offices,
        'settings' => $offices,
        'queue' => $offices,
        'scholarships' => ['system_admin', 'osa'],
        'reports' => $offices,
        'evaluations' => $offices,
        'recognition' => $offices,
        'analytics' => $offices,
        'audit' => $offices,
        'students_write' => $offices,
        'students_read' => $offices,
        'finalize' => $offices,
    ];
    return (bool) array_intersect($user['roles'], $map[$permission] ?? []);
}

function scoped_program_ids(array $user): ?array
{
    // College-wide offices see every program.
    if (array_intersect($user['roles'], college_wide_roles())) {
        return null;
    }
    // Program chairs only see the program(s) assigned on their role.
    if (in_array('program_chair', $user['roles'], true)) {
        return array_values(array_unique(chair_program_ids($user)));
    }
    return [];
}

function chair_program_ids(array $user): array
{
    $ids = [];
    foreach ($user['role_rows'] as $role) {
        if ($role['code'] === 'program_chair' && $role['program_id']) {
            $ids[] = (int) $role['program_id'];
        }
    }
    return $ids;
}

/** null = unrestricted; otherwise the program ids the user may access. */
function program_in_scope(?array $scope, int $programId): bool
{
    if ($scope === null) {
        return $programId > 0;
    }
    return in_array($programId, $scope, true);
}

function programs_for_user(array $user, string $columns = 'id, code, name'): array
{
    $scope = scoped_program_ids($user);
    $sql = 'SELECT ' . $columns . ' FROM programs';
    if (is_array($scope)) {
        if ($scope === []) {
            return [];
        }
        $sql .= ' WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
    $sql .= ' ORDER BY code';
    return db()->query($sql)->fetchAll();
}

function login_path(string $portal): string
{
    return match ($portal) {
        'admin' => 'admin/login.php',
        'student' => 'student/login.php',
        default => 'professor/login.php',
    };
}

function home_path(string $portal, ?array $user = null): string
{
    if ($portal === 'admin' && $user !== null && is_view_only_oic($user)) {
        return 'admin/users.php?view=students';
    }
    return match ($portal) {
        'admin' => 'admin/dashboard.php',
        'student' => 'student/dashboard.php',
        default => 'professor/dashboard.php',
    };
}

function account_portals(array $user): array
{
    $portals = [];
    if (array_intersect($user['roles'] ?? [], office_roles())) {
        $portals['admin'] = 'College Admins';
    }
    if (!is_view_only_oic($user) && in_array('professor', $user['roles'] ?? [], true)) {
        $portals['professor'] = 'Professor';
    }
    return $portals;
}

function ensure_student_auth_columns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        $existsAdviser = db()->query("SHOW COLUMNS FROM students LIKE 'adviser_id'")->fetch();
        if (!$existsAdviser) {
            try {
                db()->exec('ALTER TABLE students ADD COLUMN adviser_id INT UNSIGNED DEFAULT NULL AFTER section_id');
            } catch (Throwable $e) {
                try {
                    db()->exec('ALTER TABLE students ADD COLUMN adviser_id INT UNSIGNED DEFAULT NULL');
                } catch (Throwable $e2) {
                    error_log('ensure_student_auth_columns adviser_id: ' . $e2->getMessage());
                }
            }
        }
        $existsHash = db()->query("SHOW COLUMNS FROM students LIKE 'password_hash'")->fetch();
        if (!$existsHash) {
            try {
                db()->exec('ALTER TABLE students ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL');
            } catch (Throwable $e) {
                error_log('ensure_student_auth_columns password_hash: ' . $e->getMessage());
            }
        }
        $existsLogin = db()->query("SHOW COLUMNS FROM students LIKE 'last_login_at'")->fetch();
        if (!$existsLogin) {
            try {
                db()->exec('ALTER TABLE students ADD COLUMN last_login_at DATETIME DEFAULT NULL');
            } catch (Throwable $e) {
                error_log('ensure_student_auth_columns last_login_at: ' . $e->getMessage());
            }
        }
        $existsEmail = db()->query("SHOW COLUMNS FROM students LIKE 'email'")->fetch();
        if (!$existsEmail) {
            try {
                db()->exec('ALTER TABLE students ADD COLUMN email VARCHAR(150) DEFAULT NULL');
            } catch (Throwable $e) {
                error_log('ensure_student_auth_columns email: ' . $e->getMessage());
            }
        }
        $existsVisible = db()->query("SHOW COLUMNS FROM enrollments LIKE 'grades_visible'")->fetch();
        if (!$existsVisible) {
            try {
                db()->exec('ALTER TABLE enrollments ADD COLUMN grades_visible TINYINT(1) NOT NULL DEFAULT 0');
            } catch (Throwable $e) {
                error_log('ensure_student_auth_columns grades_visible: ' . $e->getMessage());
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS student_password_resets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                student_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME DEFAULT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reset_token (token_hash),
                KEY idx_reset_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $existsAttempts = db()->query("SHOW COLUMNS FROM student_password_resets LIKE 'attempts'")->fetch();
        if (!$existsAttempts) {
            try {
                db()->exec('ALTER TABLE student_password_resets ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0');
            } catch (Throwable $e) {
                error_log('ensure_student_auth_columns attempts: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_student_auth_columns: ' . $e->getMessage());
    }
}

function student_default_password(): string
{
    return '123';
}

function normalize_recovery_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid Gmail or school email for password recovery.');
    }
    return $email;
}

function student_uses_default_password(array $student): bool
{
    $hash = (string) ($student['password_hash'] ?? '');
    if ($hash === '') {
        return true;
    }
    return password_verify(student_default_password(), $hash);
}

function current_student(): ?array
{
    static $loaded = false;
    static $student = null;
    if ($loaded) {
        return $student;
    }
    $loaded = true;
    if (empty($_SESSION['student_id']) || ($_SESSION['portal'] ?? '') !== 'student') {
        return null;
    }
    ensure_student_auth_columns();

    $hasAdviser = false;
    try {
        $hasAdviser = (bool) db()->query("SHOW COLUMNS FROM students LIKE 'adviser_id'")->fetch();
    } catch (Throwable $e) {}

    try {
        if ($hasAdviser) {
            $stmt = db()->prepare(
                'SELECT st.*, p.code AS program_code, p.name AS program_name, sec.name AS section_name,
                        adv_u.full_name AS adviser_name, adv_u.title AS adviser_title
                 FROM students st
                 JOIN programs p ON p.id = st.program_id
                 LEFT JOIN sections sec ON sec.id = st.section_id
                 LEFT JOIN professors adv ON adv.id = st.adviser_id
                 LEFT JOIN users adv_u ON adv_u.id = adv.user_id
                 WHERE st.id = ?'
            );
        } else {
            $stmt = db()->prepare(
                'SELECT st.*, p.code AS program_code, p.name AS program_name, sec.name AS section_name,
                        NULL AS adviser_name, NULL AS adviser_title
                 FROM students st
                 JOIN programs p ON p.id = st.program_id
                 LEFT JOIN sections sec ON sec.id = st.section_id
                 WHERE st.id = ?'
            );
        }
        $stmt->execute([(int) $_SESSION['student_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            $_SESSION = [];
            return null;
        }
        $row['portal'] = 'student';
        $row['roles'] = ['student'];
        $row['role_label'] = 'Student';
        return $student = $row;
    } catch (Throwable $e) {
        error_log('current_student error: ' . $e->getMessage());
        return null;
    }
}

function require_student(): array
{
    $student = current_student();
    if (!$student) {
        redirect('student/login.php');
    }
    return $student;
}

function attempt_student_login(): string
{
    ensure_student_auth_columns();
    $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($studentNumber === '' || $password === '') {
        return 'Please enter your student ID number and password.';
    }

    $stmt = db()->prepare('SELECT * FROM students WHERE student_number = ?');
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch();

    if (!$student) {
        try { audit(null, 'student_login_failed', 'student', $studentNumber, 'Student number not found'); } catch (Throwable $e) {}
        return 'The student number or password is not correct.';
    }

    // password_hash column may still be missing on old live DBs that haven't migrated yet
    $hash = isset($student['password_hash']) ? (string) $student['password_hash'] : '';
    $passwordOk = false;
    if ($hash !== '') {
        $passwordOk = password_verify($password, $hash);
    } else {
        $passwordOk = ($password === student_default_password());
        if ($passwordOk) {
            try {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare('UPDATE students SET password_hash = ? WHERE id = ?')
                    ->execute([$newHash, (int) $student['id']]);
            } catch (Throwable $e) {
                error_log('student login hash save: ' . $e->getMessage());
            }
        }
    }

    if (!$passwordOk) {
        try { audit(null, 'student_login_failed', 'student', $studentNumber, 'Password mismatch'); } catch (Throwable $e) {}
        return 'The student number or password is not correct.';
    }

    session_regenerate_id(true);
    $_SESSION['student_id'] = (int) $student['id'];
    $_SESSION['portal'] = 'student';

    try {
        db()->prepare('UPDATE students SET last_login_at = NOW() WHERE id = ?')->execute([(int) $student['id']]);
    } catch (Throwable $e) {
        error_log('student last_login_at update: ' . $e->getMessage());
    }
    try {
        audit(null, 'student_login', 'student', (string) $student['id'], 'Student portal login');
    } catch (Throwable $e) {}

    redirect('student/dashboard.php');
}

function attempt_portal_login(string $portal): string
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $roles = [];
    if ($user) {
        $roleStmt = db()->prepare(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
        );
        $roleStmt->execute([(int) $user['id']]);
        $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $adminRoles = office_roles();
    $passwordOk = $user && password_verify($password, $user['password_hash']);
    $portalOk = $passwordOk && (
        ($portal === 'professor' && in_array('professor', $roles, true))
        || ($portal === 'admin' && array_intersect($roles, $adminRoles))
    );
    if (!$portalOk) {
        audit($user ? (int) $user['id'] : null, 'login_failed', 'user', $username, $portal);
        return $passwordOk
            ? 'This account cannot sign in here.'
            : 'The username or password is not correct.';
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['portal'] = $portal;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    audit((int) $user['id'], 'login', 'user', (string) $user['id'], $portal . ' portal');
    redirect(home_path($portal, ['roles' => $roles]));
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $side = $_GET['side'] ?? $_POST['side'] ?? '';
        if (str_contains($script, '/admin/') || $side === 'admin') {
            redirect(login_path('admin'));
        }
        if (str_contains($script, '/student/') || $side === 'student') {
            redirect(login_path('student'));
        }
        if (str_contains($script, '/professor/') || str_contains($script, '/api/')) {
            redirect(login_path('professor'));
        }
        redirect(login_path($side === 'admin' ? 'admin' : ($side === 'student' ? 'student' : 'professor')));
    }
    return $user;
}

function require_portal(string $portal): array
{
    $user = current_user();
    if (!$user || ($user['portal'] ?? '') !== $portal) {
        redirect(login_path($portal), $user ? ['error' => 'portal'] : []);
    }
    if ($portal === 'professor' && !in_array('professor', $user['roles'], true)) {
        redirect(login_path('professor'), ['error' => 'portal']);
    }
    if ($portal === 'admin' && !array_intersect($user['roles'], office_roles())) {
        redirect(login_path('admin'), ['error' => 'portal']);
    }
    if ($portal === 'admin') {
        repair_college_wide_scope($user);
    }
    return $user;
}

function sheet_query(array $query = []): array
{
    if (($_SESSION['portal'] ?? '') === 'admin') {
        $query['side'] = 'admin';
    }
    return $query;
}

function repair_college_wide_scope(array $user): void
{
    if (is_view_only_oic($user)) {
        return;
    }
    if (!empty($_SESSION['college_scope_repaired']) || !array_intersect($user['roles'], college_wide_roles())) {
        return;
    }
    db()->prepare(
        'UPDATE user_roles ur
         JOIN roles r ON r.id = ur.role_id
         SET ur.program_id = NULL
         WHERE ur.user_id = ? AND r.code IN ("system_admin", "college_oic", "osa")'
    )->execute([(int) $user['id']]);
    $_SESSION['college_scope_repaired'] = 1;
}

function require_permission(array $user, string $permission): void
{
    if (!can($user, $permission)) {
        http_response_code(403);
        exit('Your role cannot open this page.');
    }
}

function unread_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}
