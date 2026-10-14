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

# ============================================================
# Disable SELinux
# ============================================================

disable_selinux() {

    echo "[INFO] Checking SELinux..."

    if command -v getenforce >/dev/null 2>&1; then

        CURRENT_SELINUX="$(getenforce 2>/dev/null || true)"

        if [ "$CURRENT_SELINUX" != "Disabled" ]; then

            echo "[INFO] Disabling SELinux..."

            setenforce 0 2>/dev/null || true

            if command -v grubby >/dev/null 2>&1; then

                grubby --update-kernel ALL --args selinux=0

            else

                echo "[ERROR] grubby command was not found."
                exit 1
            fi

        fi

        echo "[OK] SELinux disabled."

    else

        echo "[INFO] SELinux tools are not installed."
        echo "[OK] Nothing to configure."

    fi
}

disable_selinux

echo "[1/11] System preparation"
bash "$SCRIPT_DIR/install/01-system.sh"

echo "[2/11] Network configuration"
bash "$SCRIPT_DIR/install/02-network.sh"

echo "[3/11] MariaDB"
bash "$SCRIPT_DIR/install/03-mariadb.sh"

echo "[4/11] Zabbix"
bash "$SCRIPT_DIR/install/04-zabbix.sh"

echo "[5/11] Apache and PHP"
bash "$SCRIPT_DIR/install/05-apache-php.sh"

echo "[6/11] Centralized Syslog"
bash "$SCRIPT_DIR/install/06-rsyslog.sh"

echo "[7/11] Logrotate"
bash "$SCRIPT_DIR/install/07-logrotate.sh"

echo "[8/11] Oxidized"
bash "$SCRIPT_DIR/install/08-oxidized.sh"

echo "[9/11] Telegram"
while true; do
    if bash "$SCRIPT_DIR/install/09-telegram.sh"; then
        break
    fi

    echo
    echo "============================================================"
    echo "[WARN] Telegram configuration was not completed."
    echo "[WARN] Returning to Telegram configuration..."
    echo "============================================================"
    echo
done

echo "[10/11] Network Syslog Integration"
bash "$SCRIPT_DIR/install/10-network-syslog.sh"

echo "[11/11] TACACS+ Integration (optional)"

# INSTALL_TACACS=yes|no can be set in the environment for unattended runs.
INSTALL_TACACS="${INSTALL_TACACS:-}"
while [[ "$INSTALL_TACACS" != "yes" && "$INSTALL_TACACS" != "no" ]]; do
    echo
    echo "TACACS+ adds the tac_plus daemon (TCP/49), its own database and the"
    echo "Zabbix TACACS+ module. After installation there are no TACACS users,"
    echo "devices, policies or assignments; add them in Zabbix > TACACS+."
    read -r -p "Install TACACS+? [y/N]: " answer
    case "${answer,,}" in
        y|yes) INSTALL_TACACS="yes" ;;
        ""|n|no) INSTALL_TACACS="no" ;;
        *) echo "Please answer y or n." ;;
    esac
done

if [[ "$INSTALL_TACACS" == "yes" ]]; then
    bash "$SCRIPT_DIR/install/11-tacacs-runtime.sh"
    bash "$SCRIPT_DIR/install/11-tacacs.sh"
    # Generate tac_plus.conf from the (empty) TACACS database once.
    /usr/local/sbin/tacacs-apply-config
else
    echo "[SKIP] TACACS+ was not selected."
fi

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

