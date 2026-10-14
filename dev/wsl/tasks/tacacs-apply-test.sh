# End-to-end check of the apply mechanism, acting as the frontend user.
sec(){ echo; echo "===== $*"; }
G=$(stat -c %G /var/lib/tacacs-apply 2>/dev/null); U=$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2);print $2;exit}' /etc/php-fpm.d/zabbix.conf); U=${U:-apache}
sec "Units and permissions"
systemctl is-enabled tacacs-config-apply.path; systemctl is-active tacacs-config-apply.path
ls -la /var/lib/tacacs-apply; echo "frontend user=$U group=$G"
sec "Frontend user cannot create other files / cannot touch tac_plus.conf"
sudo -u "$U" touch /var/lib/tacacs-apply/evil 2>&1 | head -1; sudo -u "$U" sh -c 'echo x >> /etc/tac_plus.conf' 2>&1 | head -1
sec "Simulate a UI change (write request as $U)"
sudo -u "$U" php -r 'require "/usr/share/zabbix/modules/Tacacs/include/ApplyQueue.php"; var_dump(Modules\Tacacs\ApplyQueue::request("apply-test"));'
ID=$(php -r 'echo json_decode(file_get_contents("/var/lib/tacacs-apply/request"),true)["id"];'); echo "request id=$ID"
for i in $(seq 1 20); do sleep 1; grep -q "\"$ID\"" /var/lib/tacacs-apply/status.json 2>/dev/null && break; done
echo "waited ${i}s"; cat /var/lib/tacacs-apply/status.json
sec "Journal"
journalctl -u tacacs-config-apply.service -u tacacs-config-apply.path --no-pager -n 15
sec "Result"
stat -c '%y  %n' /etc/tac_plus.conf
for p in $(pgrep -x tac_plus); do echo "tac_plus pid=$p started: $(ps -o lstart= -p $p)"; done
grep -E '^(user|group|acl|host) ' /etc/tac_plus.conf
