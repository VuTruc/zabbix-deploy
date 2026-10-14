#!/bin/bash



set -e

echo
echo

# -------------------------------------------------
# Detect interface
# -------------------------------------------------

INTERFACE=$(ip -o link show | awk -F': ' '$2 != "lo" && $2 !~ /^loopback/ {print $2; exit}')

if [ -z "$INTERFACE" ]; then
    echo "[ERROR] No network interface detected."
    exit 1
fi

echo "[Network Configuration] Detected interface: $INTERFACE"
echo

# -------------------------------------------------
# Ask whether to configure static IP
# -------------------------------------------------

while true; do
    read -r -p "Do you want to configure a static IP address? [y/N]: " CONFIGURE_IP
    CONFIGURE_IP=${CONFIGURE_IP:-N}

    case "$CONFIGURE_IP" in
        y|Y) CONFIGURE_IP="Y"; break ;;
        n|N) CONFIGURE_IP="N"; break ;;
        *)   echo "[ERROR] Invalid answer. Please enter Y or N." ;;
    esac
done

# -------------------------------------------------
# Skip network configuration
# -------------------------------------------------

if [ "$CONFIGURE_IP" = "N" ]; then

    echo
    echo "[Network Configuration] Static IP configuration skipped."
    echo "[Network Configuration] Existing WSL/network configuration will be preserved."
    echo

else

    # -------------------------------------------------
    # Get current network information
    # -------------------------------------------------

    CURRENT_IP=$(ip -4 addr show "$INTERFACE" 2>/dev/null \
        | awk '/inet / {print $2; exit}')

    CURRENT_GATEWAY=$(ip route 2>/dev/null \
        | awk '/default/ {print $3; exit}')

    CURRENT_DNS=$(grep -E '^nameserver ' /etc/resolv.conf 2>/dev/null \
        | awk '{print $2; exit}')

    echo
    echo "=============================================="
    echo " Static IPv4 Configuration"
    echo "=============================================="
    echo

    IPV4_RE='^((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$'

    while true; do
        read -r -p "IP address [${CURRENT_IP:-192.168.18.57/24}]: " IP_ADDRESS
        IP_ADDRESS=${IP_ADDRESS:-${CURRENT_IP:-192.168.18.57/24}}
        prefix="${IP_ADDRESS#*/}"
        if [[ "$IP_ADDRESS" == */* && "${IP_ADDRESS%/*}" =~ $IPV4_RE \
              && "$prefix" =~ ^[0-9]+$ && "$prefix" -ge 1 && "$prefix" -le 32 ]]; then
            break
        fi
        echo "[ERROR] Enter an IPv4 address with prefix, e.g. 192.168.18.57/24. Please try again."
    done

    while true; do
        read -r -p "Gateway [${CURRENT_GATEWAY:-192.168.18.1}]: " GATEWAY
        GATEWAY=${GATEWAY:-${CURRENT_GATEWAY:-192.168.18.1}}
        [[ "$GATEWAY" =~ $IPV4_RE ]] && break
        echo "[ERROR] Enter a valid IPv4 gateway, e.g. 192.168.18.1. Please try again."
    done

    while true; do
        read -r -p "DNS [${CURRENT_DNS:-8.8.8.8}]: " DNS
        DNS=${DNS:-${CURRENT_DNS:-8.8.8.8}}
        dns_ok=1
        for d in ${DNS//,/ }; do [[ "$d" =~ $IPV4_RE ]] || dns_ok=0; done
        [[ $dns_ok -eq 1 && -n "${DNS// /}" ]] && break
        echo "[ERROR] Enter one or more IPv4 DNS servers (comma or space separated). Please try again."
    done

    echo
    echo "=============================================="
    echo "Interface : $INTERFACE"
    echo "IP        : $IP_ADDRESS"
    echo "Gateway   : $GATEWAY"
    echo "DNS       : $DNS"
    echo "=============================================="
    echo

    read -r -p "Apply this configuration? [y/N]: " CONFIRM
    CONFIRM=${CONFIRM:-N}

    case "$CONFIRM" in
        y|Y)

            # -------------------------------------------------
            # Check whether NetworkManager manages the interface
            # -------------------------------------------------

            NM_MANAGED="no"

            if command -v nmcli >/dev/null 2>&1; then
                NM_MANAGED=$(nmcli -t -f GENERAL.NM-MANAGED device show "$INTERFACE" 2>/dev/null \
                    | head -1 | cut -d: -f2)
            fi

            echo
            echo "[Network Configuration] NetworkManager management status:"
            echo "Interface : $INTERFACE"
            echo "Managed   : ${NM_MANAGED:-unknown}"

            if [ "$NM_MANAGED" = "yes" ]; then

                echo
                echo "[Network Configuration] Configuring network using NetworkManager..."

                CONNECTION=$(nmcli -t -f NAME,DEVICE connection show 2>/dev/null \
                    | awk -F: -v dev="$INTERFACE" '$2 == dev {print $1; exit}')

                if [ -z "$CONNECTION" ]; then
                    CONNECTION="$INTERFACE"

                    echo "[Network Configuration] No existing NetworkManager connection found."
                    echo "Creating connection: $CONNECTION"

                    nmcli connection add \
                        type ethernet \
                        ifname "$INTERFACE" \
                        con-name "$CONNECTION"
                else
                    echo "[Network Configuration] Using existing connection: $CONNECTION"
                fi

                echo
                echo "[Network Configuration] Configuring static IPv4..."

                nmcli connection modify "$CONNECTION" \
                    ipv4.method manual \
                    ipv4.addresses "$IP_ADDRESS" \
                    ipv4.gateway "$GATEWAY" \
                    ipv4.dns "$DNS" \
                    ipv6.method disabled

                echo
                echo "[Network Configuration] Applying network configuration..."

                nmcli connection up "$CONNECTION"

                echo
                echo "[Network Configuration] Network configuration applied."

            else

                echo
                echo "[WARNING] Interface $INTERFACE is not managed by NetworkManager."
                echo
                echo "[WARNING] NetworkManager will NOT be forced to manage it."
                echo "[WARNING] This is normal for some WSL networking modes."
                echo
                echo "[Network Configuration] Static IP configuration was NOT applied."
                echo "[Network Configuration] Existing WSL network configuration is preserved."

            fi

            ;;

        n|N)
            echo
            echo "[Network Configuration] Configuration cancelled."
            echo "[Network Configuration] Existing network configuration will be preserved."
            ;;

        *)
            echo
            echo "[ERROR] Invalid answer."
            exit 1
            ;;
    esac
fi

# -------------------------------------------------
# Display current network information
# -------------------------------------------------

echo
echo "=============================================="
echo " Current Network Information"
echo "=============================================="

echo
echo "Hostname:"
hostname

echo
echo "IPv4 address:"
ip -4 addr show "$INTERFACE" 2>/dev/null \
    | awk '/inet / {print "  " $2}'

echo
echo "Gateway:"
ip route 2>/dev/null \
    | awk '/default/ {print "  " $3 " dev " $5}'

echo
echo "DNS:"
if grep -E '^nameserver ' /etc/resolv.conf >/dev/null 2>&1; then
    grep -E '^nameserver ' /etc/resolv.conf \
        | awk '{print "  " $2}'
else
    echo "  Not detected"
fi

echo
echo "Interface status:"
ip -br addr show "$INTERFACE"

echo
echo "Routing table:"
ip route

echo
echo "=============================================="
echo " Network configuration completed."
echo "=============================================="
echo
