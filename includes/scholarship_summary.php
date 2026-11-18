<?php
declare(strict_types=1);

/** All cards and charts share the same already-filtered grant records. */
function scholarship_summary(array $assignments, array $programs): array
{
    $groups = $students = $categories = $programStudents = $programCategories = [];
    $active = 0;
    foreach ($programs as $program) {
        $groups[(int) $program['id']] = ['label' => $program['code'], 'active' => 0, 'beneficiaries' => 0, 'categories' => 0];
    }
    foreach ($assignments as $assignment) {
        $programId = (int) $assignment['program_id'];
        if (!isset($groups[$programId])) {
            $groups[$programId] = ['label' => 'Unassigned program', 'active' => 0, 'beneficiaries' => 0, 'categories' => 0];
        }
        if ($assignment['status'] === 'assigned') {
            $active++;
            $groups[$programId]['active']++;
        }
        $studentId = (int) $assignment['student_id'];
        $categoryId = (int) $assignment['category_id'];
        $students[$studentId] = $programStudents[$programId][$studentId] = true;
        $categories[$categoryId] = $programCategories[$programId][$categoryId] = true;
    }
    foreach ($groups as $programId => &$group) {
        $group['beneficiaries'] = count($programStudents[$programId] ?? []);
        $group['categories'] = count($programCategories[$programId] ?? []);
    }
    unset($group);
    return ['active' => $active, 'beneficiaries' => count($students), 'categories' => count($categories), 'programs' => array_values($groups)];
}
