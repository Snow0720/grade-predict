<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_charts.php';

db_or_setup();
$user = require_portal('professor');
$classes = professor_class_rows((int) $user['id']);

$incompleteStudents = 0;
$drafts = 0;
$submitted = 0;
$returned = 0;
$gradeProgress = [];

foreach ($classes as $class) {
    $status = $class['status'] ?? 'draft';
    if ($status === 'draft') $drafts++;
    elseif ($status === 'returned') $returned++;
    elseif ($status !== 'registrar_finalized') $submitted++;
    
    $book = build_gradebook((int) $class['id']);
    $completed = 0;
    $remaining = 0;
    foreach ($book['students'] as $student) {
        $isComplete = $student['final_equivalent'] !== null && $student['remarks'] !== 'INC';
        if ($isComplete) $completed++;
        else {
            $remaining++;
            if (in_array($status, ['draft', 'returned'], true)) $incompleteStudents++;
        }
    }
    $gradeProgress[] = [
        'label' => $class['subject_code'] . ' · ' . $class['section_name'],
        'completed' => $completed,
        'remaining' => $remaining,
    ];
}

$history = db()->prepare(
    'SELECT sr.action, sr.to_status, sr.remarks, sr.created_at,
            s.code AS subject_code, sec.name AS section_name
     FROM submission_reviews sr
     JOIN grade_submissions gs ON gs.id = sr.submission_id
     JOIN professor_assignments pa ON pa.id = gs.assignment_id
     JOIN professors pr ON pr.id = pa.professor_id
     JOIN subjects s ON s.id = pa.subject_id
     JOIN sections sec ON sec.id = pa.section_id
     WHERE pr.user_id = ?
       AND sr.action IN ("submit", "return")
     ORDER BY sr.id DESC
     LIMIT 12'
);
$history->execute([(int) $user['id']]);
$history = $history->fetchAll();

$deadlineTerm = $classes[0] ?? null;
$deadlinePeriods = [];
$nextDeadline = null;
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
foreach (['prelim' => 'Prelim', 'midterm' => 'Midterm', 'finals' => 'Finals'] as $key => $label) {
    $rawDate = $deadlineTerm[$key . '_deadline'] ?? null;
    $date = $rawDate ? DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $rawDate, 0, 10), $today->getTimezone()) : false;
    $state = !$date ? 'unset' : ($date < $today ? 'closed' : ($date == $today ? 'today' : 'upcoming'));
    $deadlinePeriods[$key] = ['label' => $label, 'date' => $date, 'state' => $state];
    if ($date && $date >= $today && ($nextDeadline === null || $date < $deadlinePeriods[$nextDeadline]['date'])) $nextDeadline = $key;
}

layout_start('Dashboard', 'dash', [['label' => 'Dashboard']]);
?>

<style>
/* Modern KPI Card Overrides */
.kpi-grid-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.modern-kpi-card {
    background: var(--theme-surface, #ffffff);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.modern-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.kpi-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

.kpi-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--muted, #64748b);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.kpi-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--ink, #0f172a);
    line-height: 1;
}

.kpi-subtext {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 4px;
    font-weight: 600;
}

/* Grade submission deadlines */
.deadline-card { background: var(--theme-surface, #fff); border: 1px solid var(--line); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.deadline-card-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.deadline-card-head h2 { margin: 0; font-size: 17px; color: var(--navy); }
.deadline-semester { border-radius: 99px; padding: 5px 10px; background: var(--theme-raised, #eff6ff); color: #285597; font-size: 12px; font-weight: 700; }
.deadline-periods { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.deadline-period { padding: 16px; border: 1px solid transparent; border-radius: 14px; min-width: 0; }
.deadline-period.is-next { background: var(--theme-raised, #fffbeb); border-color: var(--gold); box-shadow: inset 0 0 0 1px rgba(226,175,21,.15); }
.deadline-period-icon { display: block; margin-bottom: 12px; color: var(--muted, #64748b); }
.deadline-period.is-next .deadline-period-icon { color: #a47510; }
.deadline-period h3 { margin: 0 0 12px; font-size: 16px; color: var(--navy); }
.deadline-date { display: block; font-size: 24px; font-weight: 700; color: var(--navy); line-height: 1.25; }
.deadline-year { display: block; margin-top: 6px; color: var(--muted, #64748b); font-size: 14px; }
.deadline-status { display: inline-block; border-radius: 99px; padding: 4px 9px; margin-top: 12px; font-size: 12px; font-weight: 700; background: var(--theme-raised, #eff6ff); color: #285597; }
.deadline-status.is-closed { background: var(--theme-raised, #ecfdf5); color: #166534; }
.deadline-status.is-next { background: #fef3c7; color: #92400e; }
.deadline-status.is-unset { background: var(--theme-raised, #f1f5f9); color: var(--muted, #64748b); }
@media (max-width: 600px) {
    .deadline-periods { grid-template-columns: 1fr; gap: 8px; }
    .deadline-period { padding: 14px; }
}
</style>

<div class="page-head" style="margin-bottom: 24px;">
    <div>
        <p class="greeting" style="margin: 0; font-size: 1.75rem; font-weight: 800; color: var(--ink, #0f172a); font-family: var(--display);">
            Good day, <?= h(display_name($user)) ?>
        </p>
        <p class="lede" style="margin-top: 4px; color: var(--muted, #64748b); font-size: 0.95rem;">
            Every assigned class is listed below. Open a class subject to manage settings, encode, or submit grades.
        </p>
    </div>
</div>

<!-- MODERN KPI CARDS -->
<div class="kpi-grid-container">
    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: var(--theme-raised, #eff6ff); color: #2563eb;">
            <?= icon('classes') ?>
        </div>
        <div>
            <div class="kpi-title">Assigned Classes</div>
            <div class="kpi-value"><?= count($classes) ?></div>
            <div class="kpi-subtext">Teaching workload</div>
        </div>
    </div>

    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: var(--theme-raised, #fffbeb); color: #d97706;">
            <?= icon('queue') ?>
        </div>
        <div>
            <div class="kpi-title">Incomplete Grades</div>
            <div class="kpi-value"><?= $incompleteStudents ?></div>
            <div class="kpi-subtext">Students with grades still to encode</div>
        </div>
    </div>

    <div class="modern-kpi-card">
        <div class="kpi-icon-box" style="background: var(--theme-raised, #f3e8ff); color: #9333ea;">
            <?= icon('reports') ?>
        </div>
        <div>
            <div class="kpi-title">Pending Submissions</div>
            <div class="kpi-value"><?= $drafts + $returned ?></div>
            <div class="kpi-subtext">
                Grade sheets to complete or resubmit
            </div>
        </div>
    </div>
</div>

<section class="deadline-card" aria-labelledby="deadline-title">
    <div class="deadline-card-head">
        <h2 id="deadline-title">Grade Submission Deadlines</h2>
        <?php if ($deadlineTerm): ?><span class="deadline-semester"><?= h($deadlineTerm['semester_name']) ?> &middot; <?= h($deadlineTerm['year_label']) ?></span><?php endif; ?>
    </div>
    <?php if ($deadlineTerm === null): ?>
        <p class="hint">No class assigned yet.</p>
    <?php else: ?>
        <div class="deadline-periods">
            <?php foreach ($deadlinePeriods as $key => $deadline): $isNext = $nextDeadline === $key; ?>
            <article class="deadline-period<?= $isNext ? ' is-next' : '' ?>"<?= $isNext ? ' aria-label="' . h($deadline['label']) . ', next submission deadline"' : '' ?>>
                <span class="deadline-period-icon" aria-hidden="true"><?= icon($deadline['state'] === 'closed' ? 'check' : 'calendar') ?></span>
                <h3><?= h($deadline['label']) ?></h3>
                <?php if ($deadline['date']): ?>
                    <time datetime="<?= $deadline['date']->format('Y-m-d') ?>"><span class="deadline-date"><?= $deadline['date']->format('M j') ?></span><span class="deadline-year"><?= $deadline['date']->format('Y') ?></span></time>
                <?php else: ?><span class="deadline-date">&mdash;</span><span class="deadline-year">Date not set</span><?php endif; ?>
                <span class="deadline-status is-<?= h($deadline['state']) ?><?= $isNext ? ' is-next' : '' ?>"><?= ['closed' => 'Closed', 'today' => 'Due today', 'upcoming' => 'Upcoming', 'unset' => 'Not scheduled'][$deadline['state']] ?></span>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card professor-grade-progress" style="margin-bottom: 24px;">
    <?php dashboard_horizontal_bars('professor-grade-progress', 'Grade Encoding Progress by Class', $gradeProgress, [
        'completed' => 'Grades Completed',
        'remaining' => 'Grades Remaining',
    ], 'Class / Section'); ?>
    <p class="hint">Student grades per class. Completed grades have a final equivalent and no incomplete remark.</p>
</section>

<!-- CLASS CARDS LIST -->
<section style="margin-bottom: 32px;">
    <?php if ($classes === []): ?>
        <div style="background: var(--theme-surface, #ffffff); padding: 32px; text-align: center; border-radius: 12px; border: 1px solid var(--line); color: #94a3b8;">
            No class is assigned to this account yet.
        </div>
    <?php else: ?>
        <?php require __DIR__ . '/views/class_cards.php'; ?>
    <?php endif; ?>
</section>

<!-- SUBMISSION HISTORY -->
<section style="background: var(--theme-surface, #ffffff); border-radius: 12px; border: 1px solid var(--line); padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line);">
        <h2 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--ink, #0f172a); display: flex; align-items: center; gap: 8px;">
            <?= icon('audit') ?> Grade Submission History
        </h2>
    </div>

    <?php if ($history === []): ?>
        <p style="margin: 0; color: #94a3b8; font-size: 0.9rem; text-align: center; padding: 16px 0;">
            No grades have been submitted yet.
        </p>
    <?php else: ?>
        <div class="table-wrap" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem; text-align: left;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--line); color: var(--ink, #475569);">
                        <th style="padding: 10px; font-weight: 700;">Class</th>
                        <th style="padding: 10px; font-weight: 700;">Action</th>
                        <th style="padding: 10px; font-weight: 700;">Remarks</th>
                        <th style="padding: 10px; font-weight: 700; text-align: left;">When</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $item): ?>
                    <tr style="border-bottom: 1px solid var(--line);">
                        <td style="padding: 12px 10px; font-weight: 700; color: var(--ink, #0f172a);">
                            <?= h($item['subject_code'] . ' · ' . $item['section_name']) ?>
                        </td>
                        <td style="padding: 12px 10px;">
                            <?php if ($item['action'] === 'return'): ?>
                                <span style="background: var(--bad-bg); color: var(--bad); padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8rem;">
                                    Returned for correction
                                </span>
                            <?php else: ?>
                                <span style="background: var(--ok-bg); color: var(--ok); padding: 3px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8rem;">
                                    Grades submitted
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 10px; color: var(--muted, #64748b);">
                            <?= h((string) $item['remarks']) ?: '—' ?>
                        </td>
                        <td style="padding: 12px 10px; text-align: right; color: #94a3b8; font-weight: 600;">
                            <?= fmt_when($item['created_at']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>