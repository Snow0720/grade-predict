<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_charts.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'queue');

$scope = $_GET['scope'] ?? 'all';
$program = (int) ($_GET['program'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $subId = (int) ($_POST['submission_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? 'approve');
    if ($subId > 0 && in_array($action, ['approve', 'return'], true)) {
        try {
            $reviewerRole = in_array('osa', $user['roles'], true) ? 'Secretary' : role_label($user);
            review_submission($user, $subId, $action, "Approved by {$reviewerRole} from review queue.");
            flash('ok', 'Approved by Chair. Qualified students with complete semester grades and their certificates are available in Recognition.');
            if ($action === 'approve') {
                $approvedTerm = db()->prepare('SELECT pa.semester_id FROM grade_submissions gs JOIN professor_assignments pa ON pa.id = gs.assignment_id WHERE gs.id = ?');
                $approvedTerm->execute([$subId]);
                redirect('admin/recognition.php', ['semester' => (int) $approvedTerm->fetchColumn(), 'stage' => '']);
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('admin/queue.php', ['scope' => $scope, 'program' => $program, 'q' => $search]);
}

$waiting = [];
if ($scope === 'all' || in_array('system_admin', $user['roles'], true)) {
    $waiting = ['submitted', 'returned', 'chair_verified', 'oic_approved', 'osa_endorsed', 'registrar_finalized', 'rejected'];
} else {
    foreach ([
        'program_chair' => ['submitted', 'chair_verified', 'oic_approved', 'osa_endorsed'],
        'college_oic' => 'chair_verified',
        'osa' => ['submitted', 'chair_verified', 'oic_approved', 'osa_endorsed'],
    ] as $role => $status) {
        if (!in_array($role, $user['roles'], true)) {
            continue;
        }
        if (is_array($status)) {
            foreach ($status as $item) {
                $waiting[] = $item;
            }
        } else {
            $waiting[] = $status;
        }
    }
    $waiting = array_values(array_unique($waiting));
}

$programs = programs_for_user($user, 'id, code');
$scopeIds = scoped_program_ids($user);
if ($program > 0 && is_array($scopeIds) && !in_array($program, $scopeIds, true)) {
    $program = 0;
}

$sql = 'SELECT gs.id, gs.status, gs.submitted_at, gs.assignment_id, pa.semester_id, s.code AS subject_code, s.name AS subject_name,
               sec.name AS section_name, p.code AS program_code, p.id AS program_id,
               u.full_name, u.title, COUNT(e.id) AS student_count
        FROM grade_submissions gs
        JOIN professor_assignments pa ON pa.id = gs.assignment_id
        JOIN subjects s ON s.id = pa.subject_id
        JOIN programs p ON p.id = s.program_id
        JOIN sections sec ON sec.id = pa.section_id
        JOIN professors pr ON pr.id = pa.professor_id
        JOIN users u ON u.id = pr.user_id
        LEFT JOIN enrollments e ON e.assignment_id = pa.id
        WHERE 1 = 1';
$params = [];

if ($waiting === []) {
    $sql .= ' AND 1 = 0';
} else {
    $sql .= ' AND gs.status IN (' . implode(',', array_fill(0, count($waiting), '?')) . ')';
    $params = $waiting;
}

if (is_array($scopeIds)) {
    if ($scopeIds === []) {
        $sql .= ' AND 1 = 0';
    } else {
        $sql .= ' AND s.program_id IN (' . implode(',', array_map('intval', $scopeIds)) . ')';
    }
}

if ($program > 0) {
    $sql .= ' AND s.program_id = ?';
    $params[] = $program;
}

if ($search !== '') {
    $sql .= ' AND (s.code LIKE ? OR s.name LIKE ? OR sec.name LIKE ? OR u.full_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql .= ' GROUP BY gs.id, gs.status, gs.submitted_at, gs.assignment_id, pa.semester_id, s.code, s.name, sec.name, p.code, p.id, u.full_name, u.title
          ORDER BY gs.submitted_at IS NULL, gs.submitted_at DESC, gs.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Calculate Possible DL and PL candidates per assignment in queue
$assignmentIds = array_values(array_unique(array_filter(array_column($rows, 'assignment_id'))));
$classDlPl = [];
$totalPlQueue = 0;
$totalDlQueue = 0;

if ($assignmentIds !== []) {
    $inAssign = implode(',', array_map('intval', $assignmentIds));
    try {
        $stmtDlpl = db()->query(
            "SELECT pa.id AS assignment_id,
                    COUNT(DISTINCT CASE WHEN ev.status IN ('Possible PL', 'President\'s List', 'PL') THEN e.student_id END) AS pl_count,
                    COUNT(DISTINCT CASE WHEN ev.status IN ('Possible DL', 'Dean\'s List', 'DL') THEN e.student_id END) AS dl_count
             FROM professor_assignments pa
             JOIN enrollments e ON e.assignment_id = pa.id
             JOIN dl_pl_evaluations ev ON ev.student_id = e.student_id AND ev.semester_id = pa.semester_id
             WHERE pa.id IN ($inAssign)
             GROUP BY pa.id"
        );
        foreach ($stmtDlpl->fetchAll() as $statRow) {
            $aid = (int) $statRow['assignment_id'];
            $classDlPl[$aid] = [
                'pl' => (int) $statRow['pl_count'],
                'dl' => (int) $statRow['dl_count'],
            ];
        }

        $stmtOg = db()->query(
            "SELECT pa.id AS assignment_id,
                    COUNT(DISTINCT CASE WHEN og.final_eqv IS NOT NULL AND og.final_eqv <= 1.25 THEN e.student_id END) AS pl_count,
                    COUNT(DISTINCT CASE WHEN og.final_eqv IS NOT NULL AND og.final_eqv > 1.25 AND og.final_eqv <= 1.75 THEN e.student_id END) AS dl_count
             FROM professor_assignments pa
             JOIN enrollments e ON e.assignment_id = pa.id
             JOIN official_grades og ON og.enrollment_id = e.id
             WHERE pa.id IN ($inAssign)
             GROUP BY pa.id"
        );
        foreach ($stmtOg->fetchAll() as $ogRow) {
            $aid = (int) $ogRow['assignment_id'];
            $classDlPl[$aid]['pl'] = max($classDlPl[$aid]['pl'] ?? 0, (int) $ogRow['pl_count']);
            $classDlPl[$aid]['dl'] = max($classDlPl[$aid]['dl'] ?? 0, (int) $ogRow['dl_count']);
        }
    } catch (Throwable $e) {
        // Fallback gracefully if database table not ready
    }

    foreach ($classDlPl as $counts) {
        $totalPlQueue += $counts['pl'];
        $totalDlQueue += $counts['dl'];
    }
}

$reviewStatusCounts = chair_review_status_counts($rows);

layout_start('Review queue', 'queue', [['label' => 'Review queue']]);
?>
<div class="page-head">
    <div>
        <h1>Review queue</h1>
        <p class="lede">Professors submit grades directly to their assigned program chair for review and final approval.</p>
    </div>
    <a class="btn btn-ghost" href="<?= h(url('admin/queue.php', ['scope' => $scope === 'all' ? 'mine' : 'all'])) ?>"><?= $scope === 'all' ? 'Waiting for me' : 'Show all statuses' ?></a>
</div>

<section class="dashboard-grid recognition-charts queue-charts" aria-label="Queue overview and chair review status">
    <article class="card recognition-summary-chart">
        <?php dashboard_horizontal_bars('queue-overview', 'Queue Overview', [
            ['label' => 'Awaiting Chair Review', 'count' => $reviewStatusCounts['Awaiting Chair Review']],
            ['label' => 'Potential PL', 'count' => $totalPlQueue],
            ['label' => 'Potential DL', 'count' => $totalDlQueue],
        ], ['count' => 'Count'], 'Queue metric'); ?>
        <p class="hint">Potential PL and DL counts reflect student entries across the displayed class submissions.</p>
    </article>
    <article class="card recognition-workflow-chart">
        <?php dashboard_donut('chair-review-status', 'Chair Review Status', $reviewStatusCounts, [
            'colors' => ['#38823C', '#285597', '#D9A536', '#B35224'],
        ]); ?>
    </article>
</section>
<p class="hint">Showing <?= $scope === 'all' ? 'all review statuses' : 'submissions awaiting your review' ?> within your program scope.</p>

<form class="filters" method="get">
        <input type="hidden" name="view" value="review">
    <input type="hidden" name="scope" value="<?= h((string) $scope) ?>">
    <div>
        <label for="program">Program</label>
        <select id="program" name="program">
            <option value="0">All in my scope</option>
            <?php foreach ($programs as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= h($search) ?>" placeholder="Subject, section, or professor">
    </div>
    <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter Queue</button>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="empty">No submissions are currently recorded in your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Class / Subject</th><th>Program</th><th>Professor</th><th>Students</th><th>Possible DL / PL</th><th>Status</th><th>Submitted</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $aid = (int) ($row['assignment_id'] ?? 0);
                    $pl = $classDlPl[$aid]['pl'] ?? 0;
                    $dl = $classDlPl[$aid]['dl'] ?? 0;
                    $asgnMeta = ['id' => $aid, 'program_id' => $row['program_id']];
                    $canApproveThis = user_can_review($user, $asgnMeta, (string) $row['status']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($row['subject_code']) ?></strong> · <?= h($row['section_name']) ?>
                            <br><small class="hint"><?= h($row['subject_name']) ?></small>
                        </td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h(trim(($row['title'] ?? '') . ' ' . $row['full_name'])) ?></td>
                        <td><?= (int) $row['student_count'] ?></td>
                        <td>
                            <?php if ($pl > 0 || $dl > 0): ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <?php if ($pl > 0): ?>
                                        <span class="badge badge-gold" title="<?= $pl ?> Possible President's List student(s)"><?= $pl ?> PL</span>
                                    <?php endif; ?>
                                    <?php if ($dl > 0): ?>
                                        <span class="badge badge-info" title="<?= $dl ?> Possible Dean's List student(s)"><?= $dl ?> DL</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= badge(submission_label($row['status']), $row['status']) ?></td>
                        <td><?= fmt_when($row['submitted_at']) ?></td>
                        <td>
                            <div style="display:inline-flex; align-items:center; gap:6px;">
                                <a class="btn btn-sm btn-ghost" href="<?= h(url('admin/review.php', ['id' => $row['id']])) ?>">
                                    <?= icon('open') ?> Review
                                </a>
                                <?php if ($canApproveThis): ?>
                                    <form method="post" action="<?= h(url('admin/queue.php', ['scope' => $scope, 'program' => $program, 'q' => $search])) ?>" style="display:inline;" onsubmit="return confirm('Approve submission for <?= h($row['subject_code'] . ' · ' . $row['section_name']) ?>?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="submission_id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="btn btn-sm btn-gold" type="submit" title="Quick Approve">
                                            <?= icon('check') ?> Approve
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
