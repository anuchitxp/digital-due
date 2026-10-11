<?php
/** admin_criteria.php — reviewer-only: publish/retire/clone criteria versions (FR-4). */
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';

session_boot();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
$u = require_role(['due_reviewer', 'admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vid = (int)($_POST['version_id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $v = q('SELECT cv.*, d.code AS drug_code FROM dbo.criteria_versions cv JOIN dbo.drugs d ON d.id = cv.drug_id WHERE cv.id = ?', [$vid]);
    if ($v) {
        $v = $v[0];
        if ($act === 'publish') {
            x('UPDATE dbo.criteria_versions SET is_published = 1, published_at = sysdatetime(), published_by = ? WHERE id = ?', [$u['id'], $vid]);
            audit_write('criteria_publish', 'criteria_version', $vid, ['drug' => $v['drug_code'], 'version' => $v['version_no']]);
        } elseif ($act === 'retire') {
            x('UPDATE dbo.criteria_versions SET is_published = 0, effective_to = CAST(GETDATE() AS date) WHERE id = ?', [$vid]);
            audit_write('criteria_retire', 'criteria_version', $vid, ['drug' => $v['drug_code'], 'version' => $v['version_no']]);
        } elseif ($act === 'clone') {
            $newNo = trim((string)($_POST['new_version_no'] ?? ''));
            if ($newNo !== '' && preg_match('/^[0-9.\\-]{1,12}$/', $newNo)) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $newId = (int)scalar(
                        'INSERT INTO dbo.criteria_versions (drug_id, version_no, title_th, title_en, source_ref, effective_from)
                         OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, CAST(GETDATE() AS date))',
                        [$v['drug_id'], $newNo, $v['title_th'], $v['title_en'], $v['source_ref']]
                    );
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('clone failed: ' . $e->getMessage());
                    header('Location: admin_criteria.php?e=' . urlencode('สร้างเวอร์ชันใหม่ไม่สำเร็จ'));
                    exit;
                }
                x(
                    'INSERT INTO dbo.criteria_items
                       (criteria_version_id, item_code, display_order, label_th, label_en, item_type, is_mandatory, unit, options_json, auto_binding_json)
                     SELECT ?, item_code, display_order, label_th, label_en, item_type, is_mandatory, unit, options_json, auto_binding_json
                       FROM dbo.criteria_items WHERE criteria_version_id = ?',
                    [$newId, $vid]
                );
                audit_write('criteria_clone', 'criteria_version', $newId, ['from' => $vid, 'new_version' => $newNo]);
            }
        }
    }
    header('Location: admin_criteria.php?m=' . urlencode('บันทึกแล้ว'));
    exit;
}

$PAGE = ['title' => 'เกณฑ์การใช้ยา (criteria versions)', 'active' => 'admin'];
require_once __DIR__ . '/inc/layout_top.php';

$versions = q(
    'SELECT cv.*, d.code AS drug_code, d.generic_name,
            (SELECT COUNT(*) FROM dbo.criteria_items ci WHERE ci.criteria_version_id = cv.id) AS item_count,
            (SELECT COUNT(*) FROM dbo.evaluations e WHERE e.criteria_version_id = cv.id) AS eval_count
       FROM dbo.criteria_versions cv JOIN dbo.drugs d ON d.id = cv.drug_id
      ORDER BY d.code, cv.effective_from DESC'
);
?>
<div class="card">
  <div class="section-title">เกณฑ์ตามยา (criteria as data — docs/03 §1)</div>
  <div class="table-card" style="max-width:none;box-shadow:none">
    <table>
      <thead><tr><th>ยา</th><th>เวอร์ชัน</th><th>ชื่อเกณฑ์</th><th>มีผลตั้งแต่</th><th>รายการ</th><th>ถูกใช้แล้ว</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
      <tbody>
      <?php foreach ($versions as $v): ?>
        <tr>
          <td><?= h($v['drug_code']) ?> · <?= h($v['generic_name']) ?></td>
          <td style="font-family:var(--mono)"><?= h($v['version_no']) ?></td>
          <td><?= h($v['title_th']) ?></td>
          <td><?= h($v['effective_from']) ?></td>
          <td><?= (int)$v['item_count'] ?></td>
          <td><?= (int)$v['eval_count'] ?> คำขอ</td>
          <td><?= (int)$v['is_published'] === 1 ? '✅ เผยแพร่' : '⛔ ยังไม่เผยแพร่/ปลด' ?></td>
          <td>
            <form method="post" action="admin_criteria.php" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
              <?php if ((int)$v['is_published'] === 1): ?>
                <button class="btn" name="action" value="retire">ปลดใช้</button>
              <?php else: ?>
                <button class="btn btn-primary" name="action" value="publish">เผยแพร่</button>
              <?php endif; ?>
            </form>
            <form method="post" action="admin_criteria.php" style="display:flex;gap:6px;align-items:center;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
              <input type="text" name="new_version_no" placeholder="1.1" style="width:80px;padding:6px">
              <button class="btn" name="action" value="clone">สร้างเวอร์ชันใหม่ (คัดลอกรายการ)</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint" style="margin-top:10px">การแก้ไขรายการเกณฑ์ (ข้อความ/เกณฑ์ตัวเลข) ทำผ่านสคริปต์ db/ หรือ seed ใหม่ — ประวัติการใช้เกณฑ์เก่าถูก retain ตลอดไป (FR-4.2)</p>
</div>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
