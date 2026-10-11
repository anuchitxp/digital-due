-- db/50_his_synonyms.sql — point dbo synonyms at the HIS read-only views (dev: his_dup schema in 90;
-- production: DBA retargets synonyms to the real HIS views / linked server — docs/04 §3)
USE DUE_DB;
GO
-- synonyms are late-bound: safe to create before the views exist
IF OBJECT_ID('dbo.vw_DUE_Patient',   'SN') IS NOT NULL DROP SYNONYM dbo.vw_DUE_Patient;
IF OBJECT_ID('dbo.vw_DUE_Diagnoses', 'SN') IS NOT NULL DROP SYNONYM dbo.vw_DUE_Diagnoses;
IF OBJECT_ID('dbo.vw_DUE_LabLipid',  'SN') IS NOT NULL DROP SYNONYM dbo.vw_DUE_LabLipid;
IF OBJECT_ID('dbo.vw_DUE_Dispense',  'SN') IS NOT NULL DROP SYNONYM dbo.vw_DUE_Dispense;
IF OBJECT_ID('dbo.vw_DUE_Visits',    'SN') IS NOT NULL DROP SYNONYM dbo.vw_DUE_Visits;
GO
CREATE SYNONYM dbo.vw_DUE_Patient   FOR his_dup.vw_DUE_Patient;
CREATE SYNONYM dbo.vw_DUE_Diagnoses FOR his_dup.vw_DUE_Diagnoses;
CREATE SYNONYM dbo.vw_DUE_LabLipid  FOR his_dup.vw_DUE_LabLipid;
CREATE SYNONYM dbo.vw_DUE_Dispense  FOR his_dup.vw_DUE_Dispense;
CREATE SYNONYM dbo.vw_DUE_Visits    FOR his_dup.vw_DUE_Visits;
GO
PRINT 'db/50 done';
