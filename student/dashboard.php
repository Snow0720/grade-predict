<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$student = require_student();

// Fetch semesters where the student has enrollments
$semestersStmt = db()->prepare(
    'SELECT DISTINCT sem.id, sem.name, sem.is_current
     FROM enrollments e
     JOIN professor_assignments pa ON pa.id = e.assignment_id
     JOIN semesters sem ON sem.id = pa.semester_id
     WHERE e.student_id = ?
     ORDER BY sem.id DESC'
);
$semestersStmt->execute([(int) $student['id']]);
$enrolledSemesters = $semestersStmt->fetchAll();

$selectedSemesterId = (int) ($_GET['semester_id'] ?? 0);
if ($selectedSemesterId <= 0) {
    if ($enrolledSemesters !== []) {
        $selectedSemesterId = (int) $enrolledSemesters[0]['id'];
    } else {
        $activeSem = db()->query('SELECT id FROM semesters WHERE is_current = 1 LIMIT 1')->fetch();
        $selectedSemesterId = $activeSem ? (int) $activeSem['id'] : 1;
    }
}

$summary = student_grades_summary((int) $student['id'], $selectedSemesterId > 0 ? $selectedSemesterId : null);
$adviserLabel = trim((string) ($student['adviser_title'] ?? '') . ' ' . (string) ($student['adviser_name'] ?? ''));

$nameParts = preg_split('/[\s,]+/', trim((string) ($student['full_name'] ?? '')));
$initials = '';
if (!empty($nameParts[0])) {
    $initials .= mb_substr($nameParts[0], 0, 1);
}
if (!empty($nameParts[1])) {
    $initials .= mb_substr($nameParts[1], 0, 1);
}
$initials = strtoupper($initials ?: 'ST');

$usingDefaultPassword = student_uses_default_password($student);

layout_start('My Grades & Academic Standing', 'grades', [
    ['label' => 'Student Portal'],
    ['label' => 'My Grades & Standing'],
]);
?>
<?php if ($usingDefaultPassword): ?>
    <div class="alert alert-warn">You are still using the starting password. <a href="<?= h(url('student/profile.php')) ?>">Open your profile</a> and change it.</div>
<?php endif; ?>
<!-- Student Overview Profile Card -->
<div class="student-profile-card">
    <div class="student-profile-main">
        <div class="student-avatar" title="<?= h($student['full_name']) ?>">
            <?= h($initials) ?>
        </div>
        <div>
            <h1 class="student-meta-title"><?= h($student['full_name']) ?></h1>
            <div class="student-tags">
                <span class="student-tag mono" style="color:var(--navy);">
                    <strong><?= h($student['student_number'] ?? '') ?></strong>
                </span>
                <span class="student-tag">
                    <?= h($student['program_code'] ?? '') ?> · <?= h((string) ($student['year_level'] ?? '')) ?>
                </span>
                <span class="student-tag">
                    Sec <?= h($student['section_name'] ?? 'Unassigned') ?>
                </span>
                <?php if ($adviserLabel !== ''): ?>
                    <span class="student-tag hint" style="font-weight: normal;">
                        Adviser: <?= h($adviserLabel) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="student-profile-actions">
        <?= badge($student['student_type'] ?? 'Regular', $student['student_type'] ?? 'Regular') ?>
        <?php if ($enrolledSemesters !== [] && count($enrolledSemesters) > 1): ?>
            <form method="get" action="<?= h(url('student/dashboard.php')) ?>" style="margin:0;">
                <select name="semester_id" onchange="this.form.submit()" class="student-sem-select" aria-label="Select Semester">
                    <?php foreach ($enrolledSemesters as $sem): ?>
                        <option value="<?= (int) $sem['id'] ?>" <?= (int) $sem['id'] === $selectedSemesterId ? 'selected' : '' ?>>
                            <?= h($sem['name']) ?><?= (int) ($sem['is_current'] ?? 0) === 1 ? ' (Active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- DL / PL Honor Standing Risk Monitor -->
<section class="card" style="margin-bottom: 22px;">
    <div class="card-head">
        <h2>
            <?= icon('award') ?>
            <span>Honor List Standing Monitor (Dean’s / President’s List Eligibility)</span>
        </h2>
    </div>

    <?php
    $plainStanding = (string) ($summary['overall_plain'] ?? 'Pending');
    $gradesOpen = !empty($summary['grades_open']);
    ?>
    <?php if ($plainStanding === 'At risk'): ?>
        <div style="padding: 16px 20px; border-radius: 12px; background: #FFF7ED; border-left: 5px solid var(--warn); margin-bottom: 18px;">
            <span class="badge badge-warn" style="font-size: 13px;">At risk</span>
        </div>
    <?php elseif ($plainStanding === 'Not at risk'): ?>
        <div style="padding: 16px 20px; border-radius: 12px; background: #F0FDF4; border-left: 5px solid var(--ok); margin-bottom: 18px;">
            <span class="badge badge-ok" style="font-size: 13px;">Not at risk</span>
        </div>
    <?php else: ?>
        <div style="padding: 16px 20px; border-radius: 12px; background: #F0F9FF; border-left: 5px solid var(--info); margin-bottom: 18px;">
            <span class="badge badge-info" style="font-size: 13px;">Pending</span>
        </div>
    <?php endif; ?>

    <!-- Overview KPIs -->
    <div class="kpis" style="margin-bottom: 10px;">
        <article class="kpi">
            <span>Current Term GWA</span>
            <?php if ($gradesOpen): ?>
                <strong class="mono" style="font-size: 20px; color: var(--navy);">
                    <?= fmt_eqv($summary['calculated_gwa']) ?>
                </strong>
                <small><?= $summary['calculated_gwa'] !== null ? 'Released by your professors' : 'Awaiting evaluations' ?></small>
            <?php else: ?>
                <strong style="font-size: 18px; color: var(--muted);">Hidden</strong>
                <small>Shown after your professor releases grades</small>
            <?php endif; ?>
        </article>
        <article class="kpi">
            <span>Course Progress</span>
            <?php if ($gradesOpen): ?>
                <strong style="font-size: 18px; color: var(--navy);">
                    <?= (int) $summary['passed_count'] ?> / <?= (int) $summary['total_count'] ?> Passed
                </strong>
                <small><?= (int) $summary['in_progress_count'] ?> in progress · <?= (int) $summary['failed_count'] ?> failed</small>
            <?php else: ?>
                <strong style="font-size: 18px; color: var(--muted);">Hidden</strong>
                <small><?= (int) $summary['released_count'] ?> of <?= (int) $summary['total_count'] ?> classes released</small>
            <?php endif; ?>
        </article>
    </div>

    <p class="hint" style="margin: 10px 0 0; font-size: 12px; color: var(--muted); line-height: 1.4;">
        Grades stay hidden until the professor for that class allows you to view them.
    </p>
</section>

<!-- Enrolled Academic Subjects & Grades -->
<section class="card">
    <div class="card-head">
        <h2>
            <?= icon('classes') ?>
            <span>Enrolled Subject Classes &amp; Academic Grades</span>
        </h2>
    </div>

    <?php if ($summary['classes'] === []): ?>
        <p class="empty">You are not currently enrolled in any academic subject classes for this semester.</p>
    <?php else: ?>
        <!-- Desktop Full Comparison Table View -->
        <div class="table-wrap desktop-table-view">
            <table>
                <thead>
                    <tr>
                        <th>Subject Code &amp; Title</th>
                        <th>Professor &amp; Section</th>
                        <th>Prelim</th>
                        <th>Midterm</th>
                        <th>Finals</th>
                        <th>Final Grade</th>
                        <th style="text-align:center;">Subject Result</th>
                        <th style="text-align:right;">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($summary['classes'] as $idx => $class): ?>
                    <?php
                    $prelim = $class['periods']['prelim'] ?? null;
                    $midterm = $class['periods']['midterm'] ?? null;
                    $finals = $class['periods']['finals'] ?? null;
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($class['subject_code']) ?></strong>
                            <br><span class="hint" style="font-size:12px;"><?= h($class['subject_name']) ?></span>
                        </td>
                        <td>
                            <?= h($class['professor_name'] !== '' ? $class['professor_name'] : 'Faculty') ?>
                            <br><span class="hint" style="font-size:12px;">Sec <?= h($class['section_name']) ?></span>
                        </td>
                        <td>
                            <?php if (empty($class['grades_visible'])): ?>
                                <span class="hint">Hidden</span>
                            <?php elseif ($prelim && !empty($prelim['started'])): ?>
                                <span class="mono"><strong><?= fmt_eqv($prelim['equivalent']) ?></strong></span>
                                <br><small class="hint"><?= fmt_pct($prelim['percent']) ?>%</small>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($class['grades_visible'])): ?>
                                <span class="hint">Hidden</span>
                            <?php elseif ($midterm && !empty($midterm['started'])): ?>
                                <span class="mono"><strong><?= fmt_eqv($midterm['equivalent']) ?></strong></span>
                                <br><small class="hint"><?= fmt_pct($midterm['percent']) ?>%</small>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($class['grades_visible'])): ?>
                                <span class="hint">Hidden</span>
                            <?php elseif ($finals && !empty($finals['started'])): ?>
                                <span class="mono"><strong><?= fmt_eqv($finals['equivalent']) ?></strong></span>
                                <br><small class="hint"><?= fmt_pct($finals['percent']) ?>%</small>
                            <?php else: ?>
                                <span class="hint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($class['grades_visible'])): ?>
                                <span class="hint">Hidden</span>
                            <?php elseif ($class['final_equivalent'] !== null): ?>
                                <strong class="mono" style="font-size: 15px; color: var(--navy);"><?= fmt_eqv($class['final_equivalent']) ?></strong>
                                <br><small class="hint"><?= fmt_pct($class['final_percent']) ?>% <?= $class['is_official'] ? '(Official)' : '(Computed)' ?></small>
                            <?php else: ?>
                                <span class="hint">In progress</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php
                            $subjectRisk = (string) ($class['honor_risk'] ?? 'Pending');
                            $subjectRiskBadge = $subjectRisk === 'At risk' ? 'warn' : ($subjectRisk === 'Not at risk' ? 'ok' : 'info');
                            ?>
                            <span class="badge badge-<?= h($subjectRiskBadge) ?>" style="font-size:12px; padding:4px 9px;"><?= h($subjectRisk) ?></span>
                            <?php if (trim((string) ($class['honor_note'] ?? '')) !== ''): ?>
                                <p class="hint" style="margin:8px 0 0; text-align:left; line-height:1.45; max-width:420px;"><?= h((string) $class['honor_note']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if (!empty($class['grades_visible'])): ?>
                            <button type="button" class="btn btn-muted" style="padding: 4px 10px; font-size: 12px;" onclick="toggleBreakdown(<?= $idx ?>)">
                                View Scores
                            </button>
                            <?php else: ?>
                                <span class="hint">Hidden</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Expandable Component Scores Breakdown Row -->
                    <tr id="breakdown-<?= $idx ?>" style="display:none; background: #FAFBFD;">
                        <td colspan="8" style="padding: 16px 20px;">
                            <div style="border: 1px solid var(--line); border-radius: 10px; background: #fff; padding: 14px 18px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px;">
                                    <strong style="color: var(--navy); font-size: 14px;">
                                        <?= icon('record') ?> Score Breakdown · <?= h($class['subject_code']) ?> (<?= h($class['subject_name']) ?>)
                                    </strong>
                                    <span class="hint" style="font-size:12px;">Assigned Faculty: <?= h($class['professor_name']) ?></span>
                                </div>

                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                                    <?php foreach (['prelim' => 'Prelim Period', 'midterm' => 'Midterm Period', 'finals' => 'Finals Period'] as $pKey => $pLabel): ?>
                                        <?php $pData = $class['periods'][$pKey] ?? null; ?>
                                        <div style="border: 1px solid #EEF2F6; border-radius: 8px; padding: 10px 12px; background: #FDFEFF;">
                                            <div style="display:flex; justify-content:space-between; border-bottom: 1px solid #EEF2F6; padding-bottom: 6px; margin-bottom: 8px;">
                                                <strong style="font-size: 13px; color: var(--navy);"><?= $pLabel ?></strong>
                                                <span class="mono" style="font-size: 13px;">
                                                    <strong><?= fmt_eqv($pData['equivalent'] ?? null) ?></strong>
                                                    <span class="hint">(<?= fmt_pct($pData['percent'] ?? null) ?>%)</span>
                                                </span>
                                            </div>
                                            <?php if ($pData && !empty($pData['categories'])): ?>
                                                <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                                    <?php foreach ($pData['categories'] as $catKey => $catData): ?>
                                                        <?php if ((float) ($catData['weight'] ?? 0) <= 0) continue; ?>
                                                        <tr>
                                                            <td style="padding: 3px 0; color: var(--muted);"><?= ucfirst($catKey) ?> (<?= (int)$catData['weight'] ?>%)</td>
                                                            <td style="padding: 3px 0; text-align: right;" class="mono">
                                                                <?= $catData['percent'] !== null ? number_format((float)$catData['percent'], 1) . '%' : '—' ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </table>
                                            <?php else: ?>
                                                <p class="hint" style="font-size: 12px; margin: 4px 0;">No scores encoded yet for this period.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card-Based Subjects View -->
        <div class="mobile-subjects-view">
            <?php foreach ($summary['classes'] as $idx => $class): ?>
                <?php
                $prelim = $class['periods']['prelim'] ?? null;
                $midterm = $class['periods']['midterm'] ?? null;
                $finals = $class['periods']['finals'] ?? null;
                ?>
                <article class="subject-card-mobile">
                    <div class="scm-head">
                        <span class="scm-code"><?= h($class['subject_code']) ?></span>
                        <div>
                            <?php
                            $subjectRisk = (string) ($class['honor_risk'] ?? 'Pending');
                            $subjectRiskBadge = $subjectRisk === 'At risk' ? 'warn' : ($subjectRisk === 'Not at risk' ? 'ok' : 'info');
                            ?>
                            <span class="badge badge-<?= h($subjectRiskBadge) ?>" style="font-size:11px; padding:3px 7px;"><?= h($subjectRisk) ?></span>
                        </div>
                    </div>
                    <?php if (trim((string) ($class['honor_note'] ?? '')) !== ''): ?>
                        <p class="hint" style="margin:8px 0 0; line-height:1.4;"><?= h((string) $class['honor_note']) ?></p>
                    <?php endif; ?>
                    <div class="scm-title"><?= h($class['subject_name']) ?></div>
                    <div class="scm-prof">
                        <span><?= icon('faculty') ?></span>
                        <span><?= h($class['professor_name'] !== '' ? $class['professor_name'] : 'Faculty') ?> · Sec <?= h($class['section_name']) ?></span>
                    </div>

                    <div class="scm-periods">
                        <div class="scm-period-col">
                            <strong>Prelim</strong>
                            <?php if (empty($class['grades_visible'])): ?>
                                <div class="scm-period-pct" style="margin-top:6px;">Hidden</div>
                            <?php elseif ($prelim && !empty($prelim['started'])): ?>
                                <div class="scm-period-val"><?= fmt_eqv($prelim['equivalent']) ?></div>
                                <div class="scm-period-pct"><?= fmt_pct($prelim['percent']) ?>%</div>
                            <?php else: ?>
                                <div class="scm-period-pct" style="margin-top:6px;">—</div>
                            <?php endif; ?>
                        </div>
                        <div class="scm-period-col" style="border-left:1px solid #EEF2F6; border-right:1px solid #EEF2F6;">
                            <strong>Midterm</strong>
                            <?php if (empty($class['grades_visible'])): ?>
                                <div class="scm-period-pct" style="margin-top:6px;">Hidden</div>
                            <?php elseif ($midterm && !empty($midterm['started'])): ?>
                                <div class="scm-period-val"><?= fmt_eqv($midterm['equivalent']) ?></div>
                                <div class="scm-period-pct"><?= fmt_pct($midterm['percent']) ?>%</div>
                            <?php else: ?>
                                <div class="scm-period-pct" style="margin-top:6px;">—</div>
                            <?php endif; ?>
                        </div>
                        <div class="scm-period-col">
                            <strong>Finals</strong>
                            <?php if (empty($class['grades_visible'])): ?>
                                <div class="scm-period-pct" style="margin-top:6px;">Hidden</div>
                            <?php elseif ($finals && !empty($finals['started'])): ?>
                                <div class="scm-period-val"><?= fmt_eqv($finals['equivalent']) ?></div>
                                <div class="scm-period-pct"><?= fmt_pct($finals['percent']) ?>%</div>
                            <?php else: ?>
                                <div class="scm-period-pct" style="margin-top:6px;">—</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="scm-summary">
                        <div>
                            <span class="scm-final-label">Final Grade</span>
                            <div>
                                <?php if (empty($class['grades_visible'])): ?>
                                    <span class="hint" style="font-size:13px;">Hidden</span>
                                <?php elseif ($class['final_equivalent'] !== null): ?>
                                    <span class="scm-final-val"><?= fmt_eqv($class['final_equivalent']) ?></span>
                                    <small class="hint">(<?= fmt_pct($class['final_percent']) ?>%)</small>
                                <?php else: ?>
                                    <span class="hint" style="font-size:13px;">In progress</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($class['grades_visible'])): ?>
                    <button type="button" class="scm-breakdown-btn" onclick="toggleMobileBreakdown(<?= $idx ?>)">
                        <?= icon('record') ?> <span>View Score Breakdown</span>
                    </button>

                    <div id="mobile-breakdown-<?= $idx ?>" class="scm-breakdown-content">
                        <?php foreach (['prelim' => 'Prelim', 'midterm' => 'Midterm', 'finals' => 'Finals'] as $pKey => $pLabel): ?>
                            <?php $pData = $class['periods'][$pKey] ?? null; ?>
                            <div style="background:#F8FAFC; border:1px solid #EEF2F6; border-radius:8px; padding:10px; margin-bottom:8px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                    <strong style="font-size:12px; color:var(--navy);"><?= $pLabel ?> Period</strong>
                                    <span class="mono" style="font-size:12px; font-weight:700;">
                                        <?= fmt_eqv($pData['equivalent'] ?? null) ?> (<?= fmt_pct($pData['percent'] ?? null) ?>%)
                                    </span>
                                </div>
                                <?php if ($pData && !empty($pData['categories'])): ?>
                                    <table style="width:100%; font-size:11px; border-collapse:collapse;">
                                        <?php foreach ($pData['categories'] as $catKey => $catData): ?>
                                            <?php if ((float) ($catData['weight'] ?? 0) <= 0) continue; ?>
                                            <tr>
                                                <td style="padding:2px 0; color:var(--muted);"><?= ucfirst($catKey) ?> (<?= (int)$catData['weight'] ?>%)</td>
                                                <td style="padding:2px 0; text-align:right;" class="mono">
                                                    <?= $catData['percent'] !== null ? number_format((float)$catData['percent'], 1) . '%' : '—' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php else: ?>
                                    <p class="hint" style="font-size:11px; margin:2px 0;">No scores encoded yet.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
function toggleBreakdown(idx) {
    const row = document.getElementById('breakdown-' + idx);
    if (!row) return;
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}

function toggleMobileBreakdown(idx) {
    const el = document.getElementById('mobile-breakdown-' + idx);
    if (!el) return;
    el.classList.toggle('is-open');
}
</script>

<?php layout_end(); ?>
