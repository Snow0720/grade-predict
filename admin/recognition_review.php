<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'recognition');
$actor = recognition_actor($user);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$case = recognition_case($id, $user);

if (!$case) {
    http_response_code(404);
    exit('This recognition record is not available to your role.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($action === 'remark') {
            recognition_add_note($user, $id, $note);
        } elseif ($action === 'chair_endorse') {
            // Program Chair Endorsement directly approves and finalizes candidate
            recognition_endorse($user, (int) $case['semester_id'], [(int) $case['student_id']], $note);
        } else {
            recognition_act($user, (int) $case['semester_id'], [$id], $action, $note);
        }
        flash('ok', 'The recognition decision was recorded successfully.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/recognition_review.php', ['id' => $id]);
}

$grades = recognition_subject_grades((int) $case['student_id'], (int) $case['semester_id']);
$previous = db()->prepare(
    'SELECT ay.label, sem.name, e.gwa, e.status, e.reason
     FROM dl_pl_evaluations e
     JOIN semesters sem ON sem.id = e.semester_id
     JOIN academic_years ay ON ay.id = sem.academic_year_id
     WHERE e.student_id = ? AND e.basis = "official" AND e.semester_id <> ?
     ORDER BY ay.label DESC, sem.id DESC'
);
$previous->execute([(int) $case['student_id'], (int) $case['semester_id']]);
$previous = $previous->fetchAll();

$gradeSubmit = db()->prepare(
    'SELECT sr.created_at, sr.remarks AS note, u.full_name, u.title
     FROM enrollments e
     JOIN professor_assignments pa ON pa.id = e.assignment_id
     JOIN grade_submissions gs ON gs.assignment_id = pa.id
     JOIN submission_reviews sr ON sr.submission_id = gs.id AND sr.action = "submit"
     LEFT JOIN users u ON u.id = sr.reviewer_id
     WHERE e.student_id = ? AND pa.semester_id = ?
     ORDER BY sr.created_at DESC LIMIT 1'
);
$gradeSubmit->execute([(int) $case['student_id'], (int) $case['semester_id']]);
$gradeSubmit = $gradeSubmit->fetch();

$history = recognition_history([$id])[$id] ?? [];
$eventsByAction = [];
foreach ($history as $event) {
    $eventsByAction[$event['action']][] = $event;
}
if ($gradeSubmit) {
    $gradeSubmit['action'] = 'grade_submit';
    $eventsByAction['grade_submit'][] = $gradeSubmit;
}

$steps = [
    ['Grades Submitted', ['grade_submit'], 'Official subject grades are available.'],
    ['System Qualification Check', ['system_check'], $case['standing']],
    ['Awaiting Chair Review', ['endorse', 'chair_grade_approval', 'chair_return', 'chair_reject'], 'Program chair review'],
    ['Approved by Chair', ['endorse', 'chair_grade_approval'], 'Certificate eligibility confirmed'],
    ['Final DL / PL Record', ['endorse', 'chair_grade_approval'], 'Official masterlist and certificate record'],
];

$actionSets = [
    'program_chair' => [
        'chair_review' => [
            ['chair_endorse', 'Endorse & Approve Final Record', 'btn-gold'],
            ['chair_return', 'Return', 'btn-ghost'],
            ['chair_reject', 'Reject', 'btn-danger'],
        ],
        'chair_returned' => [
            ['chair_endorse', 'Endorse & Approve Final Record', 'btn-gold'],
            ['chair_reject', 'Reject', 'btn-danger'],
        ],
        'oic_returned' => [
            ['chair_endorse', 'Endorse & Approve Final Record', 'btn-gold'],
            ['chair_reject', 'Reject', 'btn-danger'],
        ],
    ],
    'college_oic' => [],
    'osa' => [],
];

$actions = $actionSets[$actor][$case['stage']] ?? [];

layout_start((string) $case['full_name'], 'recognition', [
    ['label' => 'Recognition', 'href' => 'admin/recognition.php'],
    ['label' => $case['student_number']],
]);
?>
<div class="page-head">
    <div>
        <h1><?= h($case['full_name']) ?></h1>
        <p class="lede"><?= h($case['student_number']) ?> · <?= h($case['program_code']) ?> · <?= h((string) $case['year_level']) ?> · <?= h($case['year_label'] . ' ' . $case['semester_name']) ?></p>
    </div>
    <div><?= badge(recognition_stage_label((string) $case['stage']), (string) $case['stage']) ?></div>
</div>

<section class="kpis">
    <article class="kpi"><span>Classification</span><strong style="font-size:18px;"><?= h(recognition_list_label((string) $case['list_type'])) ?></strong></article>
    <article class="kpi"><span>GWA</span><strong><?= fmt_eqv((float) $case['gwa']) ?></strong></article>
    <article class="kpi"><span>System Result</span><strong style="font-size:18px;"><?= h($case['standing']) ?></strong></article>
    <article class="kpi"><span>Student Type</span><strong style="font-size:18px;"><?= h($case['student_type']) ?></strong></article>
</section>

<section class="card">
    <h2><?= icon('final') ?> Recommendations and Approvals Status</h2>
    <div class="kpis">
        <article class="kpi"><span>Program Chair</span><strong style="font-size:16px;"><?= h($case['chair_name'] ?: 'Awaiting Chair Review') ?></strong></article>
    </div>
    <?php if ($case['last_note']): ?>
        <p style="margin-top:12px;"><strong>Latest Remarks:</strong> <?= h($case['last_note']) ?></p>
    <?php endif; ?>
</section>

<?php if ($case['required_action']): ?>
    <div class="alert alert-warn"><strong>Required Action:</strong> <?= h($case['required_action']) ?></div>
<?php endif; ?>

<section class="card">
    <h2><?= icon('sheet') ?> Individual Subject Grades</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Subject Code & Name</th><th>Professor</th><th>Percent</th><th>Equivalent</th><th>Remarks</th></tr>
            </thead>
            <tbody>
            <?php foreach ($grades as $grade): ?>
                <tr>
                    <td><strong><?= h($grade['code']) ?></strong><br><span class="hint"><?= h($grade['name']) ?></span></td>
                    <td><?= h($grade['professor_name']) ?></td>
                    <td><?= fmt_pct($grade['final_percent'] === null ? null : (float) $grade['final_percent']) ?></td>
                    <td><strong><?= fmt_eqv($grade['final_eqv'] === null ? null : (float) $grade['final_eqv']) ?></strong></td>
                    <td><?= $grade['remarks'] ? badge($grade['remarks'], $grade['remarks']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($previous !== []): ?>
<section class="card">
    <h2><?= icon('reports') ?> Previous Academic Records</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Academic Year</th><th>Semester</th><th>GWA</th><th>Standing</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($previous as $record): ?>
                <tr>
                    <td><?= h($record['label']) ?></td>
                    <td><?= h($record['name']) ?></td>
                    <td><strong><?= fmt_eqv($record['gwa'] === null ? null : (float) $record['gwa']) ?></strong></td>
                    <td><?= badge($record['status'], $record['status']) ?></td>
                    <td><?= h((string) $record['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($actions !== []): ?>
<section class="card">
    <h2><?= icon('queue') ?> Your Official Action (Program Chair)</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="note">Review Note / Remarks</label>
        <textarea id="note" name="note" rows="3" placeholder="Required when returning, rejecting, or deferring records"></textarea>
        <div class="dialog-actions">
            <button class="btn btn-ghost" name="action" value="remark" type="submit">Add Remark Only</button>
            <?php foreach ($actions as [$value, $label, $class]): ?>
                <button class="btn <?= h($class) ?>" name="action" value="<?= h($value) ?>" type="submit"><?= h($label) ?></button>
            <?php endforeach; ?>
        </div>
    </form>
</section>
<?php else: ?>
    <div class="alert alert-info">
        <strong>Workflow Information:</strong> Actions for stage <u><?= h(recognition_stage_label((string)$case['stage'])) ?></u> belong to the assigned office personnel.
    </div>
<?php endif; ?>

<section class="card">
    <h2><?= icon('audit') ?> Student Approval Timeline</h2>
    <ol class="approval-timeline">
        <?php foreach ($steps as [$label, $eventNames, $description]): ?>
            <?php
            $matched = null;
            foreach ($eventNames as $eventName) {
                if (!empty($eventsByAction[$eventName])) {
                    $matched = end($eventsByAction[$eventName]);
                }
            }
            ?>
            <li class="<?= $matched ? 'is-complete' : '' ?>">
                <strong><?= h($label) ?></strong>
                <?php if ($matched): ?>
                    <div><?= h(!empty($matched['full_name']) ? trim((string) ($matched['title'] ?? '') . ' ' . (string) $matched['full_name']) : 'System') ?> · <?= h(ucwords(str_replace('_', ' ', (string) $matched['action']))) ?></div>
                    <small><?= h(recognition_when((string) $matched['created_at'])) ?></small>
                    <?php if (!empty($matched['note'])): ?><p><?= h($matched['note']) ?></p><?php endif; ?>
                <?php else: ?>
                    <span class="hint"><?= h($description) ?> · Pending</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="card">
    <h2><?= icon('reports') ?> Complete Audit History</h2>
    <?php if ($history === []): ?>
        <p class="empty">No workflow actions recorded yet.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $event): ?>
                <li>
                    <strong><?= h(ucwords(str_replace('_', ' ', $event['action']))) ?></strong>
                    by <?= h(!empty($event['full_name']) ? trim((string) ($event['title'] ?? '') . ' ' . (string) $event['full_name']) : 'System') ?>
                    · <small><?= h(recognition_stage_label((string) $event['to_stage'])) ?></small>
                    <?php if (!empty($event['note'])): ?><div><?= h($event['note']) ?></div><?php endif; ?>
                    <small class="hint"><?= h(recognition_when((string) $event['created_at'])) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php layout_end(); ?>