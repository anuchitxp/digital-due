<?php
/**
 * evaluation_form.php — renders the per-drug criteria checklist from criteria_items
 * (criteria as data — docs/03 §1). Also displays an existing draft/review record.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/criteria.php';

$u = current_user();
if ($u === null) {   // no layout yet — plain auth gate (layout_top would also gate, but we need redirect info first)
    require_auth();
}

$drug = strtoupper((string)($_GET['drug'] ?? ''));
$hn   = trim((string)($_GET['hn'] ?? ''));
$evalId = (int)($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? '') === 'review' ? 'review' : 'edit';

$version = current_criteria_version($drug);
if ($version === null) {
    http_response_code(404);
    exit('404 — unknown drug or no published criteria version');
}
$items = load_items((int)$version['id']);

$evaluation = null;
$answers = [];
if ($evalId > 0) {
    $rows = q('SELECT * FROM dbo.evaluations WHERE id = ?', [$evalId]);
    if ($rows) {
        $evaluation = $rows[0];
        $hn = (string)$evaluation['hn'];
        foreach (q('SELECT * FROM dbo.evaluation_answers WHERE evaluation_id = ?', [$evalId]) as $a) {
            $answers[(int)$a['criteria_item_id']] = $a;
        }
    }
}

$patient = $hn !== '' ? patient_summary($hn) : null;

$PAGE = ['title' => 'แบบฟอร์มประเมิน — ' . $version['generic_name'], 'active' => 'new'];
require_once __DIR__ . '/inc/layout_top.php';

function render_field(array $item, ?array $answer, bool $readonly): void
{
    $name = 'item_' . $item['id'];
    $val = $answer['value_json'] ?? '';
    $decoded = json_decode((string)$val, true);
    $cur = is_array($decoded) ? implode(', ', $decoded) : (string)$val;
    $dis = $readonly ? ' disabled' : '';
    switch ($item['item_type']) {
        case 'boolean': ?>
          <div class="checkbox-group">
            <span class="check-pill"><input type="checkbox" id="<?= h($name) ?>" name="<?= h($name) ?>" value="1"<?= $cur === '1' ? ' checked' : '' ?><?= $dis ?>><label for="<?= h($name) ?>">ใช่ / ยืนยัน</label></span>
          </div>
        <?php break;
        case 'numeric': ?>
          <input type="number" step="0.01" name="<?= h($name) ?>" value="<?= h($cur) ?>"<?= $dis ?> style="max-width:200px">
          <?php if ($item['unit']): ?><span class="hint"><?= h($item['unit']) ?></span><?php endif; ?>
        <?php break;
        case 'date': ?>
          <input type="date" name="<?= h($name) ?>" value="<?= h($cur) ?>"<?= $dis ?>>
        <?php break;
        case 'single_choice':
            $opts = json_decode((string)$item['options_json'], true) ?: []; ?>
          <div class="checkbox-group">
            <?php foreach ($opts as $i => $o): ?>
              <span class="check-pill"><input type="radio" id="<?= h($name) ?>_<?= $i ?>" name="<?= h($name) ?>" value="<?= h($o) ?>"<?= $cur === $o ? ' checked' : '' ?><?= $dis ?>><label for="<?= h($name) ?>_<?= $i ?>"><?= h($o) ?></label></span>
            <?php endforeach; ?>
          </div>
        <?php break;
        case 'multi_choice':
            $opts = json_decode((string)$item['options_json'], true) ?: [];
            $sel = is_array($decoded) ? $decoded : []; ?>
          <div class="checkbox-group">
            <?php foreach ($opts as $i => $o): ?>
              <span class="check-pill"><input type="checkbox" id="<?= h($name) ?>_<?= $i ?>" name="<?= h($name) ?>[]" value="<?= h($o) ?>"<?= in_array($o, $sel, true) ? ' checked' : '' ?><?= $dis ?>><label for="<?= h($name) ?>_<?= $i ?>"><?= h($o) ?></label></span>
            <?php endforeach; ?>
          </div>
        <?php break;
        case 'dose': ?>
          <input type="text" name="<?= h($name) ?>" value="<?= h($cur) ?>"<?= $dis ?> placeholder="เช่น 140 mg SC ทุก 2 สัปดาห์" style="max-width:420px">
        <?php break;
        default: ?>
          <textarea name="<?= h($name) ?>" rows="2"<?= $dis ?>><?= h($cur) ?></textarea>
        <?php break;
    }
}
?>
<?php if ($patient !== null): ?>
<div class="card">
  <div class="patient-strip" style="margin:0">
    <b><?= h($patient['name']) ?></b>
    <?php if ($patient['is_csmbs']): ?><span class="badge badge-csmbs">CSMBS — เกณฑ์เข้มงวด</span>
    <?php else: ?><span class="badge badge-other"><?= h($patient['privilege']) ?></span><?php endif; ?>
    · อายุ <?= h($patient['age'] ?? '?') ?> ปี <?= h($patient['sex']) ?>
    <?php if (isset($patient['labs']['LDL_C'])): ?>
      · LDL-C ล่าสุด <b><?= h($patient['labs']['LDL_C']['value']) ?></b> mg/dL (<?= h($patient['labs']['LDL_C']['date']) ?>)
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($evaluation !== null): ?>
<div class="verdict-card <?= strtolower((string)$evaluation['summary_recommendation']) === 'pass' ? 'pass' : (strtolower((string)$evaluation['summary_recommendation']) === 'fail' ? 'fail' : 'warn') ?>">
  <div class="verdict-icon">📌</div>
  <div>
    <div class="verdict-title">คำขอ #<?= h($evaluation['id']) ?> · สถานะ: <?= h($evaluation['status']) ?> · ผลประเมิน: <?= h($evaluation['summary_recommendation'] ?? 'PENDING') ?></div>
    <div class="verdict-desc">เกณฑ์ที่ใช้: <?= h($evaluation['criteria_version_id']) ?> · ประเภท: <?= h($evaluation['evaluation_type']) ?></div>
  </div>
</div>
<?php endif; ?>

<form method="post" action="evaluation_save.php">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="evaluation_id" value="<?= h($evalId) ?>">
<input type="hidden" name="hn" value="<?= h($hn) ?>">
<input type="hidden" name="drug" value="<?= h($drug) ?>">

<div class="card">
  <div class="section-title">ข้อมูลคำขอ</div>
  <div class="row-3">
    <div class="field-row">
      <label for="eval_type">ประเภทคำขอ</label>
      <select id="eval_type" name="eval_type">
        <option value="new">คำขอใหม่</option>
        <option value="renewal"<?= ($evaluation['evaluation_type'] ?? '') === 'renewal' ? ' selected' : '' ?>>ต่อเนื่อง (renewal)</option>
      </select>
    </div>
    <div class="field-row">
      <label for="unit">หน่วยงาน</label>
      <select id="unit" name="unit">
        <?php foreach (config()['app']['units'] as $code => $label): ?>
          <option value="<?= h($code) ?>"<?= ($evaluation['unit_code'] ?? '') === $code ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field-row">
      <label for="regimen">ยา/ขนาดที่ขอ</label>
      <input type="text" id="regimen" name="planned_regimen" value="<?= h($evaluation['planned_regimen'] ?? '') ?>" placeholder="เช่น 140 mg SC ทุก 2 สัปดาห์">
    </div>
  </div>
</div>

<div class="card">
  <div class="section-title">เกณฑ์ประเมิน — เวอร์ชัน <?= h($version['version_no']) ?> (<?= h($version['title_th']) ?>)</div>
  <table style="width:100%;border-collapse:collapse;font-size:13.5px">
  <?php foreach ($items as $item): $a = $answers[$item['id']] ?? null;
      $auto = $a === null && $mode !== 'review' ? auto_check($item, $hn) : null; ?>
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:12px 8px;vertical-align:top;width:28px;font-family:var(--mono)"><?= h($item['item_code']) ?></td>
      <td style="padding:12px 8px">
        <label><?= h($item['label_th']) ?><?= (int)$item['is_mandatory'] === 1 ? ' <span class="req">*</span>' : '' ?></label>
        <?php render_field($item, $a !== null ? $a : ($auto !== null ? ['value_json' => null, 'outcome' => $auto['outcome']] : null), $mode === 'review'); ?>
        <?php if ($auto !== null && $auto['outcome'] !== 'pending'): ?>
          <div class="hint" style="margin-top:4px">🤖 ตรวจอัตโนมัติ: <?= $auto['outcome'] === 'pass' ? '✅ ผ่าน' : '❌ ไม่ผ่าน' ?> — <?= h($auto['evidence']) ?>
            <label style="display:inline;font-size:12px"> (แทรกแซง: <input type="checkbox" name="override_<?= h($item['id']) ?>" value="1"> ฉันยืนยันผลต่าง — ต้องระบุเหตุผล)</label>
          </div>
        <?php elseif ($auto !== null && $auto['evidence'] !== ''): ?>
          <div class="hint" style="margin-top:4px">ℹ️ <?= h($auto['evidence']) ?></div>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </table>
  <div class="field-row" style="margin-top:12px">
    <label for="just">เหตุผลประกอบ (ต้องกรอกหากมีรายการบังคับไม่ผ่าน)</label>
    <textarea id="just" name="physician_justification" rows="2"><?= h($evaluation['physician_justification'] ?? '') ?></textarea>
  </div>
</div>

<?php if ($mode !== 'review'): ?>
<div class="card" style="text-align:center">
  <button class="btn" type="submit" name="action" value="draft">💾 บันทึกฉบับร่าง</button>
  <button class="btn btn-primary" type="submit" name="action" value="submit">📤 ส่งพิจารณา</button>
</div>
<?php endif; ?>
</form>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
