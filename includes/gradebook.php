<?php
declare(strict_types=1);
require_once __DIR__ . '/student_feedback.php';

function load_scale(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    try {
        $fetched = db()->query(
            'SELECT id, min_percent, max_percent, equivalent, description, sort_order
             FROM grading_scale
             ORDER BY sort_order ASC, min_percent DESC'
        )->fetchAll();
        if ($fetched !== []) {
            return $rows = $fetched;
        }
    } catch (Throwable $e) {
        // Fall back to built-in scale if the table is missing.
    }
    return $rows = default_scale_rows();
}

function scale_range_label(array $band): string
{
    $min = (float) ($band['min_percent'] ?? 0);
    $max = (float) ($band['max_percent'] ?? 0);
    if ($min <= 0) {
        return 'Below ' . (string) ((int) floor($max + 0.01)) . '%';
    }
    $minShow = (int) round($min);
    $maxShow = abs($max - 100) < 0.001 ? 100 : (int) floor($max);
    return $minShow . ' – ' . $maxShow . '%';
}

function scale_remark_key(string $description): string
{
    $desc = strtolower(trim($description));
    if (str_contains($desc, 'fail')) {
        return 'FAILED';
    }
    if (str_contains($desc, 'pass') || str_contains($desc, 'fair')) {
        return 'INC';
    }
    if (str_contains($desc, 'excellent') || str_contains($desc, 'very superior')) {
        return 'PASSED';
    }
    if (str_contains($desc, 'good') || str_contains($desc, 'satisfactory')) {
        return 'Regular';
    }
    return 'assigned';
}

function save_grading_scale(array $user, array $rows): int
{
    if (($user['portal'] ?? '') !== 'admin' || !array_intersect($user['roles'], office_roles())) {
        throw new RuntimeException('Only college offices can edit the grade equivalent scale.', 403);
    }
    if ($rows === []) {
        throw new RuntimeException('Add at least one scale row.', 422);
    }

    $normalized = [];
    foreach ($rows as $index => $row) {
        $minRaw = trim((string) ($row['min_percent'] ?? ''));
        $maxRaw = trim((string) ($row['max_percent'] ?? ''));
        $eqvRaw = trim((string) ($row['equivalent'] ?? ''));
        $desc = trim((string) ($row['description'] ?? ''));
        if ($minRaw === '' && $maxRaw === '' && $eqvRaw === '' && $desc === '') {
            continue;
        }
        if (!is_numeric($minRaw) || !is_numeric($maxRaw) || !is_numeric($eqvRaw)) {
            throw new RuntimeException('Every scale row needs numeric min %, max %, and grade point.', 422);
        }
        if ($desc === '') {
            throw new RuntimeException('Every scale row needs a remark / description.', 422);
        }
        $min = round((float) $minRaw, 2);
        $max = round((float) $maxRaw, 2);
        $eqv = round((float) $eqvRaw, 2);
        if ($min < 0 || $max > 100 || $min > $max) {
            throw new RuntimeException('Percentage ranges must stay within 0–100 and min cannot exceed max.', 422);
        }
        if ($eqv < 1 || $eqv > 5) {
            throw new RuntimeException('Grade points must be between 1.00 and 5.00.', 422);
        }
        $normalized[] = [
            'min_percent' => $min,
            'max_percent' => $max,
            'equivalent' => $eqv,
            'description' => $desc,
            'sort_order' => $index + 1,
        ];
    }
    if ($normalized === []) {
        throw new RuntimeException('Add at least one scale row.', 422);
    }

    usort($normalized, static fn(array $a, array $b): int => $b['min_percent'] <=> $a['min_percent']);
    for ($i = 0; $i < count($normalized); $i++) {
        for ($j = $i + 1; $j < count($normalized); $j++) {
            $a = $normalized[$i];
            $b = $normalized[$j];
            if ($a['min_percent'] <= $b['max_percent'] && $b['min_percent'] <= $a['max_percent']) {
                throw new RuntimeException('Percentage ranges cannot overlap.', 422);
            }
        }
    }

    db()->beginTransaction();
    try {
        db()->exec('DELETE FROM grading_scale');
        $stmt = db()->prepare(
            'INSERT INTO grading_scale (min_percent, max_percent, equivalent, description, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($normalized as $i => $row) {
            $stmt->execute([
                $row['min_percent'],
                $row['max_percent'],
                $row['equivalent'],
                $row['description'],
                $i + 1,
            ]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit((int) $user['id'], 'scale_update', 'grading_scale', null, count($normalized) . ' bands saved');
    return count($normalized);
}

function load_rules(?int $semesterId = null): array
{
    $row = null;
    if ($semesterId !== null && $semesterId > 0) {
        $stmt = db()->prepare(
            'SELECT * FROM eligibility_rules
             WHERE is_active = 1 AND (semester_id = ? OR semester_id IS NULL)
             ORDER BY semester_id IS NULL, id DESC
             LIMIT 1'
        );
        $stmt->execute([$semesterId]);
        $row = $stmt->fetch();
    }
    if (!$row) {
        $row = db()->query('SELECT * FROM eligibility_rules WHERE is_active = 1 ORDER BY semester_id IS NULL, id DESC LIMIT 1')->fetch();
    }
    if (!$row) {
        return [
            'irregular_ineligible' => 0,
            'disqualify_at' => 2,
            'pl_min' => 1,
            'pl_max' => 1.25,
            'dl_min' => 1.26,
            'dl_max' => 1.75,
            'confirmed' => 0,
            'notes' => 'TEMPORARY DEMO ASSUMPTIONS',
        ];
    }
    return $row;
}

function professor_class_rows(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT pa.id, s.code AS subject_code, s.name AS subject_name, sec.name AS section_name, sec.year_level,
                p.code AS program_code, sem.name AS semester_name, ay.label AS year_label,
                sem.prelim_deadline, sem.midterm_deadline, sem.finals_deadline,
                gs.status, COUNT(e.id) AS student_count
         FROM professor_assignments pa
         JOIN professors pr ON pr.id = pa.professor_id
         JOIN subjects s ON s.id = pa.subject_id
         JOIN sections sec ON sec.id = pa.section_id
         JOIN programs p ON p.id = s.program_id
         JOIN semesters sem ON sem.id = pa.semester_id
         JOIN academic_years ay ON ay.id = sem.academic_year_id
         LEFT JOIN grade_submissions gs ON gs.assignment_id = pa.id
         LEFT JOIN enrollments e ON e.assignment_id = pa.id
         WHERE pr.user_id = ?
         GROUP BY pa.id, s.code, s.name, sec.name, sec.year_level, p.code, sem.name, ay.label,
                 sem.prelim_deadline, sem.midterm_deadline, sem.finals_deadline, gs.status
         ORDER BY ay.label DESC, s.code'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function offline_gradebook_urls(int $userId): array
{
    $urls = [
        url('professor/dashboard.php'),
        url('professor/classes.php'),
    ];
    $views = ['sheet', 'summary', 'final', 'settings', 'equivalent'];
    foreach (professor_class_rows($userId) as $class) {
        $id = (int) $class['id'];
        $urls[] = url('professor/class.php', ['id' => $id]);
        foreach ($views as $view) {
            foreach (array_keys(PERIODS) as $period) {
                $urls[] = url('professor/class.php', [
                    'id' => $id,
                    'view' => $view,
                    'period' => $period,
                ]);
            }
        }
    }
    return array_values(array_unique($urls));
}

function offline_admin_urls(array $user): array
{
    $urls = is_view_only_oic($user) ? [] : [url('admin/dashboard.php')];
    if (can($user, 'students_read')) {
        $urls[] = url('admin/students.php');
        $scope = scoped_program_ids($user);
        $sql = 'SELECT id FROM students';
        if (is_array($scope)) {
            if ($scope === []) {
                $sql = '';
            } else {
                $sql .= ' WHERE program_id IN (' . implode(',', array_map('intval', $scope)) . ')';
            }
        }
        if ($sql !== '') {
            $ids = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_slice($ids, 0, 100) as $id) {
                $urls[] = url('admin/student.php', ['id' => (int) $id]);
            }
        }
    }
    if (can($user, 'manage_faculty')) {
        $urls[] = url('admin/faculty.php');
    }
    if (can($user, 'manage_academics')) {
        $urls[] = url('admin/academics.php');
    }
    if (can($user, 'manage_users')) {
        $urls[] = url('admin/users.php');
    }
    $pages = [
        'queue' => 'admin/queue.php',
        'evaluations' => 'admin/evaluations.php',
        'recognition' => 'admin/recognition.php',
        'rules' => 'admin/rules.php',
        'settings' => 'admin/settings.php',
        'reports' => 'admin/reports.php',
        'scholarships' => 'admin/scholarships.php',
        'analytics' => 'admin/analytics.php',
        'audit' => 'admin/audit.php',
    ];
    foreach ($pages as $permission => $path) {
        if (can($user, $permission)) {
            $urls[] = url($path);
        }
    }
    return array_values(array_unique($urls));
}

function ensure_submitted_periods_column(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $exists = db()->query("SHOW COLUMNS FROM grade_submissions LIKE 'submitted_periods'")->fetch();
    if ($exists) {
        return;
    }
    db()->exec("ALTER TABLE grade_submissions ADD COLUMN submitted_periods VARCHAR(64) NOT NULL DEFAULT '' AFTER status");
    db()->exec("UPDATE grade_submissions SET submitted_periods = 'prelim,midterm,finals' WHERE status NOT IN ('draft', 'returned') AND submitted_periods = ''");
}

function ensure_percent_override_columns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $columns = [
        'prelim_percent_override' => 'DECIMAL(5,2) DEFAULT NULL',
        'midterm_percent_override' => 'DECIMAL(5,2) DEFAULT NULL',
        'finals_percent_override' => 'DECIMAL(5,2) DEFAULT NULL',
        'final_percent_override' => 'DECIMAL(5,2) DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        $exists = db()->query("SHOW COLUMNS FROM enrollments LIKE " . db()->quote($name))->fetch();
        if ($exists) {
            continue;
        }
        db()->exec("ALTER TABLE enrollments ADD COLUMN {$name} {$definition}");
    }
}

function normalize_percent_override(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        throw new RuntimeException('Enter a valid percent from 0 to 100.', 422);
    }
    $percent = round((float) $value, 2);
    if ($percent < 0 || $percent > 100) {
        throw new RuntimeException('Percent must be from 0 to 100.', 422);
    }
    return $percent;
}

function save_percent_overrides(array $user, int $assignmentId, array $rows): int
{
    ensure_percent_override_columns();
    if (($user['portal'] ?? '') !== 'admin' || !array_intersect($user['roles'], office_roles())) {
        throw new RuntimeException('Only college offices can edit grade percents.', 403);
    }
    $assignment = fetch_assignment($assignmentId);
    if (!$assignment || !user_can_view_assignment($assignment, $user)) {
        throw new RuntimeException('This class is outside your office scope.', 403);
    }
    if (assignment_is_finalized($assignment)) {
        throw new RuntimeException('Finalized grades are locked.', 403);
    }

    $updated = 0;
    $stmt = db()->prepare(
        'UPDATE enrollments
         SET prelim_percent_override = ?, midterm_percent_override = ?, finals_percent_override = ?, final_percent_override = ?
         WHERE id = ? AND assignment_id = ?'
    );
    foreach ($rows as $row) {
        $enrollmentId = (int) ($row['enrollment_id'] ?? 0);
        if ($enrollmentId <= 0) {
            continue;
        }
        $prelim = normalize_percent_override($row['prelim'] ?? null);
        $midterm = normalize_percent_override($row['midterm'] ?? null);
        $finals = normalize_percent_override($row['finals'] ?? null);
        $final = normalize_percent_override($row['final'] ?? null);
        $stmt->execute([$prelim, $midterm, $finals, $final, $enrollmentId, $assignmentId]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        }
    }
    audit(
        (int) $user['id'],
        'percent_override',
        'professor_assignment',
        (string) $assignmentId,
        'Updated office grade percents for ' . $updated . ' student(s)'
    );
    return $updated;
}

function submitted_period_keys(array $assignment): array
{
    $status = (string) ($assignment['submission_status'] ?? 'draft');
    if (in_array($status, ['draft', 'returned'], true)) {
        return [];
    }
    $raw = (string) ($assignment['submitted_periods'] ?? '');
    $keys = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $key): bool => $key !== ''));
    return array_values(array_intersect($keys, array_keys(PERIODS)));
}

function period_list_label(array $keys): string
{
    $labels = [];
    foreach (array_keys(PERIODS) as $key) {
        if (in_array($key, $keys, true)) {
            $labels[] = PERIODS[$key];
        }
    }
    $count = count($labels);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return $labels[0];
    }
    if ($count === 2) {
        return $labels[0] . ' and ' . $labels[1];
    }
    return implode(', ', array_slice($labels, 0, -1)) . ', and ' . $labels[$count - 1];
}

function fetch_assignment(int $id): ?array
{
    ensure_submitted_periods_column();
    $stmt = db()->prepare(
        'SELECT pa.*,
                s.code AS subject_code, s.name AS subject_name, s.year_level AS subject_year, s.program_id,
                p.code AS program_code, p.name AS program_name,
                sec.name AS section_name, sec.year_level,
                sem.name AS semester_name, sem.academic_year_id, sem.prelim_deadline, sem.midterm_deadline, sem.finals_deadline,
                ay.label AS year_label,
                u.id AS professor_user_id, u.full_name AS professor_name, u.title AS professor_title,
                gs.status AS submission_status, gs.id AS submission_id, gs.return_note, gs.flag_note,
                gs.submitted_periods, gs.submitted_at, gs.locked, gs.version
         FROM professor_assignments pa
         JOIN subjects s ON s.id = pa.subject_id
         JOIN programs p ON p.id = s.program_id
         JOIN sections sec ON sec.id = pa.section_id
         JOIN semesters sem ON sem.id = pa.semester_id
         JOIN academic_years ay ON ay.id = sem.academic_year_id
         JOIN professors pr ON pr.id = pa.professor_id
         JOIN users u ON u.id = pr.user_id
         LEFT JOIN grade_submissions gs ON gs.assignment_id = pa.id
         WHERE pa.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function default_category_percent(string $category): float
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db()->query('SELECT category, weight_percent FROM default_category_weights') as $row) {
            $map[$row['category']] = (float) $row['weight_percent'];
        }
    }
    return $map[$category] ?? 0.0;
}

function category_visible(array $book, string $period, string $category): bool
{
    $items = $book['items'][$period][$category] ?? [];
    $weight = (float) ($book['weights'][$period][$category] ?? 0);
    return $items !== [] || $weight > 0;
}

function period_unfilled(array $book, string $period): array
{
    $names = [];
    $weights = $book['weights'][$period] ?? [];
    if (($book['students'] ?? []) === []) {
        return ['No students are enrolled'];
    }
    foreach ($book['students'] as $student) {
        $missing = false;
        $columns = 0;
        foreach ($weights as $category => $weight) {
            if ((float) $weight <= 0) {
                continue;
            }
            $items = $student['periods'][$period]['categories'][$category]['items'] ?? [];
            if ($items === []) {
                $missing = true;
                continue;
            }
            foreach ($items as $item) {
                $columns++;
                if (!array_key_exists('score', $item) || $item['score'] === null || $item['score'] === '') {
                    $missing = true;
                }
            }
        }
        if ($columns === 0 || $missing) {
            $names[] = (string) $student['full_name'];
        }
    }
    return $names;
}

function period_gap_message(string $period, array $names): string
{
    $label = PERIODS[$period] ?? 'This period';
    $shown = array_slice($names, 0, 5);
    $extra = count($names) - count($shown);
    $who = implode(', ', $shown);
    if ($extra > 0) {
        $who .= ' and ' . $extra . ' more';
    }
    return $label . ' still has blank scores for ' . $who . '.';
}

function professor_owns_assignment(array $assignment, array $user): bool
{
    return in_array('professor', $user['roles'], true)
        && (int) $assignment['professor_user_id'] === (int) $user['id'];
}

function assignment_is_finalized(array $assignment): bool
{
    return (int) ($assignment['locked'] ?? 0) === 1
        || ($assignment['submission_status'] ?? '') === 'registrar_finalized';
}

function structure_is_editable(array $assignment, array $user): bool
{
    if (!professor_owns_assignment($assignment, $user) || assignment_is_finalized($assignment)) {
        return false;
    }
    return in_array($assignment['submission_status'] ?? 'draft', ['draft', 'returned'], true);
}

function period_is_editable(array $assignment, array $user, string $period): bool
{
    if (!isset(PERIODS[$period]) || !professor_owns_assignment($assignment, $user) || assignment_is_finalized($assignment)) {
        return false;
    }
    if (in_array($assignment['submission_status'] ?? 'draft', ['draft', 'returned'], true)) {
        return true;
    }
    return !in_array($period, submitted_period_keys($assignment), true);
}

function assignment_is_editable(array $assignment, array $user): bool
{
    if (!professor_owns_assignment($assignment, $user) || assignment_is_finalized($assignment)) {
        return false;
    }
    foreach (array_keys(PERIODS) as $period) {
        if (period_is_editable($assignment, $user, $period)) {
            return true;
        }
    }
    return false;
}

function user_can_view_assignment(array $assignment, array $user): bool
{
    if (in_array('professor', $user['roles'], true) && (int) $assignment['professor_user_id'] === (int) $user['id'] && ($user['portal'] ?? '') === 'professor') {
        return true;
    }
    if (($user['portal'] ?? '') !== 'admin') {
        return false;
    }
    return (bool) array_intersect($user['roles'], office_roles());
}

function build_gradebook(int $assignmentId): array
{
    static $cache = [];
    if (isset($cache[$assignmentId])) {
        return $cache[$assignmentId];
    }
    $assignment = fetch_assignment($assignmentId);
    if (!$assignment) {
        return [];
    }
    $scale = load_scale();
    $weights = [];
    $wstmt = db()->prepare('SELECT period, category, weight_percent FROM category_weights WHERE assignment_id = ?');
    $wstmt->execute([$assignmentId]);
    foreach ($wstmt as $row) {
        $weights[$row['period']][$row['category']] = (float) $row['weight_percent'];
    }
    $periodWeights = [];
    $pstmt = db()->prepare('SELECT period, weight_percent FROM period_weights WHERE assignment_id = ?');
    $pstmt->execute([$assignmentId]);
    foreach ($pstmt as $row) {
        $periodWeights[$row['period']] = (float) $row['weight_percent'];
    }
    foreach (array_keys(PERIODS) as $period) {
        if (!isset($periodWeights[$period])) {
            $periodWeights[$period] = 0.0;
        }
        foreach (array_keys(CATEGORIES) as $category) {
            if (!isset($weights[$period][$category])) {
                $weights[$period][$category] = 0.0;
            }
        }
    }

    $items = [];
    $istmt = db()->prepare(
        'SELECT * FROM assessment_components WHERE assignment_id = ? ORDER BY period, sort_order, id'
    );
    $istmt->execute([$assignmentId]);
    foreach ($istmt as $row) {
        $items[$row['period']][$row['category']][] = $row;
    }

    ensure_percent_override_columns();
    ensure_student_auth_columns();
    $studentsStmt = db()->prepare(
        'SELECT e.id AS enrollment_id, st.id AS student_id, st.student_number, st.full_name, st.student_type, st.is_demo,
                p.code AS program_code, st.year_level, e.grades_visible,
                e.prelim_percent_override, e.midterm_percent_override, e.finals_percent_override, e.final_percent_override
         FROM enrollments e
         JOIN students st ON st.id = e.student_id
         JOIN programs p ON p.id = st.program_id
         WHERE e.assignment_id = ?
         ORDER BY st.full_name'
    );
    $studentsStmt->execute([$assignmentId]);
    $students = $studentsStmt->fetchAll();

    $scoreMap = [];
    $scoreStmt = db()->prepare(
        'SELECT sc.component_id, sc.enrollment_id, sc.score, sc.mark
         FROM assessment_scores sc
         JOIN assessment_components c ON c.id = sc.component_id
         WHERE c.assignment_id = ?'
    );
    $scoreStmt->execute([$assignmentId]);
    foreach ($scoreStmt as $row) {
        $scoreMap[$row['enrollment_id']][$row['component_id']] = [
            'score' => $row['score'],
            'mark' => $row['mark'],
        ];
    }

    $adjustments = [];
    $adjStmt = db()->prepare('SELECT enrollment_id, period, adjustment FROM score_adjustments WHERE assignment_id = ?');
    $adjStmt->execute([$assignmentId]);
    foreach ($adjStmt as $row) {
        $adjustments[$row['enrollment_id']][$row['period']] = (float) $row['adjustment'];
    }

    $builtStudents = [];
    foreach ($students as $index => $student) {
        $builtStudents[] = assemble_student(
            $student,
            $index + 1,
            $items,
            $scoreMap[$student['enrollment_id']] ?? [],
            $weights,
            $periodWeights,
            $adjustments[$student['enrollment_id']] ?? [],
            $scale
        );
    }

    return $cache[$assignmentId] = [
        'assignment' => $assignment,
        'students' => $builtStudents,
        'weights' => $weights,
        'period_weights' => $periodWeights,
        'items' => $items,
        'scale' => $scale,
    ];
}

function assemble_student(
    array $student,
    int $number,
    array $items,
    array $scores,
    array $weights,
    array $periodWeights,
    array $adjustments,
    array $scale
): array {
    $periods = [];
    foreach (array_keys(PERIODS) as $period) {
        $categories = [];
        $parts = [];
        foreach (array_keys(CATEGORIES) as $category) {
            $catItems = [];
            foreach ($items[$period][$category] ?? [] as $item) {
                $entry = $scores[$item['id']] ?? null;
                $raw = is_array($entry) ? $entry['score'] : $entry;
                $mark = is_array($entry) ? ($entry['mark'] ?? null) : null;
                $catItems[] = [
                    'id' => (int) $item['id'],
                    'code' => $item['code'],
                    'label' => $item['label'],
                    'category' => $category,
                    'max_score' => (float) $item['max_score'],
                    'item_date' => $item['item_date'],
                    'score' => $raw === null || $raw === '' ? null : (float) $raw,
                    'mark' => $mark,
                ];
            }
            $percent = category_percent($catItems);
            $categories[$category] = [
                'weight' => (float) ($weights[$period][$category] ?? 0),
                'percent' => $percent,
                'equivalent' => lookup_equivalent($percent, $scale),
                'items' => $catItems,
            ];
            $parts[$category] = $percent;
        }
        $adjustment = (float) ($adjustments[$period] ?? 0);
        $result = weighted_percent($parts, $weights[$period] ?? [], $adjustment);
        $filledScores = 0;
        $blankScores = 0;
        foreach ($categories as $category => $data) {
            if ((float) ($weights[$period][$category] ?? 0) <= 0 && ($data['items'] ?? []) === []) {
                continue;
            }
            foreach ($data['items'] as $item) {
                if (!array_key_exists('score', $item) || $item['score'] === null || $item['score'] === '') {
                    $blankScores++;
                    continue;
                }
                $filledScores++;
            }
        }
        $started = $filledScores > 0;
        $slotsComplete = $filledScores > 0 && $blankScores === 0;
        $complete = $result['complete'] && $slotsComplete;
        $equivalent = lookup_equivalent($complete ? $result['percent'] : null, $scale);
        $periods[$period] = [
            'categories' => $categories,
            'adjustment' => $adjustment,
            'percent' => $result['percent'],
            'base' => $result['base'],
            'equivalent' => $equivalent,
            'complete' => $complete,
            'started' => $started,
            'missing' => $result['missing'],
            'weight_total' => $result['weight_total'],
            'remarks' => grade_remarks($equivalent, $complete, $started),
        ];
    }

    foreach (array_keys(PERIODS) as $period) {
        $overrideKey = $period . '_percent_override';
        $periods[$period]['computed_percent'] = $periods[$period]['percent'];
        $periods[$period]['computed_equivalent'] = $periods[$period]['equivalent'];
        $periods[$period]['percent_overridden'] = false;
        if (($student[$overrideKey] ?? null) !== null && $student[$overrideKey] !== '') {
            $overridePercent = round((float) $student[$overrideKey], 2);
            $periods[$period]['percent'] = $overridePercent;
            $periods[$period]['equivalent'] = lookup_equivalent($overridePercent, $scale);
            $periods[$period]['percent_overridden'] = true;
            $periods[$period]['complete'] = true;
            $periods[$period]['remarks'] = grade_remarks($periods[$period]['equivalent'], true);
        }
    }

    $periodParts = [];
    foreach ($periods as $key => $period) {
        $periodParts[$key] = $period['percent'];
    }
    $final = weighted_percent($periodParts, $periodWeights, 0);
    $computedFinalPercent = $final['percent'];
    $finalPercent = $computedFinalPercent;
    $finalOverridden = false;
    if ($student['final_percent_override'] !== null && $student['final_percent_override'] !== '') {
        $finalPercent = round((float) $student['final_percent_override'], 2);
        $finalOverridden = true;
    }
    $finalEqv = lookup_equivalent($finalPercent, $scale);

    $featureAverages = [];
    foreach (['attendance', 'recitation', 'activities', 'quizzes', 'project', 'exam'] as $feature) {
        $vals = [];
        foreach ($periods as $period) {
            if (($period['categories'][$feature]['percent'] ?? null) !== null) {
                $vals[] = $period['categories'][$feature]['percent'];
            }
        }
        $featureAverages[$feature] = $vals === [] ? null : round(array_sum($vals) / count($vals), 2);
    }
    $predictedPercent = null;
    $predictedEqv = null;
    if (!in_array(null, $featureAverages, true)) {
        $predictedPercent = preserved_linear_prediction($featureAverages);
        if ($predictedPercent !== null) {
            $predictedEqv = lookup_equivalent($predictedPercent, $scale);
        }
    }

    $finalStarted = false;
    $periodsReady = 0;
    $periodsExpected = 0;
    foreach ($periods as $key => $period) {
        if ((float) ($periodWeights[$key] ?? 0) <= 0) {
            continue;
        }
        $periodsExpected++;
        if (!empty($period['started'])) {
            $finalStarted = true;
        }
        if (!empty($period['complete'])) {
            $periodsReady++;
        }
    }
    $finalComplete = $finalOverridden || ($periodsExpected > 0 && $periodsReady === $periodsExpected && $final['complete']);

    return [
        'number' => $number,
        'enrollment_id' => (int) $student['enrollment_id'],
        'student_id' => (int) $student['student_id'],
        'student_number' => $student['student_number'],
        'full_name' => $student['full_name'],
        'student_type' => $student['student_type'],
        'is_demo' => (int) ($student['is_demo'] ?? 0),
        'program_code' => $student['program_code'],
        'year_level' => $student['year_level'],
        'grades_visible' => (int) ($student['grades_visible'] ?? 0) === 1,
        'periods' => $periods,
        'final_percent' => $finalPercent,
        'computed_final_percent' => $computedFinalPercent,
        'final_equivalent' => $finalEqv,
        'final_percent_overridden' => $finalOverridden,
        'final_complete' => $finalComplete,
        'final_missing' => $finalOverridden ? [] : $final['missing'],
        'remarks' => $finalOverridden
            ? grade_remarks($finalEqv, true, true)
            : grade_remarks($finalComplete ? $finalEqv : null, $finalComplete, $finalStarted),
        'features' => $featureAverages,
        'predicted_percent' => $predictedPercent,
        'predicted_equivalent' => $predictedEqv,
        'percent_overrides' => [
            'prelim' => $student['prelim_percent_override'] !== null && $student['prelim_percent_override'] !== '' ? (float) $student['prelim_percent_override'] : null,
            'midterm' => $student['midterm_percent_override'] !== null && $student['midterm_percent_override'] !== '' ? (float) $student['midterm_percent_override'] : null,
            'finals' => $student['finals_percent_override'] !== null && $student['finals_percent_override'] !== '' ? (float) $student['finals_percent_override'] : null,
            'final' => $student['final_percent_override'] !== null && $student['final_percent_override'] !== '' ? (float) $student['final_percent_override'] : null,
        ],
    ];
}

function student_payload(array $student): array
{
    $periods = [];
    foreach ($student['periods'] as $key => $period) {
        $cats = [];
        foreach ($period['categories'] as $cat => $data) {
            $cats[$cat] = [
                'percent' => $data['percent'],
                'equivalent' => $data['equivalent'],
            ];
        }
        $periods[$key] = [
            'percent' => $period['percent'],
            'equivalent' => $period['equivalent'],
            'complete' => $period['complete'],
            'remarks' => $period['remarks'],
            'categories' => $cats,
            'weight_total' => $period['weight_total'],
        ];
    }
    return [
        'enrollment_id' => $student['enrollment_id'],
        'periods' => $periods,
        'final_percent' => $student['final_percent'],
        'final_equivalent' => $student['final_equivalent'],
        'remarks' => $student['remarks'],
        'predicted_percent' => $student['predicted_percent'],
        'predicted_equivalent' => $student['predicted_equivalent'],
    ];
}

function find_student_row(array $book, int $enrollmentId): ?array
{
    if (empty($book['students'])) {
        return null;
    }
    foreach ($book['students'] as $student) {
        if ((int) $student['enrollment_id'] === $enrollmentId) {
            return $student;
        }
    }
    return null;
}

function provision_assignment(int $assignmentId): void
{
    $pdo = db();
    $defaults = $pdo->query('SELECT category, weight_percent FROM default_category_weights')->fetchAll();
    $periodDefaults = [
        'prelim' => (float) setting('period_prelim', '30'),
        'midterm' => (float) setting('period_midterm', '30'),
        'finals' => (float) setting('period_finals', '40'),
    ];
    $insertWeight = $pdo->prepare(
        'INSERT INTO category_weights (assignment_id, period, category, weight_percent) VALUES (?, ?, ?, ?)'
    );
    $insertPeriod = $pdo->prepare(
        'INSERT INTO period_weights (assignment_id, period, weight_percent) VALUES (?, ?, ?)'
    );
    $insertItem = $pdo->prepare(
        'INSERT INTO assessment_components (assignment_id, period, category, code, label, max_score, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach (array_keys(PERIODS) as $period) {
        $insertPeriod->execute([$assignmentId, $period, $periodDefaults[$period]]);
        foreach ($defaults as $row) {
            $insertWeight->execute([$assignmentId, $period, $row['category'], $row['weight_percent']]);
        }
        foreach (default_component_template() as $item) {
            $insertItem->execute([
                $assignmentId,
                $period,
                $item['category'],
                $item['code'],
                $item['label'],
                $item['max_score'],
                $item['sort_order'],
            ]);
        }
    }
    $pdo->prepare('INSERT INTO grade_submissions (assignment_id, status) VALUES (?, "draft")')->execute([$assignmentId]);
}

function ensure_student_adviser_column(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $exists = db()->query("SHOW COLUMNS FROM students LIKE 'adviser_id'")->fetch();
    if ($exists) {
        return;
    }
    db()->exec('ALTER TABLE students ADD COLUMN adviser_id INT UNSIGNED DEFAULT NULL AFTER section_id');
    db()->exec('ALTER TABLE students ADD KEY idx_students_adviser (adviser_id)');
    $fk = db()->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND CONSTRAINT_NAME = 'fk_students_adviser'"
    )->fetch();
    if (!$fk) {
        db()->exec('ALTER TABLE students ADD CONSTRAINT fk_students_adviser FOREIGN KEY (adviser_id) REFERENCES professors (id)');
    }
}

function faculty_masterlist(): array
{
    ensure_student_adviser_column();
    return db()->query(
        'SELECT pr.id AS professor_id, u.full_name, u.title, u.is_active,
                GROUP_CONCAT(DISTINCT CONCAT(p.code, " ", sec.year_level, " ", sec.name) ORDER BY p.code, sec.name SEPARATOR ", ") AS sections_label
         FROM professors pr
         JOIN users u ON u.id = pr.user_id
         LEFT JOIN professor_assignments pa ON pa.professor_id = pr.id
         LEFT JOIN sections sec ON sec.id = pa.section_id
         LEFT JOIN programs p ON p.id = sec.program_id
         GROUP BY pr.id, u.full_name, u.title, u.is_active
         ORDER BY u.is_active DESC, u.full_name'
    )->fetchAll();
}

function professor_choice_label(array $person): string
{
    $name = trim((string) ($person['title'] ?? '') . ' ' . (string) ($person['full_name'] ?? ''));
    $sections = trim((string) ($person['sections_label'] ?? ''));
    if ($sections !== '') {
        $name .= ' — ' . $sections;
    }
    if (array_key_exists('is_active', $person) && (int) $person['is_active'] !== 1) {
        $name .= ' (inactive)';
    }
    return $name;
}

function enroll_student_for_professor(int $studentId, int $professorId, int $sectionId): int
{
    if ($studentId <= 0 || $professorId <= 0 || $sectionId <= 0) {
        return 0;
    }
    $count = db()->prepare(
        'SELECT COUNT(*)
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         WHERE e.student_id = ? AND pa.professor_id = ? AND pa.section_id = ?'
    );
    $count->execute([$studentId, $professorId, $sectionId]);
    $before = (int) $count->fetchColumn();
    db()->prepare(
        'INSERT IGNORE INTO enrollments (assignment_id, student_id)
         SELECT pa.id, ?
         FROM professor_assignments pa
         WHERE pa.professor_id = ? AND pa.section_id = ?'
    )->execute([$studentId, $professorId, $sectionId]);
    $count->execute([$studentId, $professorId, $sectionId]);
    return (int) $count->fetchColumn() - $before;
}

function enroll_section(int $assignmentId): int
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO enrollments (assignment_id, student_id)
         SELECT pa.id, st.id
         FROM professor_assignments pa
         JOIN sections sec ON sec.id = pa.section_id
         JOIN students st ON st.section_id = sec.id AND st.program_id = sec.program_id
         WHERE pa.id = ?'
    );
    $stmt->execute([$assignmentId]);
    return $stmt->rowCount();
}

function save_one_score(array $user, array $post): array
{
    $kind = (string) ($post['kind'] ?? 'score');
    if ($kind === 'adjustment') {
        throw new RuntimeException('Adjustments are not used on this sheet.', 422);
    }
    $enrollmentId = (int) ($post['enrollment_id'] ?? 0);
    $enroll = db()->prepare(
        'SELECT e.id, e.assignment_id, pa.professor_id, pr.user_id
         FROM enrollments e
         JOIN professor_assignments pa ON pa.id = e.assignment_id
         JOIN professors pr ON pr.id = pa.professor_id
         WHERE e.id = ?'
    );
    $enroll->execute([$enrollmentId]);
    $enroll = $enroll->fetch();
    if (!$enroll || (int) $enroll['user_id'] !== (int) $user['id']) {
        throw new RuntimeException('That student is not in your class.', 403);
    }
    $assignment = fetch_assignment((int) $enroll['assignment_id']);
    if (!$assignment || assignment_is_finalized($assignment) || (int) $assignment['professor_user_id'] !== (int) $user['id']) {
        throw new RuntimeException('This sheet is locked. You can edit it again if it is returned.', 403);
    }
    $pdo = db();
    $componentId = (int) ($post['component_id'] ?? 0);
    $component = $pdo->prepare('SELECT * FROM assessment_components WHERE id = ? AND assignment_id = ?');
    $component->execute([$componentId, (int) $enroll['assignment_id']]);
    $component = $component->fetch();
    if (!$component) {
        throw new RuntimeException('That column is not on this sheet.', 422);
    }
    $componentPeriod = (string) ($component['period'] ?? '');
    if (!period_is_editable($assignment, $user, $componentPeriod)) {
        throw new RuntimeException((PERIODS[$componentPeriod] ?? 'This period') . ' is already submitted. You can edit it again if it is returned.', 403);
    }
    $raw = trim((string) ($post['score'] ?? ''));
    $score = null;
    $mark = null;
    if (($component['category'] ?? '') === 'attendance' && array_key_exists('mark', $post)) {
        $choice = trim((string) ($post['mark'] ?? ''));
        if ($choice !== '' && !isset(attendance_choices()[$choice])) {
            throw new RuntimeException('Choose Present, Absent, Late, or Excuse.', 422);
        }
        $mark = $choice === '' ? null : $choice;
        $score = attendance_score($choice, (float) $component['max_score']);
    } elseif ($raw !== '') {
        if (!preg_match('/^\d+$/', $raw)) {
            throw new RuntimeException('Enter a whole number, or leave the cell blank.', 422);
        }
        $score = (float) (int) $raw;
        $max = (float) $component['max_score'];
        if ($score < 0 || $score > $max) {
            throw new RuntimeException('The score must be from 0 to ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.', 422);
        }
    }
    $pdo->prepare(
        'INSERT INTO assessment_scores (component_id, enrollment_id, score, mark)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), mark = VALUES(mark)'
    )->execute([$componentId, $enrollmentId, $score, $mark]);
    audit((int) $user['id'], 'grade_edit', 'assessment_score', (string) $componentId, 'Enrollment ' . $enrollmentId . ' = ' . ($score === null ? 'blank' : (string) $score));
    return [
        'assignment_id' => (int) $enroll['assignment_id'],
        'enrollment_id' => $enrollmentId,
    ];
}

function student_subject_risk_explanation(string $subject, string $status, ?float $finalEqv, array $periods, float $disqualifyLine): string
{
    $feedback = student_subject_feedback($subject, $status, $finalEqv, $periods, $disqualifyLine);
    return $feedback['description'] . ' ' . $feedback['recommendation'];
}

function student_plain_risk(string $label): string
{
    $text = strtolower($label);
    if (str_contains($text, 'not at risk') || str_contains($text, 'good standing') || str_contains($text, 'safe') || str_contains($text, 'on track')) {
        return 'Not at risk';
    }
    if (str_contains($text, 'at risk') || str_contains($text, 'disqual') || str_contains($text, 'fail')) {
        return 'At risk';
    }
    if (str_contains($text, 'no enrollment')) {
        return 'No enrollments';
    }
    return 'Pending';
}

function set_class_grade_visibility(int $assignmentId, string $mode, array $visibleIds): int
{
    ensure_student_auth_columns();
    $rows = db()->prepare('SELECT id FROM enrollments WHERE assignment_id = ?');
    $rows->execute([$assignmentId]);
    $ids = array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        return 0;
    }
    $allowed = array_values(array_intersect(array_map('intval', $visibleIds), $ids));
    $shown = match ($mode) {
        'all' => $ids,
        'none' => [],
        default => $allowed,
    };
    $update = db()->prepare('UPDATE enrollments SET grades_visible = ? WHERE id = ? AND assignment_id = ?');
    foreach ($ids as $enrollmentId) {
        $update->execute([in_array($enrollmentId, $shown, true) ? 1 : 0, $enrollmentId, $assignmentId]);
    }
    return count($shown);
}

function toggle_student_grade_visibility(int $assignmentId, int $enrollmentId): bool
{
    ensure_student_auth_columns();
    $stmt = db()->prepare('SELECT grades_visible FROM enrollments WHERE id = ? AND assignment_id = ?');
    $stmt->execute([$enrollmentId, $assignmentId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        throw new RuntimeException('That student is not in this class.');
    }
    $visible = (int) $current === 1 ? 0 : 1;
    db()->prepare('UPDATE enrollments SET grades_visible = ? WHERE id = ? AND assignment_id = ?')
        ->execute([$visible, $enrollmentId, $assignmentId]);
    return $visible === 1;
}

function student_grades_summary(int $studentId, ?int $semesterId = null): array
{
    ensure_student_auth_columns();
    $rules = load_rules($semesterId);
    $disqualifyLine = (float) ($rules['disqualify_at'] ?? 2.0);
    $dlMax = (float) ($rules['dl_max'] ?? 1.75);

    $sql = 'SELECT e.id AS enrollment_id, pa.id AS assignment_id, pa.semester_id,
                   s.id AS subject_id, s.code AS subject_code, s.name AS subject_name,
                   sec.name AS section_name, sec.year_level,
                   u.full_name AS professor_name, u.title AS professor_title,
                   gs.status AS submission_status,
                   og.final_percent AS official_percent,
                   og.final_eqv AS official_eqv,
                   og.remarks AS official_remarks,
                   sem.name AS semester_name,
                   e.grades_visible
            FROM enrollments e
            JOIN professor_assignments pa ON pa.id = e.assignment_id
            JOIN subjects s ON s.id = pa.subject_id
            JOIN sections sec ON sec.id = pa.section_id
            JOIN professors pr ON pr.id = pa.professor_id
            JOIN users u ON u.id = pr.user_id
            JOIN semesters sem ON sem.id = pa.semester_id
            LEFT JOIN grade_submissions gs ON gs.assignment_id = pa.id
            LEFT JOIN official_grades og ON og.enrollment_id = e.id
            WHERE e.student_id = ?';
    $params = [$studentId];
    if ($semesterId !== null && $semesterId > 0) {
        $sql .= ' AND pa.semester_id = ?';
        $params[] = $semesterId;
    }
    $sql .= ' ORDER BY s.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $enrollments = $stmt->fetchAll();

    $classes = [];
    $equivalentsForGwa = [];
    $atRiskSubjects = [];
    $failingSubjects = [];
    $passedCount = 0;
    $failedCount = 0;
    $inProgressCount = 0;
    $releasedCount = 0;

    foreach ($enrollments as $enr) {
        $enrollmentId = (int) $enr['enrollment_id'];
        $assignmentId = (int) $enr['assignment_id'];

        $isOfficial = $enr['official_eqv'] !== null;
        $finalPercent = null;
        $finalEqv = null;
        $remarks = '';
        $periods = [];

        if ($isOfficial) {
            $finalPercent = $enr['official_percent'] !== null ? (float) $enr['official_percent'] : null;
            $finalEqv = (float) $enr['official_eqv'];
            $remarks = (string) $enr['official_remarks'];
        }

        try {
            $book = build_gradebook($assignmentId);
            $sRow = find_student_row($book, $enrollmentId);
            if ($sRow) {
                $periods = $sRow['periods'] ?? [];
                if (!$isOfficial) {
                    $finalPercent = $sRow['final_percent'] !== null ? (float) $sRow['final_percent'] : null;
                    $finalEqv = $sRow['final_equivalent'] !== null ? (float) $sRow['final_equivalent'] : null;
                    $remarks = (string) ($sRow['remarks'] ?? '');
                }
            }
        } catch (Throwable $e) {
            error_log('student_grades_summary: ' . $e->getMessage());
        }

        // Passing status
        $passingStatus = 'In Progress';
        $passingBadge = 'info';
        if ($finalEqv !== null) {
            if ($finalEqv <= 3.0) {
                $passingStatus = 'PASSED';
                $passingBadge = 'ok';
                $passedCount++;
            } else {
                $passingStatus = 'FAILED';
                $passingBadge = 'bad';
                $failedCount++;
                $failingSubjects[] = $enr['subject_code'];
            }
        } else {
            $hasFailPeriod = false;
            $hasAnyScores = false;
            foreach ($periods as $pKey => $pData) {
                if (!empty($pData['started'])) {
                    $hasAnyScores = true;
                }
                if (($pData['equivalent'] !== null && (float) $pData['equivalent'] > 3.0) || ($pData['remarks'] ?? '') === 'FAILED') {
                    $hasFailPeriod = true;
                }
            }
            if ($hasFailPeriod) {
                $passingStatus = 'Failing (At Risk)';
                $passingBadge = 'bad';
                $failingSubjects[] = $enr['subject_code'];
            } elseif ($hasAnyScores) {
                $passingStatus = 'In Progress (Passing)';
                $passingBadge = 'info';
                $inProgressCount++;
            } else {
                $passingStatus = 'Pending';
                $passingBadge = 'muted';
                $inProgressCount++;
            }
        }

        // Honor risk per subject
        $subjectHonorRisk = 'Pending';
        $subjectHonorBadge = 'muted';
        $subjectHonorNote = '';

        if ($finalEqv !== null) {
            $equivalentsForGwa[] = $finalEqv;
            if ($finalEqv > 3.0) {
                $subjectHonorRisk = 'Disqualified (Failed)';
                $subjectHonorBadge = 'bad';
                $subjectHonorNote = 'Failing grade disqualifies from honor standing.';
                $atRiskSubjects[] = [
                    'code' => $enr['subject_code'],
                    'grade' => number_format($finalEqv, 2),
                    'reason' => 'Subject ' . $enr['subject_code'] . ' has a failing grade (' . number_format($finalEqv, 2) . ').',
                ];
            } elseif ($finalEqv >= $disqualifyLine) {
                $subjectHonorRisk = 'At Risk (Grade >= ' . number_format($disqualifyLine, 2) . ')';
                $subjectHonorBadge = 'warn';
                $subjectHonorNote = 'Grade of ' . number_format($finalEqv, 2) . ' meets or exceeds the ' . number_format($disqualifyLine, 2) . ' disqualification line.';
                $atRiskSubjects[] = [
                    'code' => $enr['subject_code'],
                    'grade' => number_format($finalEqv, 2),
                    'reason' => 'Subject ' . $enr['subject_code'] . ' has a grade of ' . number_format($finalEqv, 2) . ' (meets or exceeds the ' . number_format($disqualifyLine, 2) . ' disqualification threshold).',
                ];
            } else {
                $subjectHonorRisk = 'Safe (< ' . number_format($disqualifyLine, 2) . ')';
                $subjectHonorBadge = 'ok';
                $subjectHonorNote = 'Meets honor criteria.';
            }
        } else {
            $periodRisk = false;
            foreach ($periods as $pName => $pData) {
                if ($pData['equivalent'] !== null && (float) $pData['equivalent'] >= $disqualifyLine) {
                    $periodRisk = true;
                    $atRiskSubjects[] = [
                        'code' => $enr['subject_code'],
                        'grade' => number_format((float) $pData['equivalent'], 2) . ' (' . ucfirst($pName) . ')',
                        'reason' => 'Subject ' . $enr['subject_code'] . ' ' . ucfirst($pName) . ' period grade (' . number_format((float) $pData['equivalent'], 2) . ') is >= ' . number_format($disqualifyLine, 2) . '.',
                    ];
                    break;
                }
            }
            $recordedPeriod = false;
            foreach ($periods as $pData) {
                if (($pData['equivalent'] ?? null) !== null && $pData['equivalent'] !== '') {
                    $recordedPeriod = true;
                    break;
                }
            }
            if (!$recordedPeriod) {
                $subjectHonorRisk = 'Pending';
                $subjectHonorBadge = 'info';
                $subjectHonorNote = '';
            } elseif ($periodRisk) {
                $subjectHonorRisk = 'At Risk (Period Grade >= ' . number_format($disqualifyLine, 2) . ')';
                $subjectHonorBadge = 'warn';
                $subjectHonorNote = 'A period grade is currently >= ' . number_format($disqualifyLine, 2) . '.';
            } else {
                $subjectHonorRisk = 'Safe / On Track';
                $subjectHonorBadge = 'info';
                $subjectHonorNote = 'On track, pending final grade.';
            }
        }

        $subjectFeedback = student_subject_feedback(
            (string) $enr['subject_name'],
            student_plain_risk($subjectHonorRisk),
            $finalEqv !== null ? (float) $finalEqv : null,
            $periods,
            $disqualifyLine
        );

        $gradesVisible = (int) ($enr['grades_visible'] ?? 0) === 1;
        $subjectHonorNote = $gradesVisible ? $subjectFeedback['description'] . ' ' . $subjectFeedback['recommendation'] : '';
        if ($gradesVisible) {
            $releasedCount++;
        }

        $classes[] = [
            'assignment_id' => $assignmentId,
            'enrollment_id' => $enrollmentId,
            'grades_visible' => $gradesVisible,
            'subject_code' => $enr['subject_code'],
            'subject_name' => $enr['subject_name'],
            'section_name' => $enr['section_name'],
            'professor_name' => trim(($enr['professor_title'] ?? '') . ' ' . ($enr['professor_name'] ?? '')),
            'semester_name' => $enr['semester_name'],
            'is_official' => $isOfficial,
            'submission_status' => $enr['submission_status'] ?? 'draft',
            'periods' => $gradesVisible ? $periods : [],
            'final_percent' => $gradesVisible ? $finalPercent : null,
            'final_equivalent' => $gradesVisible ? $finalEqv : null,
            'remarks' => $gradesVisible ? $remarks : '',
            'passing_status' => $gradesVisible ? $passingStatus : 'Hidden',
            'passing_badge' => $gradesVisible ? $passingBadge : 'muted',
            'honor_risk' => $gradesVisible ? student_plain_risk($subjectHonorRisk) : 'Hidden',
            'honor_badge' => $subjectHonorBadge,
            'honor_note' => $subjectHonorNote,
            'performance_description' => $gradesVisible ? $subjectFeedback['description'] : '',
            'performance_recommendation' => $gradesVisible ? $subjectFeedback['recommendation'] : '',
        ];
    }

    $calculatedGwa = gwa_of($equivalentsForGwa);
    $riskReasons = [];
    $overallStanding = 'Pending';
    $overallBadge = 'muted';

    if ($classes === []) {
        $overallStanding = 'No Enrollments';
        $overallBadge = 'muted';
    } elseif ($atRiskSubjects !== [] || $failingSubjects !== []) {
        $overallStanding = 'At Risk';
        $overallBadge = 'warn';
        foreach ($atRiskSubjects as $ars) {
            $riskReasons[] = $ars['reason'];
        }
    } elseif ($calculatedGwa !== null && $calculatedGwa > $dlMax) {
        $overallStanding = 'At Risk';
        $overallBadge = 'warn';
        $riskReasons[] = 'Current estimated GWA (' . number_format($calculatedGwa, 2) . ') is outside the college honor standing threshold (<= ' . number_format($dlMax, 2) . ').';
    } elseif ($calculatedGwa !== null && $calculatedGwa <= $dlMax && count($equivalentsForGwa) === count($classes)) {
        $overallStanding = 'In Good Standing (Not At Risk)';
        $overallBadge = 'ok';
    } elseif (count($equivalentsForGwa) > 0) {
        $overallStanding = 'In Good Standing (On Track)';
        $overallBadge = 'ok';
    } else {
        $overallStanding = 'Pending Evaluation';
        $overallBadge = 'info';
    }

    return [
        'classes' => $classes,
        'calculated_gwa' => $calculatedGwa,
        'overall_standing' => $overallStanding,
        'overall_badge' => $overallBadge,
        'risk_reasons' => $riskReasons,
        'at_risk_count' => count($atRiskSubjects),
        'passed_count' => $passedCount,
        'failed_count' => $failedCount,
        'in_progress_count' => $inProgressCount,
        'total_count' => count($classes),
        'disqualify_line' => $disqualifyLine,
        'dl_max' => $dlMax,
        'released_count' => $releasedCount,
        'grades_open' => $classes !== [] && $releasedCount === count($classes),
        'overall_plain' => student_plain_risk($overallStanding),
    ];
}
