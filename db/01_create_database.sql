-- db/01_create_database.sql — DUE_DB creation (idempotent, SQL Server 2019+)
IF DB_ID('DUE_DB') IS NULL
BEGIN
    CREATE DATABASE DUE_DB;
    PRINT 'DUE_DB created';
END
GO
USE DUE_DB;
GO
-- Thai-aware collation for name columns is set per-column in 10_schema.sql
PRINT 'db/01 done';
