<?php
declare(strict_types=1);

function dashboard_population_data(?array $scope, int $program = 0, string $yearLevel = '', int $semester = 0): array
{
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
    if ($semester > 0) {
        $populationSql .= ' AND e.semester_id = ?';
        $populationParams[] = $semester;
    }
    $populationSql .= ' GROUP BY sem.id, ay.label, sem.name ORDER BY ay.label ASC, sem.id ASC';
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
    
    return compact('populationLabels', 'populationDl', 'populationPl', 'populationTable', 'dlMove', 'plMove');
}

function dashboard_population_chart(array $data): void
{
    ?>
    <div class="card-head"><h2><?= icon('analytics') ?> DL / PL Population Trends</h2>
        <span class="hint">DL <?= $data['dlMove'] > 0 ? '+' : '' ?><?= (int) $data['dlMove'] ?> · PL <?= $data['plMove'] > 0 ? '+' : '' ?><?= (int) $data['plMove'] ?></span>
    </div>
    <?php dashboard_trend($data['populationLabels'], $data['populationDl'], $data['populationPl']); ?>
    <?php if ($data['populationTable'] !== []): ?>
    <details class="chart-details"><summary>View semester counts</summary><div class="table-wrap">
        <table><thead><tr><th>Semester</th><th>DL Students</th><th>PL Students</th></tr></thead><tbody>
            <?php foreach ($data['populationTable'] as $row): ?><tr><td><?= h($row['semester']) ?></td><td><?= (int) $row['dl'] ?></td><td><?= (int) $row['pl'] ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div></details>
    <?php endif; ?>
    <?php
}
