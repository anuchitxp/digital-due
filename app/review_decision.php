<?php
/** review_decision.php — POST: record decision (append-only) + status transition + audit. */
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';

session_boot();
csrf_verify();
$u = require_role(['due_reviewer', 'admin']);

$eid    = (int)($_POST['evaluation_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$comments = trim((string)($_POST['comments'] ?? ''));

$rows = q('SELECT * FROM dbo.evaluations WHERE id = ?', [$eid]);
if (!$rows) {
    header('Location: review_queue.php?e=' . urlencode('ไม่พบคำขอ'));
    exit;
}
$ev = $rows[0];
$isCreator = (int)$ev['created_by'] === (int)$u['id'];

$newStatus = next_status($ev['status'], $action, $isCreator);
if ($newStatus === null) {
    header('Location: review_queue.php?id=' . $eid
         . '&e=' . urlencode($isCreator && $action !== 'info_request' && $action !== 'start_review'
                ? 'ผู้ขอไม่สามารถตัดสินคำขอของตนเองได้ (separation of duties)'
                : 'การเปลี่ยนสถานะไม่ถูกต้อง'));
    exit;
}

$decisionMap = [
    'approve' => 'approved',
    'approve_conditions' => 'approved_with_conditions',
    'info_request' => 'info_requested',
    'reject' => 'rejected',
];

$pdo = db();
$pdo->beginTransaction();
try {
    if ($action === 'start_review') {
        x('UPDATE dbo.evaluations SET status = ? WHERE id = ?', [$newStatus, $eid]);
    } else {
        x(
            'INSERT INTO dbo.decisions (evaluation_id, decision, comments, decided_by) VALUES (?, ?, ?, ?)',
            [$eid, $decisionMap[$action], $comments !== '' ? $comments : null, $u['id']]
        );
        x('UPDATE dbo.evaluations SET status = ? WHERE id = ?', [$newStatus, $eid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('review_decision failed: ' . $e->getMessage());
    header('Location: review_queue.php?id=' . $eid . '&e=' . urlencode('บันทึกไม่สำเร็จ — ตรวจ server log'));
    exit;
}

audit_write('decision', 'evaluation', $eid, ['action' => $action, 'new_status' => $newStatus]);
header('Location: review_queue.php?m=' . urlencode('บันทึกการตัดสินใจของคำขอ #' . $eid . ' เรียบร้อย'));
exit;
