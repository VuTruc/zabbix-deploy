#!/bin/bash



set -e


if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This module must be run as root."
    exit 1
fi

echo
echo "[System Preparation] Checking operating system..."

if [ ! -f /etc/os-release ]; then
    echo "ERROR: /etc/os-release not found."
    exit 1
fi

source /etc/os-release

echo "OS: ${PRETTY_NAME:-Unknown}"

if [[ "${ID}" != "ol" ]]; then
    echo "ERROR: This installer currently supports Oracle Linux."
    echo "Detected ID: ${ID}"
    exit 1
fi

echo
echo "[System Preparation] Checking Oracle Linux version..."

if [[ "${VERSION_ID%%.*}" != "9" ]]; then
    echo "WARNING: This project is currently designed for Oracle Linux 9."
    echo "Detected version: ${VERSION_ID}"
    read -r -p "Continue anyway? [y/N]: " answer

    if [[ ! "$answer" =~ ^[Yy]$ ]]; then
        echo "Installation cancelled."
        exit 1
    fi
fi

echo
echo "[System Preparation] Checking CPU..."

CPU_COUNT=$(nproc)

echo "CPU cores: $CPU_COUNT"

if [ "$CPU_COUNT" -lt 2 ]; then
    echo "WARNING: Less than 2 CPU cores detected."
fi

echo
echo "[System Preparation] Checking memory..."

MEMORY_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)

echo "Memory: ${MEMORY_MB} MB"

if [ "$MEMORY_MB" -lt 4096 ]; then
    echo "WARNING: Less than 4 GB RAM detected."
fi

echo
echo "[System Preparation] Checking disk space..."

ROOT_AVAILABLE_GB=$(df -BG / | awk 'NR==2 {gsub("G","",$4); print $4}')

echo "Available space on /: ${ROOT_AVAILABLE_GB} GB"

if [ "$ROOT_AVAILABLE_GB" -lt 20 ]; then
    echo "WARNING: Less than 20 GB free space detected."
fi

echo
echo "[System Preparation] Updating package metadata..."

dnf makecache

echo
echo "[System Preparation] Installing basic utilities..."

dnf install -y \
    NetworkManager \
    hostname \
    curl \
    wget \
    vim-enhanced \
    git \
    unzip \
    tar \
    gzip \
    rsync \
    net-tools \
    bind-utils \
    iproute \
    policycoreutils-python-utils \
    acl

echo
echo "=============================================="
echo " Hostname Configuration"
echo "=============================================="

CURRENT_HOSTNAME="$(hostname)"

echo
echo "Current hostname: ${CURRENT_HOSTNAME}"

echo
while true; do
    read -r -p "Enter new hostname [${CURRENT_HOSTNAME}]: " HOSTNAME_VALUE
    HOSTNAME_VALUE="${HOSTNAME_VALUE:-$CURRENT_HOSTNAME}"

    if [[ "$HOSTNAME_VALUE" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]]; then
        break
    fi

    echo "ERROR: Invalid hostname: ${HOSTNAME_VALUE}"
    echo "       Use letters, digits, '.' and '-' (must start with a letter or digit). Please try again."
done

echo
echo "[System Preparation] Setting hostname..."

hostnamectl set-hostname "$HOSTNAME_VALUE"

echo "Hostname set to: $(hostname)"

echo
echo "[System Preparation] Checking network..."

if ip route show default | grep -q default; then
    echo "Default route: OK"
else
    echo "WARNING: No default route detected."
fi

echo
echo "[System Preparation] Checking DNS..."

if getent hosts repo.oracle.com >/dev/null 2>&1; then
    echo "DNS resolution: OK"
else
    echo "WARNING: DNS resolution test failed."
fi

echo
echo "[System Preparation] Checking Internet connectivity..."

if curl -fsS --connect-timeout 5 https://www.oracle.com >/dev/null 2>&1; then
    echo "Internet connectivity: OK"
else
    echo "WARNING: Internet connectivity test failed."
fi


echo
echo "[System Preparation] Enabling NetworkManager..."

if systemctl list-unit-files | grep -q '^NetworkManager.service'; then
    systemctl enable --now NetworkManager
    echo "NetworkManager: enabled"
else
    echo "ERROR: NetworkManager service not found."
    exit 1
fi

echo
echo "[System Preparation] Enabling time synchronization..."

if systemctl list-unit-files | grep -q '^chronyd.service'; then
    systemctl enable --now chronyd
    echo "chronyd: enabled"
fi

echo
echo "[System Preparation] System preparation completed."
echo "=============================================="
