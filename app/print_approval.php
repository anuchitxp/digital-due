<?php
/** print_approval.php — printable approval package (print CSS; no server PDF dependency). */
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';

$eid = (int)($_GET['id'] ?? 0);
$rows = q(
    'SELECT e.*, d.generic_name, d.brand_name, cv.version_no, cv.title_th, u.full_name AS creator
       FROM dbo.evaluations e
       JOIN dbo.drugs d ON d.id = e.drug_id
       JOIN dbo.criteria_versions cv ON cv.id = e.criteria_version_id
       JOIN dbo.users u ON u.id = e.created_by
      WHERE e.id = ?',
    [$eid]
);
if (!$rows) {
    http_response_code(404);
    exit('404 — evaluation not found');
}
$e = $rows[0];
// access: creator or reviewer/admin
$u = require_auth();
if ((int)$e['created_by'] !== (int)$u['id'] && !$u['is_reviewer']) {
    http_response_code(403);
    exit('403');
}
$snap = q('SELECT * FROM dbo.patient_snapshots WHERE id = ?', [$e['patient_snapshot_id']])[0] ?? null;
$answers = q(
    'SELECT ci.item_code, ci.label_th, ci.is_mandatory, a.value_json, a.outcome, a.source, a.override_justification
       FROM dbo.evaluation_answers a JOIN dbo.criteria_items ci ON ci.id = a.criteria_item_id
      WHERE a.evaluation_id = ? ORDER BY ci.display_order',
    [$eid]
);
$decisions = q(
    'SELECT de.*, u.full_name AS reviewer FROM dbo.decisions de
      JOIN dbo.users u ON u.id = de.decided_by WHERE de.evaluation_id = ? ORDER BY de.id',
    [$eid]
);
audit_write('export', 'evaluation', $eid, ['print' => true]);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>ใบอนุมัติ DUE #<?= (int)$e['id'] ?></title>
<link rel="stylesheet" href="assets/due.css">
</head>
<body>
<nav class="topnav no-print"><a href="review_queue.php">← กลับคิว</a> <button class="btn" onclick="window.print()">🖨️ พิมพ์</button></nav>

<div class="card print-sheet" style="display:block">
  <h1 style="text-align:center;font-size:18px">แบบประเมินการใช้ยา (DUE) — ใบเสนอพิจารณา/อนุมัติ</h1>
  <p style="text-align:center;font-size:12px;color:#374151">โรงพยาบาล (โปรโตไทป์) · เกณฑ์ <?= h($e['version_no']) ?> — <?= h($e['title_th']) ?> (สมมติฐาน รอคณะกรรมการยืนยัน)</p>
  <table class="ps-table" style="margin-top:12px">
    <tr><td class="k">คำขอ #</td><td><?= (int)$e['id'] ?></td><td class="k">วันที่สร้าง</td><td><?= h(substr((string)$e['created_at'], 0, 16)) ?></td></tr>
    <tr><td class="k">HN / ชื่อ</td><td><?= h($e['hn']) ?> / <?= h($snap['full_name'] ?? '-') ?></td><td class="k">สิทธิ</td><td><?= h($snap['privilege'] ?? '-') ?><?= !empty($snap['is_csmbs']) ? ' (CSMBS — เกณฑ์เข้มงวด)' : '' ?></td></tr>
    <tr><td class="k">ยา</td><td><?= h($e['generic_name']) ?> (<?= h($e['brand_name']) ?>)</td><td class="k">ขนาดที่ขอ</td><td><?= h($e['planned_regimen'] ?? '-') ?></td></tr>
    <tr><td class="k">หน่วยงาน</td><td><?= h($e['unit_code']) ?></td><td class="k">แพทย์ผู้ขอ</td><td><?= h($e['creator']) ?></td></tr>
    <tr><td class="k">ประเภท</td><td><?= $e['evaluation_type'] === 'renewal' ? 'ต่อเนื่อง (renewal)' : 'คำขอใหม่' ?></td><td class="k">สถานะ</td><td><?= h($e['status']) ?> · ผลประเมินระบบ: <?= h($e['summary_recommendation'] ?? 'PENDING') ?></td></tr>
  </table>

  <div style="font-size:13px"><b>รายการเกณฑ์:</b>
    <table class="ps-table">
      <tr><th style="text-align:left">รหัส</th><th style="text-align:left">รายการ</th><th style="text-align:left">ผล</th><th style="text-align:left">ที่มา</th></tr>
      <?php foreach ($answers as $a): ?>
      <tr>
        <td><?= h($a['item_code']) ?></td>
        <td><?= h($a['label_th']) ?><?= $a['override_justification'] ? '<br><i>เหตุผลแทรกแซง: ' . h($a['override_justification']) . '</i>' : '' ?></td>
        <td><?= h(strtoupper($a['outcome'])) ?></td>
        <td><?= h($a['source']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($decisions): ?>
  <div style="font-size:13px"><b>การตัดสินใจของคณะ/เภสัชกร:</b>
    <ul style="margin:6px 0 0 18px">
      <?php foreach ($decisions as $d): ?>
        <li><?= h($d['decision']) ?> — <?= h($d['reviewer']) ?> (<?= h(substr((string)$d['decided_at'], 0, 16)) ?>)<?= $d['comments'] ? ': ' . h($d['comments']) : '' ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($e['physician_justification']): ?>
  <div style="font-size:13px;margin-top:8px"><b>เหตุผลประกอบของแพทย์:</b> <?= nl2br(h($e['physician_justification'])) ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:48px;text-align:center;font-size:13px">
    <div style="border-top:1px solid #334155;margin-top:36px;padding-top:4px">แพทย์ผู้ขอ</div>
    <div style="border-top:1px solid #334155;margin-top:36px;padding-top:4px">เภสัชกร / ประธานกรรมการ DUE</div>
  </div>
</div>
</body>
</html>
