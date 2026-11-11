<?php
declare(strict_types=1);

$tone = [
    'attendance' => 'att',
    'recitation' => 'rec',
    'activities' => 'act',
    'quizzes'    => 'quiz',
    'project'    => 'proj',
    'exam'       => 'exam',
];

$periodData = $book['items'][$period] ?? [];
$visible = [];
$shareCatalog = [];
$totalActiveColumns = 0;

foreach (CATEGORIES as $cat => $label) {
    $catWeight = (float) ($book['weights'][$period][$cat] ?? 0);
    $itemCount = count($periodData[$cat] ?? []);
    
    // Automatically hide category from sheet grid if weight is 0
    if ($catWeight > 0) {
        $visible[$cat] = $label;
        $totalActiveColumns += $itemCount;
        $shareCatalog[] = [
            'key'     => $cat,
            'label'   => $label,
            'percent' => $catWeight,
            'tone'    => $tone[$cat],
        ];
    }
}
$shareCatalogJson = json_encode($shareCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

if (!function_exists('clean_pct')) {
    function clean_pct(mixed $val): string {
        if ($val === null || $val === '') return '';
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

.cat-head-title {
    font-size: 0.82rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: inherit;
}
.item-max-badge {
    display: block;
    font-size: 0.75rem;
    font-weight: 700;
}
.score {
    background-color: #fff;
    border: 1px solid #D5CFC4;
    color: #1A2332;
    font-weight: 700;
    border-radius: 6px;
    text-align: center;
    width: 60px;
    padding: 4px;
}
.score:focus {
    outline: none;
    border-color: #B7A06A;
    box-shadow: 0 0 0 3px rgba(183, 160, 106, 0.28);
    background-color: #fff;
}

.empty-state-banner {
    background-color: #f1f5f9;
    border: 1px dashed #cbd5e1;
    color: #475569;
    font-weight: 600;
    text-align: center;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 12px;
}
</style>

<div class="sheet-body w-100">
    <div class="w-100">
        <?php if ($totalActiveColumns === 0): ?>
            <div class="empty-state-banner">
                No active assessment columns found for this period.
            </div>
        <?php endif; ?>

        <div class="sheet-scroll w-100" data-gradebook data-period="<?= h($period) ?>" data-assignment="<?= (int) $assignment['id'] ?>" data-csrf="<?= h(csrf_token()) ?>" data-save-url="<?= h(url('api/score.php')) ?>">
            <table class="grade-grid w-100" id="encodingTable">
                <thead>
                    <tr>
                        <th class="sticky col-num" rowspan="2"><span class="sticky-label">#</span></th>
                        <th class="sticky col-sn" rowspan="2"><span class="sticky-label">Student number</span></th>
                        <th class="sticky col-name" rowspan="2"><span class="sticky-label">Student name</span></th>

                        <!-- CATEGORY HEADERS WITH BOLD TITLES -->
                        <?php foreach ($visible as $cat => $label): ?>
                            <?php
                            $count = count($periodData[$cat] ?? []);
                            $share = clean_pct($book['weights'][$period][$cat] ?? 0);
                            ?>
                            <th class="cat-<?= h($tone[$cat]) ?>" colspan="<?= max(1, $count) + 1 ?>">
                                <span class="cat-head-title">
                                    <?= h($label) ?> (<?= h($share) ?>%)
                                </span>
                            </th>
                        <?php endforeach; ?>

                        <th class="cat-grade" rowspan="2" data-tip="Period percent">%</th>
                        <th class="cat-grade" rowspan="2" data-tip="Grade equivalent">Eqv</th>
                    </tr>

                    <!-- SUB-HEADERS: CLEAN WHOLE MAX SCORE INDICATORS -->
                    <tr>
                        <?php foreach ($visible as $cat => $label): ?>
                            <?php foreach ($periodData[$cat] ?? [] as $item): ?>
                                <?php $max_score_clean = clean_pct($item['max_score'] ?? 10); ?>
                                <th class="item-sub-head">
                                    <div class="fw-bold" data-tip="<?= h($item['label']) ?>"><?= h($item['code']) ?></div>
                                    <span class="item-max-badge">
                                        /<?= h($max_score_clean) ?>
                                    </span>
                                </th>
                            <?php endforeach; ?>
                            <?php if (($periodData[$cat] ?? []) === []): ?>
                                <th class="item-sub-head"></th>
                            <?php endif; ?>
                            <th class="item-sub-head ttl" data-tip="<?= h($label) ?> total">Ttl</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                <?php if ($book['students'] === []): ?>
                    <tr>
                        <td class="sticky text-center py-4 text-muted fw-semibold" colspan="40" style="background: #f8fafc;">
                            No students are enrolled in this class yet.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($book['students'] as $rowIndex => $student): ?>
                    <?php 
                    $weights = []; 
                    foreach (CATEGORIES as $cat => $label) { 
                        $weights[$cat] = $student['periods'][$period]['categories'][$cat]['weight']; 
                    } 
                    ?>
                    <tr data-weights="<?= h(json_encode($weights)) ?>" data-adjustment="<?= h((string) $student['periods'][$period]['adjustment']) ?>">
                        <td class="sticky col-num"><?= (int) $student['number'] ?></td>
                        <td class="sticky col-sn mono"><?= h($student['student_number']) ?></td>
                        <td class="sticky col-name">
                            <?= h($student['full_name']) ?> 
                            <?= $student['student_type'] === 'Irregular' ? badge('Irregular', 'Irregular') : '' ?>
                            <small class="sn-mobile"><?= h($student['student_number']) ?></small>
                        </td>

                        <!-- SCORES INPUTS & CLEAN WHOLE NUMBER TTL DISPLAY -->
                        <?php foreach ($visible as $cat => $label): ?>
                            <?php foreach ($student['periods'][$period]['categories'][$cat]['items'] as $item): ?>
                                <td class="tone-<?= h($tone[$cat]) ?>">
                                    <input class="score" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" min="0" max="<?= h((string) (int) $item['max_score']) ?>"
                                           aria-label="<?= h($student['full_name'] . ' ' . $item['label']) ?>"
                                           data-component="<?= (int) $item['id'] ?>"
                                           data-enrollment="<?= (int) $student['enrollment_id'] ?>"
                                           data-cat="<?= h($cat) ?>"
                                           data-max="<?= h((string) $item['max_score']) ?>"
                                           data-row="<?= $rowIndex ?>"
                                           value="<?= $item['score'] === null ? '' : h(clean_pct($item['score'])) ?>"
                                           <?= $editable ? '' : 'readonly' ?>>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($student['periods'][$period]['categories'][$cat]['items'] === []): ?>
                                <td class="tone-<?= h($tone[$cat]) ?>"></td>
                            <?php endif; ?>
                            <td class="tone-<?= h($tone[$cat]) ?> ttl" data-ttl="<?= h($cat) ?>">
                                <?= clean_pct($student['periods'][$period]['categories'][$cat]['percent']) ?>
                            </td>
                        <?php endforeach; ?>

                        <td class="grade-total" data-period-pct><?= clean_pct($student['periods'][$period]['percent']) ?></td>
                        <td class="grade-eqv" data-period-eqv><?= $student['periods'][$period]['equivalent'] === null ? '' : fmt_eqv((float) $student['periods'][$period]['equivalent']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="save-state mt-2" id="saveState"></p>
        <?php require __DIR__ . '/submit_bar.php'; ?>
    </div>
</div>
<script type="application/json" id="scaleJson"><?= json_encode($book['scale'], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<!-- KEYBOARD NAVIGATION AND DIGITS-ONLY ENFORCEMENT FOR AJAX INPUTS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    function enforceDigitsOnly(input) {
        if (input.dataset.digitsOnlyBound === '1') return;
        input.dataset.digitsOnlyBound = '1';

        input.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (e.key === 'Tab') return;
            if (e.shiftKey) {
                e.preventDefault();
                return;
            }
            const allowed = [
                'Backspace', 'Delete', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (allowed.includes(e.key)) return;

            // Reject anything that is not a single digit 0-9 (blocks dot, letters, symbols, space)
            if (!/^[0-9]$/.test(e.key)) {
                e.preventDefault();
            }
        });

        input.addEventListener('beforeinput', function(e) {
            if (e.inputType === 'insertText' && e.data) {
                if (!/^\d+$/.test(e.data)) {
                    e.preventDefault();
                }
            }
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const clip = e.clipboardData || window.clipboardData;
            const text = clip ? clip.getData('text') : '';
            const digits = text.replace(/\D/g, '');
            if (!digits) return;

            let start = this.value.length;
            let end = this.value.length;
            try {
                if (typeof this.selectionStart === 'number') {
                    start = this.selectionStart;
                    end = this.selectionEnd;
                }
            } catch (err) {}

            const cur = this.value;
            this.value = cur.slice(0, start) + digits + cur.slice(end);
            try {
                if (typeof this.setSelectionRange === 'function') {
                    this.setSelectionRange(start + digits.length, start + digits.length);
                }
            } catch (err) {}
            this.dispatchEvent(new Event('input', { bubbles: true }));
        });

        input.addEventListener('drop', function(e) {
            e.preventDefault();
            const text = e.dataTransfer ? e.dataTransfer.getData('text') : '';
            const digits = text.replace(/\D/g, '');
            if (!digits) return;
            this.value = digits;
            this.dispatchEvent(new Event('input', { bubbles: true }));
        });

        input.addEventListener('input', function() {
            if (this.value && /\D/.test(this.value)) {
                const sel = typeof this.selectionStart === 'number' ? this.selectionStart : null;
                const cleaned = this.value.replace(/\D/g, '');
                this.value = cleaned;
                if (sel !== null) {
                    try {
                        this.setSelectionRange(Math.min(cleaned.length, sel), Math.min(cleaned.length, sel));
                    } catch (err) {}
                }
            }
        });
    }

    const inputs = Array.from(document.querySelectorAll('.score:not([readonly])'));
    if (inputs.length === 0) return;

    inputs.forEach(input => {
        enforceDigitsOnly(input);
    });

    const rows = {};
    inputs.forEach(input => {
        const r = input.dataset.row;
        if (r !== undefined) {
            if (!rows[r]) rows[r] = [];
            rows[r].push(input);
        }
    });

    const rowKeys = Object.keys(rows).sort((a, b) => Number(a) - Number(b));

    inputs.forEach(input => {
        input.addEventListener('keydown', function(e) {
            const currentRow = this.dataset.row;
            if (!currentRow || !rows[currentRow]) return;

            const rowInputs = rows[currentRow];
            const colIndex = rowInputs.indexOf(this);
            const rowIndex = rowKeys.indexOf(currentRow);

            if (e.key === 'Enter' || e.key === 'ArrowDown') {
                e.preventDefault();
                if (rowIndex < rowKeys.length - 1) {
                    const nextRowKey = rowKeys[rowIndex + 1];
                    const target = rows[nextRowKey][colIndex] || rows[nextRowKey][0];
                    target.focus();
                    target.select();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (rowIndex > 0) {
                    const prevRowKey = rowKeys[rowIndex - 1];
                    const target = rows[prevRowKey][colIndex] || rows[prevRowKey][0];
                    target.focus();
                    target.select();
                }
            } else if (e.key === 'ArrowRight') {
                let isAtEnd = true;
                try {
                    isAtEnd = (this.selectionStart === null || this.selectionStart === this.value.length);
                } catch (err) {}
                if (isAtEnd && colIndex < rowInputs.length - 1) {
                    e.preventDefault();
                    rowInputs[colIndex + 1].focus();
                    rowInputs[colIndex + 1].select();
                }
            } else if (e.key === 'ArrowLeft') {
                let isAtStart = true;
                try {
                    isAtStart = (this.selectionEnd === null || this.selectionEnd === 0);
                } catch (err) {}
                if (isAtStart && colIndex > 0) {
                    e.preventDefault();
                    rowInputs[colIndex - 1].focus();
                    rowInputs[colIndex - 1].select();
                }
            }
        });

        input.addEventListener('focus', function() {
            this.select();
        });
    });
});
</script>