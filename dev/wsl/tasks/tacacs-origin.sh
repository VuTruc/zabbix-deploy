# Read-only: how was tac_plus installed on this host? (to automate it in install.sh)
sec(){ echo; echo "===== $*"; }
sec "Binary"
ls -la /usr/sbin/tac_plus* /usr/local/sbin/tac_plus* /usr/local/bin/tac_plus* 2>/dev/null
/usr/sbin/tac_plus -v 2>&1 | head -2
rpm -qf /usr/sbin/tac_plus 2>&1
rpm -qa | grep -iE 'tacacs|tac_plus|libwrap|tcp_wrappers|tcpd' 
ldd /usr/sbin/tac_plus 2>&1
ls -la /usr/lib64/libtacacs* /usr/local/lib/libtacacs* 2>/dev/null
sec "dnf history mentioning build tools / tacacs"
dnf history list 2>/dev/null | head -25
dnf history list 2>/dev/null | awk 'NR>2{print $1}' | grep -E '^[0-9]+$' | head -40 | while read id; do dnf history info $id 2>/dev/null | grep -iE 'tacacs|tac_plus|bison|flex|pam-devel|libtirpc|gcc|rpm-build' | sed "s/^/[tx $id] /"; done | head -40
sec "Source/build leftovers"
find / -xdev \( -iname 'tacacs-F4*' -o -iname 'tac_plus*.tar*' -o -iname 'tacacs.spec' -o -iname 'tac_plus' -type d \) 2>/dev/null | grep -v '^/proc' | head -20
ls -la ~root/rpmbuild/RPMS/*/ 2>/dev/null
sec "systemd unit"
systemctl cat tac_plus 2>&1 | head -40
sec "Config files (metadata only)"
ls -la /etc/tac_plus.conf /etc/tacacs/ /var/log/tacacs/ /var/log/tac_plus.log 2>&1
echo "tacacs-db.conf keys:"; sed -E 's/=.*/= .../' /etc/tacacs/tacacs-db.conf 2>/dev/null
sec "DB user grants (root socket, no passwords shown)"
mysql -N -e "SELECT user,host FROM mysql.user WHERE user LIKE '%tacacs%'" 2>&1
for uh in $(mysql -N -e "SELECT CONCAT(\"'\",user,\"'@'\",host,\"'\") FROM mysql.user WHERE user LIKE '%tacacs%'" 2>/dev/null); do mysql -N -e "SHOW GRANTS FOR $uh" 2>&1 | sed -E 's/IDENTIFIED BY PASSWORD .*//'; done
mysql -N -e "SHOW DATABASES LIKE '%tacacs%'" 2>&1
sec "Firewall / PAM"
firewall-cmd --list-all 2>&1 | head -15
ls /etc/pam.d | grep -i tac
