<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
ensure_student_auth_columns();
$user = require_portal('admin');
require_permission($user, 'students_read');
ensure_student_adviser_column();

$scope = scoped_program_ids($user);
$programs = programs_for_user($user, 'id, code');
$faculty = faculty_masterlist();

$q = trim((string) ($_GET['q'] ?? ''));
$program = (int) ($_GET['program'] ?? 0);
$year = trim((string) ($_GET['year'] ?? ''));
$section = (int) ($_GET['section'] ?? 0);
$professor = (int) ($_GET['professor'] ?? 0);
$assignment = ($_GET['assignment'] ?? '') === 'assigned' ? 'assigned' : ((($_GET['assignment'] ?? '') === 'unassigned') ? 'unassigned' : '');
$type = ($_GET['type'] ?? '') === 'Irregular' ? 'Irregular' : ((($_GET['type'] ?? '') === 'Regular') ? 'Regular' : '');
$page = (int) ($_GET['page'] ?? 1);
if ($program > 0 && is_array($scope) && !in_array($program, $scope, true)) {
    $program = 0;
}

$where = 'WHERE 1 = 1';
$params = [];

if (is_array($scope)) {
    if ($scope === []) {
        $where .= ' AND 1 = 0';
    } else {
        $where .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}

if ($program > 0) {
    $where .= ' AND st.program_id = ?';
    $params[] = $program;
}
if ($year !== '') {
    $where .= ' AND st.year_level = ?';
    $params[] = $year;
}
if ($section > 0) {
    $where .= ' AND st.section_id = ?';
    $params[] = $section;
}
if ($assignment === 'assigned') {
    $where .= ' AND st.adviser_id IS NOT NULL';
} elseif ($assignment === 'unassigned') {
    $where .= ' AND st.adviser_id IS NULL';
}
if ($professor > 0 && $assignment !== 'unassigned') {
    $where .= ' AND st.adviser_id = ?';
    $params[] = $professor;
}
if ($type !== '') {
    $where .= ' AND st.student_type = ?';
    $params[] = $type;
}
if ($q !== '') {
    $where .= ' AND (st.student_number LIKE ? OR st.full_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$count = db()->prepare("SELECT COUNT(*) FROM students st $where");
$count->execute($params);
$totalMatching = (int) $count->fetchColumn();
$pager = paginate($totalMatching, $page);

$list = db()->prepare(
    "SELECT st.*, p.code AS program_code, sec.name AS section_name,
            au.full_name AS adviser_name, au.title AS adviser_title
     FROM students st
     JOIN programs p ON p.id = st.program_id
     LEFT JOIN sections sec ON sec.id = st.section_id
     LEFT JOIN professors adv ON adv.id = st.adviser_id
     LEFT JOIN users au ON au.id = adv.user_id
     $where
     ORDER BY st.full_name
     LIMIT {$pager['per']} OFFSET {$pager['offset']}"
);
$list->execute($params);
$rows = $list->fetchAll();

$studentIds = array_column($rows, 'id');
$studentStandings = [];
if ($studentIds !== []) {
    $inStudents = implode(',', array_map('intval', $studentIds));
    try {
        $stEvalStmt = db()->query(
            "SELECT student_id, basis, status FROM dl_pl_evaluations
             WHERE student_id IN ($inStudents) AND basis IN ('official', 'predicted')"
        );
        foreach ($stEvalStmt->fetchAll() as $er) {
            $sid = (int) $er['student_id'];
            $stText = (string) $er['status'];
            if (str_contains($stText, 'PL') || str_contains($stText, 'President')) {
                $studentStandings[$sid] = 'Possible PL';
            } elseif ($stText === 'Possible DL' || str_contains($stText, 'Dean')) {
                if (($studentStandings[$sid] ?? '') !== 'Possible PL') {
                    $studentStandings[$sid] = 'Possible DL';
                }
            }
        }
    } catch (Throwable $e) {}
}

// Overall Stats Counters (for Scope)
$statWhere = 'WHERE 1 = 1';
if (is_array($scope) && $scope !== []) {
    $statWhere .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
}
$totalAll = (int) db()->query("SELECT COUNT(*) FROM students st $statWhere")->fetchColumn();
$regularAll = (int) db()->query("SELECT COUNT(*) FROM students st $statWhere AND st.student_type = 'Regular'")->fetchColumn();
$irregularAll = (int) db()->query("SELECT COUNT(*) FROM students st $statWhere AND st.student_type = 'Irregular'")->fetchColumn();

$yearSql = 'SELECT DISTINCT st.year_level FROM students st WHERE st.year_level <> ""';
$sectionSql = 'SELECT sec.id, sec.name, sec.year_level, p.code AS program_code
    FROM sections sec JOIN programs p ON p.id = sec.program_id WHERE 1 = 1';

if (is_array($scope) && $scope !== []) {
    $scopeIn = implode(',', array_map('intval', $scope));
    $yearSql .= " AND st.program_id IN ($scopeIn)";
    $sectionSql .= " AND sec.program_id IN ($scopeIn)";
}
if ($program > 0) {
    $sectionSql .= ' AND sec.program_id = ' . $program;
}

$years = db()->query($yearSql . ' ORDER BY st.year_level')->fetchAll(PDO::FETCH_COLUMN);
$sections = db()->query($sectionSql . ' ORDER BY p.code, sec.year_level, sec.name')->fetchAll();

layout_start('Students Masterlist', 'students', [['label' => 'Students']]);
?>
<div class="page-head">
    <div>
        <h1>Students Masterlist</h1>
        <p class="lede">Student records already in the college. New student portal accounts are created in User management.</p>
    </div>
    <?php if (can($user, 'import')): ?>
        <a class="btn" href="<?= h(url('admin/import.php')) ?>"><?= icon('import') ?> Import Student Records</a>
    <?php endif; ?>
</div>

<!-- Summary Stats Header -->
<section class="kpis">
    <article class="kpi">
        <span>Total Masterlist Students</span>
        <strong><?= $totalAll ?></strong>
    </article>
    <article class="kpi">
        <span>Regular Students</span>
        <strong><?= $regularAll ?></strong>
    </article>
    <article class="kpi">
        <span>Irregular Students</span>
        <strong><?= $irregularAll ?></strong>
    </article>
    <article class="kpi">
        <span>Active Sections</span>
        <strong><?= count($sections) ?></strong>
    </article>
</section>

<!-- Search and Filtering Toolbar -->
<form class="filters" method="get" action="<?= h(url('admin/students.php')) ?>" data-live="students">
    <div>
        <label for="q">Search Record</label>
        <input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Name or student number" autocomplete="off">
    </div>
    <div>
        <label for="program">Program</label>
        <select id="program" name="program">
            <option value="0">All Programs</option>
            <?php foreach ($programs as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="year">Year Level</label>
        <select id="year" name="year">
            <option value="">All Years</option>
            <?php foreach ($years as $item): ?>
                <option value="<?= h((string) $item) ?>" <?= $year === (string) $item ? 'selected' : '' ?>><?= h((string) $item) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="section">Section</label>
        <select id="section" name="section">
            <option value="0">All Sections</option>
            <?php foreach ($sections as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $section === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['program_code'] . ' · ' . $item['year_level'] . ' · ' . $item['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="type">Academic Type</label>
        <select id="type" name="type">
            <option value="">All Types</option>
            <option value="Regular" <?= $type === 'Regular' ? 'selected' : '' ?>>Regular</option>
            <option value="Irregular" <?= $type === 'Irregular' ? 'selected' : '' ?>>Irregular</option>
        </select>
    </div>
    <div>
        <label for="professor">Assigned Professor</label>
        <select id="professor" name="professor">
            <option value="0">All Professors</option>
            <?php foreach ($faculty as $person): ?>
                <option value="<?= (int) $person['professor_id'] ?>" <?= $professor === (int) $person['professor_id'] ? 'selected' : '' ?>><?= h(trim($person['title'] . ' ' . $person['full_name'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="assignment">Professor Assignment</label>
        <select id="assignment" name="assignment">
            <option value="">All Students</option>
            <option value="assigned" <?= $assignment === 'assigned' ? 'selected' : '' ?>>Has assigned professor</option>
            <option value="unassigned" <?= $assignment === 'unassigned' ? 'selected' : '' ?>>No assigned professor</option>
        </select>
    </div>
    <div style="display:flex; align-items:flex-end;">
        <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter</button>
    </div>
</form>

<section class="card" id="student-results" aria-live="polite">
    <?php if ($rows === []): ?>
        <p class="empty">No student records match your active search filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Full Name</th>
                        <th>Recovery email</th>
                        <th>Program</th>
                        <th>Year</th>
                        <th>Section</th>
                        <th>Assigned Professor</th>
                        <th>Type</th>
                        <th>Possible DL / PL</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono"><strong><?= h($row['student_number']) ?></strong></td>
                        <td><strong><?= h($row['full_name']) ?></strong></td>
                        <td><?= h((string) ($row['email'] ?? '')) !== '' ? h((string) $row['email']) : '—' ?></td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h($row['year_level']) ?></td>
                        <td><?= h($row['section_name'] ?? '—') ?></td>
                        <td><?= !empty($row['adviser_name']) ? h(trim((string) $row['adviser_title'] . ' ' . $row['adviser_name'])) : '—' ?></td>
                        <td><?= badge($row['student_type'], $row['student_type']) ?></td>
                        <td>
                            <?php $std = $studentStandings[(int)$row['id']] ?? null; ?>
                            <?php if ($std === 'Possible PL'): ?>
                                <span class="badge badge-gold">Possible PL</span>
                            <?php elseif ($std === 'Possible DL'): ?>
                                <span class="badge badge-info">Possible DL</span>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <a class="btn btn-ghost btn-sm" href="<?= h(url('admin/student.php', ['id' => $row['id']])) ?>">
                                <?= icon('record') ?> View Info
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pager_html($pager, 'admin/students.php', ['q' => $q, 'program' => $program, 'year' => $year, 'section' => $section, 'professor' => $professor, 'assignment' => $assignment, 'type' => $type]) ?>
    <?php endif; ?>
</section>
<?php layout_end(); ?>