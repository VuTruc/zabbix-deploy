# Read-only: why a Policy (Show-Only) assignment still gives full access.
sec(){ echo; echo "===== $*"; }
red(){ sed -E 's/(key[[:space:]]*=[[:space:]]*).*/\1"***"/; s/(login[[:space:]]*=[[:space:]]*(des|crypt|cleartext)[[:space:]]+).*/\1***/'; }
sec "Services"
for s in tac_plus tacacs-config-apply.path tacacs-audit-collector.timer httpd zabbix-server; do printf '%-30s %s\n' $s "$(systemctl is-active $s)"; done
for p in $(pgrep -x tac_plus); do echo "tac_plus pid=$p started: $(ps -o lstart= -p $p)  args: $(tr '\0' ' ' </proc/$p/cmdline)"; done
systemctl show tac_plus -p ExecStart | cut -c1-200
sec "Apply status"
cat /var/lib/tacacs-apply/status.json 2>/dev/null; echo; cat /var/lib/tacacs-apply/request 2>/dev/null
sec "DB: policies / rules / assignments"
php -r '
$c=parse_ini_file("/etc/tacacs/tacacs-db.conf");
$p=new PDO("mysql:host={$c["host"]};dbname={$c["database"]};charset=utf8mb4",$c["username"],$c["password"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(["SELECT * FROM policies","SELECT * FROM policy_rules","SELECT a.*,u.username,d.name dev,d.address FROM assignments a JOIN users u ON u.id=a.user_id JOIN devices d ON d.id=a.device_id"] as $s){ echo "-- $s\n"; foreach($p->query($s,PDO::FETCH_ASSOC) as $r) echo "  ",json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n"; }' 2>&1
sec "Active /etc/tac_plus.conf (redacted)"
stat -c '%y' /etc/tac_plus.conf; red </etc/tac_plus.conf
sec "tac_plus.log (last 60, redacted)"
tail -60 /var/log/tac_plus.log | red
sec "accounting.log (last 20)"
tail -20 /var/log/tacacs/accounting.log
