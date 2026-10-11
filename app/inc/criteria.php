<?php
/**
 * inc/criteria.php — criteria-as-data loader, HIS auto-check engine (auto_binding_json),
 * and the patient summary used by lookup/form pages. Thresholds live in the DB, never here
 * (docs/03 §1, docs/09 P2).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Latest published criteria version for a drug code (PITAVA|EVO|INCL). */
function current_criteria_version(string $drugCode): ?array
{
    $rows = q(
        'SELECT TOP (1) cv.*, d.code AS drug_code, d.generic_name, d.brand_name
           FROM dbo.criteria_versions cv
           JOIN dbo.drugs d ON d.id = cv.drug_id
          WHERE d.code = ? AND cv.is_published = 1
          ORDER BY cv.effective_from DESC, cv.id DESC',
        [$drugCode]
    );
    return $rows[0] ?? null;
}

function load_items(int $versionId): array
{
    return q(
        'SELECT * FROM dbo.criteria_items WHERE criteria_version_id = ? ORDER BY display_order, id',
        [$versionId]
    );
}

/**
 * Auto-check one item against HIS read-only views per its auto_binding_json.
 * Returns ['outcome' => pass|fail|pending, 'source' => auto|manual, 'evidence' => string].
 */
function auto_check(array $item, string $hn): array
{
    $binding = json_decode((string)($item['auto_binding_json'] ?? ''), true);
    if (!is_array($binding)) {
        return ['outcome' => 'pending', 'source' => 'manual', 'evidence' => ''];
    }
    try {
        switch ($binding['check'] ?? $binding['field'] ?? '') {
            case 'ldl_c':
                $val = scalar(
                    'SELECT TOP (1) value_num FROM dbo.vw_DUE_LabLipid
                      WHERE hn = ? AND item_code = ? AND lab_date >= DATEADD(day, -?, GETDATE())
                      ORDER BY lab_date DESC',
                    [$hn, 'LDL_C', (string)($binding['lookback_days'] ?? 180)]
                );
                if ($val === null) {
                    return ['outcome' => 'pending', 'source' => 'manual', 'evidence' => 'ไม่พบผลแล็บในช่วงเวลา'];
                }
                $thr = (float)($binding['value'] ?? 0);
                $ok = match ($binding['op'] ?? 'gte') {
                    'gte' => (float)$val >= $thr,
                    'gt'  => (float)$val > $thr,
                    'lte' => (float)$val <= $thr,
                    'lt'  => (float)$val < $thr,
                    default => false,
                };
                return [
                    'outcome' => $ok ? 'pass' : 'fail',
                    'source' => 'auto',
                    'evidence' => 'LDL-C ล่าสุด ' . (string)$val . ' mg/dL (เกณฑ์ ' . $binding['op'] . ' ' . $thr . ')',
                ];

            case 'months_on_class':
                $days = scalar(
                    'SELECT SUM(CAST(days_supply AS int)) FROM dbo.vw_DUE_Dispense
                      WHERE hn = ? AND drug_code = ? AND date_dispensed >= DATEADD(day, -?, GETDATE())',
                    [$hn, strtoupper((string)($binding['class'] ?? '')), (string)($binding['lookback_days'] ?? 365)]
                );
                if ($days === null) {
                    return ['outcome' => 'fail', 'source' => 'auto', 'evidence' => 'ไม่พบประวัติจ่ายยากลุ่มนี้'];
                }
                $months = ((float)$days) / 30.0;
                $thr = (float)($binding['value'] ?? 0);
                $ok = match ($binding['op'] ?? 'gte') {
                    'gte' => $months >= $thr,
                    'gt'  => $months > $thr,
                    default => false,
                };
                return [
                    'outcome' => $ok ? 'pass' : 'fail',
                    'source' => 'auto',
                    'evidence' => 'จ่ายสะสม ~' . round($months, 1) . ' เดือน (ต้องการ ≥' . $thr . ')',
                ];

            case 'icd_any':
                foreach ((array)($binding['icd_prefixes'] ?? []) as $i => $prefix) {
                    $hit = scalar(
                        'SELECT TOP (1) 1 FROM dbo.vw_DUE_Diagnoses
                          WHERE hn = ? AND icd10 LIKE ? AND dx_date >= DATEADD(day, -?, GETDATE())',
                        [$hn, $prefix . '%', (string)($binding['lookback_days'] ?? 1825)]
                    );
                    if ($hit !== null) {
                        return ['outcome' => 'pass', 'source' => 'auto', 'evidence' => 'พบ ICD ' . $prefix . '* ในเวชระเบียน'];
                    }
                }
                return ['outcome' => 'fail', 'source' => 'auto', 'evidence' => 'ไม่พบรหัสข้อบ่งชี้ที่กำหนด'];

            default:
                return ['outcome' => 'pending', 'source' => 'manual', 'evidence' => ''];
        }
    } catch (Throwable $e) {
        // HIS views unreachable → manual-entry fallback (FR-1.3); never hard-fail the form
        return ['outcome' => 'pending', 'source' => 'manual', 'evidence' => 'HIS ไม่พร้อมใช้งาน — กรอกด้วยตนเอง'];
    }
}

/** Server-side verdict from per-item outcomes (docs/03 §4). */
function compute_verdict(array $items, array $answers): string
{
    $verdict = 'PASS';
    foreach ($items as $item) {
        $a = $answers[$item['id']] ?? null;
        $outcome = $a['outcome'] ?? 'pending';
        if ($outcome === 'fail' && (int)$item['is_mandatory'] === 1) {
            return 'FAIL';
        }
        if (in_array($outcome, ['pending', 'fail'], true)) {
            $verdict = 'PENDING';
        }
    }
    return $verdict;
}

/** Patient summary (HIS read-only) for lookup + form pages. Returns null if HN unknown. */
function patient_summary(string $hn): ?array
{
    try {
        $p = q('SELECT * FROM dbo.vw_DUE_Patient WHERE hn = ? AND is_active = 1', [$hn]);
        if (!$p) {
            return null;
        }
        $p = $p[0];
        $labs = [];
        foreach (['LDL_C', 'HDL_C', 'TG', 'ALT', 'CK'] as $code) {
            $row = q(
                'SELECT TOP (1) value_num, unit, lab_date FROM dbo.vw_DUE_LabLipid
                  WHERE hn = ? AND item_code = ? ORDER BY lab_date DESC',
                [$hn, $code]
            );
            if ($row) {
                $labs[$code] = ['value' => (float)$row[0]['value_num'], 'date' => $row[0]['lab_date']];
            }
        }
        $dx = q(
            'SELECT DISTINCT icd10, icd10_name_th FROM dbo.vw_DUE_Diagnoses WHERE hn = ?',
            [$hn]
        );
        $hist = q(
            'SELECT drug_code, MAX(generic_name) AS generic_name,
                    SUM(CAST(days_supply AS int)) AS total_days, MAX(date_dispensed) AS last_date
               FROM dbo.vw_DUE_Dispense WHERE hn = ?
              GROUP BY drug_code ORDER BY drug_code',
            [$hn]
        );
        $age = null;
        if (!empty($p['birth_date'])) {
            $age = (new DateTime((string)$p['birth_date']))->diff(new DateTime('now'))->y;
        }
        return [
            'hn' => (string)$p['hn'],
            'name' => (string)($p['thai_name'] ?? $p['en_name']),
            'birth_date' => $p['birth_date'],
            'age' => $age,
            'sex' => (string)$p['sex'],
            'weight' => $p['weight_kg'] !== null ? (float)$p['weight_kg'] : null,
            'privilege' => (string)$p['privilege_code'],
            'is_csmbs' => strtoupper((string)$p['privilege_code']) === 'CSMBS',
            'labs' => $labs,
            'diagnoses' => $dx,
            'statin_history' => $hist,
        ];
    } catch (Throwable $e) {
        return null; // HIS down → manual entry path (FR-1.3)
    }
}
