<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/workflow.php';
$chair = ['roles' => ['program_chair'], 'role_rows' => [['code' => 'program_chair', 'program_id' => 1]]];
foreach (['submitted', 'chair_verified', 'oic_approved', 'osa_endorsed'] as $status) {
    if (next_status($status, 'approve') !== 'registrar_finalized'
        || next_status($status, 'return') !== 'returned'
        || next_status($status, 'reject') !== 'rejected'
        || role_for_status($status) !== 'program_chair'
        || !user_can_review($chair, ['program_id' => 1], $status)
        || user_can_review($chair, ['program_id' => 2], $status)
        || user_can_review(['roles' => ['osa']], ['program_id' => 1], $status)
        || user_can_review(['roles' => ['college_oic']], ['program_id' => 1], $status)) {
        throw new RuntimeException('Direct chair review workflow failed');
    }
}
foreach (['draft', 'returned', 'registrar_finalized'] as $status) {
    if (user_can_review($chair, ['program_id' => 1], $status)) throw new RuntimeException('Invalid status is reviewable');
}
if (next_status('draft', 'submit') !== 'submitted' || next_status('returned', 'submit') !== 'submitted') throw new RuntimeException('Professor submission routing failed');
echo "Direct chair review, legacy queues, program scope and role checks passed\n";

foreach (['submitted' => 'Awaiting Chair Review', 'registrar_finalized' => 'Approved by Chair', 'returned' => 'Returned for Revision', 'rejected' => 'Rejected'] as $status => $label) {
    if (submission_label($status) !== $label) throw new RuntimeException('Unexpected submission label');
}
require_once dirname(__DIR__) . '/includes/recognition.php';
$counts = recognition_workflow_counts([
    ['case_id' => 1, 'stage' => 'chair_review'],
    ['case_id' => 2, 'stage' => 'chair_endorsed'],
    ['case_id' => 3, 'stage' => 'chair_returned'],
    ['case_id' => 4, 'stage' => 'chair_rejected'],
    ['case_id' => null, 'stage' => null],
]);
if ($counts !== ['Endorsed' => 1, 'For Review' => 2, 'Rejected' => 1, 'Returned' => 1]) throw new RuntimeException('Workflow chart labels or totals mismatch');
echo "Four-status labels, rejection and recognition distribution checks passed\n";

$queueCounts = chair_review_status_counts(array_map(static fn ($status) => ['status' => $status], ['submitted', 'chair_verified', 'oic_approved', 'osa_endorsed', 'registrar_finalized', 'returned', 'rejected']));
if ($queueCounts !== ['Approved by Chair' => 1, 'Awaiting Chair Review' => 4, 'Rejected' => 1, 'Returned for Revision' => 1]) throw new RuntimeException('Queue chart distribution mismatch');
if (array_sum(chair_review_status_counts([])) !== 0) throw new RuntimeException('Empty queue distribution mismatch');
echo "Queue chart status and empty-state checks passed\n";
