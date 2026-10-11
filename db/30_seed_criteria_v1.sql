-- db/30_seed_criteria_v1.sql — criteria v1.0-draft per docs/03 (PLACEHOLDER values —
-- committee must validate against current CSMBS circulars before production; docs/03 §5)
USE DUE_DB;
GO
INSERT INTO dbo.criteria_versions (drug_id, version_no, title_th, title_en, source_ref, effective_from, is_published)
SELECT d.id, '1.0-draft', v.title_th, v.title_en, N'PLACEHOLDER — รอคณะกรรมการยืนยัน (docs/03 §5)', CONVERT(date, '2025-10-01'), 1
FROM (VALUES
  ('PITAVA', N'เกณฑ์การใช้ Pitavastatin ตัวต้นแบบ (Livalo®)', N'Original-brand pitavastatin DUE criteria'),
  ('EVO',    N'เกณฑ์การใช้ Evolocumab (Repatha®)',            N'Evolocumab DUE criteria'),
  ('INCL',   N'เกณฑ์การใช้ Inclisiran (Sybrava®)',            N'Inclisiran DUE criteria')
) AS v(code, title_th, title_en)
JOIN dbo.drugs d ON d.code = v.code
WHERE NOT EXISTS (SELECT 1 FROM dbo.criteria_versions cv WHERE cv.drug_id = d.id AND cv.version_no = '1.0-draft');
GO
-- ---------- items ----------
INSERT INTO dbo.criteria_items
  (criteria_version_id, item_code, display_order, label_th, label_en, item_type, is_mandatory, unit, options_json, auto_binding_json)
SELECT cv.id, v.item_code, v.ord, v.th, v.en, v.itype, v.mand, v.unit, v.opts, v.binding
FROM (VALUES
  -- ---------------- PITAVASTATIN (docs/03 §3.1) ----------------
  ('PITAVA','P1',1,N'ข้อบ่งชี้ที่สนับสนุนการใช้ statin','Diagnosis justifying statin therapy','single_choice',1,NULL,
    N'["secondary_prevention","primary_prevention"]', NULL),
  ('PITAVA','P2',2,N'statin สูตรยาขนาดสูงสุด ≥3 เดือน แล้ว LDL เกินเป้า','Generic statin at max dose ≥3 months, LDL above goal','boolean',1,NULL,
    NULL, N'{"view":"vw_DUE_Dispense","check":"months_on_class","class":"statin","op":"gte","value":3,"lookback_days":365}'),
  ('PITAVA','P3',3,N'หรือ แพ้/ไม่สามารถใช้ statin ได้ ≥2 ตัว (ระบุ)','Or intolerance to ≥2 statins (detail)','boolean',0,NULL, NULL, NULL),
  ('PITAVA','P4',4,N'LDL-C ปัจจุบัน (mg/dL) — ต้องสูงกว่าเป้าหมายตามกลุ่ม','Current LDL-C above risk-based goal','numeric',1,N'mg/dL',
    NULL, N'{"view":"vw_DUE_LabLipid","field":"ldl_c","op":"gt","value":100,"lookback_days":180}'),
  ('PITAVA','P5',5,N'ไม่มีข้อห้ามใช้ (โรคตับ active, ตั้งครรภ์ ฯลฯ)','No contraindication (active liver disease, pregnancy)','boolean',1,NULL, NULL, NULL),
  ('PITAVA','P6',6,N'ถ้าใช้ cyclosporine: ขนาด ≤1 mg/day','If on cyclosporine: dose ≤1 mg/day','boolean',0,NULL, NULL, NULL),
  ('PITAVA','P7',7,N'ขนาดและระยะเวลาที่ขอ','Planned dose and duration','dose',1,NULL, NULL, NULL),
  ('PITAVA','P8',8,N'แผนติดตาม lipid panel ที่ 4–12 สัปดาห์','Monitoring plan: lipid panel at 4–12 weeks','boolean',1,NULL, NULL, NULL),
  -- ---------------- EVOLOCUMAB (docs/03 §3.2) ----------------
  ('EVO','E1',1,N'ข้อบ่งชี้: ASCVD เชิงเลือด หรือ hypercholesterolemia รุนแรง/สูง (รวม FH)','Clinical ASCVD or severe/high-risk hypercholesterolemia (incl. FH)','multi_choice',1,NULL,
    N'["post_acs","pci_cabg","ischemic_stroke_tia","pad","severe_hc_fh"]',
    N'{"view":"vw_DUE_Diagnoses","check":"icd_any","icd_prefixes":["I21","I22","I25","I63","I70","E78"],"lookback_days":1825}'),
  ('EVO','E2',2,N'statin ขนาดสูงสุด ≥3 เดือน (ระบุขนาด/ผล)','Maximally tolerated statin ≥3 months (dose, % LDL reduction)','boolean',1,NULL,
    NULL, N'{"view":"vw_DUE_Dispense","check":"months_on_class","class":"statin","op":"gte","value":3,"lookback_days":365}'),
  ('EVO','E3',3,N'หากไม่ได้ใช้ statin: มีข้อมูล statin intolerance','If statin not used: documented intolerance','boolean',0,NULL, NULL, NULL),
  ('EVO','E4',4,N'ezetimibe ≥3 เดือน (หรือมีข้อห้าม)','Ezetimibe trial ≥3 months (or contraindication)','boolean',1,NULL,
    NULL, N'{"view":"vw_DUE_Dispense","check":"months_on_class","class":"ezetimibe","op":"gte","value":3,"lookback_days":365}'),
  ('EVO','E5',5,N'LDL-C ≥ เกณฑ์เริ่มยา (secondary ≥70 / severe-FH ≥100 mg/dL)','LDL-C above initiation threshold (70 / 100 mg/dL by risk)','numeric',1,N'mg/dL',
    NULL, N'{"view":"vw_DUE_LabLipid","field":"ldl_c","op":"gte","value":70,"lookback_days":180}'),
  ('EVO','E6',6,N'ไม่มีการใช้ยากลุ่ม PCSK9 คู่ขนาน/ซ้ำ','No duplicate PCSK9-class therapy','boolean',1,NULL, NULL, NULL),
  ('EVO','E7',7,N'สถานะการตั้งครรภ์/การคุมกำเนิด (ถ้าเกี่ยวข้อง)','Pregnancy/contraception status where applicable','single_choice',0,NULL,
    N'["na","not_pregnant","pregnant","contraception"]', NULL),
  ('EVO','E8',8,N'แผนการฉันยา (SC 140 q2w / 420 q4w) + ฝึกฉันเอง','Injection plan (SC 140 q2w / 420 q4w) + self-injection training','dose',1,NULL, NULL, NULL),
  ('EVO','E9',9,N'กฎต่อเนื่อง: ที่ 3 เดือน LDL ลด ≥50% หรือ <70 มิฉะนั้นประเมินหยุด','Continuation rule at 3 months: LDL ↓≥50% or <70 else review stop','boolean',1,NULL, NULL, NULL),
  -- ---------------- INCLISIRAN (docs/03 §3.3) ----------------
  ('INCL','I1',1,N'ข้อบ่งชี้เดียวกับ E1 (ตามถ้อยคำผู้จ่ายเงิน — รอยืนยัน)','Same indication gate as E1 (payer wording TBC)','multi_choice',1,NULL,
    N'["post_acs","pci_cabg","ischemic_stroke_tia","pad","severe_hc_fh"]', NULL),
  ('INCL','I2',2,N'statin ขนาดสูงสุด ± ezetimibe ครบระยะตามเกณฑ์','Max tolerated statin ± ezetimibe for required duration','boolean',1,NULL,
    NULL, N'{"view":"vw_DUE_Dispense","check":"months_on_class","class":"statin","op":"gte","value":3,"lookback_days":365}'),
  ('INCL','I3',3,N'ประวัติ PCSK9 mAb: ล้มเหลว/ไม่สามารถใช้ (หรือ payer อนุญาตเป็นเส้นแรก — รอยืนยัน)','Prior PCSK9 mAb failure/intolerance (or first-line allowed — TBC)','single_choice',1,NULL,
    N'["failed","intolerant","never_used"]', NULL),
  ('INCL','I4',4,N'LDL-C ≥ เกณฑ์เริ่มยา (ค่าตามเวอร์ชัน)','LDL-C above initiation threshold (version value)','numeric',1,N'mg/dL',
    NULL, N'{"view":"vw_DUE_LabLipid","field":"ldl_c","op":"gte","value":100,"lookback_days":180}'),
  ('INCL','I5',5,N'ยอมรับตารางฉัน: วันที่ 0 → 3 ด. → ทุก 6 ด.','Dosing schedule acknowledged: day 0 → 3 mo → q6mo','boolean',1,NULL, NULL, NULL),
  ('INCL','I6',6,N'การต่อเนื่องต้องมี LDL ใหม่ + ผ่าน response rule','Renewal requires interval LDL + response rule','boolean',0,NULL, NULL, NULL),
  ('INCL','I7',7,N'ไม่มีข้อห้าม / ประเมินการตั้งครรภ์','No contraindication / pregnancy check','boolean',1,NULL, NULL, NULL)
) AS v(code, item_code, ord, th, en, itype, mand, unit, opts, binding)
JOIN dbo.drugs d ON d.code = v.code
JOIN dbo.criteria_versions cv ON cv.drug_id = d.id AND cv.version_no = '1.0-draft'
WHERE NOT EXISTS (
  SELECT 1 FROM dbo.criteria_items ci
  WHERE ci.criteria_version_id = cv.id AND ci.item_code = v.item_code);
GO
PRINT 'db/30 done';
