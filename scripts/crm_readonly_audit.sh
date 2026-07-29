#!/usr/bin/env bash
# =============================================================================
#  SuiteCRM READ-ONLY inventory / audit  (crmga live box)
# -----------------------------------------------------------------------------
#  SAFE BY DESIGN:
#    * Only reads: cat / ls / find / grep, and SQL SHOW / SELECT / COUNT /
#      information_schema. No INSERT/UPDATE/DELETE/DDL, no file writes to the app,
#      no repair/optimize. It cannot change your CRM or its data.
#    * DB credentials are read from SuiteCRM's own config.php and passed to mysql
#      only via the MYSQL_PWD env var (never on a command line, never printed).
#    * Password columns (SMTP pass, IMAP pass, DB pass) are deliberately EXCLUDED
#      from all output, so the report is safe to share.
#
#  RUN AS ROOT ON THE SERVER:
#      sudo bash crm_readonly_audit.sh
#  Optional overrides:
#      APP=/home/crmga/public_html LEGACY=... PHP=/usr/local/bin/ea-php82 \
#      OUT=/root/crm_audit.txt  sudo -E bash crm_readonly_audit.sh
# =============================================================================
set +e
export LC_ALL=C

APP="${APP:-/home/crmga/public_html}"
LEGACY="${LEGACY:-$APP/public/legacy}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
[ -x "$PHP" ] || PHP="$(command -v php 2>/dev/null || echo php)"
TS="$(date +%Y%m%d_%H%M%S 2>/dev/null || echo now)"
OUT="${OUT:-/root/crm_readonly_audit_$TS.txt}"

hr(){ echo; echo "==================== $* ===================="; }
sub(){ echo; echo "----- $* -----"; }

# --- resolve DB creds from SuiteCRM config (password used only via MYSQL_PWD) ---
CREDS="$("$PHP" -r '
  $f=$argv[1];
  if(!is_file($f)){ fwrite(STDERR,"config not found: $f\n"); exit(3); }
  $sugar_config=array(); include $f;
  $d=isset($sugar_config["dbconfig"])?$sugar_config["dbconfig"]:array();
  echo ($d["db_host_name"]??"localhost")."\t".($d["db_user_name"]??"")."\t".($d["db_name"]??"")."\t".($d["db_password"]??"");
' "$LEGACY/config.php" 2>/dev/null)"
IFS=$'\t' read -r DBHOST DBUSER DBNAME DBPASS <<<"$CREDS"
DBHOST="${DBHOST:-${DB_HOST:-localhost}}"; DBUSER="${DBUSER:-${DB_USER:-}}"
DBNAME="${DBNAME:-${DB_NAME:-}}";           DBPASS="${DBPASS:-${DB_PASS:-}}"
export MYSQL_PWD="$DBPASS"
MYSQL="mysql -h $DBHOST -u $DBUSER $DBNAME"
Q(){ $MYSQL -N -e "$1" 2>&1; }     # tab output, no headers
T(){ $MYSQL -t -e "$1" 2>&1; }     # pretty table output

{
hr "SuiteCRM READ-ONLY AUDIT  ($TS)"
echo "APP=$APP"; echo "LEGACY=$LEGACY"; echo "PHP=$PHP"
echo "DB: host=$DBHOST  user=$DBUSER  name=$DBNAME  (password hidden)"
[ -z "$DBUSER" ] && echo "!! Could not read DB creds from config.php — set DB_USER/DB_NAME/DB_PASS env and re-run for DB sections."

hr "1. ENVIRONMENT"
echo "host: $(hostname 2>/dev/null)"; uname -a 2>/dev/null
"$PHP" -v 2>&1 | head -3
sub "PHP extensions (relevant)"; "$PHP" -m 2>/dev/null | grep -iE 'mysqli|pdo|imap|curl|mbstring|gd|zip|opcache|redis|memcache|soap|ldap|intl' | sort | tr '\n' ' '; echo
sub "cron for crmga (SuiteCRM scheduler runner)"; crontab -l -u crmga 2>/dev/null; crontab -l 2>/dev/null | grep -i cron.php
sub "app disk usage"; du -sh "$APP" 2>/dev/null; df -h "$APP" 2>/dev/null | tail -2

hr "2. VERSION / EDITION"
echo "# composer.json (name + version + suitecrm requires):"
"$PHP" -r '$j=json_decode(@file_get_contents($argv[1]),true); if($j){echo "name=".($j["name"]??"?")."  version=".($j["version"]??"?")."\n"; foreach(($j["require"]??[]) as $k=>$v){ if(stripos($k,"suitecrm")!==false||stripos($k,"salesagility")!==false) echo "  require: $k $v\n";}}' "$APP/composer.json" 2>/dev/null
echo "# legacy sugar_version.php:"; "$PHP" -r '$sugar_version=$sugar_flavor="";include $argv[1]; echo "sugar_version=$sugar_version  flavor=$sugar_flavor\n";' "$LEGACY/sugar_version.php" 2>/dev/null
echo "# suitecrm_version.php:"; cat "$LEGACY/suitecrm_version.php" 2>/dev/null | grep -iE 'version' | head
echo "# VERSION files:"; cat "$APP/VERSION" 2>/dev/null; cat "$LEGACY/VERSION" 2>/dev/null

hr "3. DATABASE OVERVIEW"
sub "server + db size + table count"
T "SELECT VERSION() AS mysql_version;"
T "SELECT COUNT(*) AS tables, ROUND(SUM(data_length+index_length)/1024/1024,1) AS total_mb, ROUND(SUM(data_length)/1024/1024,1) AS data_mb, ROUND(SUM(index_length)/1024/1024,1) AS index_mb FROM information_schema.tables WHERE table_schema=DATABASE();"
sub "per-table size + estimated rows (top 80 by rows)"
T "SELECT table_name, engine, table_rows, ROUND((data_length+index_length)/1024/1024,2) AS size_mb, table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_rows DESC LIMIT 80;"

hr "4. MODULES WHICH HAVE DATA (exact active-record counts)"
echo "# For every bean table that has a 'deleted' column: total rows and active (deleted=0)."
echo "# Only tables with total>0 are shown, sorted by active desc."
TBLS="$(Q "SELECT t.table_name FROM information_schema.tables t JOIN information_schema.columns c ON c.table_schema=t.table_schema AND c.table_name=t.table_name WHERE t.table_schema=DATABASE() AND t.table_type='BASE TABLE' AND c.column_name='deleted' ORDER BY t.table_name;")"
{
  printf "TABLE\tTOTAL\tACTIVE\n"
  for tbl in $TBLS; do
    line="$(Q "SELECT '$tbl', COUNT(*), SUM(deleted=0) FROM \`$tbl\`;")"
    printf "%s\n" "$line"
  done
} | awk -F'\t' 'NR==1{print;next} $2+0>0{print}' | sort -t$'\t' -k3 -nr | column -t -s$'\t' | head -120

hr "5. ACTIVE / INSTALLED MODULES"
sub "legacy module list (compiled modules.ext.php -> \$moduleList)"
"$PHP" -r '$moduleList=array();$f=$argv[1]; if(is_file($f)){include $f; sort($moduleList); echo count($moduleList)." modules:\n"; echo implode(", ",$moduleList),"\n";} else echo "not found: $f\n";' "$LEGACY/custom/application/Ext/Include/modules.ext.php" 2>/dev/null
sub "deployed CUSTOM module dirs (custom/modules)"; ls -1 "$LEGACY/custom/modules" 2>/dev/null
sub "SuiteCRM-8 extensions (public app extensions)"; ls -1 "$APP/extensions" 2>/dev/null
sub "Studio/custom fields count per module (fields_meta_data)"
T "SELECT custom_module, COUNT(*) AS custom_fields FROM fields_meta_data WHERE deleted=0 GROUP BY custom_module ORDER BY custom_fields DESC;"

hr "6. INSTALLED PLUGINS / PACKAGES (Module Loader + composer)"
sub "upgrade_history (installed packages / plugins / patches)"
T "SELECT date_entered, type, name, version, status FROM upgrade_history ORDER BY date_entered DESC LIMIT 100;"
sub "third-party composer packages (non salesagility/suitecrm core)"
"$PHP" -r '$j=json_decode(@file_get_contents($argv[1]),true); foreach(($j["require"]??[]) as $k=>$v){ if(stripos($k,"suitecrm")===false && stripos($k,"salesagility")===false && strpos($k,"php")!==0 && strpos($k,"ext-")!==0) echo "  $k: $v\n"; }' "$APP/composer.json" 2>/dev/null

hr "7. EMAIL SETUP"
sub "outbound (SMTP) accounts  [passwords excluded]"
T "SELECT id, name, type, mail_sendtype, mail_smtptype, mail_smtpserver, mail_smtpport, mail_smtpuser, mail_smtpauth_req, mail_smtpssl FROM outbound_email;"
sub "inbound mailboxes  [passwords excluded]"
T "SELECT id, name, status, server_url, port, protocol, email_user, mailbox, is_personal, is_ssl, delete_seen FROM inbound_email WHERE deleted=0;"
sub "mail-related system config (config.php)  [pass keys excluded]"
"$PHP" -r '$sugar_config=array();include $argv[1]; function w($a,$p=""){foreach($a as $k=>$v){$key=$p.$k; if(stripos($key,"pass")!==false||stripos($key,"secret")!==false||stripos($key,"key")!==false){continue;} if(is_array($v)) w($v,$key."."); else if(preg_match("/mail|smtp|imap|notify|email/i",$key)) echo "  $key = ".(is_bool($v)?($v?"true":"false"):$v)."\n"; }} w($sugar_config);' "$LEGACY/config.php" 2>/dev/null

hr "8. EMAIL TEMPLATES"
T "SELECT COUNT(*) AS total_active FROM email_templates WHERE deleted=0;"
T "SELECT name, type, published, date_entered FROM email_templates WHERE deleted=0 ORDER BY date_entered DESC LIMIT 100;"

hr "9. SCHEDULERS (jobs)"
echo "# status Active/Inactive, interval is cron-like; last_run shows if it fires."
T "SELECT name, job, status, job_interval, date_time_start, date_time_end, last_run FROM schedulers WHERE deleted=0 ORDER BY status, name;"
sub "recent job_queue runs (last 30)"
T "SELECT name, status, resolution, date_entered, date_modified FROM job_queue ORDER BY date_entered DESC LIMIT 30;" 2>/dev/null

hr "10. CUSTOM SCHEDULERS (custom job code)"
sub "custom Scheduler functions"; cat "$LEGACY/custom/modules/Schedulers/functions.php" 2>/dev/null | head -200
sub "custom scheduled-task extensions"; ls -1 "$LEGACY/custom/Extension/modules/Schedulers/Ext/ScheduledTasks/" 2>/dev/null; echo "--- contents ---"; for f in "$LEGACY"/custom/Extension/modules/Schedulers/Ext/ScheduledTasks/*.php; do [ -f "$f" ] && { echo "### $f"; cat "$f"; }; done 2>/dev/null | head -300

hr "11. CUSTOM LOGIC HOOKS"
sub "application-level compiled hooks"; cat "$LEGACY/custom/application/Ext/LogicHooks/logichooks.ext.php" 2>/dev/null | head -200
sub "all logic_hooks.php / LogicHook extension files under custom/"
find "$LEGACY/custom" -type f \( -name 'logic_hooks.php' -o -path '*Ext/LogicHooks/*.php' \) 2>/dev/null | sort
echo "--- contents (each file) ---"
while IFS= read -r f; do [ -f "$f" ] && { echo "### $f"; cat "$f"; echo; }; done < <(find "$LEGACY/custom" -type f \( -name 'logic_hooks.php' -o -path '*Ext/LogicHooks/*.php' \) 2>/dev/null | sort) | head -500
sub "custom hook CLASS files referenced (custom/modules/*/*.php excluding metadata)"
find "$LEGACY/custom/modules" -maxdepth 2 -type f -name '*.php' 2>/dev/null | grep -viE 'Ext/|metadata|language|vardefs' | sort | head -80

hr "12. TELEPHONY / CLICK-TO-DIAL / SMS / ASTERISK"
sub "module & file names matching telephony/sms keywords"
find "$APP" -maxdepth 6 -iregex '.*\(asterisk\|clicktodial\|click2dial\|click-to-dial\|c2call\|clicktocall\|softphone\|webrtc\|\bcti\b\|\bvoip\b\|\bsip\b\|\bami\b\|\bari\b\|twilio\|plivo\|vonage\|nexmo\|textmagic\|\bsms\b\|3cx\|freepbx\|issabel\|vicidial\).*' 2>/dev/null | grep -viE '/node_modules/|/vendor/(symfony|doctrine|laminas)/' | sort | head -120
sub "DB tables matching telephony/sms"
for pat in asterisk cti voip sip dial sms twilio call softphone; do echo "# LIKE '%$pat%':"; Q "SHOW TABLES LIKE '%$pat%';"; done
sub "config keys matching phone/sms/asterisk/twilio"
"$PHP" -r '$sugar_config=array();include $argv[1]; function w($a,$p=""){foreach($a as $k=>$v){$key=$p.$k; if(preg_match("/pass|secret|token|key/i",$key))continue; if(is_array($v)) w($v,$key."."); else if(preg_match("/phone|dial|asterisk|sip|voip|sms|twilio|cti|call/i",$key)) echo "  $key = $v\n"; }} w($sugar_config);' "$LEGACY/config.php" 2>/dev/null
sub "click-to-dial hints in custom JS/theme (tel: links / dialers)"
grep -rilE 'clickToDial|click2dial|tel:|softphone|webrtc|jssip|sipml|asterisk|originate' "$LEGACY/custom" "$APP/extensions" 2>/dev/null | grep -viE '/vendor/|/node_modules/' | sort | head -40

hr "13. CUSTOMIZATION FOOTPRINT (overview)"
sub "custom/ tree (2 levels)"; find "$LEGACY/custom" -maxdepth 2 -type d 2>/dev/null | sort | head -120
sub "custom workflows (AOW) count"; T "SELECT COUNT(*) AS active_workflows FROM aow_workflow WHERE deleted=0;" 2>/dev/null
sub "roles / security groups"; T "SELECT COUNT(*) roles FROM acl_roles WHERE deleted=0;" 2>/dev/null; T "SELECT COUNT(*) security_groups FROM securitygroups WHERE deleted=0;" 2>/dev/null
sub "users (active/total)"; T "SELECT SUM(status='Active') AS active_users, COUNT(*) AS total_users FROM users WHERE deleted=0;" 2>/dev/null

hr "DONE"
echo "Report written to: $OUT"
echo "NOTE: password columns/keys were excluded. Review before sharing if you wish; SMTP/IMAP server+user and DB host/user/name ARE included (no passwords)."
} 2>&1 | tee "$OUT"

# scrub the exported password from this shell's environment
unset MYSQL_PWD DBPASS
echo
echo ">>> Saved: $OUT   (size: $(du -h "$OUT" 2>/dev/null | cut -f1))"
