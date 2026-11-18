<?php
declare(strict_types=1);
if (!professor_owns_assignment($assignment, $user) || ($book['students'] ?? []) === []) {
    return;
}
$released = 0;
foreach ($book['students'] as $student) {
    if (!empty($student['grades_visible'])) {
        $released++;
    }
}
$visibilityStudents = array_values($book['students']);
$visibilityPager = paginate(count($visibilityStudents), (int) ($_GET['page'] ?? 1), 10);
$visibilityPageStudents = array_slice($visibilityStudents, $visibilityPager['offset'], $visibilityPager['per']);
?>
<section class="card" style="margin-bottom:16px;">
    <div class="card-head">
        <h2><?= icon('eye') ?> Student grade visibility</h2>
    </div>
    <p class="hint" style="margin-top:0;">Grades are visible only to students who have paid the tuition fee for the corresponding term. Use the eye icon to show or hide grades.</p>
    <form method="post" action="<?= h(url('professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => $view, 'period' => $period]))) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
        <input type="hidden" name="action" value="release_grades">
        <input type="hidden" name="visibility_page" value="<?= (int) $visibilityPager['page'] ?>">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>View</th>
                        <th>Student number</th>
                        <th>Name</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($visibilityPageStudents as $student): ?>
                    <?php $canView = !empty($student['grades_visible']); ?>
                    <tr>
                        <td>
                            <button class="grade-eye<?= $canView ? ' is-open' : '' ?>" type="submit" name="toggle_enrollment" value="<?= (int) $student['enrollment_id'] ?>" aria-pressed="<?= $canView ? 'true' : 'false' ?>" aria-label="<?= $canView ? 'Hide grades from ' . h($student['full_name']) : 'Allow ' . h($student['full_name']) . ' to view grades' ?>">
                                <?= icon($canView ? 'eye' : 'eye-off') ?>
                            </button>
                        </td>
                        <td class="mono"><?= h($student['student_number']) ?></td>
                        <td><?= h($student['full_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px;">
            <button class="btn" type="submit" name="release_mode" value="all"><?= icon('eye') ?> Allow all</button>
            <button class="btn btn-ghost" type="submit" name="release_mode" value="none"><?= icon('eye-off') ?> Hide all</button>
        </div>
        <p class="hint"><?= (int) $released ?> of <?= count($book['students']) ?> students can currently see this class grade.</p>
        <?= pager_html($visibilityPager, 'professor/class.php', sheet_query(['id' => $assignment['id'], 'view' => $view, 'period' => $period])) ?>
    </form>
</section>
