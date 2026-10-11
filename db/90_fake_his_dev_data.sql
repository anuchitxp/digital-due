-- db/90_fake_his_dev_data.sql — DEV ONLY fake HIS schema + views with SYNTHETIC patients
-- (never load real patient data into a dev environment — docs/06 §3, docs/08 §5)
USE DUE_DB;
GO
IF SCHEMA_ID('his_dup') IS NULL EXEC('CREATE SCHEMA his_dup');
GO
DROP VIEW IF EXISTS his_dup.vw_DUE_Visits;
DROP VIEW IF EXISTS his_dup.vw_DUE_Dispense;
DROP VIEW IF EXISTS his_dup.vw_DUE_LabLipid;
DROP VIEW IF EXISTS his_dup.vw_DUE_Diagnoses;
DROP VIEW IF EXISTS his_dup.vw_DUE_Patient;
DROP TABLE IF EXISTS his_dup.dispense;
DROP TABLE IF EXISTS his_dup.lab_results;
DROP TABLE IF EXISTS his_dup.diagnoses;
DROP TABLE IF EXISTS his_dup.patients;
GO
CREATE TABLE his_dup.patients (
  hn varchar(16) PRIMARY KEY, thai_name nvarchar(192), en_name nvarchar(192),
  birth_date date, sex nvarchar(8), weight_kg decimal(5,2),
  privilege_code varchar(24), privilege_name nvarchar(64), is_active bit
);
CREATE TABLE his_dup.diagnoses (
  hn varchar(16), icd10 varchar(8), icd10_name_th nvarchar(128), dx_date date, dx_type varchar(8)
);
CREATE TABLE his_dup.lab_results (
  hn varchar(16), lab_date date, item_code varchar(12), item_name nvarchar(64),
  value_num decimal(10,2), unit nvarchar(16), is_abnormal bit
);
CREATE TABLE his_dup.dispense (
  hn varchar(16), date_dispensed date, drug_code varchar(16), generic_name nvarchar(128),
  dose nvarchar(32), dose_unit nvarchar(16), quantity int, days_supply int, prescriber_unit varchar(24)
);
GO
INSERT INTO his_dup.patients VALUES
  ('6500012', N'สมชาย ใจดี*',   N'Somchai Jaidee*',   '1963-04-12', N'ช',   74, 'CSMBS',  N'ข้าราชการ (CSMBS)', 1),
  ('6500033', N'สมหญิง รักงาน*', N'Somying Rakngan*',  '1967-09-02', N'หญิง', 66, 'CSMBS',  N'ข้าราชการ (CSMBS)', 1),
  ('6600045', N'ประเสริฐ มั่นคง*', N'Prasert Mankong*', '1958-01-20', N'ช',   71, 'CSMBS',  N'ข้าราชการ (CSMBS)', 1),
  ('6600078', N'มาลี สุขใส*',    N'Malee Suksai*',     '1971-06-15', N'หญิง', 68, 'UCS',    N'สิทธิบัตรทอง (UCS)', 1),
  ('6500090', N'วิชัย เพียรงาน*', N'Wichai Piengngan*', '1954-11-30', N'ช',   63, 'CSMBS',  N'ข้าราชการ (CSMBS)', 1);
GO
INSERT INTO his_dup.diagnoses VALUES
  ('6500012','I21.9',N'MI เฉียบพลัน (post-STEMI)','2024-02-10','chronic'),
  ('6500012','I25.1',N'CAD เอกสารแล้ว','2024-02-10','chronic'),
  ('6500012','E11.9',N'DM type 2','2020-05-01','chronic'),
  ('6500033','E78.01',N'FH (Familial Hypercholesterolemia)','2019-08-01','chronic'),
  ('6500033','E11.9',N'DM type 2','2021-03-11','chronic'),
  ('6600045','I63.3',N'Ischemic stroke','2023-11-05','chronic'),
  ('6600045','I25.1',N'CAD','2023-11-05','chronic'),
  ('6600078','E11.9',N'DM type 2','2022-01-09','chronic'),
  ('6600078','E78.5',N'Dyslipidemia','2022-01-09','chronic'),
  ('6500090','I25.1',N'CAD','2022-07-19','chronic'),
  ('6500090','N18.3',N'CKD stage 3','2023-02-02','chronic');
GO
INSERT INTO his_dup.lab_results VALUES
  ('6500012','2025-08-10','LDL_C',N'LDL-C',118.00,N'mg/dL',1),
  ('6500012','2025-08-10','HDL_C',N'HDL-C',42.00, N'mg/dL',0),
  ('6500012','2025-08-10','TG',   N'Triglyceride',165.00,N'mg/dL',1),
  ('6500012','2025-08-10','ALT',  N'ALT (SGPT)',28.00, N'U/L',0),
  ('6500012','2025-08-10','CK',   N'Creatine kinase',110.00,N'U/L',0),
  ('6500033','2025-07-22','LDL_C',N'LDL-C',156.00,N'mg/dL',1),
  ('6500033','2025-07-22','HDL_C',N'HDL-C',48.00, N'mg/dL',0),
  ('6500033','2025-07-22','TG',   N'Triglyceride',140.00,N'mg/dL',1),
  ('6600045','2025-08-30','LDL_C',N'LDL-C',61.00, N'mg/dL',0),
  ('6600045','2025-02-20','LDL_C',N'LDL-C',142.00,N'mg/dL',1),  -- ก่อนเริ่ม evolocumab
  ('6600078','2025-08-05','LDL_C',N'LDL-C',132.00,N'mg/dL',1),
  ('6600078','2025-08-05','TG',   N'Triglyceride',210.00,N'mg/dL',1),
  ('6500090','2025-08-18','LDL_C',N'LDL-C',96.00, N'mg/dL',1),
  ('6500090','2025-08-18','ALT',  N'ALT (SGPT)',22.00, N'U/L',0),
  ('6500090','2025-08-18','CK',   N'Creatine kinase',145.00,N'U/L',1);
GO
INSERT INTO his_dup.dispense VALUES
  ('6500012','2025-02-01','ATORVA', N'Atorvastatin', N'40→80', N'mg', 90, 90, 'CARDIO'),
  ('6500012','2025-05-02','ATORVA', N'Atorvastatin', N'80',    N'mg', 90, 90, 'CARDIO'),
  ('6500012','2025-06-01','EZET',   N'Ezetimibe',    N'10',    N'mg', 90, 90, 'CARDIO'),
  ('6500033','2025-01-05','ROSUVA', N'Rosuvastatin', N'20',    N'mg', 30, 30, 'ENDO'),
  ('6600045','2024-09-01','ROSUVA', N'Rosuvastatin', N'20',    N'mg', 90, 90, 'CARDIO'),
  ('6600045','2024-10-01','EZET',   N'Ezetimibe',    N'10',    N'mg', 180,180, 'CARDIO'),
  ('6600045','2025-03-01','EVO',    N'Evolocumab',   N'140',   N'mg', 2,  28, 'CARDIO'),  -- renewal case
  ('6600045','2025-03-29','EVO',    N'Evolocumab',   N'140',   N'mg', 2,  28, 'CARDIO'),
  ('6600078','2025-04-10','ATORVA', N'Atorvastatin', N'40',    N'mg', 120,120, 'ENDO'),
  ('6500090','2024-06-01','SIMVA',  N'Simvastatin',  N'20',    N'mg', 60, 60, 'CARDIO'),
  ('6500090','2024-08-01','ATORVA', N'Atorvastatin', N'20',    N'mg', 30, 30, 'CARDIO');
GO
CREATE VIEW his_dup.vw_DUE_Patient AS
SELECT hn, thai_name, en_name, birth_date, sex, weight_kg, privilege_code, privilege_name, is_active
FROM his_dup.patients;
GO
CREATE VIEW his_dup.vw_DUE_Diagnoses AS
SELECT hn, icd10, icd10_name_th, dx_date, dx_type
FROM his_dup.diagnoses;
GO
CREATE VIEW his_dup.vw_DUE_LabLipid AS
SELECT hn, lab_date, item_code, item_name, value_num, unit, is_abnormal
FROM his_dup.lab_results;
GO
CREATE VIEW his_dup.vw_DUE_Dispense AS
SELECT hn, date_dispensed, drug_code, generic_name, dose, dose_unit, quantity, days_supply, prescriber_unit
FROM his_dup.dispense;
GO
CREATE VIEW his_dup.vw_DUE_Visits AS
SELECT p.hn, visit_id = CAST(p.hn AS bigint), visit_datetime = CONVERT(datetime, '2025-09-01'),
       clinic_code = CASE WHEN p.hn IN ('6500012','6600045','6500090') THEN 'CARDIO' ELSE 'ENDO' END,
       provider_id = NULL
FROM his_dup.patients p;
GO
PRINT 'db/90 done (DEV fake HIS — synthetic only)';
