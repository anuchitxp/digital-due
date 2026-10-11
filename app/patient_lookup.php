<?php
/** patient_lookup.php — HN lookup via HIS read-only views + audit (FR-1, FR-1.4). */
declare(strict_types=1);
$PAGE = ['title' => 'ค้นหาผู้ป่วย', 'active' => 'new'];
require_once __DIR__ . '/inc/layout_top.php';

$hn = trim((string)($_GET['q'] ?? ''));
$result = null;
if ($hn !== '') {
    audit_write('lookup', 'patient', null, ['hn' => $hn]);   // FR-1.4: log every lookup
    $result = patient_summary($hn);                          // null = unknown or HIS down
}
?>
<div class="card">
  <div class="section-title">ค้นหาผู้ป่วยจาก HIS (อ่านอย่างเดียว)</div>
  <form method="get" action="patient_lookup.php" class="lookup-row" style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end">
    <div class="field-row" style="margin:0">
      <label for="q">HN <span class="hint">ข้อมูลจำลอง: 6500012, 6500033, 6600045, 6600078, 6500090</span></label>
      <input type="text" id="q" name="q" value="<?= h($hn) ?>" placeholder="กรอก HN">
    </div>
    <button class="btn btn-primary" type="submit">🔍 ค้นหา</button>
  </form>

  <?php if ($hn !== '' && $result === null): ?>
    <div class="flash err" style="margin-top:14px">ไม่พบ HN นี้ หรือ HIS ไม่พร้อมใช้งาน — สามารถกรอกข้อมูลด้วยตนเองในแบบฟอร์มได้ (FR-1.3)</div>
  <?php elseif ($result !== null): ?>
    <div class="patient-strip" style="margin-top:14px">
      <b><?= h($result['name']) ?></b>
      <?php if ($result['is_csmbs']): ?><span class="badge badge-csmbs">CSMBS — เกณฑ์เข้มงวด</span>
      <?php else: ?><span class="badge badge-other"><?= h($result['privilege']) ?></span><?php endif; ?>
      · อายุ <?= h($result['age'] ?? '?') ?> ปี <?= h($result['sex']) ?>
      <?php if ($result['weight']): ?>· <?= h($result['weight']) ?> kg<?php endif; ?>
      <div style="margin-top:6px">
        ผลแล็บล่าสุด:
        <?php foreach ($result['labs'] as $code => $l): ?>
          <span class="chip"><?= h($code) ?> <?= h($l['value']) ?> (<?= h($l['date']) ?>)</span>
        <?php endforeach; ?>
        <?php if (!$result['labs']): ?><span class="chip">ไม่พบผลแล็บ — กรอกด้วยตนเอง</span><?php endif; ?>
      </div>
      <div style="margin-top:6px">Diagnosis:
        <?php foreach ($result['diagnoses'] as $d): ?>
          <span class="chip"><?= h($d['icd10']) ?> <?= h($d['icd10_name_th']) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($result['statin_history']): ?>
      <div style="margin-top:6px"><b>ประวัติยาลดไขมัน:</b>
        <ul style="margin:4px 0 0 18px;font-size:12.5px">
          <?php foreach ($result['statin_history'] as $s): ?>
            <li><?= h($s['generic_name']) ?> · รวม ~<?= h(round(((int)$s['total_days']) / 30, 1)) ?> เดือน · ครั้งล่าสุด <?= h($s['last_date']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <div style="margin-top:10px">
        <a class="btn btn-primary" style="text-decoration:none" href="evaluation_form.php?hn=<?= h($result['hn']) ?>&drug=PITAVA">เริ่มคำขอ (Pitavastatin)</a>
        <a class="btn" style="text-decoration:none" href="evaluation_form.php?hn=<?= h($result['hn']) ?>&drug=EVO">เริ่มคำขอ (Evolocumab)</a>
        <a class="btn" style="text-decoration:none" href="evaluation_form.php?hn=<?= h($result['hn']) ?>&drug=INCL">เริ่มคำขอ (Inclisiran)</a>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
