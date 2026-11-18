<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'queue');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$submission = db()->prepare('SELECT * FROM grade_submissions WHERE id = ?');
$submission->execute([$id]);
$submission = $submission->fetch();

if (!$submission) {
    http_response_code(404);
    exit('Submission not found.');
}

$assignment = fetch_assignment((int) $submission['assignment_id']);
$scope = scoped_program_ids($user);

if (!$assignment || (is_array($scope) && !in_array((int) $assignment['program_id'], $scope, true))) {
    http_response_code(403);
    exit('This submission is outside your program.');
}

$canViewCurrent = user_can_review($user, $assignment, (string) $submission['status']);
if (!$canViewCurrent && !in_array('system_admin', $user['roles'], true)) {
    $handled = db()->prepare('SELECT 1 FROM submission_reviews WHERE submission_id = ? AND reviewer_id = ? LIMIT 1');
    $handled->execute([$id, (int) $user['id']]);
    if (!$handled->fetchColumn()) {
        // College offices share the admin view, so any office account may open the sheet.
        if (!array_intersect($user['roles'], office_roles())) {
            http_response_code(403);
            exit('This submission is not assigned to your office.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = (string) ($_POST['action'] ?? $_POST['review_action'] ?? '');
        if ($action === 'save_percents') {
            $rows = [];
            foreach ((array) ($_POST['pct'] ?? []) as $enrollmentId => $values) {
                $rows[] = [
                    'enrollment_id' => (int) $enrollmentId,
                    'prelim' => $values['prelim'] ?? '',
                    'midterm' => $values['midterm'] ?? '',
                    'finals' => $values['finals'] ?? '',
                    'final' => $values['final'] ?? '',
                ];
            }
            $count = save_percent_overrides($user, (int) $submission['assignment_id'], $rows);
            flash('ok', $count > 0
                ? "Saved office grade percents for {$count} student(s). Equivalents updated from the scale."
                : 'Grade percents were saved.');
        } else {
            review_submission($user, $id, (string) ($_POST['review_action'] ?? ''), (string) ($_POST['remarks'] ?? ''));
            flash('ok', 'Review action recorded successfully.');
            if (($_POST['review_action'] ?? '') === 'approve') {
                flash('ok', 'Class grades have been finalized. Qualified students are now available for Recognition review.');
                redirect('admin/recognition.php', ['semester' => (int) $assignment['semester_id'], 'stage' => '']);
            }
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/review.php', ['id' => $id]);
}

$book = build_gradebook((int) $assignment['id']);
$history = db()->prepare(
    'SELECT r.*, u.full_name, u.title FROM submission_reviews r LEFT JOIN users u ON u.id = r.reviewer_id WHERE r.submission_id = ? ORDER BY r.id ASC'
);
$history->execute([$id]);
$history = $history->fetchAll();

$canAct = user_can_review($user, $assignment, $submission['status']);
$canEditPct = array_intersect($user['roles'], office_roles())
    && !assignment_is_finalized($assignment);

// Calculate quick stats and Possible DL / PL
$totalStudents = count($book['students']);
$passedCount = 0;
$rules = load_rules((int) ($assignment['semester_id'] ?? 0));

$evalMap = [];
if (!empty($assignment['semester_id'])) {
    try {
        $evalStmt = db()->prepare('SELECT student_id, basis, status, gwa FROM dl_pl_evaluations WHERE semester_id = ?');
        $evalStmt->execute([(int) $assignment['semester_id']]);
        foreach ($evalStmt->fetchAll() as $ev) {
            $evalMap[(int) $ev['student_id']][$ev['basis']] = $ev;
        }
    } catch (Throwable $e) {}
}

$plCandidatesCount = 0;
$dlCandidatesCount = 0;
$studentStandings = [];

foreach ($book['students'] as $st) {
    if (strtoupper((string)($st['remarks'] ?? '')) === 'PASSED') {
        $passedCount++;
    }
    $sid = (int) ($st['student_id'] ?? 0);
    $standing = null;
    if (isset($evalMap[$sid])) {
        $off = (string) ($evalMap[$sid]['official']['status'] ?? '');
        $pred = (string) ($evalMap[$sid]['predicted']['status'] ?? '');
        if (in_array($off, ['Possible PL', 'President\'s List', 'PL'], true) || in_array($pred, ['Possible PL', 'President\'s List', 'PL'], true)) {
            $standing = 'Possible PL';
        } elseif (in_array($off, ['Possible DL', 'Dean\'s List', 'DL'], true) || in_array($pred, ['Possible DL', 'Dean\'s List', 'DL'], true)) {
            $standing = 'Possible DL';
        }
    }
    if ($standing === null) {
        $eqv = $st['final_equivalent'] !== null ? (float) $st['final_equivalent'] : ($st['predicted_equivalent'] !== null ? (float) $st['predicted_equivalent'] : null);
        if ($eqv !== null && $eqv > 0) {
            if ($eqv <= (float) $rules['pl_max']) {
                $standing = 'Possible PL';
            } elseif ($eqv <= (float) $rules['dl_max']) {
                $standing = 'Possible DL';
            }
        }
    }
    $studentStandings[$st['enrollment_id']] = $standing;
    if ($standing === 'Possible PL') {
        $plCandidatesCount++;
    } elseif ($standing === 'Possible DL') {
        $dlCandidatesCount++;
    }
}
$passRate = $totalStudents > 0 ? round(($passedCount / $totalStudents) * 100, 1) : 0;

layout_start($assignment['subject_code'] . ' · ' . $assignment['section_name'], 'queue', [
    ['label' => 'Review queue', 'href' => 'admin/queue.php'],
    ['label' => $assignment['subject_code'] . ' ' . $assignment['section_name']],
]);
?>
<div class="page-head">
    <div>
        <h1><?= h($assignment['subject_code']) ?> · <?= h($assignment['section_name']) ?></h1>
        <p class="lede"><?= h($assignment['subject_name']) ?> · <?= h(trim(($assignment['professor_title'] ?? '') . ' ' . $assignment['professor_name'])) ?><?php $sentPeriods = submitted_period_keys($assignment); if ($sentPeriods !== []): ?> · Submitted: <?= h(period_list_label($sentPeriods)) ?><?php endif; ?></p>
    </div>
    <div>
        <?= badge(submission_label($submission['status']), $submission['status']) ?>
    </div>
</div>

<!-- Class Overview Metrics -->
<section class="kpis">
    <article class="kpi">
        <span>Enrolled Students</span>
        <strong><?= $totalStudents ?></strong>
    </article>
    <article class="kpi">
        <span>Class Passing Rate</span>
        <strong><?= $passRate ?>%</strong>
    </article>
    <article class="kpi">
        <span>Possible PL</span>
        <strong style="color: #b45309;"><?= $plCandidatesCount ?></strong>
    </article>
    <article class="kpi">
        <span>Possible DL</span>
        <strong style="color: #0284c7;"><?= $dlCandidatesCount ?></strong>
    </article>
    <article class="kpi">
        <span>Current Workflow Stage</span>
        <strong style="font-size:16px;"><?= h(submission_label($submission['status'])) ?></strong>
    </article>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= icon('sheet') ?> Computed Grades Sheet</h2>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => 'final']))) ?>" target="_blank">
            <?= icon('sheet') ?> View Full Sheet
        </a>
    </div>
    <?php if ($canEditPct): ?>
        <p class="hint">College offices can edit the grade <strong>percent</strong> here. The equivalent updates from the official scale. Leave a field blank to keep the professor-computed percent. Professors cannot change these.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="save_percents">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Type</th>
                            <th>Prelim %</th>
                            <th>Eqv</th>
                            <th>Midterm %</th>
                            <th>Eqv</th>
                            <th>Finals %</th>
                            <th>Eqv</th>
                            <th>Final %</th>
                            <th>Final Eqv</th>
                            <th>Remarks</th>
                            <th>Possible DL / PL</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($book['students'] as $student): ?>
                        <?php $eid = (int) $student['enrollment_id']; ?>
                        <tr>
                            <td>
                                <strong><?= h($student['full_name']) ?></strong>
                                <br><span class="mono hint"><?= h($student['student_number']) ?></span>
                            </td>
                            <td><?= badge((string) $student['student_type'], (string) $student['student_type']) ?></td>
                            <?php foreach (['prelim', 'midterm', 'finals'] as $periodKey): ?>
                                <?php
                                $override = $student['percent_overrides'][$periodKey] ?? null;
                                $computedPct = $student['periods'][$periodKey]['computed_percent'] ?? $student['periods'][$periodKey]['percent'];
                                ?>
                                <td>
                                    <input
                                        type="number"
                                        name="pct[<?= $eid ?>][<?= $periodKey ?>]"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= $override !== null ? h(number_format((float) $override, 2, '.', '')) : '' ?>"
                                        placeholder="<?= $computedPct === null ? '—' : h(number_format((float) $computedPct, 2, '.', '')) ?>"
                                        aria-label="<?= h(PERIODS[$periodKey] . ' percent for ' . $student['full_name']) ?>"
                                        style="width: 88px;"
                                    >
                                </td>
                                <td><strong><?= fmt_eqv($student['periods'][$periodKey]['equivalent'] === null ? null : (float) $student['periods'][$periodKey]['equivalent']) ?></strong></td>
                            <?php endforeach; ?>
                            <td>
                                <?php
                                $finalOverride = $student['percent_overrides']['final'] ?? null;
                                $finalComputed = $student['computed_final_percent'] ?? $student['final_percent'];
                                ?>
                                <input
                                    type="number"
                                    name="pct[<?= $eid ?>][final]"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value="<?= $finalOverride !== null ? h(number_format((float) $finalOverride, 2, '.', '')) : '' ?>"
                                    placeholder="<?= $finalComputed === null ? '—' : h(number_format((float) $finalComputed, 2, '.', '')) ?>"
                                    aria-label="<?= h('Final percent for ' . $student['full_name']) ?>"
                                    style="width: 88px;"
                                >
                            </td>
                            <td><strong><?= fmt_eqv($student['final_equivalent'] === null ? null : (float) $student['final_equivalent']) ?></strong></td>
                            <td><?= badge((string) $student['remarks'], (string) $student['remarks']) ?></td>
                            <td>
                                <?php if (($studentStandings[$eid] ?? '') === 'Possible PL'): ?>
                                    <span class="badge badge-gold">Possible PL</span>
                                <?php elseif (($studentStandings[$eid] ?? '') === 'Possible DL'): ?>
                                    <span class="badge badge-info">Possible DL</span>
                                <?php else: ?>
                                    <span class="hint">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 14px;">
                <button class="btn" type="submit"><?= icon('check') ?> Save Grade Percents</button>
            </div>
        </form>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Type</th>
                        <th>Final %</th>
                        <th>Equivalent</th>
                        <th>Remarks</th>
                        <th>Predicted Eqv</th>
                        <th>Possible DL / PL</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($book['students'] as $student): ?>
                    <?php $eid = (int) $student['enrollment_id']; ?>
                    <tr>
                        <td>
                            <strong><?= h($student['full_name']) ?></strong>
                            <br><span class="mono hint"><?= h($student['student_number']) ?></span>
                        </td>
                        <td><?= badge((string) $student['student_type'], (string) $student['student_type']) ?></td>
                        <td><?= fmt_pct($student['final_percent']) ?></td>
                        <td>
                            <strong><?= fmt_eqv($student['final_equivalent'] === null ? null : (float) $student['final_equivalent']) ?></strong>
                            <?php if (!empty($student['final_percent_overridden'])): ?>
                                <br><span class="hint">Office override</span>
                            <?php endif; ?>
                        </td>
                        <td><?= badge((string) $student['remarks'], (string) $student['remarks']) ?></td>
                        <td><?= fmt_eqv($student['predicted_equivalent'] === null ? null : (float) $student['predicted_equivalent']) ?></td>
                        <td>
                            <?php if (($studentStandings[$eid] ?? '') === 'Possible PL'): ?>
                                <span class="badge badge-gold">Possible PL</span>
                            <?php elseif (($studentStandings[$eid] ?? '') === 'Possible DL'): ?>
                                <span class="badge badge-info">Possible DL</span>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($canAct): ?>
<section class="card">
    <h2><?= icon('queue') ?> Action for Your Office</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="remarks">Review Remarks / Notes</label>
        <textarea id="remarks" name="remarks" rows="3" placeholder="Required when returning, rejecting, or flagging for corrections"></textarea>
        <div class="dialog-actions">
            <button class="btn btn-gold" name="review_action" value="approve" type="submit">
                Approve & Finalize Class Grades
            </button>
            <button class="btn btn-ghost" name="review_action" value="return" type="submit">Return to Professor</button>
            <button class="btn btn-danger" name="review_action" value="reject" type="submit">Reject</button>
            <button class="btn btn-danger" name="review_action" value="flag" type="submit">Flag Discrepancy</button>
        </div>
    </form>
</section>
<?php else: ?>
    <div class="alert alert-info">
        <strong>Workflow Note:</strong> No review action is available for this record. Submitted grades are reviewed by the assigned program chair.
    </div>
<?php endif; ?>

<section class="card">
    <h2><?= icon('audit') ?> Class Verification History</h2>
    <?php if ($history === []): ?>
        <p class="empty">No review notes recorded yet.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $item): ?>
                <li>
                    <strong><?= h(ucfirst((string) $item['action'])) ?></strong> by 
                    <?= h(!empty($item['full_name']) ? trim((string)($item['title'] ?? '') . ' ' . (string)$item['full_name']) : 'System') ?>
                    · <small><?= h((string) $item['from_status']) ?> → <?= h((string) $item['to_status']) ?></small>
                    <?php if (!empty($item['remarks'])): ?>
                        <div style="margin-top:4px;"><?= h((string) $item['remarks']) ?></div>
                    <?php endif; ?>
                    <small class="hint"><?= fmt_when($item['created_at']) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php layout_end(); ?>
