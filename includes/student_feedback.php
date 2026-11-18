<?php
declare(strict_types=1);

/** Evidence-based wording only: blank scores mean unrecorded, not unsubmitted work. */
function student_subject_feedback(string $subject, string $status, ?float $finalEqv, array $periods, float $riskLine): array
{
    $subject = trim($subject) ?: 'this subject';
    $periodNames = ['prelim' => 'preliminary', 'midterm' => 'midterm', 'finals' => 'final'];
    $areaNames = ['exam' => 'examination', 'quizzes' => 'quizzes', 'activities' => 'activities', 'project' => 'project', 'recitation' => 'recitation', 'attendance' => 'attendance'];
    $advice = [
        'exam' => 'You can improve your examination performance by reviewing difficult lessons, practising questions, and discussing mistakes with your professor.',
        'quizzes' => 'You can improve your quiz performance by reviewing each lesson regularly and practising the questions you find difficult.',
        'activities' => 'You can improve your activity performance by following the instructions carefully and using feedback from your professor.',
        'project' => 'You can improve your project work by reviewing the instructions, checking each required part, and asking your professor for guidance.',
        'recitation' => 'You can strengthen your class participation by reviewing lessons beforehand and practising how to explain your answers.',
        'attendance' => 'You can improve your attendance by attending scheduled classes regularly and discussing any attendance concerns with your professor.',
    ];
    $areas = $periodGrades = $unrecorded = [];
    foreach ($periods as $periodKey => $period) {
        $periodName = $periodNames[$periodKey] ?? ucfirst((string) $periodKey);
        if (isset($period['equivalent']) && is_numeric($period['equivalent'])) {
            $periodGrades[] = ['label' => $periodName, 'grade' => (float) $period['equivalent']];
        }
        foreach ($period['categories'] ?? [] as $key => $category) {
            if ((float) ($category['weight'] ?? 0) <= 0) continue;
            $label = $periodName . ' ' . ($areaNames[$key] ?? (string) $key);
            $missing = $recorded = 0;
            foreach ($category['items'] ?? [] as $item) {
                if ((float) ($item['max_score'] ?? 0) <= 0) continue;
                if (!isset($item['score']) || $item['score'] === '') $missing++;
                elseif (is_numeric($item['score'])) $recorded++;
            }
            if ($missing) $unrecorded[] = ['label' => $label, 'count' => $missing];
            // Use the actual category percentage; never substitute a missing score with zero.
            if (!isset($category['percent']) || !is_numeric($category['percent'])) continue;
            $areas[] = [
                'key' => $key, 'label' => $label, 'percent' => (float) $category['percent'],
                'grade' => isset($category['equivalent']) && is_numeric($category['equivalent']) ? (float) $category['equivalent'] : null,
                'partial' => $missing > 0, 'recorded' => $recorded,
            ];
        }
    }
    usort($areas, static fn ($a, $b) => $a['percent'] <=> $b['percent']);
    $weak = array_values(array_filter($areas, static fn ($a) => $a['grade'] !== null && $a['grade'] >= $riskLine));
    $risk = strtolower(trim($status)) === 'at risk';
    $pending = in_array(strtolower(trim($status)), ['pending', 'incomplete'], true);
    $description = [];
    $recommendations = [];
    if ($pending) {
        $description[] = 'Your academic standing in ' . $subject . ' cannot be determined yet because some of your grades are not yet available.';
    } elseif ($risk && $finalEqv !== null && $finalEqv > 3.0) {
        $description[] = 'Your current performance in ' . $subject . ' is below the passing requirements. You need to improve your overall performance in this subject.';
    } elseif ($risk && ($finalEqv !== null || $periodGrades !== [])) {
        $description[] = 'Your current performance in ' . $subject . ' indicates that you need to improve to meet the requirements for honor standing.';
        if ($finalEqv !== null && $finalEqv <= 3.0) {
            $description[] = 'You are passing the subject, but your overall result still needs improvement for honor consideration.';
        }
    } elseif (!$risk && ($finalEqv !== null || $periodGrades !== [])) {
        $description[] = 'Your current results in ' . $subject . ' support your good standing in this subject.';
        if ($finalEqv === null) $description[] = 'Your progress so far is encouraging, although your final subject grade is not yet available.';
    } else {
        $description[] = 'Your result in ' . $subject . ' is currently listed as ' . $status . '. Your available grade details do not yet show which assessments explain this result.';
    }
    if ($areas !== []) {
        $focus = $weak[0] ?? $areas[0];
        $best = $areas[count($areas) - 1];
        $strong = $best['grade'] !== null && $best['grade'] < $riskLine;
        if ($weak !== []) {
            $focusLabels = implode(' and ', array_column(array_slice($weak, 0, 2), 'label'));
            if ($strong && $best['label'] !== $focus['label']) {
                $description[] = 'Your recorded results show that you performed well in your ' . $best['label'] . ', but you still need to improve your ' . $focusLabels . '.';
            } else {
                $description[] = 'Your recorded results show that your ' . $focusLabels . (count($weak) === 1 && in_array($focus['key'], ['exam', 'project', 'attendance', 'recitation'], true) ? ' needs more attention.' : ' need more attention.');
            }
            $used = [];
            foreach (array_slice($weak, 0, 2) as $target) {
                if (isset($used[$target['key']])) continue;
                $used[$target['key']] = true;
                $recommendations[] = $advice[$target['key']] ?? 'You can review the work you found difficult and ask your professor how to improve.';
            }
        } elseif ($strong) {
            $description[] = 'Your recorded performance in your ' . $best['label'] . ' is a strength you can build on.';
            $recommendations[] = 'You can maintain your progress by keeping up your study habits and preparing for your next assessments.';
        } else {
            $description[] = 'Your best results so far are in your ' . $best['label'] . '.';
            $recommendations[] = 'You can review your ' . $focus['label'] . ' with your professor to identify what to work on next.';
        }
    }
    if ($unrecorded !== []) {
        $labels = implode(' and ', array_column(array_slice($unrecorded, 0, 2), 'label'));
        $description[] = 'Some scores for your ' . $labels . ' have not been recorded yet.';
        $recommendations[] = 'You can check these scores with your professor. If any work still needs to be submitted, arrange to complete it. If you have already submitted it, ask when your grades will be updated.';
    }
    if ($recommendations === []) {
        $recommendations[] = $pending
            ? 'You can ask your professor which grades are still being updated and whether there are any requirements you need to complete.'
            : 'You can discuss your results with your professor and ask which areas need more attention.';
    }
    return ['description' => implode(' ', $description), 'recommendation' => implode(' ', $recommendations)];
}
