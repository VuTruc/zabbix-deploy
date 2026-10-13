#!/bin/bash

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=============================================="
echo " Zabbix Monitoring Deployment"
echo "=============================================="
echo

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run this installer as root."
    echo "ERROR: Hãy chạy installer bằng tài khoản root."
    exit 1
fi

echo "[1/9] System preparation"
bash "$SCRIPT_DIR/install/01-system.sh"

echo "[2/9] Network configuration"
bash "$SCRIPT_DIR/install/02-network.sh"

echo "[3/9] MariaDB"
bash "$SCRIPT_DIR/install/03-mariadb.sh"

echo "[4/9] Zabbix"
bash "$SCRIPT_DIR/install/04-zabbix.sh"

echo "[5/9] Apache and PHP"
bash "$SCRIPT_DIR/install/05-apache-php.sh"

echo "[6/9] Centralized Syslog"
bash "$SCRIPT_DIR/install/06-rsyslog.sh"

echo "[7/9] Logrotate"
bash "$SCRIPT_DIR/install/07-logrotate.sh"

echo "[8/9] Oxidized"
bash "$SCRIPT_DIR/install/08-oxidized.sh"

echo "[9/9] Telegram"
bash "$SCRIPT_DIR/install/09-telegram.sh"

echo
echo "=============================================="
echo " Installation completed"
echo "=============================================="

echo
echo "=============================================="
echo " Zabbix Web Access"
echo "=============================================="

SERVER_IP="$(hostname -I | awk '{print $1}')"

echo
echo "Access Zabbix Web interface using:"
echo
echo "  Localhost:"
echo "    http://localhost:8000/zabbix/"
echo
echo "  Server IP:"
echo "    http://${SERVER_IP}:8000/zabbix/"
echo
echo "Default login:"
echo "  Username: Admin"
echo "  Password: zabbix"
echo
echo "IMPORTANT:"
echo "  Please change the default Admin password immediately"
echo "  after the first login for security."
echo

