<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
$scope = scoped_program_ids($user);
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
    'program_chair' => ['submitted'],
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
foreach (['program_chair', 'college_oic', 'osa'] as $role) {
    $stmt = db()->prepare('SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = ?');
    $stmt->execute([$role]);
    $adminStats[$role] = (int) $stmt->fetchColumn();
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
$recent = db()->query(
    'SELECT a.action, a.entity, a.details, a.created_at, u.full_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT 10'
)->fetchAll();

layout_start('College offices', 'dash', [['label' => 'Dashboard']]);
?>
<div class="page-head">
    <div>
        <h1>College offices</h1>
        <p class="lede">Manage records and monitor every workflow step. Waiting for your office: <?= $queueCount ?>. Finalized classes: <?= $finalized ?>. Students in scope: <?= $students ?>.</p>
    </div>
</div>

<section class="card">
    <h2><?= icon('reports') ?> Academic & Personnel Overview</h2>
    <div class="kpis">
        <article class="kpi"><span>Total Students</span><strong><?= $adminStats['students'] ?></strong></article>
        <article class="kpi"><span>Faculty / Professors</span><strong><?= $adminStats['professors'] ?></strong></article>
        <article class="kpi"><span>DL Candidates</span><strong><a href="<?= h(url('admin/evaluations.php', ['standing' => 'dl'])) ?>" style="color:#0284c7;text-decoration:none;" title="View Dean's List Candidates"><?= $adminStats['dl'] ?? 0 ?></a></strong></article>
        <article class="kpi"><span>PL Candidates</span><strong><a href="<?= h(url('admin/evaluations.php', ['standing' => 'pl'])) ?>" style="color:#b45309;text-decoration:none;" title="View President's List Candidates"><?= $adminStats['pl'] ?? 0 ?></a></strong></article>
    </div>
    <div class="kpis" style="margin-top: 12px;">
        <article class="kpi"><span>Program Chairs</span><strong><?= $adminStats['program_chair'] ?></strong></article>
        <article class="kpi"><span>College OIC</span><strong><?= $adminStats['college_oic'] ?></strong></article>
        <article class="kpi"><span>Secretary</span><strong><?= $adminStats['osa'] ?></strong></article>
        <article class="kpi"><span>Sheets waiting for me</span><strong><?= $queueCount ?></strong></article>
    </div>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= icon('queue') ?> Recognition Workflow</h2>
        <a class="btn btn-sm" href="<?= h(url('admin/recognition.php')) ?>">Open all records</a>
    </div>
    <div class="kpis">
        <article class="kpi"><span>Chair Review</span><strong><?= $stageStats['chair_review'] ?? 0 ?></strong></article>
        <article class="kpi"><span>Endorsed</span><strong><?= $stageStats['chair_endorsed'] ?? 0 ?></strong></article>
        <article class="kpi"><span>Finalized Records</span><strong><?= ($stageStats['chair_endorsed'] ?? 0) + ($stageStats['registrar_verified'] ?? 0) + ($stageStats['final'] ?? 0) ?></strong></article>
    </div>
</section>

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
<?php layout_end(); ?>
