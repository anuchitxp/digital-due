-- db/40_seed_users_roles.sql — roles, demo users, app SQL login (dev defaults; production: IT/DBA adapts)
USE DUE_DB;
GO
INSERT INTO dbo.roles (code)
SELECT v.code FROM (VALUES ('physician'),('clinic_staff'),('due_reviewer'),('admin')) AS v(code)
WHERE NOT EXISTS (SELECT 1 FROM dbo.roles r WHERE r.code = v.code);
GO
INSERT INTO dbo.users (login_name, full_name, license_no)
SELECT v.login_name, v.full_name, v.lic
FROM (VALUES
  ('HOSP\due_doc1',   N'นพ.ทดสอบ หัวใจดี (CARDIO)',   N'MD-0001'),
  ('HOSP\due_doc2',   N'พญ.ทดสอบ ต่อมดี (ENDO)',      N'MD-0002'),
  ('HOSP\due_nurse1', N'พย.ทดสอบ ช่วยเหลือ',           NULL),
  ('HOSP\due_pharm1', N'ภก.ทดสอบ ทวนสอบ',              NULL),
  ('HOSP\due_admin1', N'ผู้ดูแลระบบ DUE',              NULL)
) AS v(login_name, full_name, lic)
WHERE NOT EXISTS (SELECT 1 FROM dbo.users u WHERE u.login_name = v.login_name);
GO
INSERT INTO dbo.user_roles (user_id, role_id)
SELECT u.id, r.id
FROM (VALUES
  ('HOSP\due_doc1',   'physician'),
  ('HOSP\due_doc2',   'physician'),
  ('HOSP\due_nurse1', 'clinic_staff'),
  ('HOSP\due_pharm1', 'due_reviewer'),
  ('HOSP\due_pharm1', 'admin'),          -- dev convenience; split in production (docs/08)
  ('HOSP\due_admin1', 'admin')
) AS v(login_name, role_code)
JOIN dbo.users u ON u.login_name = v.login_name
JOIN dbo.roles r ON r.code = v.role_code
WHERE NOT EXISTS (SELECT 1 FROM dbo.user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id);
GO
-- ---------- app SQL login for PHP (least privilege; docs/07 §2) ----------
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = 'due_app')
  CREATE LOGIN due_app WITH PASSWORD = 'Due#App-2024!', CHECK_POLICY = ON;
GO
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = 'due_app')
  CREATE USER due_app FOR LOGIN due_app;
GO
ALTER ROLE db_datareader ADD MEMBER due_app;
ALTER ROLE db_datawriter ADD MEMBER due_app;
GO
DENY UPDATE, DELETE ON dbo.decisions  TO due_app;
DENY UPDATE, DELETE ON dbo.audit_log  TO due_app;
-- NOTE: do NOT deny ALTER/CONTROL here — CONTROL implies INSERT and would break the app.
-- Append-only enforcement is by trigger (db/10) plus this targeted DENY.
GO
PRINT 'db/40 done';
