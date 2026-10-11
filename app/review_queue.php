<?php
/** review_queue.php — reviewer queue (submitted / under_review / info_requested). */
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';
$u = require_role(['due_reviewer', 'admin']);

$rows = q(
    "SELECT e.id, e.hn, e.status, e.summary_recommendation, e.evaluation_type, e.unit_code,
            e.created_at, d.generic_name, u.full_name AS creator
       FROM dbo.evaluations e
       JOIN dbo.drugs d ON d.id = e.drug_id
       JOIN dbo.users u ON u.id = e.created_by
      WHERE e.status IN ('submitted','under_review','info_requested')
      ORDER BY e.submitted_at ASC, e.id ASC"
);

$PAGE = ['title' => 'คิวตรวจสอบคำขอ', 'active' => 'review'];
require_once __DIR__ . '/inc/layout_top.php';
?>
<div class="table-card">
  <table>
    <thead><tr><th>#</th><th>HN</th><th>ยา</th><th>หน่วย</th><th>ประเภท</th><th>ผลประเมิน</th><th>ผู้ขอ</th><th>สถานะ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $rec = strtolower((string)$r['summary_recommendation']);
        $pill = $rec === 'pass' ? 'v-pass' : ($rec === 'fail' ? 'v-fail' : 'v-warn'); ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['hn']) ?></td>
        <td><?= h($r['generic_name']) ?></td>
        <td><?= h($r['unit_code']) ?></td>
        <td><?= $r['evaluation_type'] === 'renewal' ? 'ต่อเนื่อง' : 'ใหม่' ?></td>
        <td><span class="verdict-pill <?= $pill ?>"><?= h($r['summary_recommendation'] ?? 'PENDING') ?></span></td>
        <td><?= h($r['creator']) ?></td>
        <td><?= h($r['status']) ?></td>
        <td><a href="evaluation_form.php?id=<?= (int)$r['id'] ?>&mode=review">เปิดดู</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:var(--text-muted)">คิวว่าง</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (isset($_GET['id'])): $eid = (int)$_GET['id'];
    $ev = q('SELECT * FROM dbo.evaluations WHERE id = ?', [$eid]);
    if ($ev && in_array($ev[0]['status'], ['submitted', 'under_review', 'info_requested'], true)):
        $isCreator = (int)$ev[0]['created_by'] === (int)$u['id']; ?>
<div class="card">
  <div class="section-title">บันทึกการตัดสินใจ — คำขอ #<?= $eid ?><?= $isCreator ? ' (คุณเป็นผู้ขอ — ระบบไม่อนุญาตให้ตัดสินคำขอของตัวเอง)' : '' ?></div>
  <form method="post" action="review_decision.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="evaluation_id" value="<?= $eid ?>">
    <div class="field-row">
      <label>การตัดสินใจ</label>
      <div class="checkbox-group">
        <?php foreach (['approve' => 'อนุมัติ', 'approve_conditions' => 'อนุมัติแบบมีเงื่อนไข', 'info_request' => 'ขอข้อมูลเพิ่ม', 'reject' => 'ไม่อนุมัติ'] as $act => $label):
            $disabled = can_transition($ev[0], $act) ? '' : ' disabled'; ?>
          <span class="check-pill"><input type="radio" id="dec_<?= h($act) ?>" name="action" value="<?= h($act) ?>"<?= $disabled ?>><label for="dec_<?= h($act) ?>"><?= h($label) ?></label></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field-row">
      <label for="comments">ความเห็น / เงื่อนไข</label>
      <textarea id="comments" name="comments" rows="2"></textarea>
    </div>
    <button class="btn btn-primary" type="submit">บันทึกการตัดสินใจ</button>
    <span class="hint">การตัดสินใจถูกบันทึกแบบ append-only — แก้ไข/ลบไม่ได้ (docs/04, docs/08)</span>
  </form>
</div>
<?php endif; endif; ?>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
