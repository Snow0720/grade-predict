<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_charts.php';

db_or_setup();
$user = require_portal('admin');
$moduleController = module_controller('admin/dashboard.php', (string) ($_GET['view'] ?? ''));
if ($moduleController !== null) {
    require __DIR__ . '/' . $moduleController;
    return;
}

$scope = scoped_program_ids($user);
$isChair = in_array('program_chair', $user['roles'], true);
$compactDashboard = $isChair || in_array('osa', $user['roles'], true);
$programSql = '';

if (is_array($scope)) {
    if ($scope === []) {
        $programSql = ' AND 1 = 0';
    } else {
        $programSql = ' AND s.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}

if (is_array($scope)) {
    $students = $scope === []
        ? 0
        : (int) db()->query('SELECT COUNT(*) FROM students WHERE program_id IN (' . implode(',', array_map('intval', $scope)) . ')')->fetchColumn();
} else {
    $students = (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
}

$waiting = [
    'program_chair' => ['submitted', 'chair_verified', 'oic_approved', 'osa_endorsed'],
    'college_oic' => ['chair_verified'],
    'osa' => ['submitted', 'chair_verified', 'oic_approved', 'osa_endorsed'],
];

$myStatuses = [];
foreach ($waiting as $role => $statuses) {
    if (in_array($role, $user['roles'], true)) {
        foreach ($statuses as $status) {
            $myStatuses[] = $status;
        }
    }
}
$myStatuses = array_values(array_unique($myStatuses));

$queueCount = 0;
if ($myStatuses !== []) {
    $in = implode(',', array_fill(0, count($myStatuses), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM grade_submissions gs
         JOIN professor_assignments pa ON pa.id = gs.assignment_id
         JOIN subjects s ON s.id = pa.subject_id
         WHERE gs.status IN ($in) $programSql"
    );
    $stmt->execute($myStatuses);
    $queueCount = (int) $stmt->fetchColumn();
}

$finalizedSql = "SELECT COUNT(*) FROM grade_submissions gs
    JOIN professor_assignments pa ON pa.id = gs.assignment_id
    JOIN subjects s ON s.id = pa.subject_id
    WHERE gs.status = 'registrar_finalized' $programSql";
$finalizedStmt = db()->prepare($finalizedSql);
$finalizedStmt->execute();
$finalized = (int) $finalizedStmt->fetchColumn();

$currentSemester = (int) db()->query('SELECT id FROM semesters WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();

$adminStats = [];
$stageStats = [];
$recent = [];

$adminStats['students'] = $students;
$adminStats['professors'] = (int) db()->query('SELECT COUNT(*) FROM professors')->fetchColumn();
$adminStats['personnel'] = (int) db()->query(
    "SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur
     JOIN roles r ON r.id = ur.role_id
     WHERE r.code IN ('system_admin', 'program_chair', 'college_oic', 'osa')
       AND NOT EXISTS (SELECT 1 FROM professors pr WHERE pr.user_id = ur.user_id)"
)->fetchColumn();
foreach (['program_chair', 'college_oic', 'osa'] as $role) {
    $stmt = db()->prepare('SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = ?');
    $stmt->execute([$role]);
    $adminStats[$role] = (int) $stmt->fetchColumn();
}
// Chairs see only faculty and personnel assigned to their own programs.
if (is_array($scope)) {
    $scopeList = $scope === [] ? '0' : implode(',', array_map('intval', $scope));
    $adminStats['professors'] = (int) db()->query(
        "SELECT COUNT(DISTINCT pa.professor_id) FROM professor_assignments pa
         JOIN subjects s ON s.id = pa.subject_id WHERE s.program_id IN ($scopeList)"
    )->fetchColumn();
    $adminStats['personnel'] = (int) db()->query(
        "SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
         WHERE r.code = 'program_chair' AND ur.program_id IN ($scopeList)"
    )->fetchColumn();
}

if ($currentSemester > 0) {
    $recogWhere = 'semester_id = ?';
    $recogParams = [$currentSemester];
    if (is_array($scope)) {
        if ($scope === []) {
            $recogWhere .= ' AND 1 = 0';
        } else {
            $recogWhere .= ' AND program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
        }
    }
    $stmt = db()->prepare("SELECT stage, COUNT(*) AS total FROM recognition_cases WHERE $recogWhere GROUP BY stage");
    $stmt->execute($recogParams);
    foreach ($stmt as $row) {
        $stageStats[$row['stage']] = (int) $row['total'];
    }
    $stmt = db()->prepare("SELECT list_type, COUNT(*) AS total FROM recognition_cases WHERE $recogWhere GROUP BY list_type");
    $stmt->execute($recogParams);
    foreach ($stmt as $row) {
        $adminStats[strtolower($row['list_type'])] = (int) $row['total'];
    }

    // Also verify evaluations table so possible DL and PL are always visible in admin
    try {
        $evalWhere = 'e.semester_id = ?';
        $evalParams = [$currentSemester];
        if (is_array($scope)) {
            if ($scope === []) {
                $evalWhere .= ' AND 1 = 0';
            } else {
                $evalWhere .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
            }
        }
        $evalStmt = db()->prepare(
            "SELECT e.status, COUNT(DISTINCT e.student_id) AS total
             FROM dl_pl_evaluations e
             JOIN students st ON st.id = e.student_id
             WHERE $evalWhere
             GROUP BY e.status"
        );
        $evalStmt->execute($evalParams);
        $dlEvalCount = 0;
        $plEvalCount = 0;
        foreach ($evalStmt->fetchAll() as $erow) {
            $st = strtolower((string)$erow['status']);
            if (str_contains($st, 'pl') || str_contains($st, 'president')) {
                $plEvalCount += (int)$erow['total'];
            } elseif (str_contains($st, 'dl') || str_contains($st, 'dean')) {
                $dlEvalCount += (int)$erow['total'];
            }
        }
        if ($dlEvalCount > ($adminStats['dl'] ?? 0)) {
            $adminStats['dl'] = $dlEvalCount;
        }
        if ($plEvalCount > ($adminStats['pl'] ?? 0)) {
            $adminStats['pl'] = $plEvalCount;
        }
    } catch (Throwable $e) {}
}
$candidatePrograms = programs_for_user($user, 'id, code');
$candidateRows = [];
if ($currentSemester > 0) {
    $candidateSql = 'SELECT st.id AS student_id, st.program_id, e.status, rc.list_type
        FROM students st
        LEFT JOIN dl_pl_evaluations e ON e.student_id = st.id AND e.semester_id = ?
        LEFT JOIN recognition_cases rc ON rc.student_id = st.id AND rc.semester_id = ?
        WHERE (e.id IS NOT NULL OR rc.id IS NOT NULL)';
    if (is_array($scope)) {
        $candidateSql .= $scope === [] ? ' AND 1 = 0' : ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
    $candidateStmt = db()->prepare($candidateSql);
    $candidateStmt->execute([$currentSemester, $currentSemester]);
    $candidateRows = $candidateStmt->fetchAll();
}
$candidateGroups = dashboard_candidate_groups($candidatePrograms, $candidateRows);

if (!$compactDashboard && can($user, 'audit')) {
    $recent = db()->query(
        'SELECT a.action, a.entity, a.details, a.created_at, u.full_name
         FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.id DESC LIMIT 10'
    )->fetchAll();
}

$population = null;
if (can($user, 'analytics')) {
    require_once __DIR__ . '/../includes/dashboard_population.php';
    $trendProgram = (int) ($_GET['trend_program'] ?? 0);
    if ($trendProgram > 0 && is_array($scope) && !in_array($trendProgram, $scope, true)) $trendProgram = 0;
    $trendYear = (string) ($_GET['trend_year_level'] ?? '');
    if (!in_array($trendYear, ['1st Year', '2nd Year', '3rd Year', '4th Year'], true)) $trendYear = '';
    $trendSemester = max(0, (int) ($_GET['trend_semester'] ?? 0));
    $population = dashboard_population_data($scope, $trendProgram, $trendYear, $trendSemester);
    $trendSemesters = db()->query('SELECT sem.id, sem.name, ay.label FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id ORDER BY sem.id DESC')->fetchAll();
}

layout_start('Dashboard', 'dash', [['label' => 'Dashboard']]);
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
    </div>
</div>

<div class="dashboard-grid">
    <section class="card">
        <?php dashboard_donut('academic-community', 'Academic community', [
            'Faculty' => $adminStats['professors'],
            'Personnel' => $adminStats['personnel'],
            'Students' => $students,
        ], ['reference_style' => true, 'colors' => ['#38823C', '#B35224', '#285597']]); ?>
    </section>
    <section class="card">
        <?php dashboard_grouped_bars('honor-candidates', 'DL / PL candidates', $candidateGroups, can($user, 'evaluations')); ?>
    </section>
    <?php if (!$compactDashboard): ?>
    <?php if (!in_array('system_admin', $user['roles'], true)): ?>
    <section class="card">
        <?php dashboard_bars('office-personnel', 'Office personnel', [
            'Program Chairs' => $adminStats['program_chair'],
            'College OIC' => $adminStats['college_oic'], 'Secretary' => $adminStats['osa'],
        ]); ?>
    </section>
    <section class="card">
        <?php dashboard_bars('submission-progress', 'Grade submissions', [
            'Waiting for my office' => $queueCount, 'Finalized classes' => $finalized,
        ], can($user, 'queue') ? ['Waiting for my office' => url('admin/queue.php')] : []); ?>
    </section>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($population !== null): ?>
<section class="card" id="population-trends">
    <form class="filters" method="get" action="<?= h(url('admin/dashboard.php#population-trends')) ?>">
        <div><label for="trend-program">Program</label><select id="trend-program" name="trend_program"><option value="0">All in my scope</option><?php foreach ($candidatePrograms as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $trendProgram === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option><?php endforeach; ?></select></div>
        <div><label for="trend-year">Year level</label><select id="trend-year" name="trend_year_level"><option value="">All years</option><?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yearOption): ?><option value="<?= h($yearOption) ?>" <?= $trendYear === $yearOption ? 'selected' : '' ?>><?= h($yearOption) ?></option><?php endforeach; ?></select></div>
        <div><label for="trend-semester">Semester</label><select id="trend-semester" name="trend_semester"><option value="0">All semesters</option><?php foreach ($trendSemesters as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $trendSemester === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['label'] . ' ' . $item['name']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter trends</button>
    </form>
    <?php dashboard_population_chart($population); ?>
</section>
<?php endif; ?>

<?php if (!$compactDashboard && !in_array('system_admin', $user['roles'], true)): ?>
<section class="card">
    <div class="card-head">
        <h2><?= icon('queue') ?> Recognition Workflow</h2>
        <?php if (can($user, 'recognition')): ?><a class="btn btn-sm" href="<?= h(url('admin/recognition.php')) ?>">Open records</a><?php endif; ?>
    </div>
    <?php
    $recognitionValues = [];
    foreach ($stageStats as $stage => $count) {
        $recognitionValues[recognition_stage_label((string) $stage)] = (int) $count;
    }
    if ($recognitionValues === []) $recognitionValues = ['Chair Review' => 0, 'Endorsed' => 0, 'Finalized Records' => 0];
    dashboard_bars('recognition-stages', 'Records by current stage', $recognitionValues);
    ?>
</section>
<?php endif; ?>

<?php if (!$compactDashboard && can($user, 'audit')): ?>
<section class="card">
    <div class="card-head">
        <h2><?= icon('audit') ?> Recent System Activities</h2>
        <a class="btn btn-sm btn-ghost" href="<?= h(url('admin/audit.php')) ?>">View Full Audit Log</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>When</th><th>Personnel</th><th>Action</th><th>Record</th><th>Details</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $item): ?>
                <tr>
                    <td><?= fmt_when($item['created_at']) ?></td>
                    <td><?= h($item['full_name'] ?: 'System') ?></td>
                    <td><?= h($item['action']) ?></td>
                    <td><?= h($item['entity']) ?></td>
                    <td><?= h((string) $item['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php layout_end(); ?>
