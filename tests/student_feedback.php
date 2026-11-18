<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/student_feedback.php';
$checks = 0;
function feedback_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function feedback_area(float $percent, float $grade, array $items = []): array {
    return ['weight' => 40, 'percent' => $percent, 'equivalent' => $grade, 'items' => $items];
}
$periods = ['finals' => ['equivalent' => 2.25, 'categories' => [
    'exam' => feedback_area(95, 1.25, [['score' => 95, 'max_score' => 100]]),
    'activities' => feedback_area(65, 5.0, [['score' => 65, 'max_score' => 100]]),
]]];
$risk = student_subject_feedback('IT Project 1', 'At risk', 2.25, $periods, 2.0);
feedback_check(str_contains($risk['description'], 'honor standing') && str_contains($risk['description'], 'You are passing'), 'Passing-but-honor-risk grades are explained accurately');
feedback_check(str_contains($risk['description'], 'performed well in your final examination') && str_contains($risk['description'], 'improve your final activities'), 'Actual strong examination and weak activity scores are described');
feedback_check(str_contains($risk['recommendation'], 'activity performance') && !str_contains($risk['recommendation'], 'next examination'), 'Recommendations target weak activities without inventing weak examinations');
feedback_check(!str_contains($risk['description'], 'unrecorded') && !str_contains($risk['recommendation'], 'outstanding'), 'Complete records do not invent missing requirements');
$changed = $periods;
$changed['finals']['categories']['exam'] = feedback_area(50, 5.0);
$changed['finals']['categories']['activities'] = feedback_area(98, 1.0);
$lowExam = student_subject_feedback('IT Project 1', 'At risk', 5.0, $changed, 2.0);
feedback_check(str_contains($lowExam['description'], 'below the passing requirements') && str_contains($lowExam['recommendation'], 'examination performance'), 'Failing and examination-specific advice is evidence-based');
feedback_check($risk !== $lowExam, 'Different performances generate different feedback');
$pending = student_subject_feedback('Math', 'Pending', null, ['prelim' => ['categories' => [
    'quizzes' => feedback_area(80, 2.5, [['score' => 8, 'max_score' => 10], ['score' => null, 'max_score' => 10]]),
]]], 2.0);
feedback_check(str_contains($pending['description'], 'scores for your preliminary quizzes have not been recorded yet'), 'Partial category results and unencoded scores are distinguished');
feedback_check(str_contains($pending['recommendation'], 'If any work still needs to be submitted'), 'Blank scores prompt verification rather than accusations');
$empty = student_subject_feedback('Science', 'Pending', null, [], 2.0);
feedback_check(!str_contains($empty['description'], '%') && !str_contains($empty['description'], 'unrecorded score'), 'No scores or specific missing items are invented for empty data');
$zero = student_subject_feedback('Math', 'At risk', null, ['prelim' => ['equivalent' => 5, 'categories' => ['exam' => feedback_area(0, 5, [['score' => 0, 'max_score' => 100]])]]], 2.0);
feedback_check(str_contains($zero['description'], 'preliminary examination needs more attention') && !str_contains($zero['description'], 'not been recorded'), 'A recorded zero is not treated as missing');
$good = student_subject_feedback('English', 'Not at risk', 1.5, ['prelim' => ['categories' => ['exam' => feedback_area(92, 1.5)]]], 2.0);
feedback_check(str_contains($good['description'], 'good standing') && !str_contains($good['description'], 'qualifies for honor'), 'Good subject performance does not guarantee honor eligibility');
$inactive = student_subject_feedback('English', 'Not at risk', 1.5, ['prelim' => ['categories' => ['exam' => ['weight' => 0, 'percent' => 0, 'equivalent' => 5, 'items' => [['score' => null, 'max_score' => 10]]]]]], 2.0);
feedback_check(!str_contains($inactive['description'], '0.0%') && !str_contains($inactive['description'], 'unrecorded'), 'Zero-weight categories do not influence feedback');
foreach ([$risk, $lowExam, $pending, $empty, $zero, $good] as $feedback) {
    foreach ($feedback as $text) {
        feedback_check(!preg_match('/\b(the student|he\/she|his\/her)\b/i', $text), 'Feedback never uses third-person student reports');
        feedback_check(!preg_match('/\d+\.\d+|%|threshold|equivalent|classification|rubric/i', $text), 'Feedback uses plain language without technical grade details');
        feedback_check(str_starts_with($text, 'Your') || str_starts_with($text, 'You'), 'Feedback addresses the student directly');
    }
}
echo "Student performance feedback: $checks checks passed\n";
