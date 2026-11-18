<?php
declare(strict_types=1);

/** Server-rendered charts keep the dashboard useful offline and without JavaScript. */
function dashboard_candidate_groups(array $programs, array $rows): array
{
    $groups = [];
    foreach ($programs as $program) {
        $groups[(int) $program['id']] = ['label' => (string) $program['code'], 'dl' => 0, 'pl' => 0];
    }
    $candidates = [];
    foreach ($rows as $row) {
        $programId = (int) $row['program_id'];
        if (!isset($groups[$programId])) continue;
        $status = strtolower((string) ($row['status'] ?? ''));
        $list = (string) ($row['list_type'] ?? '');
        if (!in_array($list, ['PL', 'DL'], true)) {
            $list = str_contains($status, 'president') || str_contains($status, 'pl') ? 'PL'
                : (str_contains($status, 'dean') || str_contains($status, 'dl') ? 'DL' : '');
        }
        if ($list === '') continue;
        $key = (int) $row['student_id'];
        if (($candidates[$key]['list'] ?? '') !== 'PL') {
            $candidates[$key] = ['program' => $programId, 'list' => $list];
        }
    }
    foreach ($candidates as $candidate) $groups[$candidate['program']][strtolower($candidate['list'])]++;
    return array_values($groups);
}

function dashboard_grouped_bars(string $id, string $title, array $groups, bool $linked = false, array $seriesLabels = [], string $groupLabel = 'Program'): void
{
    $candidateChart = $seriesLabels === [];
    $seriesLabels = $seriesLabels ?: ['dl' => "Dean's List", 'pl' => "President's List"];
    $seriesKeys = array_keys($seriesLabels);
    $colors = ['#285597', '#38823C', '#B35224', '#7561A5', '#348C90', '#B58B28'];
    $seriesCount = count($seriesKeys);
    $maximum = 0;
    foreach ($groups as $group) {
        foreach ($seriesKeys as $series) $maximum = max($maximum, $group[$series] ?? 0);
    }
    $step = max(1, (int) ceil($maximum / 4));
    $ceiling = $step * 4;
    $width = max(560, count($groups) * max(110, $seriesCount * 28 + 20) + 60);
    $plot = $width - 64;
    $slot = $plot / max(1, count($groups));
    $bar = min(38, ($slot * .8 - 6 * ($seriesCount - 1)) / $seriesCount);
    $groupWidth = $seriesCount * $bar + 6 * ($seriesCount - 1);
    ?>
    <figure class="dashboard-chart candidate-chart" aria-labelledby="<?= h($id) ?>-title">
        <figcaption id="<?= h($id) ?>-title"><?= h($title) ?></figcaption>
        <div class="candidate-chart-scroll">
            <svg viewBox="0 0 <?= $width ?> 310" style="min-width:<?= max(360, count($groups) * 75 + 60) ?>px" role="img" aria-labelledby="<?= h($id) ?>-title">
                <?php for ($tick = 0; $tick <= 4; $tick++): $y = 250 - $tick * 54; ?>
                    <line x1="46" y1="<?= $y ?>" x2="<?= $width - 18 ?>" y2="<?= $y ?>" stroke="#E8EAF0" stroke-dasharray="3 4"/>
                    <text x="35" y="<?= $y + 4 ?>" text-anchor="end" class="chart-label"><?= $tick * $step ?></text>
                <?php endfor; ?>
                <?php foreach ($groups as $index => $group): $center = 46 + $slot * ($index + .5); ?>
                    <?php foreach ($seriesKeys as $seriesIndex => $series): $height = 216 * $group[$series] / $ceiling; ?>
                        <rect class="candidate-bar" x="<?= $center - $groupWidth / 2 + $seriesIndex * ($bar + 6) ?>" y="<?= 250 - $height ?>" width="<?= $bar ?>" height="<?= $height ?>" rx="5" fill="<?= $colors[$seriesIndex % count($colors)] ?>"><title><?= h($group['label']) ?> - <?= h($seriesLabels[$series]) ?>: <?= (int) $group[$series] ?></title></rect>
                    <?php endforeach; ?>
                    <text x="<?= $center ?>" y="276" text-anchor="middle" class="chart-label"><?= h($group['label']) ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
        <ul class="chart-legend candidate-legend"><?php foreach ($seriesKeys as $seriesIndex => $series): ?><li><span class="legend-dot" style="background:<?= $colors[$seriesIndex % count($colors)] ?>"></span><?= h($seriesLabels[$series]) ?></li><?php endforeach; ?></ul>
        <?php if ($maximum === 0): ?><p class="hint"><?= $candidateChart ? 'No candidates recorded for the current semester.' : 'No records available for these programs.' ?></p><?php endif; ?>
        <details class="chart-details"><summary><?= $candidateChart ? 'View candidate counts' : 'View counts' ?></summary><table><thead><tr><th><?= h($groupLabel) ?></th><?php foreach ($seriesLabels as $seriesLabel): ?><th><?= h($seriesLabel) ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($groups as $group): ?><tr><td><?= h($group['label']) ?></td><?php foreach ($seriesKeys as $series): ?><td><?php if ($linked): ?><a href="<?= h(url('admin/evaluations.php', ['program' => $group['label'], 'standing' => $series])) ?>"><?= (int) $group[$series] ?></a><?php else: ?><?= (int) $group[$series] ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></details>
    </figure>
    <?php
}

function dashboard_horizontal_bars(string $id, string $title, array $groups, array $seriesLabels, string $groupLabel = 'Scholarship category'): void
{
    $maximum = 0;
    foreach ($groups as $group) {
        foreach ($seriesLabels as $key => $label) $maximum = max($maximum, (int) ($group[$key] ?? 0));
    }
    $step = max(1, (int) ceil($maximum / 4));
    $ceiling = $step * 4;
    $colors = ['#285597', '#38823C'];
    ?>
    <figure class="dashboard-chart horizontal-chart" aria-labelledby="<?= h($id) ?>-title">
        <figcaption id="<?= h($id) ?>-title"><?= h($title) ?></figcaption>
        <div class="horizontal-chart-rows">
        <?php foreach ($groups as $group): ?>
            <div class="horizontal-chart-row">
                <span class="horizontal-chart-label"><?= h($group['label']) ?></span>
                <div class="horizontal-chart-bars">
                <?php $i = 0; foreach ($seriesLabels as $key => $label): $value = max(0, (int) ($group[$key] ?? 0)); ?>
                    <div class="horizontal-chart-track">
                        <div class="horizontal-chart-bar" style="width:<?= round(100 * $value / $ceiling, 3) ?>%;background:<?= $colors[$i++ % count($colors)] ?>" role="img" aria-label="<?= h($group['label'] . ' — ' . $label . ': ' . $value) ?>" title="<?= h($label . ': ' . $value) ?>"></div>
                        <span class="horizontal-chart-count"><?= $value ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
            <div class="horizontal-chart-axis" aria-hidden="true"><?php for ($tick = 0; $tick <= 4; $tick++): ?><span><?= $tick * $step ?></span><?php endfor; ?></div>
        </div>
        <ul class="chart-legend candidate-legend"><?php $i = 0; foreach ($seriesLabels as $label): ?><li><span class="legend-dot" style="background:<?= $colors[$i++ % count($colors)] ?>"></span><?= h($label) ?></li><?php endforeach; ?></ul>
        <?php if ($maximum === 0): ?><p class="hint">No records match this selection.</p><?php endif; ?>
        <details class="chart-details"><summary>View counts</summary><table><thead><tr><th><?= h($groupLabel) ?></th><?php foreach ($seriesLabels as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($groups as $group): ?><tr><td><?= h($group['label']) ?></td><?php foreach ($seriesLabels as $key => $label): ?><td><?= (int) ($group[$key] ?? 0) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></details>
    </figure>
    <?php
}

function dashboard_bars(string $id, string $title, array $values, array $links = []): void
{
    $max = max(1, ...array_values($values));
    ?>
    <figure class="dashboard-chart" aria-labelledby="<?= h($id) ?>-title">
        <figcaption id="<?= h($id) ?>-title"><?= h($title) ?></figcaption>
        <?php if (array_sum($values) === 0): ?><p class="hint">No records in this scope yet.</p><?php endif; ?>
        <div class="bar-chart">
            <?php $i = 0; foreach ($values as $label => $value): $i++; ?>
                <div class="bar-row">
                    <span><?= h($label) ?></span>
                    <svg viewBox="0 0 315 22" preserveAspectRatio="none" role="img" aria-label="<?= h($label) ?>: <?= (int) $value ?>">
                        <rect width="315" height="22" rx="4" fill="#F0F0F7"/>
                        <rect width="<?= round(315 * $value / $max, 2) ?>" height="22" rx="4" fill="<?= $i % 2 ? '#000058' : '#E2AF15' ?>"/>
                    </svg>
                    <strong><?= (int) $value ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <details class="chart-details"><summary>View values<?= $links ? ' and records' : '' ?></summary>
            <table><thead><tr><th>Category</th><th>Count</th></tr></thead><tbody>
            <?php foreach ($values as $label => $value): ?><tr><td><?php if (isset($links[$label])): ?><a href="<?= h($links[$label]) ?>"><?= h($label) ?></a><?php else: ?><?= h($label) ?><?php endif; ?></td><td><?= (int) $value ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </details>
    </figure>
    <?php
}

function dashboard_pie(string $id, string $title, array $values): void
{
    $total = array_sum($values);
    $angle = -M_PI / 2;
    $colors = ['#000058', '#E2AF15', '#B9B9CC'];
    ?>
    <figure class="dashboard-chart" aria-labelledby="<?= h($id) ?>-title">
        <figcaption id="<?= h($id) ?>-title"><?= h($title) ?></figcaption>
        <div class="donut-layout">
            <svg viewBox="0 0 180 180" role="img" aria-labelledby="<?= h($id) ?>-title">
                <circle cx="90" cy="90" r="76" fill="#F0F0F7"/>
                <?php $i = 0; foreach ($values as $label => $value): $color = $colors[$i++ % 3];
                    if ($total <= 0 || $value <= 0) continue;
                    $next = $angle + 2 * M_PI * $value / $total;
                    $tooltip = h($label) . ': ' . (int) $value . ' (' . round(100 * $value / $total, 1) . '%)';
                    if ($value === $total): ?>
                        <circle cx="90" cy="90" r="76" fill="<?= $color ?>"><title><?= $tooltip ?></title></circle>
                    <?php else: ?>
                        <path d="M90 90 L<?= 90 + 76 * cos($angle) ?> <?= 90 + 76 * sin($angle) ?> A76 76 0 <?= $value / $total > .5 ? 1 : 0 ?> 1 <?= 90 + 76 * cos($next) ?> <?= 90 + 76 * sin($next) ?> Z" fill="<?= $color ?>"><title><?= $tooltip ?></title></path>
                    <?php endif; $angle = $next;
                endforeach; ?>
            </svg>
            <ul class="chart-legend">
                <?php $i = 0; foreach ($values as $label => $value): ?><li><span class="legend-dot" style="background:<?= $colors[$i++ % 3] ?>"></span><span><?= h($label) ?></span><strong><?= (int) $value ?><?= $total > 0 ? ' (' . round(100 * $value / $total, 1) . '%)' : '' ?></strong></li><?php endforeach; ?>
            </ul>
        </div>
        <?php if ($total === 0): ?><p class="hint">No records in this scope yet.</p><?php endif; ?>
    </figure>
    <?php
}

function dashboard_donut(string $id, string $title, array $values, array $options = []): void
{
    $total = array_sum($values);
    $offset = 0;
    $colors = $options['colors'] ?? ['#E2AF15', '#000058', '#B9B9CC'];
    $referenceStyle = !empty($options['reference_style']);
    $radius = $referenceStyle ? 58 : 64;
    $thickness = $referenceStyle ? 36 : 22;
    ?>
    <figure class="dashboard-chart" aria-labelledby="<?= h($id) ?>-title">
        <figcaption id="<?= h($id) ?>-title"><?= h($title) ?></figcaption>
        <div class="donut-layout<?= $referenceStyle ? ' community-donut' : '' ?>">
            <svg viewBox="0 0 180 180" role="<?= $referenceStyle ? 'group' : 'img' ?>" aria-labelledby="<?= h($id) ?>-title">
                <circle cx="90" cy="90" r="<?= $radius ?>" fill="none" stroke="#F0F0F7" stroke-width="<?= $thickness ?>"/>
                <?php $i = 0; foreach ($values as $label => $value): $portion = $total > 0 ? $value / $total * 100 : 0;
                    $color = $colors[$i++ % count($colors)];
                    if ($value <= 0) continue;
                    ?>
                    <?php if ($referenceStyle): ?><g class="donut-segment" tabindex="0" role="img" data-chart-detail="<?= h($label) ?>: <?= (int) $value ?> (<?= round($portion, 1) ?>%)" aria-label="<?= h($label) ?>: <?= (int) $value ?> (<?= round($portion, 1) ?>%)" aria-describedby="<?= h($id) ?>-tooltip"><?php endif; ?>
                    <?php if ($referenceStyle && $portion < 100):
                        $start = ($offset / 100 * 2 * M_PI) - M_PI / 2;
                        $end = (($offset + $portion) / 100 * 2 * M_PI) - M_PI / 2;
                        $large = $portion > 50 ? 1 : 0;
                        $outer = $radius + $thickness / 2;
                        $inner = $radius - $thickness / 2;
                        $path = sprintf('M %.4f %.4f A %d %d 0 %d 1 %.4f %.4f L %.4f %.4f A %d %d 0 %d 0 %.4f %.4f Z',
                            90 + $outer * cos($start), 90 + $outer * sin($start), $outer, $outer, $large,
                            90 + $outer * cos($end), 90 + $outer * sin($end),
                            90 + $inner * cos($end), 90 + $inner * sin($end), $inner, $inner, $large,
                            90 + $inner * cos($start), 90 + $inner * sin($start));
                    ?>
                    <path d="<?= h($path) ?>" fill="<?= h($color) ?>" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"><title><?= h($label) ?>: <?= (int) $value ?> (<?= round($portion, 1) ?>%)</title></path>
                    <?php else: ?>
                    <circle cx="90" cy="90" r="<?= $radius ?>" fill="none" stroke="<?= h($color) ?>" stroke-width="<?= $thickness ?>" pathLength="100" stroke-dasharray="<?= $portion ?> <?= 100 - $portion ?>" stroke-dashoffset="<?= -$offset ?>" transform="rotate(-90 90 90)"><title><?= h($label) ?>: <?= (int) $value ?> (<?= round($portion, 1) ?>%)</title></circle>
                <?php endif; ?>
                <?php if ($referenceStyle): ?></g><?php endif; ?>
                <?php $offset += $portion; endforeach; ?>
                <text x="90" y="90" text-anchor="middle" class="donut-total"><?= (int) $total ?></text>
                <text x="90" y="110" text-anchor="middle" class="chart-label">Total</text>
            </svg>
            <ul class="chart-legend">
                <?php $i = 0; foreach ($values as $label => $value): ?><li title="<?= h($label) ?>: <?= (int) $value ?>"><span class="legend-dot" style="background:<?= h($colors[$i++ % count($colors)]) ?>"></span><span><?= h($label) ?></span><?php if (!$referenceStyle): ?><strong><?= (int) $value ?></strong><?php endif; ?></li><?php endforeach; ?>
            </ul>
            <?php if ($referenceStyle): ?><div class="chart-hover-detail" id="<?= h($id) ?>-tooltip" role="tooltip" hidden></div><?php endif; ?>
        </div>
        <?php if ($total === 0): ?><p class="hint">No records match these filters.</p><?php endif; ?>
    </figure>
    <?php
}

function dashboard_trend(array $labels, array $dl, array $pl): void
{
    if ($labels === []) {
        echo '<p class="empty">No official semester history is available for these filters.</p>';
        return;
    }
    $max = max(1, ...$dl, ...$pl);
    $count = count($labels);
    ?>
    <svg class="trend-svg" viewBox="0 0 800 320" role="img" aria-label="DL and PL population by semester">
        <?php for ($tick = 0; $tick <= 4; $tick++): $y = 260 - $tick * 55; ?>
            <line x1="48" y1="<?= $y ?>" x2="770" y2="<?= $y ?>" stroke="#E2E8F0"/>
            <text x="38" y="<?= $y + 5 ?>" text-anchor="end" class="chart-label"><?= round($max * $tick / 4, 1) ?></text>
        <?php endfor; ?>
        <?php foreach ([$dl, $pl] as $index => $series): $points = []; foreach ($series as $i => $value) { $points[] = (48 + ($count === 1 ? 361 : $i * 722 / ($count - 1))) . ',' . (260 - $value / $max * 220); } ?>
            <polyline points="<?= h(implode(' ', $points)) ?>" fill="none" stroke="<?= $index ? '#E2AF15' : '#000058' ?>" stroke-width="3"/>
            <?php foreach ($series as $i => $value): ?><circle cx="<?= 48 + ($count === 1 ? 361 : $i * 722 / ($count - 1)) ?>" cy="<?= 260 - $value / $max * 220 ?>" r="5" fill="<?= $index ? '#E2AF15' : '#000058' ?>"><title><?= h($labels[$i]) ?> — <?= $index ? 'PL' : 'DL' ?>: <?= (int) $value ?></title></circle><?php endforeach; ?>
        <?php endforeach; ?>
        <text x="48" y="294" class="chart-label"><?= h($labels[0]) ?></text>
        <?php if ($count > 1): ?><text x="770" y="294" text-anchor="end" class="chart-label"><?= h($labels[$count - 1]) ?></text><?php endif; ?>
    </svg>
    <ul class="chart-legend trend-legend"><li><span class="legend-dot" style="background:#000058"></span>Dean's List</li><li><span class="legend-dot" style="background:#E2AF15"></span>President's List</li></ul>
    <?php
}
