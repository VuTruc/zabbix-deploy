# Read-only diagnosis: why deleting an assignment/rule does not revoke access.
# Changes nothing in /etc or the DB. Secrets/hashes are redacted.
sec(){ echo; echo "===== $*"; }
red(){ sed -E 's/(key[[:space:]]*=[[:space:]]*).*/\1"***"/; s/(login[[:space:]]*=[[:space:]]*(des|crypt|cleartext)[[:space:]]+).*/\1***/'; }
sec "tac_plus.conf vs daemon start time"
stat -c '%y  %n' /etc/tac_plus.conf
for p in $(pgrep -x tac_plus); do echo "tac_plus pid=$p started: $(ps -o lstart= -p $p)"; done
systemctl show tac_plus -p FragmentPath -p ExecStart -p ExecReload 2>/dev/null | cut -c1-250
sec "Who regenerates the config? (cron/systemd/sudoers/php references)"
grep -rIl "tacacs-config-generator\|tac_plus.conf" /etc/systemd /etc/cron* /etc/sudoers /etc/sudoers.d /usr/share/zabbix/modules/Tacacs /etc/incron* 2>/dev/null
sec "Current /etc/tac_plus.conf (redacted)"
red < /etc/tac_plus.conf
sec "DB content (no hashes/secrets)"
php -r '
$c=parse_ini_file("/etc/tacacs/tacacs-db.conf");
$p=new PDO("mysql:host={$c["host"]};dbname={$c["database"]};charset=utf8mb4",$c["username"],$c["password"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=["users"=>"SELECT id,username,enabled FROM users","devices"=>"SELECT id,name,address,enabled FROM devices",
"policies"=>"SELECT * FROM policies","policy_rules"=>"SELECT * FROM policy_rules","assignments"=>"SELECT * FROM assignments",
"user_policies"=>"SELECT * FROM user_policies","user_devices"=>"SELECT * FROM user_devices","device_policies"=>"SELECT * FROM device_policies",
"assignment_page_security(cols)"=>"SHOW COLUMNS FROM assignment_page_security"];
foreach($q as $n=>$s){ echo "-- $n\n"; foreach($p->query($s,PDO::FETCH_ASSOC) as $r){ if(isset($r["Field"])){echo "  ",$r["Field"],"\n";continue;} echo "  ",json_encode($r,JSON_UNESCAPED_SLASHES),"\n";} }' 2>&1
sec "Dry-run: repo generator -> /tmp (does NOT touch /etc)"
T=$(mktemp -d); tr -d '\r' < "$REPO/install/tacacs/tacacs-config-generator.php" | sed "s#const OUTPUT_CONFIG = '/etc/tac_plus.conf';#const OUTPUT_CONFIG = '$T/tac_plus.conf';#" > "$T/gen.php"
grep -q "$T/tac_plus.conf" "$T/gen.php" && php "$T/gen.php" 2>&1
[ -f "$T/tac_plus.conf" ] && { echo "--- diff current(/etc) vs repo-generated (redacted)"; diff <(red </etc/tac_plus.conf) <(red <"$T/tac_plus.conf"); }
rm -rf "$T"
sec "Recent accounting (last 15)"
tail -15 /var/log/tacacs/accounting.log
sec "Recent tac_plus.log (last 20)"
tail -20 /var/log/tac_plus.log
