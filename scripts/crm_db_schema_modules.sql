-- ============================================================================
--  crmga  READ-ONLY  schema + module-data report   (MySQL / MariaDB)
--  100% read-only: only SELECT / SHOW / PREPARE+EXECUTE of a SELECT.
--  No INSERT/UPDATE/DELETE, no CREATE/ALTER/DROP, no temp tables. Cannot change
--  data. Safe to run on production.
--
--  RUN (writes a report file you can upload):
--    MYSQL_PWD='YOUR_DB_PASSWORD' mysql -u crmga_crm_admin crmga_crm_prod -t \
--        < crm_db_schema_modules.sql > crm_db_report.txt 2>&1
--  (or, matching your notes:)
--    mysql -u crmga_crm_admin -p'YOUR_DB_PASSWORD' crmga_crm_prod -t \
--        < crm_db_schema_modules.sql > crm_db_report.txt 2>&1
-- ============================================================================

SELECT '===== 0. TARGET DB / SERVER =====' AS section;
SELECT DATABASE() AS db_name, VERSION() AS server_version, NOW() AS report_time,
       @@character_set_server AS charset, @@collation_server AS collation;

SELECT '===== 1. DATABASE SIZE + TABLE COUNT =====' AS section;
SELECT COUNT(*)                                              AS tables,
       ROUND(SUM(data_length+index_length)/1024/1024,1)      AS total_mb,
       ROUND(SUM(data_length)/1024/1024,1)                   AS data_mb,
       ROUND(SUM(index_length)/1024/1024,1)                  AS index_mb
FROM information_schema.tables
WHERE table_schema = DATABASE();

SELECT '===== 2. EVERY TABLE: engine / est.rows / size / collation (sorted by rows) =====' AS section;
SELECT table_name,
       engine,
       table_rows                                         AS est_rows,
       ROUND((data_length+index_length)/1024/1024,2)      AS size_mb,
       table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE'
ORDER BY table_rows DESC;

SELECT '===== 3. MODULES WITH DATA: EXACT total + active(deleted=0) for EVERY bean table =====' AS section;
-- Build one UNION-ALL COUNT query across every table that has a `deleted` column
-- (i.e. every SuiteCRM bean/module table), then run it. Exact counts, not estimates.
SET SESSION group_concat_max_len = 8000000;
SELECT GROUP_CONCAT(
         CONCAT('SELECT ''', table_name,
                ''' AS module_table, COUNT(*) AS total_rows, ',
                'SUM(CASE WHEN deleted=0 THEN 1 ELSE 0 END) AS active_rows ',
                'FROM `', table_name, '`')
         SEPARATOR ' UNION ALL ')
  INTO @cnt_sql
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name = 'deleted';
SET @cnt_sql = CONCAT('SELECT * FROM (', @cnt_sql,
                      ') z WHERE total_rows > 0 ORDER BY active_rows DESC, total_rows DESC');
PREPARE s FROM @cnt_sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT '===== 4. CUSTOMIZED MODULES: custom fields per module (Studio) =====' AS section;
SELECT custom_module, COUNT(*) AS custom_fields
FROM fields_meta_data
WHERE deleted = 0
GROUP BY custom_module
ORDER BY custom_fields DESC;

SELECT '===== 5. INSTALLED PACKAGES / PLUGINS (Module Loader history) =====' AS section;
SELECT date_entered, type, name, version, status
FROM upgrade_history
ORDER BY date_entered DESC;

SELECT '===== 6. EMAIL: outbound SMTP accounts (passwords excluded) =====' AS section;
SELECT id, name, type, mail_sendtype, mail_smtptype, mail_smtpserver,
       mail_smtpport, mail_smtpuser, mail_smtpauth_req, mail_smtpssl
FROM outbound_email;

SELECT '===== 7. EMAIL: inbound mailboxes (passwords excluded) =====' AS section;
SELECT id, name, status, server_url, port, protocol, email_user, mailbox,
       is_personal, is_ssl
FROM inbound_email
WHERE deleted = 0;

SELECT '===== 8. EMAIL TEMPLATES =====' AS section;
SELECT COUNT(*) AS total_active FROM email_templates WHERE deleted = 0;
SELECT name, type, published, date_entered
FROM email_templates
WHERE deleted = 0
ORDER BY date_entered DESC;

SELECT '===== 9. SCHEDULERS (jobs, status, interval, last run) =====' AS section;
SELECT name, job, status, job_interval, date_time_start, date_time_end, last_run
FROM schedulers
WHERE deleted = 0
ORDER BY status, name;

SELECT '===== 10. TELEPHONY / SMS tables present (asterisk / cti / voip / sip / dial / sms / twilio / call) =====' AS section;
SELECT table_name, table_rows AS est_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND ( table_name LIKE '%asterisk%' OR table_name LIKE '%cti%'
     OR table_name LIKE '%voip%'     OR table_name LIKE '%sip%'
     OR table_name LIKE '%dial%'     OR table_name LIKE '%sms%'
     OR table_name LIKE '%twilio%'   OR table_name LIKE '%call%' )
ORDER BY table_name;

SELECT '===== 11. FULL COLUMN SCHEMA (table, position, column, type, null, key, default) =====' AS section;
SELECT table_name, ordinal_position AS pos, column_name, column_type,
       is_nullable AS nullable, column_key AS ky, column_default AS dflt
FROM information_schema.columns
WHERE table_schema = DATABASE()
ORDER BY table_name, ordinal_position;

SELECT '===== 12. INDEXES (per table / key) =====' AS section;
SELECT table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

SELECT '===== END OF REPORT =====' AS section;
