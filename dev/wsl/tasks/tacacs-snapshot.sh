# Read-only: snapshot deployed TACACS module + DB/collector state for comparison with repo.
# Copies files only into dev/wsl/logs/host/ (git-ignored). Prints no passwords.
sec(){ echo; echo "===== $*"; }
OUT="$REPO/dev/wsl/logs/host"; rm -rf "$OUT/Tacacs"; mkdir -p "$OUT"
cp -a /usr/share/zabbix/modules/Tacacs "$OUT/Tacacs" && echo "copied deployed module -> dev/wsl/logs/host/Tacacs"
for f in /usr/local/libexec/tacacs-audit-collector.php /usr/local/libexec/tacacs-config-generator.php /usr/local/bin/tacacs-config-generator* ; do
  [ -f "$f" ] && cp -f "$f" "$OUT/" && echo "copied $f"; done
ls -la /usr/local/libexec/ /usr/local/bin/ 2>/dev/null | grep -i tacacs
sec "Module dirs mtime"
ls -ld --time-style=+%F_%T /usr/share/zabbix/modules/Tacacs*
sec "Zabbix DB: custom modules"
mysql -N zabbix -e "SELECT moduleid,id,relative_path,status FROM module WHERE relative_path NOT LIKE 'widgets/%'" 2>&1
sec "TACACS DB (connection from /etc/tacacs/tacacs-db.conf, password not printed)"
php -r '
$c=parse_ini_file("/etc/tacacs/tacacs-db.conf"); echo "host=",$c["host"]," db=",$c["database"]," user=",$c["username"],"\n";
$p=new PDO("mysql:host={$c["host"]};dbname={$c["database"]};charset=utf8mb4",$c["username"],$c["password"]);
foreach($p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t){ $n=$p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); echo str_pad($t,32)," rows=$n\n"; }
foreach(["audit_log"] as $t){ echo "\n-- $t columns:\n"; foreach($p->query("SHOW COLUMNS FROM $t") as $r) echo "  ",$r["Field"]," ",$r["Type"],"\n"; }
$r=$p->query("SELECT MIN(id),MAX(id) FROM audit_log")->fetch(PDO::FETCH_NUM); echo "audit_log id range: ",implode("..",$r),"\n";
' 2>&1
sec "Collector / timer history"
systemctl list-timers --all 2>/dev/null | grep -i tacacs
journalctl -u tacacs-audit-collector.service -u tacacs-audit-collector.timer --no-pager -n 30 2>&1 | tail -30
sec "Accounting log (size/lines only)"
wc -l /var/log/tacacs/accounting.log /var/log/tac_plus.log 2>/dev/null
grep -n "accounting\|logfile\|log =" /etc/tac_plus.conf 2>/dev/null | grep -vi "key\|pass" | head
ls -la /var/lib/tacacs* 2>/dev/null
sec "Generated config validation"
tac_plus -C /etc/tac_plus.conf -P >/dev/null 2>&1 && echo "tac_plus -P: OK" || echo "tac_plus -P: FAIL"
