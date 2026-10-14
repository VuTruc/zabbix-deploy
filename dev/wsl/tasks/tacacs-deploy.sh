# Deploy latest TACACS module from repo to this DEV host, then (optionally) apply config.
#   run.sh tacacs-deploy           -> backup + deploy module/collector/generator + dry-run diff (tac_plus.conf untouched)
#   run.sh tacacs-deploy --apply   -> additionally regenerate /etc/tac_plus.conf from DB and restart tac_plus
set -Eeuo pipefail
APPLY=0; [ "${1:-}" = "--apply" ] && APPLY=1
sec(){ echo; echo "===== $*"; }
red(){ sed -E 's/(key[[:space:]]*=[[:space:]]*).*/\1"***"/; s/(login[[:space:]]*=[[:space:]]*(des|crypt|cleartext)[[:space:]]+).*/\1***/'; }
TS=$(date +%Y%m%d-%H%M%S); BK=/var/backups/tacacs-dev/$TS; install -d -m 0700 "$BK"
GEN=/usr/local/bin/tacacs-config-generator

sec "1. Pre-flight"
/usr/sbin/tac_plus -C /etc/tac_plus.conf -P >/dev/null 2>&1 && echo "current tac_plus.conf: parser OK"

sec "2. Backups -> $BK"
cp -a /etc/tac_plus.conf "$BK/"; [ -f "$GEN" ] && cp -a "$GEN" "$BK/"
cp -a /usr/share/zabbix/modules/Tacacs "$BK/Tacacs-module"
DB=$(php -r '$c=parse_ini_file("/etc/tacacs/tacacs-db.conf"); echo $c["database"];')
mysqldump --single-transaction --no-tablespaces "$DB" > "$BK/$DB.sql" && echo "DB dump: $BK/$DB.sql ($(wc -c <"$BK/$DB.sql") bytes)"
ls -la "$BK"

sec "3. Move old module backups out of /usr/share/zabbix/modules"
install -d -m 0700 /var/backups/zabbix-modules/legacy
for d in /usr/share/zabbix/modules/Tacacs.*; do [ -d "$d" ] && mv -n "$d" /var/backups/zabbix-modules/legacy/ && echo "moved $(basename "$d")"; done
ls /usr/share/zabbix/modules

sec "4. Run install/11-tacacs.sh from repo"
bash "$REPO/install/11-tacacs.sh"

sec "5. Verify deployed files == repo"
( cd "$REPO/install/tacacs-module" && find . -type f ) | while read -r f; do cmp -s "$REPO/install/tacacs-module/$f" "/usr/share/zabbix/modules/Tacacs/$f" || echo "DIFF $f"; done; echo "module compare done"
cmp -s "$REPO/install/tacacs/tacacs-config-generator.php" "$GEN" && echo "generator: SAME as repo"
cmp -s "$REPO/install/tacacs/tacacs-audit-collector.php" /usr/local/libexec/tacacs-audit-collector.php && echo "collector: SAME as repo"
systemctl is-active tacacs-audit-collector.timer || true; systemctl is-enabled tacacs-audit-collector.timer || true
systemctl start tacacs-audit-collector.service; sleep 1
journalctl -u tacacs-audit-collector.service --no-pager -n 8
php -r '$c=parse_ini_file("/etc/tacacs/tacacs-db.conf");$p=new PDO("mysql:host={$c["host"]};dbname={$c["database"]}",$c["username"],$c["password"]);echo "audit_log rows: ",$p->query("SELECT COUNT(*) FROM audit_log")->fetchColumn(),"\n";'

sec "6. Dry-run generator -> /tmp, diff vs current /etc/tac_plus.conf (redacted)"
T=$(mktemp -d); sed "s#const OUTPUT_CONFIG = '/etc/tac_plus.conf';#const OUTPUT_CONFIG = '$T/tac_plus.conf';#" "$GEN" > "$T/gen.php"
php "$T/gen.php" || { echo "[ERROR] generator dry-run failed; nothing applied"; exit 1; }
diff <(red </etc/tac_plus.conf) <(red <"$T/tac_plus.conf") && echo "(no difference)" || true
echo "--- generated config (redacted)"; red <"$T/tac_plus.conf"; rm -rf "$T"

if [ $APPLY -eq 1 ]; then
  sec "7. APPLY: regenerate /etc/tac_plus.conf and restart tac_plus"
  php "$GEN"
  /usr/sbin/tac_plus -C /etc/tac_plus.conf -P >/dev/null 2>&1 && echo "parser OK"
  systemctl restart tac_plus; sleep 1; systemctl is-active tac_plus
  for p in $(pgrep -x tac_plus); do echo "tac_plus pid=$p started: $(ps -o lstart= -p $p)"; done
  stat -c '%y  %n' /etc/tac_plus.conf
else
  echo; echo "[INFO] tac_plus.conf NOT changed. Re-run with --apply to regenerate and restart tac_plus."
fi

sec "Rollback"
echo "module:    rm -rf /usr/share/zabbix/modules/Tacacs && cp -a $BK/Tacacs-module /usr/share/zabbix/modules/Tacacs"
echo "generator: cp -a $BK/tacacs-config-generator $GEN"
echo "config:    cp -a $BK/tac_plus.conf /etc/tac_plus.conf && systemctl restart tac_plus"
echo "db:        mysql $DB < $BK/$DB.sql"
echo "timer:     systemctl disable --now tacacs-audit-collector.timer"
