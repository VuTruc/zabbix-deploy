#!/bin/bash



set -euo pipefail


# ============================================================
# Root check
# ============================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This module must be run as root."
    echo "ERROR: Module này phải được chạy bằng root."
    exit 1
fi

# ============================================================
# Operating system check
# ============================================================

source /etc/os-release

if [[ "$ID" != "ol" ]]; then
    echo "ERROR: Oracle Linux is required."
    echo "ERROR: Yêu cầu Oracle Linux."
    exit 1
fi

if [[ "$VERSION_ID" != 9* ]]; then
    echo "ERROR: Oracle Linux 9 is required."
    echo "ERROR: Yêu cầu Oracle Linux 9."
    exit 1
fi

echo
echo "[Rsyslog] Operating system:"
echo "       $PRETTY_NAME"

# ============================================================
# Install rsyslog and ACL tools
# ============================================================

echo
echo "[Rsyslog] Installing rsyslog and ACL tools..."

dnf install -y \
    rsyslog \
    acl

# ============================================================
# Create network log directory
# ============================================================

echo
echo "[Rsyslog] Creating network log directory..."

mkdir -p /var/log/network

chmod 755 /var/log/network

# ============================================================
# Configure centralized network syslog
# ============================================================

echo
echo "[Rsyslog] Creating centralized syslog configuration..."

cat > /etc/rsyslog.d/30-network-syslog.conf <<'EOF'
# ============================================================
# Centralized Network Syslog
# Managed by zabbix-monitoring-deploy
# ============================================================

# ------------------------------------------------------------
# Load UDP input module
# ------------------------------------------------------------

module(load="imudp")

# ------------------------------------------------------------
# Listen for network devices on UDP/514
# ------------------------------------------------------------

input(
    type="imudp"
    port="514"
)

# ------------------------------------------------------------
# Dynamic network log file
#
# Each network device gets its own directory:
#
# /var/log/network/<source-ip>/syslog.log
#
# Example:
#
# /var/log/network/192.168.18.1/syslog.log
# ------------------------------------------------------------

template(
    name="NetworkLogFile"
    type="string"
    string="/var/log/network/%fromhost-ip%/syslog.log"
)

# ------------------------------------------------------------
# Store remote network syslog messages
# ------------------------------------------------------------

if (
    $fromhost-ip != "127.0.0.1"
    and
    $fromhost-ip != "::1"
) then {

    action(
        type="omfile"
        dynaFile="NetworkLogFile"
        createDirs="on"
        dirCreateMode="0750"
        fileCreateMode="0640"
    )
}
EOF

# ============================================================
# Configure network event log
# ============================================================

echo
echo "[Rsyslog] Creating network event log configuration..."

cat > /etc/rsyslog.d/31-network-events.conf <<'EOF'
# ============================================================
# Network Event Log
# Managed by zabbix-monitoring-deploy
# ============================================================

template(
    name="NetworkEventLogFile"
    type="string"
    string="/var/log/network/%fromhost-ip%/events.log"
)

# ============================================================
# Network event detection
#
# Supports common event formats from:
#   - Cisco IOS / IOS-XE
#   - FortiGate
#   - Other network devices using common syslog patterns
# ============================================================

if (
    $msg contains 'type="event"'
    or
    $msg contains '%LINK-'
    or
    $msg contains '%LINEPROTO-'
    or
    $msg contains '%SYS-5-CONFIG_I'
    or
    $msg contains '%SYS-5-'
    or
    $msg contains '%IF-'
    or
    $msg contains '%PORT-'
    or
    $msg contains 'link up'
    or
    $msg contains 'link down'
    or
    $msg contains 'interface up'
    or
    $msg contains 'interface down'
    or
    $msg contains 'port up'
    or
    $msg contains 'port down'
    or
    $msg contains 'changed state'
    or
    $msg contains 'administratively down'
    or
    $msg contains 'configuration changed'
    or
    $msg contains 'configured from'
) then {

    action(
        type="omfile"
        dynaFile="NetworkEventLogFile"
        createDirs="on"
        dirCreateMode="0750"
        fileCreateMode="0640"
    )
}
EOF

# ============================================================
# Configure Zabbix access
# ============================================================

echo
echo "[Rsyslog] Configuring Zabbix access to network logs..."

if ! command -v setfacl >/dev/null 2>&1; then

    echo "ERROR: setfacl command was not found."
    echo "ERROR: ACL package installation failed."
    exit 1

fi

if id zabbix >/dev/null 2>&1; then

    echo
    echo "[Rsyslog] Configuring ACL for user 'zabbix'..."

    # Allow Zabbix to traverse and read the main directory.
    setfacl -m u:zabbix:rX /var/log/network

    # Default ACL for automatically created directories/files.
    setfacl -d -m u:zabbix:rX /var/log/network

    echo "Zabbix ACL: configured"

else

    echo
    echo "WARNING: User 'zabbix' does not exist."
    echo "WARNING: Zabbix ACL configuration skipped."

fi

# ============================================================
# Validate rsyslog configuration
# ============================================================

echo
echo "[Rsyslog] Validating rsyslog configuration..."

if ! rsyslogd -N1; then

    echo
    echo "ERROR: rsyslog configuration validation failed."
    exit 1

fi

echo "rsyslog configuration: valid"

# ============================================================
# Enable rsyslog
# ============================================================

echo
echo "[Rsyslog] Enabling rsyslog service..."

systemctl enable rsyslog

# ============================================================
# Restart rsyslog
# ============================================================

echo
echo "[Rsyslog] Restarting rsyslog..."

systemctl restart rsyslog

# ============================================================
# Verify rsyslog service
# ============================================================

if ! systemctl is-active --quiet rsyslog; then

    echo
    echo "ERROR: rsyslog failed to start."
    echo
    echo "========== rsyslog status =========="
    systemctl status rsyslog --no-pager

    echo
    echo "========== rsyslog journal =========="
    journalctl -u rsyslog -n 80 --no-pager

    exit 1

fi

echo
echo "rsyslog: running"

# ============================================================
# Firewall configuration
#
# firewalld is optional.
#
# If firewalld exists and is running:
#     Open UDP/514.
#
# If firewalld does not exist:
#     Skip firewall configuration.
# ============================================================

echo
echo "[Rsyslog] Checking firewall..."

if command -v firewall-cmd >/dev/null 2>&1 \
    && systemctl is-active --quiet firewalld; then

    echo "firewalld detected and running."

    if firewall-cmd --permanent --add-port=514/udp; then

        firewall-cmd --reload

        echo "Firewall UDP/514: allowed"

    else

        echo
        echo "ERROR: Failed to open UDP/514 in firewalld."
        exit 1

    fi

else

    echo "firewalld is not installed or not running."
    echo "Firewall configuration: skipped."

fi

# ============================================================
# Check UDP/514
# ============================================================

echo
echo "[Rsyslog] Checking UDP/514 listener..."

if ss -lunp | grep -qE '(:|\.)514[[:space:]]'; then

    echo "UDP/514: listening"

else

    echo
    echo "ERROR: UDP/514 is not listening."
    echo
    echo "========== rsyslog status =========="
    systemctl status rsyslog --no-pager

    echo
    echo "========== UDP listeners =========="
    ss -lunp

    exit 1

fi

# ============================================================
# Check network log directory
# ============================================================

echo
echo "[Rsyslog] Checking network log directory..."

if [ -d /var/log/network ]; then

    ls -ld /var/log/network

else

    echo "ERROR: /var/log/network does not exist."
    exit 1

fi

# ============================================================
# Check ACL
# ============================================================

echo
echo "[Rsyslog] Checking ACL..."

if command -v getfacl >/dev/null 2>&1; then

    getfacl /var/log/network

else

    echo "ERROR: getfacl command not found."
    exit 1

fi

# ============================================================
# Final status
# ============================================================

echo
echo "=============================================="
echo " Centralized Syslog installation completed"
echo "=============================================="

echo
echo "Syslog listener:"
echo "  UDP/514"

echo
echo "Network log directory:"
echo "  /var/log/network/<source-ip>/syslog.log"

echo
echo "Network event log:"
echo "  /var/log/network/<source-ip>/events.log"

echo
echo "Zabbix access:"
echo "  ACL enabled"

echo
echo "=============================================="
