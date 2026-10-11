<?php
/**
 * evaluation_save.php — POST handler: validate → save evaluation + answers + audit → redirect (302).
 * No HTML output; all exits are redirects or error codes (docs/09 P1).
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';
require_once __DIR__ . '/inc/criteria.php';

session_boot();
csrf_verify();
$u = require_auth();
if (!in_array('physician', $u['roles'], true) && !in_array('clinic_staff', $u['roles'], true)) {
    header('Location: index.php?e=' . urlencode('สิทธิไม่เพียงพอ'));
    exit;
}

$evalId   = (int)($_POST['evaluation_id'] ?? 0);
$hn       = trim((string)($_POST['hn'] ?? ''));
$drug     = strtoupper((string)($_POST['drug'] ?? ''));
$evalType = ($_POST['eval_type'] ?? 'new') === 'renewal' ? 'renewal' : 'new';
$unit     = (string)($_POST['unit'] ?? 'CARDIO');
$action   = ($_POST['action'] ?? 'draft') === 'submit' ? 'submit' : 'draft';
$just     = trim((string)($_POST['physician_justification'] ?? ''));

if ($hn === '' || $drug === '') {
    header('Location: index.php?e=' . urlencode('ต้องระบุ HN และยา'));
    exit;
}
$version = current_criteria_version($drug);
if ($version === null) {
    header('Location: index.php?e=' . urlencode('ไม่พบเกณฑ์ที่เผยแพร่สำหรับยานี้'));
    exit;
}
$items = load_items((int)$version['id']);

// duplicate/active check (US-11): block submit when an active evaluation exists for hn+drug
$dupe = q(
    "SELECT TOP (1) id, status FROM dbo.evaluations
      WHERE hn = ? AND drug_id = ? AND status NOT IN ('withdrawn','rejected','expired') AND id <> ?",
    [$hn, $version['drug_id'], $evalId]
);
if ($dupe && $action === 'submit') {
    header('Location: evaluation_form.php?hn=' . urlencode($hn) . '&drug=' . urlencode($drug)
         . '&id=' . (int)$dupe[0]['id'] . '&e=' . urlencode('มีคำขอที่ยังไม่จบสำหรับยานี้อยู่แล้ว (#' . (int)$dupe[0]['id'] . ')'));
    exit;
}

// ---- collect + evaluate answers (server-side truth; auto where bound, override allowed+audited)
$answers = [];       // [item_id => ['value'=>..., 'outcome'=>..., 'source'=>..., 'why'=>...]]
$missing = [];
foreach ($items as $item) {
    $iid = (int)$item['id'];
    $field = 'item_' . $iid;
    $raw = $_POST[$field] ?? null;
    if (is_array($raw)) {
        $raw = array_map('strval', $raw);
    } elseif (is_string($raw)) {
        $raw = trim($raw);
    }
    $value = match ($item['item_type']) {
        'boolean' => !empty($raw) ? '1' : '',
        'multi_choice' => is_array($raw) ? json_encode(array_values($raw), JSON_UNESCAPED_UNICODE) : null,
        default => ($raw === null || $raw === '') ? null : (string)$raw,
    };
    $hasValue = match ($item['item_type']) {
        'boolean' => !empty($raw),
        default => $value !== null && $value !== '' && $value !== '[]',
    };

    // outcome: auto binding first, then the recorded/entered answer
    $auto = auto_check($item, $hn);
    $override = !empty($_POST['override_' . $iid]);
    if ($auto['source'] === 'auto') {
        $outcome = $auto['outcome'];
        $source = 'auto';
        if ($override) {
            $outcome = $hasValue ? 'pass' : 'fail';
            $source = 'physician';
        }
    } else {
        $source = 'physician';
        if (!$hasValue) {
            $outcome = 'pending';
        } elseif ($item['item_type'] === 'boolean') {
            $outcome = 'pass';                 // answered yes/confirmed
        } elseif ($item['item_type'] === 'single_choice' || $item['item_type'] === 'multi_choice') {
            $outcome = 'pass';
        } else {
            $outcome = 'pass';                 // provided numeric/text/date → presence counts; thresholds via bindings
        }
    }
    if ($outcome === 'pending' && (int)$item['is_mandatory'] === 1) {
        $missing[] = $item['item_code'];
    }
    $answers[$iid] = [
        'value'   => $value,
        'outcome' => $outcome,
        'source'  => $source,
        'why'     => $override ? trim((string)($_POST['why_' . $iid] ?? $just)) : null,
    ];
}

if ($action === 'submit' && $missing) {
    header('Location: evaluation_form.php?hn=' . urlencode($hn) . '&drug=' . urlencode($drug)
         . ($evalId ? '&id=' . $evalId : '')
         . '&e=' . urlencode('รอกรอกรายการบังคับ: ' . implode(', ', $missing)));
    exit;
}

// ---- persist (transaction)
$pdo = db();
$pdo->beginTransaction();
try {
    // patient snapshot at evaluation time (provenance)
    $patient = patient_summary($hn);
    $snapId = (int)scalar(
        'INSERT INTO dbo.patient_snapshots (hn, full_name, birth_date, sex, weight_kg, privilege, is_csmbs, snapshot_json, fetched_by)
         OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $hn,
            $patient['name'] ?? '(manual entry)',
            $patient['birth_date'] ?? null,
            $patient['sex'] ?? null,
            $patient['weight'] ?? null,
            $patient['privilege'] ?? null,
            !empty($patient['is_csmbs']) ? 1 : 0,
            $patient === null ? json_encode(['manual' => true], JSON_UNESCAPED_UNICODE) : json_encode($patient, JSON_UNESCAPED_UNICODE),
            $u['id'],
        ]
    );

    if ($evalId > 0) {
        $owner = q('SELECT created_by, status FROM dbo.evaluations WHERE id = ?', [$evalId]);
        if (!$owner || (int)$owner[0]['created_by'] !== (int)$u['id']) {
            $pdo->rollBack();
            header('Location: index.php?e=' . urlencode('แก้ไขได้เฉพาะเจ้าของคำขอ'));
            exit;
        }
        x(
            'UPDATE dbo.evaluations
                SET patient_snapshot_id = ?, drug_id = ?, criteria_version_id = ?, unit_code = ?,
                    evaluation_type = ?, planned_regimen = ?, physician_justification = ?,
                    status = ?, submitted_at = ?, summary_recommendation = ?, updated_at = sysdatetime()
              WHERE id = ?',
            [
                $snapId, $version['drug_id'], $version['id'], $unit, $evalType,
                (string)($_POST['planned_regimen'] ?? ''), $just !== '' ? $just : null,
                $action === 'submit' ? 'submitted' : 'draft',
                $action === 'submit' ? date('Y-m-d H:i:s') : null,
                null, $evalId,
            ]
        );
        x('DELETE FROM dbo.evaluation_answers WHERE evaluation_id = ?', [$evalId]);
    } else {
        $evalId = (int)scalar(
            'INSERT INTO dbo.evaluations
               (patient_snapshot_id, hn, drug_id, criteria_version_id, unit_code, evaluation_type,
                planned_regimen, physician_justification, status, submitted_at, created_by)
             OUTPUT INSERTED.id
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $snapId, $hn, $version['drug_id'], $version['id'], $unit, $evalType,
                (string)($_POST['planned_regimen'] ?? ''), $just !== '' ? $just : null,
                $action === 'submit' ? 'submitted' : 'draft',
                $action === 'submit' ? date('Y-m-d H:i:s') : null,
                $u['id'],
            ]
        );
    }

    foreach ($answers as $iid => $a) {
        x(
            'INSERT INTO dbo.evaluation_answers (evaluation_id, criteria_item_id, value_json, outcome, source, override_justification)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$evalId, $iid, $a['value'], $a['outcome'], $a['source'], $a['why']]
        );
    }

    // verdict from mandatory outcomes
    $verdict = compute_verdict($items, array_map(fn($a) => ['outcome' => $a['outcome']], $answers));
    x('UPDATE dbo.evaluations SET summary_recommendation = ? WHERE id = ?', [$verdict, $evalId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('evaluation_save failed: ' . $e->getMessage());
    header('Location: index.php?e=' . urlencode('บันทึกไม่สำเร็จ — ตรวจ server log'));
    exit;
}

audit_write($action === 'submit' ? 'submit' : 'update', 'evaluation', $evalId, [
    'hn' => $hn, 'drug' => $drug, 'verdict' => $verdict, 'missing' => $missing,
]);

$msg = $action === 'submit'
    ? 'ส่งคำขอ #' . $evalId . ' เข้าสู่คิวตรวจสอบแล้ว'
    : 'บันทึกฉบับร่าง #' . $evalId . ' แล้ว';
header('Location: ' . ($action === 'submit' && $u['is_reviewer'] ? 'review_queue.php' : 'evaluation_form.php')
     . ($action === 'submit' ? '?m=' . urlencode($msg) : '?id=' . $evalId . '&hn=' . urlencode($hn) . '&drug=' . urlencode($drug) . '&m=' . urlencode($msg)));
exit;
