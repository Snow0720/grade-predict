<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'analytics');

$program = (int) ($_GET['program'] ?? 0);
$yearLevel = (string) ($_GET['year_level'] ?? '');
$semester = (int) ($_GET['semester'] ?? 0);
$scope = scoped_program_ids($user);
if ($program > 0 && is_array($scope) && !in_array($program, $scope, true)) {
    $program = 0;
}

$sql = 'SELECT pa.id FROM professor_assignments pa
        JOIN subjects s ON s.id = pa.subject_id
        JOIN sections sec ON sec.id = pa.section_id
        WHERE 1 = 1';
$params = [];
if (is_array($scope)) {
    $sql .= $scope === [] ? ' AND 1 = 0' : ' AND s.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
}
if ($program > 0) {
    $sql .= ' AND s.program_id = ?';
    $params[] = $program;
}
if ($yearLevel !== '') {
    $sql .= ' AND sec.year_level = ?';
    $params[] = $yearLevel;
}
if ($semester > 0) {
    $sql .= ' AND pa.semester_id = ?';
    $params[] = $semester;
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$totalStudentsEvaluated = 0;
$overallPredictedSum = 0;

foreach ($ids as $id) {
    $book = build_gradebook((int) $id);
    foreach ($book['students'] as $student) {
        if ($student['predicted_percent'] !== null) {
            $overallPredictedSum += $student['predicted_percent'];
            $totalStudentsEvaluated++;
        }
    }
}

$dlplSql = "SELECT e.status, COUNT(*) AS total
            FROM dl_pl_evaluations e
            JOIN students st ON st.id = e.student_id
            WHERE e.basis = 'official'";
$dlplParams = [];
if (is_array($scope)) {
    if ($scope === []) {
        $dlplSql .= ' AND 1 = 0';
    } else {
        $dlplSql .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}
if ($program > 0) {
    $dlplSql .= ' AND st.program_id = ?';
    $dlplParams[] = $program;
}
$dlplSql .= ' GROUP BY e.status';
$dlplStmt = db()->prepare($dlplSql);
$dlplStmt->execute($dlplParams);
$dlplRows = $dlplStmt->fetchAll();
$dlplBuckets = ['President’s List' => 0, 'Dean’s List' => 0, 'Not Qualified' => 0];
foreach ($dlplRows as $row) {
    $status = strtolower((string) $row['status']);
    $total = (int) $row['total'];
    if (str_contains($status, 'possible pl') || str_contains($status, 'president')) {
        $dlplBuckets['President’s List'] += $total;
    } elseif (str_contains($status, 'possible dl') || str_contains($status, 'dean')) {
        $dlplBuckets['Dean’s List'] += $total;
    } else {
        $dlplBuckets['Not Qualified'] += $total;
    }
}

$populationSql = 'SELECT sem.id, ay.label AS year_label, sem.name AS semester_name,
        SUM(CASE
            WHEN e.status IN ("Possible DL", "Dean\'s List", "DL") THEN 1
            WHEN e.status = "Final Approved" AND rc.list_type = "DL" THEN 1
            ELSE 0
        END) AS dl_count,
        SUM(CASE
            WHEN e.status IN ("Possible PL", "President\'s List", "PL") THEN 1
            WHEN e.status = "Final Approved" AND rc.list_type = "PL" THEN 1
            ELSE 0
        END) AS pl_count
    FROM dl_pl_evaluations e
    JOIN students st ON st.id = e.student_id
    JOIN semesters sem ON sem.id = e.semester_id
    JOIN academic_years ay ON ay.id = sem.academic_year_id
    LEFT JOIN recognition_cases rc ON rc.student_id = e.student_id AND rc.semester_id = e.semester_id
    WHERE e.basis = "official"';
$populationParams = [];
if (is_array($scope)) {
    if ($scope === []) {
        $populationSql .= ' AND 1 = 0';
    } else {
        $populationSql .= ' AND st.program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
    }
}
if ($program > 0) {
    $populationSql .= ' AND st.program_id = ?';
    $populationParams[] = $program;
}
if ($yearLevel !== '') {
    $populationSql .= ' AND st.year_level = ?';
    $populationParams[] = $yearLevel;
}
$populationSql .= ' GROUP BY sem.id, ay.label, sem.name ORDER BY ay.label ASC, sem.name ASC';
$populationStmt = db()->prepare($populationSql);
$populationStmt->execute($populationParams);
$populationRows = $populationStmt->fetchAll();

$populationLabels = [];
$populationDl = [];
$populationPl = [];
$populationSample = false;
$qualifiedTotal = 0;
foreach ($populationRows as $row) {
    $dlCount = (int) $row['dl_count'];
    $plCount = (int) $row['pl_count'];
    $qualifiedTotal += $dlCount + $plCount;
    $populationLabels[] = trim((string) $row['semester_name'] . ' ' . (string) $row['year_label']);
    $populationDl[] = $dlCount;
    $populationPl[] = $plCount;
}
if ($qualifiedTotal === 0) {
    $populationSample = true;
    $populationLabels = [
        '1st Sem 2024–2025',
        '2nd Sem 2024–2025',
        '1st Sem 2025–2026',
        '2nd Sem 2025–2026',
        '1st Sem 2026–2027',
    ];
    $populationDl = [35, 42, 48, 55, 46];
    $populationPl = [12, 16, 20, 24, 18];
}
$populationTable = [];
foreach ($populationLabels as $index => $label) {
    $populationTable[] = [
        'semester' => $label,
        'dl' => $populationDl[$index],
        'pl' => $populationPl[$index],
    ];
}
$latestIndex = count($populationDl) - 1;
$previousIndex = $latestIndex - 1;
$dlMove = $previousIndex >= 0 ? $populationDl[$latestIndex] - $populationDl[$previousIndex] : 0;
$plMove = $previousIndex >= 0 ? $populationPl[$latestIndex] - $populationPl[$previousIndex] : 0;

$programs = programs_for_user($user, 'id, code');
$semesters = db()->query('SELECT sem.id, sem.name, ay.label FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id')->fetchAll();

$trendTone = static function (?float $delta): string {
    if ($delta === null || abs($delta) < 0.05) {
        return 'flat';
    }
    return $delta > 0 ? 'up' : 'down';
};

$chart = [
    'dlplTrendLabels' => $populationLabels,
    'dlplPl' => $populationPl,
    'dlplDl' => $populationDl,
    'populationSample' => $populationSample,
    'dlplLabels' => array_keys($dlplBuckets),
    'dlplValues' => array_values($dlplBuckets),
];

$totalFilteredAssignments = count($ids);
$totalDlPlQualifiers = $dlplBuckets['President’s List'] + $dlplBuckets['Dean’s List'];
$overallAveragePredicted = $totalStudentsEvaluated > 0 ? round($overallPredictedSum / $totalStudentsEvaluated, 2) : null;

layout_start('Analytics', 'analytics', [['label' => 'Analytics']]);
?>
<div class="page-head">
    <div>
        <h1>Analytics</h1>
        <p class="lede">Official DL and PL mix, and how many students qualified in each semester.</p>
    </div>
</div>

<section class="kpis" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
    <article class="kpi">
        <span>Predicted class average</span>
        <strong><?= $overallAveragePredicted === null ? 'N/A' : h(number_format($overallAveragePredicted, 2) . '%') ?></strong>
        <small><?= (int) $totalStudentsEvaluated ?> evaluated students</small>
    </article>
    <article class="kpi">
        <span>DL / PL qualified</span>
        <strong><?= (int) $totalDlPlQualifiers ?></strong>
        <small><?= (int) $dlplBuckets['Not Qualified'] ?> not qualified</small>
    </article>
    <article class="kpi">
        <span>Tracked sheets</span>
        <strong><?= (int) $totalFilteredAssignments ?></strong>
        <small>Filtered class sections</small>
    </article>
</section>

<section class="card" style="margin-bottom: 18px;">
    <form class="filters" method="get" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 180px;">
            <label for="program">Program</label>
            <select id="program" name="program" style="width: 100%;">
                <option value="0">All</option>
                <?php foreach ($programs as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= $program === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 180px;">
            <label for="year_level">Year level</label>
            <select id="year_level" name="year_level" style="width: 100%;">
                <option value="">All</option>
                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $year): ?>
                    <option value="<?= h($year) ?>" <?= $yearLevel === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label for="semester">Semester</label>
            <select id="semester" name="semester" style="width: 100%;">
                <option value="0">All</option>
                <?php foreach ($semesters as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= $semester === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['label'] . ' ' . $item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button class="btn btn-sm" type="submit"><?= icon('search') ?> Filter</button>
        </div>
    </form>
</section>

<section class="card chart-box chart-box-mix" style="margin-bottom: 18px;">
    <h2><?= icon('award') ?> Official DL / PL mix</h2>
    <p class="hint">Students qualified for PL, DL, or not qualified.</p>
    <div class="mix-chart">
        <canvas id="dlplChart"></canvas>
    </div>
</section>

<section class="card chart-box chart-box-wide" style="margin-bottom: 18px;">
    <div class="rate-chart-head">
        <div>
            <h2><?= icon('analytics') ?> DL / PL Population Trends</h2>
            <p class="hint">
                <?php if ($populationSample): ?>
                    SAMPLE / DEMO historical data. These counts are not student records.
                <?php else: ?>
                    Official Dean’s List and President’s List students in each semester.
                <?php endif; ?>
            </p>
        </div>
        <span class="trend-pill trend-<?= h($trendTone((float) ($dlMove + $plMove))) ?>">
            DL <?= $dlMove > 0 ? '+' : '' ?><?= (int) $dlMove ?> · PL <?= $plMove > 0 ? '+' : '' ?><?= (int) $plMove ?>
        </span>
    </div>
    <div style="height: 280px; position: relative;">
        <canvas id="dlplTrendChart"></canvas>
    </div>
    <div class="table-wrap" style="margin-top: 16px;">
        <table class="data">
            <thead>
                <tr>
                    <th>Semester</th>
                    <th>DL Students</th>
                    <th>PL Students</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($populationTable as $row): ?>
                    <tr>
                        <td><?= h($row['semester']) ?></td>
                        <td class="num"><?= (int) $row['dl'] ?></td>
                        <td class="num"><?= (int) $row['pl'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script type="application/json" id="chartData"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const raw = document.getElementById('chartData');
    if (!raw || !window.Chart) return;
    const data = JSON.parse(raw.textContent);

    new Chart(document.getElementById('dlplChart'), {
        type: 'doughnut',
        data: {
            labels: data.dlplLabels,
            datasets: [{
                data: data.dlplValues,
                backgroundColor: ['#C5A45B', '#2457D6', '#94A3B8']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    const plTrend = (data.dlplPl || []).map(Number);
    const dlTrend = (data.dlplDl || []).map(Number);
    new Chart(document.getElementById('dlplTrendChart'), {
        type: 'line',
        data: {
            labels: data.dlplTrendLabels || ['Prelim', 'Midterm', 'Finals'],
            datasets: [
                {
                    label: "Dean's List",
                    data: dlTrend,
                    borderColor: '#2457D6',
                    backgroundColor: '#2457D6',
                    borderWidth: 3,
                    tension: 0.35,
                    pointRadius: 5,
                    pointBackgroundColor: '#fff',
                    pointBorderWidth: 2
                },
                {
                    label: "President's List",
                    data: plTrend,
                    borderColor: '#16A34A',
                    backgroundColor: '#16A34A',
                    borderWidth: 3,
                    tension: 0.35,
                    pointRadius: 5,
                    pointBackgroundColor: '#fff',
                    pointBorderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        title: function (items) {
                            return items.length ? items[0].label : '';
                        },
                        label: function (ctx) {
                            const series = ctx.datasetIndex === 0 ? dlTrend : plTrend;
                            const v = series[ctx.dataIndex];
                            let move = '';
                            if (ctx.dataIndex > 0) {
                                const d = v - series[ctx.dataIndex - 1];
                                move = ' (' + (d > 0 ? '+' : '') + d + ')';
                            }
                            return ctx.dataset.label + ': ' + v + ' students' + move;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: 'rgba(100, 116, 139, .18)' }
                },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
<?php layout_end(); ?>
