<?php
/** index.php — role-aware home (docs/09 P1). */
declare(strict_types=1);
$PAGE = ['title' => 'DUE — หน้าหลัก', 'active' => 'home'];
require_once __DIR__ . '/inc/layout_top.php';

$myDrafts = (int)q(
    "SELECT COUNT(*) AS n FROM dbo.evaluations WHERE created_by = ? AND status = 'draft'",
    [$U['id']]
)[0]['n'];
$pending = $U['is_reviewer']
    ? (int)q("SELECT COUNT(*) AS n FROM dbo.evaluations WHERE status IN ('submitted','under_review')")[0]['n']
    : null;
$month = (int)q(
    'SELECT COUNT(*) AS n FROM dbo.evaluations WHERE created_at >= DATEADD(month, -1, GETDATE())'
)[0]['n'];

$drugs = q('SELECT code, generic_name, brand_name FROM dbo.drugs WHERE is_active = 1 ORDER BY code');
?>
<div class="card">
  <div class="section-title">ภาพรวมของฉัน</div>
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-label">ฉบับร่างของฉัน</div><div class="kpi-value"><?= $myDrafts ?></div><div class="kpi-sub">ยังไม่ส่งพิจารณา</div></div>
    <?php if ($pending !== null): ?>
    <div class="kpi green"><div class="kpi-label">รอตรวจสอบ</div><div class="kpi-value"><?= $pending ?></div><div class="kpi-sub">submitted / under_review</div></div>
    <?php endif; ?>
    <div class="kpi purple"><div class="kpi-label">คำขอ 30 วันล่าสุด</div><div class="kpi-value"><?= $month ?></div><div class="kpi-sub">ทุกหน่วยงาน</div></div>
  </div>
</div>

<?php if (in_array('physician', $U['roles'], true) || in_array('clinic_staff', $U['roles'], true)): ?>
<div class="card">
  <div class="section-title">สร้างคำขอใหม่</div>
  <p style="font-size:14px;margin-bottom:10px">เลือกยาเพื่อเปิดแบบฟอร์มประเมินตามเกณฑ์:</p>
  <div class="checkbox-group">
    <?php foreach ($drugs as $d): ?>
      <span class="check-pill"><a class="btn" style="text-decoration:none" href="evaluation_form.php?drug=<?= h($d['code']) ?>"><?= h($d['generic_name']) ?> (<?= h($d['brand_name']) ?>)</a></span>
    <?php endforeach; ?>
  </div>
  <p style="font-size:13px;color:var(--text-muted);margin-top:10px">หรือค้นผู้ป่วยก่อน: <a href="patient_lookup.php">ค้นจาก HN</a></p>
</div>
<?php endif; ?>

<div class="card">
  <div class="section-title">ลิงก์เพิ่มเติม</div>
  <ul style="font-size:14px;margin-left:18px">
    <?php if ($U['is_reviewer']): ?><li><a href="review_queue.php">คิวตรวจสอบคำขอ</a></li><?php endif; ?>
    <li><a href="reports.php">รายงานสรุป (KPI / แนวโน้ม)</a></li>
  </ul>
</div>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
