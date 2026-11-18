<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
ensure_student_auth_columns();
ensure_student_adviser_column();
$user = require_portal('admin');
$moduleController = module_controller('admin/users.php', (string) ($_GET['view'] ?? ''));
if ($moduleController !== null) {
    require __DIR__ . '/' . $moduleController;
    return;
}

require_permission($user, 'manage_users');

$officeRoles = [
    'system_admin' => 'System Administrator',
    'program_chair' => 'Program Chair',
    'college_oic' => 'College OIC',
    'osa' => 'Secretary',
];
$accountScope = scoped_program_ids($user);
if (is_array($accountScope)) $officeRoles = ['program_chair' => 'Program Chair'];
$assignableRoles = array_diff_key($officeRoles, ['system_admin' => true]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (is_array($accountScope) && in_array($action, ['create', 'update'], true)) {
            if (($_POST['role'] ?? '') !== 'program_chair' || !program_in_scope($accountScope, (int) ($_POST['program_id'] ?? 0))) {
                throw new RuntimeException('Office accounts must belong to your assigned program.');
            }
        }
        if (is_array($accountScope) && in_array($action, ['update', 'toggle'], true)) {
            $targetRoles = db()->prepare('SELECT r.code, ur.program_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
            $targetRoles->execute([(int) ($_POST['user_id'] ?? 0)]);
            $targetRoles = $targetRoles->fetchAll();
            if ($targetRoles === []) throw new RuntimeException('Account not found.');
            foreach ($targetRoles as $targetRole) {
                if ($targetRole['code'] !== 'program_chair' || !program_in_scope($accountScope, (int) $targetRole['program_id'])) {
                    throw new RuntimeException('This account is outside your assigned program.');
                }
            }
        }
        if ($action === 'create_professor') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $name === '' || strlen($password) < 8) {
                throw new RuntimeException('Username, name, and a password of at least 8 characters are required.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO users (username, password_hash, full_name, title, must_change_password) VALUES (?, ?, ?, ?, 1)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $title]);
            $userId = (int) db()->lastInsertId();
            $roleId = (int) db()->query("SELECT id FROM roles WHERE code = 'professor'")->fetchColumn();
            db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
            db()->prepare('INSERT INTO professors (user_id) VALUES (?)')->execute([$userId]);
            audit((int) $user['id'], 'account_create', 'user', (string) $userId, 'Professor ' . $username);
            db()->commit();
            flash('ok', 'Professor account created. They must change the temporary password upon initial login.');
        } elseif ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $programId = (int) ($_POST['program_id'] ?? 0);
            if ($username === '' || $name === '' || !isset($assignableRoles[$role]) || strlen($password) < 8) {
                throw new RuntimeException('Complete the account form with a password of at least 8 characters.');
            }
            if ($role === 'program_chair' && $programId <= 0) {
                throw new RuntimeException('A program chair must be assigned to one program.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO users (username, password_hash, full_name, title, must_change_password) VALUES (?, ?, ?, "", 1)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name]);
            $userId = (int) db()->lastInsertId();
            $roleId = db()->prepare('SELECT id FROM roles WHERE code = ?');
            $roleId->execute([$role]);
            db()->prepare('INSERT INTO user_roles (user_id, role_id, program_id) VALUES (?, ?, ?)')
                ->execute([$userId, (int) $roleId->fetchColumn(), $role === 'program_chair' ? $programId : null]);
            audit((int) $user['id'], 'account_create', 'user', (string) $userId, $role . ' ' . $username);
            db()->commit();
            flash('ok', 'Office account created successfully.');
        } elseif ($action === 'toggle') {
            $target = (int) ($_POST['user_id'] ?? 0);
            if ($target === (int) $user['id']) {
                throw new RuntimeException('You cannot disable the account you are currently using.');
            }
            db()->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?')->execute([$target]);
            audit((int) $user['id'], 'account_status', 'user', (string) $target, 'Active flag toggled');
            flash('ok', 'Account status updated.');
        } elseif ($action === 'update') {
            $target = (int) ($_POST['user_id'] ?? 0);
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $role = (string) ($_POST['role'] ?? '');
            $programId = (int) ($_POST['program_id'] ?? 0);
            if ($target <= 0 || $name === '' || !isset($officeRoles[$role])) {
                throw new RuntimeException('Complete the account name and role.');
            }
            if ($target === (int) $user['id'] && $role !== 'system_admin') {
                throw new RuntimeException('You cannot remove the System Administrator role from the account you are using.');
            }
            if ($role === 'program_chair' && $programId <= 0) {
                throw new RuntimeException('A Program Chair must have an assigned program.');
            }
            $roleStmt = db()->prepare('SELECT id FROM roles WHERE code = ?');
            $roleStmt->execute([$role]);
            $roleId = (int) $roleStmt->fetchColumn();
            db()->beginTransaction();
            db()->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$name, $target]);
            db()->prepare('DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code <> "professor"')->execute([$target]);
            db()->prepare('INSERT INTO user_roles (user_id, role_id, program_id) VALUES (?, ?, ?)')
                ->execute([$target, $roleId, $role === 'program_chair' ? $programId : null]);
            audit((int) $user['id'], 'account_update', 'user', (string) $target, $role . ' ' . $name);
            db()->commit();
            flash('ok', 'Office account updated successfully.');
        } elseif ($action === 'create_student') {
            $scope = scoped_program_ids($user);
            $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $programId = (int) ($_POST['program_id'] ?? 0);
            $year = trim((string) ($_POST['year_level'] ?? ''));
            $type = ($_POST['student_type'] ?? '') === 'Irregular' ? 'Irregular' : 'Regular';
            $sectionId = (int) ($_POST['section_id'] ?? 0);
            $professorId = (int) ($_POST['professor_id'] ?? 0);
            $email = normalize_recovery_email((string) ($_POST['email'] ?? ''));

            if ($studentNumber === '' || $name === '' || $programId <= 0 || !in_array($year, ['1st Year', '2nd Year', '3rd Year', '4th Year'], true)) {
                throw new RuntimeException('Complete the student number, name, program, and year level.');
            }
            if (is_array($scope) && !in_array($programId, $scope, true)) {
                throw new RuntimeException('That program is outside your access.');
            }
            if ($sectionId > 0) {
                $section = db()->prepare('SELECT program_id FROM sections WHERE id = ?');
                $section->execute([$sectionId]);
                $sectionProgram = (int) $section->fetchColumn();
                if ($sectionProgram !== $programId) {
                    throw new RuntimeException('The section must belong to the selected program.');
                }
            }
            $professorName = '';
            if ($professorId > 0) {
                $professor = db()->prepare(
                    'SELECT pr.id, u.full_name, u.title
                     FROM professors pr
                     JOIN users u ON u.id = pr.user_id
                     WHERE pr.id = ? AND u.is_active = 1'
                );
                $professor->execute([$professorId]);
                $professor = $professor->fetch();
                if (!$professor) {
                    throw new RuntimeException('Choose an active professor from the faculty masterlist.');
                }
                $professorName = trim($professor['title'] . ' ' . $professor['full_name']);
            }

            db()->prepare(
                'INSERT INTO students (student_number, full_name, student_type, year_level, program_id, section_id, adviser_id, email, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $studentNumber,
                $name,
                $type,
                $year,
                $programId,
                $sectionId > 0 ? $sectionId : null,
                $professorId > 0 ? $professorId : null,
                $email,
                password_hash(student_default_password(), PASSWORD_DEFAULT),
            ]);

            $studentId = (int) db()->lastInsertId();
            $enrolled = enroll_student_for_professor($studentId, $professorId, $sectionId);
            $detail = $studentNumber . ' ' . $name;
            if ($professorName !== '') {
                $detail .= ' · professor ' . $professorName;
            }
            audit((int) $user['id'], 'student_create', 'student', (string) $studentId, $detail);
            $message = 'Student portal account created. Starting password is 123. Recovery email: ' . $email . '.';
            if ($professorName !== '') {
                $message .= ' Assigned professor: ' . $professorName . '.';
                if ($sectionId <= 0) {
                    $message .= ' Choose a section so they can be placed on that professor\'s grade sheet.';
                } elseif ($enrolled > 0) {
                    $message .= ' Placed on ' . $enrolled . ' class sheet' . ($enrolled === 1 ? '' : 's') . '.';
                } else {
                    $message .= ' That professor has no class sheet for this section yet.';
                }
            }
            flash('ok', $message);
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $duplicate = str_contains($e->getMessage(), 'Duplicate');
        if ($duplicate && $action === 'create_student') {
            flash('error', 'That student number already exists.');
        } elseif ($duplicate) {
            flash('error', 'That username already exists.');
        } else {
            flash('error', $e->getMessage());
        }
    }
    redirect('admin/users.php');
}

$programs = db()->query('SELECT id, code FROM programs ORDER BY code')->fetchAll();
$studentScope = scoped_program_ids($user);
$studentPrograms = programs_for_user($user, 'id, code');
$faculty = faculty_masterlist();
$studentSectionSql = 'SELECT sec.id, sec.name, sec.year_level, sec.program_id, p.code AS program_code
     FROM sections sec
     JOIN programs p ON p.id = sec.program_id';
if (is_array($studentScope)) {
    $studentSectionSql .= $studentScope === []
        ? ' WHERE 1 = 0'
        : ' WHERE sec.program_id IN (' . implode(',', array_map('intval', $studentScope)) . ')';
}
$studentSectionSql .= ' ORDER BY p.code, sec.year_level, sec.name';
$sectionChoices = db()->query($studentSectionSql)->fetchAll();
$accounts = db()->query(
    "SELECT u.id, u.username, u.full_name, u.is_active, r.name AS role_name, r.code AS role_code,
            p.id AS program_id, p.code AS program_code
     FROM users u
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     LEFT JOIN programs p ON p.id = ur.program_id
     WHERE r.code <> 'professor'
     " . (is_array($accountScope) ? ' AND ur.program_id IN (' . ($accountScope === [] ? '0' : implode(',', array_map('intval', $accountScope))) . ')' : '') . "
     ORDER BY u.full_name"
)->fetchAll();

layout_start('User Management', 'users', [['label' => 'Office accounts']]);
?>
<div class="page-head">
    <div>
        <h1>User Management</h1>
        <p class="lede">Manage office accounts and create student portal accounts. A new student starts with password 123.</p>
    </div>
</div>

<!-- New Office Account Form Card -->
<section class="card">
    <h2><?= icon('users') ?> New Office Account</h2>
    <p class="hint">Office accounts sign in through the College Admins portal.</p>
    
    <form method="post" class="form-grid" style="margin-top: 14px;" data-offline-save data-offline-label="New office account">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        
        <div>
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" placeholder="e.g. College Secretary" required>
        </div>
        <div>
            <label for="username">System Username</label>
            <input id="username" name="username" placeholder="e.g. secretary" required>
        </div>
        <div>
            <label for="role">Assigned Role</label>
            <select id="role" name="role">
                <?php foreach ($assignableRoles as $code => $label): ?>
                    <option value="<?= h($code) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="program_id">Program Scope (For Program Chairs)</label>
            <select id="program_id" name="program_id">
                <option value="0">Not a program chair</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['id'] ?>"><?= h($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wide">
            <label for="password">Temporary Password</label>
            <input id="password" name="password" type="text" minlength="8" placeholder="At least 8 characters" required>
        </div>
        <div class="wide" style="margin-top: 6px;">
            <button class="btn btn-gold" type="submit">Create Office Account</button>
        </div>
    </form>
</section>

    <!-- New Professor Form -->
<section class="card" style="margin-top: 18px;">
        <h2><?= icon('faculty') ?> New Professor Account</h2>
        <form method="post" style="margin-top: 12px;" data-offline-save data-offline-label="New professor">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_professor">
            
            <label for="professor_title">Title / Prefix</label>
            <input id="professor_title" name="title" value="Mr." required>
            
            <label for="professor_full_name">Full Name</label>
            <input id="professor_full_name" name="full_name" placeholder="e.g. Febrick Quiza" required>
            
            <label for="professor_username">System Username</label>
            <input id="professor_username" name="username" placeholder="e.g. fquiza" required>
            
            <label for="professor_password">Temporary Password</label>
            <input id="professor_password" name="password" type="text" minlength="8" placeholder="At least 8 characters" required>
            
            <div style="margin-top: 16px;">
                <button class="btn btn-gold" type="submit">Create Professor Account</button>
            </div>
        </form>
</section>

<section class="card" style="margin-top: 18px;">
    <h2 id="new-student-account"><?= icon('students') ?> New Student Portal Account</h2>
    <p class="hint">The student gives you their Gmail or school email. The starting password is 123. They change it after signing in.</p>
    <form method="post" class="form-grid" style="margin-top: 14px;" data-offline-save data-offline-label="New student account">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_student">
        <div>
            <label for="new_student_number">Student ID / Number</label>
            <input id="new_student_number" name="student_number" placeholder="e.g. 08-15-41258" required>
        </div>
        <div>
            <label for="new_full_name">Full Name</label>
            <input id="new_full_name" name="full_name" placeholder="Last Name, First Name M." required>
        </div>
        <div>
            <label for="new_email">Recovery email</label>
            <input id="new_email" name="email" type="email" placeholder="student@gmail.com or school email" required>
            <p class="hint">Used when the student forgets the password.</p>
        </div>
        <div>
            <label for="new_program">Program</label>
            <select id="new_program" name="program_id">
                <?php foreach ($studentPrograms as $item): ?>
                    <option value="<?= (int) $item['id'] ?>"><?= h($item['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="new_year">Year Level</label>
            <select id="new_year" name="year_level" required>
                <option value="" disabled selected>Select year level</option>
                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yearOption): ?>
                    <option value="<?= h($yearOption) ?>"><?= h($yearOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="new_type">Academic Standing Type</label>
            <select id="new_type" name="student_type">
                <option value="Regular">Regular</option>
                <option value="Irregular">Irregular</option>
            </select>
        </div>
        <div>
            <label for="new_section">Section</label>
            <select id="new_section" name="section_id">
                <option value="0">Unassigned / None</option>
                <?php foreach ($sectionChoices as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" data-program="<?= (int) $item['program_id'] ?>"><?= h($item['program_code'] . ' · ' . $item['year_level'] . ' · ' . $item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="new_professor">Assigned Professor</label>
            <select id="new_professor" name="professor_id">
                <option value="0">Unassigned / None</option>
                <?php foreach ($faculty as $person): ?>
                    <?php if ((int) $person['is_active'] !== 1) continue; ?>
                    <option value="<?= (int) $person['professor_id'] ?>"><?= h(professor_choice_label($person)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">The student is placed on this professor's grade sheets for the selected section.</p>
        </div>
        <div class="wide" style="margin-top: 6px;">
            <button class="btn btn-gold" type="submit">Create Student Account</button>
        </div>
    </form>
</section>

<!-- Office Accounts Table -->
<section class="card" style="margin-top: 18px;">
    <h2><?= icon('users') ?> Registered Office Accounts</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role Permission</th>
                    <th>Program Scope</th>
                    <th>Status & Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td>
                        <input form="account-<?= (int) $account['id'] ?>" name="full_name" value="<?= h($account['full_name']) ?>" aria-label="Full name" required style="min-width: 180px;">
                    </td>
                    <td class="mono"><strong><?= h($account['username']) ?></strong></td>
                    <td>
                        <select form="account-<?= (int) $account['id'] ?>" name="role" aria-label="Role" style="min-width: 160px;">
                            <?php foreach ($officeRoles as $code => $label): ?>
                                <option value="<?= h($code) ?>" <?= $account['role_code'] === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select form="account-<?= (int) $account['id'] ?>" name="program_id" aria-label="Program" style="min-width: 120px;">
                            <option value="0">Not assigned</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int) $program['id'] ?>" <?= (int) $account['program_id'] === (int) $program['id'] ? 'selected' : '' ?>><?= h($program['code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?= (int) $account['is_active'] === 1 ? badge('Active', 'PASSED') : badge('Disabled', 'FAILED') ?>
                            
                            <form id="account-<?= (int) $account['id'] ?>" method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-sm" type="submit">Save</button>
                            </form>
                            
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">Toggle</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function () {
    var program = document.getElementById('new_program');
    var section = document.getElementById('new_section');
    if (!program || !section) return;
    var options = Array.prototype.slice.call(section.options);
    function sync() {
        var pid = program.value;
        options.forEach(function (opt) {
            if (!opt.getAttribute('data-program')) return;
            var show = opt.getAttribute('data-program') === pid;
            opt.hidden = !show;
            opt.disabled = !show;
            if (!show && opt.selected) section.value = '0';
        });
    }
    program.addEventListener('change', sync);
    sync();
})();
</script>
<?php layout_end(); ?>
