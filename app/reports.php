<?php
/** reports.php — KPIs + Chart.js (vendored) + recent table. Datasets via json_encode. */
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/audit.php';

$months = min(12, max(3, (int)($_GET['months'] ?? 12)));
$unit   = strtoupper((string)($_GET['unit'] ?? 'ALL'));
$unitFilter = in_array($unit, ['CARDIO', 'ENDO'], true);

$where = "e.created_at >= DATEADD(month, -?, GETDATE())";
$params = [$months];
if ($unitFilter) {
    $where .= ' AND e.unit_code = ?';
    $params[] = $unit;
}

$totals = q(
    "SELECT
       COUNT(*) AS total,
       SUM(CASE WHEN e.summary_recommendation = 'PASS' THEN 1 ELSE 0 END) AS pass_n,
       SUM(CASE WHEN e.summary_recommendation = 'FAIL' THEN 1 ELSE 0 END) AS fail_n,
       SUM(CASE WHEN e.status = 'draft' OR e.summary_recommendation IS NULL THEN 1 ELSE 0 END) AS pending_n,
       SUM(COALESCE(p.unit_price, 0)) AS cost
     FROM dbo.evaluations e
     LEFT JOIN dbo.drug_prices p
       ON p.drug_id = e.drug_id AND p.effective_from = (
            SELECT MAX(p2.effective_from) FROM dbo.drug_prices p2 WHERE p2.drug_id = e.drug_id)
     WHERE $where",
    $params
)[0];

$monthly = q(
    "SELECT CONVERT(varchar(7), e.created_at, 126) AS ym,
            SUM(CASE WHEN e.summary_recommendation = 'PASS' THEN 1 ELSE 0 END) AS pass_n,
            SUM(CASE WHEN e.summary_recommendation = 'FAIL' THEN 1 ELSE 0 END) AS fail_n,
            SUM(CASE WHEN e.summary_recommendation IS NULL OR e.summary_recommendation = 'PENDING' THEN 1 ELSE 0 END) AS pend_n
       FROM dbo.evaluations e
      WHERE $where
      GROUP BY CONVERT(varchar(7), e.created_at, 126) ORDER BY ym",
    $params
);

$byDrug = q(
    "SELECT d.code, d.generic_name, COUNT(*) AS n
       FROM dbo.evaluations e JOIN dbo.drugs d ON d.id = e.drug_id
      WHERE $where GROUP BY d.code, d.generic_name ORDER BY d.code",
    $params
);

$topFail = q(
    "SELECT TOP (1) ci.item_code, ci.label_th, COUNT(*) AS n
       FROM dbo.evaluation_answers a
       JOIN dbo.criteria_items ci ON ci.id = a.criteria_item_id
       JOIN dbo.evaluations e ON e.id = a.evaluation_id
      WHERE a.outcome = 'fail' AND $where
      GROUP BY ci.item_code, ci.label_th ORDER BY n DESC",
    $params
);

$recent = q(
    "SELECT TOP (10) e.id, e.hn, e.status, e.summary_recommendation, e.unit_code, e.created_at,
            d.generic_name, u.full_name AS creator
       FROM dbo.evaluations e
       JOIN dbo.drugs d ON d.id = e.drug_id
       JOIN dbo.users u ON u.id = e.created_by
      WHERE $where
      ORDER BY e.created_at DESC",
    $params
);

$PAGE = ['title' => 'รายงาน DUE', 'active' => 'reports'];
require_once __DIR__ . '/inc/layout_top.php';
audit_write('export', 'report', null, ['unit' => $unit, 'months' => $months]);
?>
<div class="topnav" style="max-width:1000px">
  <form method="get" action="reports.php" style="display:flex;gap:10px;align-items:center">
    <label style="margin:0">หน่วยงาน
      <select name="unit" onchange="this.form.submit()">
        <option value="ALL">ทั้งหมด</option>
        <option value="CARDIO"<?= $unit === 'CARDIO' ? ' selected' : '' ?>>อายุรกรรมหัวใจ</option>
        <option value="ENDO"<?= $unit === 'ENDO' ? ' selected' : '' ?>>อายุรกรรมต่อมไร้ท่อ</option>
      </select>
    </label>
    <label style="margin:0">ช่วงเวลา
      <select name="months" onchange="this.form.submit()">
        <?php foreach ([3, 6, 12] as $m): ?>
          <option value="<?= $m ?>"<?= $months === $m ? ' selected' : '' ?>><?= $m ?> เดือนล่าสุด</option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</div>

<div class="kpi-grid">
  <div class="kpi blue"><div class="kpi-label">คำขอทั้งหมด</div><div class="kpi-value"><?= (int)$totals['total'] ?></div><div class="kpi-sub">ในช่วงที่เลือก</div></div>
  <div class="kpi green"><div class="kpi-label">ผ่านเกณฑ์</div><div class="kpi-value"><?= (int)($totals['pass_n'] ?? 0) ?></div><div class="kpi-sub"><?= $totals['total'] ? round(100 * (int)$totals['pass_n'] / (int)$totals['total']) : 0 ?> %</div></div>
  <div class="kpi red"><div class="kpi-label">ไม่ผ่านเกณฑ์</div><div class="kpi-value"><?= (int)($totals['fail_n'] ?? 0) ?></div><div class="kpi-sub"><?= $totals['total'] ? round(100 * (int)$totals['fail_n'] / (int)$totals['total']) : 0 ?> %</div></div>
  <div class="kpi amber"><div class="kpi-label">มูลค่ายารวม (บาท)</div><div class="kpi-value"><?= number_format((float)($totals['cost'] ?? 0)) ?></div><div class="kpi-sub">ประมาณการจากราคาในระบบ</div></div>
  <div class="kpi purple"><div class="kpi-label">รายการที่ fail บ่อยสุด</div><div class="kpi-value" style="font-size:18px;padding-top:8px"><?= $topFail ? h($topFail[0]['item_code']) : '—' ?></div><div class="kpi-sub"><?= $topFail ? h($topFail[0]['label_th']) : 'ไม่มีข้อมูล' ?></div></div>
</div>

<div class="charts-row">
  <div class="chart-card">
    <div class="chart-title">แนวโน้มรายเดือน (ผ่าน / ไม่ผ่าน / รอ)</div>
    <div class="chart-wrap" style="height:230px"><canvas id="trend"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-title">จำนวนตามชนิดยา</div>
    <div class="chart-wrap" style="height:230px"><canvas id="drugs"></canvas></div>
  </div>
</div>

<div class="table-card" style="margin-top:16px">
  <table>
    <thead><tr><th>วันที่</th><th>#</th><th>HN</th><th>ยา</th><th>หน่วย</th><th>ผล</th><th>สถานะ</th><th>ผู้ขอ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r):
        $rec = strtolower((string)$r['summary_recommendation']);
        $pill = $rec === 'pass' ? 'v-pass' : ($rec === 'fail' ? 'v-fail' : 'v-warn'); ?>
      <tr>
        <td><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
        <td><?= (int)$r['id'] ?></td><td><?= h($r['hn']) ?></td><td><?= h($r['generic_name']) ?></td><td><?= h($r['unit_code']) ?></td>
        <td><span class="verdict-pill <?= $pill ?>"><?= h($r['summary_recommendation'] ?? 'PENDING') ?></span></td>
        <td><?= h($r['status']) ?></td><td><?= h($r['creator']) ?></td>
        <td><a href="print_approval.php?id=<?= (int)$r['id'] ?>">พิมพ์</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script src="assets/vendor/chart.umd.min.js"></script>
<script>
(function () {
  var trend = <?= json_encode($monthly, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var drugs = <?= json_encode($byDrug, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var TH = "'Noto Sans Thai','Segoe UI',sans-serif";
  Chart.defaults.font.family = TH;
  new Chart(document.getElementById('trend'), {
    type: 'bar',
    data: {
      labels: trend.map(r => r.ym),
      datasets: [
        { label: 'ผ่าน', data: trend.map(r => +r.pass_n), backgroundColor: '#059669', stack: 's' },
        { label: 'ไม่ผ่าน', data: trend.map(r => +r.fail_n), backgroundColor: '#dc2626', stack: 's' },
        { label: 'รอ', data: trend.map(r => +r.pend_n), backgroundColor: '#f59e0b', stack: 's' }
      ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
  });
  new Chart(document.getElementById('drugs'), {
    type: 'bar',
    data: {
      labels: drugs.map(r => r.code),
      datasets: [{ label: 'คำขอ', data: drugs.map(r => +r.n), backgroundColor: ['#1a56db', '#7c3aed', '#0d9488'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
})();
</script>
<?php require_once __DIR__ . '/inc/layout_bottom.php'; ?>
