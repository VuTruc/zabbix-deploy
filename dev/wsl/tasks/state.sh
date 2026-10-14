# Read-only inventory of the dev host. Does NOT print secrets or change anything.
sec(){ echo; echo "===== $*"; }
sec OS
cat /etc/os-release | grep -E '^(PRETTY_NAME|VERSION_ID)='; uname -r
echo "PID1: $(ps -p 1 -o comm=)"; getenforce 2>/dev/null || echo "getenforce: n/a"
sec Versions
for c in "zabbix_server -V" "zabbix_agentd -V" "php -v" "ruby -v" "mysql --version" "httpd -v" "tac_plus -v"; do
  printf '%-18s ' "${c%% *}"; $c 2>/dev/null | head -1 || echo "n/a"; done
rpm -q zabbix-server-mysql zabbix-web zabbix-agent 2>/dev/null
command -v oxidized >/dev/null && echo "oxidized: $(oxidized --version 2>/dev/null | head -1)" || ls /usr/local/bin/oxidized 2>/dev/null
sec Services
for s in mariadb zabbix-server zabbix-agent httpd php-fpm rsyslog oxidized oxidized-node-scheduler.timer tac_plus tacacs-audit-collector.timer firewalld NetworkManager; do
  printf '%-32s %-10s %s\n' "$s" "$(systemctl is-active $s 2>/dev/null)" "$(systemctl is-enabled $s 2>/dev/null)"; done
sec Listening ports
ss -lntup 2>/dev/null | awk 'NR==1 || $5 ~ /:(80|8000|8888|3306|10050|10051|514|49)$/' | cut -c1-160
sec "Zabbix frontend modules (deployed)"
ls -1 /usr/share/zabbix/modules 2>/dev/null || echo "none"
sec "Deployed vs repo (CR-stripped md5 compare)"
cmp_dir(){ src="$1"; dst="$2"; [ -d "$dst" ] || { echo "MISSING $dst"; return; }
  ( cd "$src" && find . -type f ) | while read -r f; do
    a=$(tr -d '\r' < "$src/$f" | md5sum | cut -c1-8); b=$( [ -f "$dst/$f" ] && md5sum < "$dst/$f" | cut -c1-8 || echo missing)
    [ "$a" = "$b" ] || echo "DIFF $dst/${f#./} (repo=$a host=$b)"; done; echo "checked $dst"; }
for m in /usr/share/zabbix/modules/*/; do grep -l '"id"' "$m/manifest.json" >/dev/null 2>&1 && echo "$m id=$(grep -o '"id"[^,]*' $m/manifest.json)"; done
cmp_dir "$REPO/install/oxidized-module" /usr/share/zabbix/modules/Oxidized
cmp_dir "$REPO/install/tacacs-module" /usr/share/zabbix/modules/Tacacs
cmp_dir "$REPO/install/zabbix/modules/NetworkSyslog" /usr/share/zabbix/modules/NetworkSyslog
for p in /usr/local/bin/oxidized-node-scheduler /usr/local/bin/zabbix-network-syslog-discovery; do
  n=$(basename $p); s=$(find "$REPO/install" -name "${n#zabbix-}" -type f | head -1)
  [ -f "$p" ] && [ -n "$s" ] && { [ "$(tr -d '\r'<"$s"|md5sum)" = "$(md5sum<"$p")" ] && echo "SAME $p" || echo "DIFF $p"; } || echo "skip $p"; done
sec TACACS files
for f in /usr/sbin/tac_plus /etc/tac_plus.conf /etc/tacacs/tacacs-db.conf; do [ -e $f ] && echo "present $f" || echo "absent  $f"; done
ls -la /opt/tacacs* /usr/local/lib/tacacs* 2>/dev/null | head
sec Network syslog
ls /var/log/network 2>/dev/null | head -20; echo "dirs: $(ls /var/log/network 2>/dev/null | wc -l)"
sec Oxidized
ls -la /home/oxidized/.config/oxidized 2>/dev/null; echo "nodes in router.db: $(grep -cv '^\s*#' /home/oxidized/.config/oxidized/router.db 2>/dev/null)"
sec "Env files (names only)"
ls -la /etc/zabbix/*.env 2>/dev/null
sec "Zabbix server log tail (errors)"
grep -iE 'error|fail' /var/log/zabbix/zabbix_server.log 2>/dev/null | tail -15

sec "Zabbix DB: registered modules (read-only)"
mysql -N zabbix -e "SELECT moduleid,id,relative_path,status FROM module" 2>&1 | head -30
sec "TACACS audit collector units"
systemctl cat tacacs-audit-collector.timer 2>&1 | head -20
systemctl status tacacs-audit-collector.service --no-pager 2>&1 | head -12
ls -la /var/log/tac_plus* /var/log/tacacs* 2>/dev/null | head
