<?php
declare(strict_types=1);

if (!function_exists('clean_pct')) {
    function clean_pct(mixed $val): string {
        if ($val === null || $val === '') return '—';
        $f = (float) $val;
        if (floor($f) == $f) {
            return (string) ((int) $f);
        }
        return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    }
}

?>

<style>
/* Full Width Table Layout Overrides */
.sheet-body {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    padding-right: 0 !important;
    margin-right: 0 !important;
}
.sheet-scroll {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
}
.grade-grid {
    width: 100% !important;
    min-width: 100% !important;
    border-collapse: collapse !important;
}

.grade-grid th {
    font-weight: 700;
}
.grade-grid th.item-sub-head {
    font-size: 0.85rem;
}

/* Data Cells Styling */
.grade-grid td {
    border: 1px solid var(--line) !important;
    padding: 10px 8px !important;
    font-size: 0.88rem !important;
}
.grade-grid tbody tr:hover {
    background-color: var(--theme-surface, #f8fafc) !important;
}

td.predicted-cell small,
td.predicted-cell .small { color: var(--muted, #5C6B7A); }

.period-grade-head {
    text-align: center;
    background: var(--theme-raised, #E8EEF6);
    color: var(--ink, #142B49);
    letter-spacing: .04em;
    text-transform: uppercase;
}
.period-grade-head small {
    display: block;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
    color: var(--muted, #5C6B7A);
}
.overall-grade-head {
    text-align: center;
    background: #142B49;
    color: #fff;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.overall-grade-head small {
    display: block;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
    color: #E7DCC4;
}
td.period-pct, td.period-eqv, td.period-remarks {
    text-align: center;
    background: var(--theme-surface, #F8FAFC);
}
td.overall-pct, td.overall-eqv {
    text-align: center;
    font-weight: 800;
    background: #E7DCC4;
    color: var(--ink, #142B49);
}

/* Predicted Cells */
td.predicted-cell {
    background-color: var(--theme-surface, #f8fafc) !important;
    color: var(--ink, #475569) !important;
    text-align: center !important;
}

/* PERFECT SCREEN-CENTERED MODAL DIALOG & BACKDROP */
#submitDialog {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 28px;
    width: 90%;
    max-width: 480px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    background: var(--theme-surface, #ffffff);
    margin: 0;
}

#submitDialog::backdrop {
    background-color: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
}
</style>

<div class="sheet-body w-100">
    <p class="hint">Each period has its own grade. Overall combines Prelim, Midterm, and Finals.</p>
    <div class="w-100">
        <div class="sheet-scroll w-100">
            <table class="grade-grid w-100">
                <thead>
                    <tr>
                        <th class="sticky col-num" rowspan="2" style="width: 40px; text-align: left;"><span class="sticky-label">#</span></th>
                        <th class="sticky col-sn" rowspan="2" style="width: 130px;"><span class="sticky-label">Student number</span></th>
                        <th class="sticky col-name" rowspan="2"><span class="sticky-label">Student name</span></th>
                        <?php foreach (PERIODS as $key => $label): ?>
                            <?php $share = clean_pct($book['period_weights'][$key] ?? 0); ?>
                            <th colspan="3" class="period-grade-head">
                                <?= h($label) ?>
                                <?php if ($share !== '—' && (float) ($book['period_weights'][$key] ?? 0) > 0): ?>
                                    <small><?= h($share) ?>% of overall</small>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        <th colspan="3" class="overall-grade-head">
                            Overall
                            <small>Prelim + Midterm + Finals</small>
                        </th>
                        <th rowspan="2" class="item-sub-head">Semester GWA / Eligibility</th>
                    </tr>
                    <tr>
                        <?php foreach (PERIODS as $ignored): ?>
                            <th class="item-sub-head">%</th>
                            <th class="item-sub-head">Eqv</th>
                            <th class="item-sub-head">Remarks</th>
                        <?php endforeach; ?>
                        <th class="item-sub-head">%</th>
                        <th class="item-sub-head">Eqv</th>
                        <th class="item-sub-head">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($book['students'] as $student): ?>
                    <tr>
                        <td class="sticky col-num text-center fw-bold"><?= (int) $student['number'] ?></td>
                        <td class="sticky col-sn mono"><?= h($student['student_number']) ?></td>
                        <td class="sticky col-name">
                            <span class="fw-bold text-dark"><?= h($student['full_name']) ?></span>
                            <small class="sn-mobile"><?= h($student['student_number']) ?></small>
                        </td>

                        <?php foreach (array_keys(PERIODS) as $key): ?>
                            <td class="period-pct"><?= clean_pct($student['periods'][$key]['percent']) ?></td>
                            <td class="period-eqv"><?= fmt_eqv($student['periods'][$key]['equivalent']) ?></td>
                            <td class="period-remarks"><?php $periodRemark = (string) ($student['periods'][$key]['remarks'] ?? ''); ?><?= $periodRemark === '' ? '—' : badge($periodRemark, $periodRemark) ?></td>
                        <?php endforeach; ?>

                        <td class="overall-pct"><?= clean_pct($student['final_percent']) ?></td>
                        <td class="overall-eqv"><?= fmt_eqv($student['final_equivalent']) ?></td>
                        <td class="text-center"><?= badge($student['remarks'], $student['remarks']) ?></td>

<!-- Predicted Final GWA and Candidate Status -->
<!-- Semester GWA and Eligibility -->
<?php
$prediction = $student['semester_prediction'] ?? [];
$gwa = $prediction['gwa'] ?? null;
$status = $prediction['candidate_status'] ?? 'Pending';
$mode = $prediction['mode'] ?? 'pending';
?>

<td class="predicted-cell">
    <?php if ($mode === 'actual' && $gwa !== null): ?>
        <strong>
            <?= number_format((float) $gwa, 2) ?>
        </strong>

        <small class="d-block">
            Actual Semester GWA
        </small>

        <div class="small fw-bold">
            <?= h((string) $status) ?>
        </div>

    <?php elseif ($mode === 'predicted' && $gwa !== null): ?>
        <strong>
            <?= number_format((float) $gwa, 2) ?>
        </strong>

        <small class="d-block">
            Predicted Final GWA
        </small>

        <small class="d-block">
            <?= h((string) ($prediction['stage'] ?? '')) ?>
        </small>

        <div class="small fw-bold">
            <?= h((string) $status) ?>
        </div>

    <?php else: ?>
        <span>Awaiting complete Finals grades</span>
    <?php endif; ?>
</td>


                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require __DIR__ . '/submit_bar.php'; ?>
    </div>
</div>
<script type="application/json" id="scaleJson">[]</script>
