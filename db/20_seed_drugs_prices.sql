-- db/20_seed_drugs_prices.sql — target drugs + placeholder prices (verify with pharmacy, docs/04 §5)
USE DUE_DB;
GO
INSERT INTO dbo.drugs (code, generic_name, brand_name, atc_code, route)
SELECT v.code, v.generic_name, v.brand_name, v.atc_code, v.route
FROM (VALUES
  ('PITAVA', N'Pitavastatin',      N'Livalo®',  'C10AA08', 'oral'),  -- ATC verify with pharmacy
  ('EVO',    N'Evolocumab',        N'Repatha®', 'C10AX13', 'sc'),    -- ATC verify with pharmacy
  ('INCL',   N'Inclisiran',        N'Sybrava®', 'C10AX14', 'sc')     -- ATC verify with pharmacy
) AS v(code, generic_name, brand_name, atc_code, route)
WHERE NOT EXISTS (SELECT 1 FROM dbo.drugs d WHERE d.code = v.code);
GO
INSERT INTO dbo.drug_prices (drug_id, pack_desc, unit_price, currency, effective_from)
SELECT d.id, v.pack_desc, v.unit_price, 'THB', CONVERT(date, '2025-10-01')
FROM (VALUES
  ('PITAVA', N'เม็ด 2 mg',          22.00),
  ('EVO',    N'แอมพูล 140 mg/mL',   2300.00),
  ('INCL',   N'แอมพูล 284 mg/1.5 mL', 8500.00)
) AS v(code, pack_desc, unit_price)
JOIN dbo.drugs d ON d.code = v.code
WHERE NOT EXISTS (
  SELECT 1 FROM dbo.drug_prices p
  WHERE p.drug_id = d.id AND p.effective_from = CONVERT(date, '2025-10-01'));
GO
PRINT 'db/20 done';
