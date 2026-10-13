#!/bin/bash



set -euo pipefail

# ============================================================
# Zabbix Monitoring Deployment
# 09 - Telegram Integration
# ============================================================

ZABBIX_ENV="/etc/zabbix/zabbix-export.env"
TELEGRAM_ENV="/etc/zabbix/telegram.env"

TELEGRAM_TEMPLATE_URL="https://git.zabbix.com/projects/ZBX/repos/zabbix/raw/templates/media/telegram/media_telegram.yaml?at=release%2F7.0"
TELEGRAM_TEMPLATE_FALLBACK_URL="https://raw.githubusercontent.com/zabbix/zabbix/7.0/templates/media/telegram/media_telegram.yaml"

TELEGRAM_TEMPLATE_FILE="/tmp/zabbix-telegram-media.yaml"

TELEGRAM_USERNAME_DEFAULT="telegram"
TELEGRAM_NAME_DEFAULT="Telegram Notifications"
TELEGRAM_GROUP_DEFAULT="Telegram Notifications"

ACTION_NAME="Telegram Problem & Recovery"

TEST_ONLY=0

# ============================================================
# Colors
# ============================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# ============================================================
# Functions
# ============================================================

info()
{
    echo -e "${CYAN}$1${NC}"
}

success()
{
    echo -e "${GREEN}$1${NC}"
}

warning()
{
    echo -e "${YELLOW}$1${NC}"
}

error()
{
    echo -e "${RED}$1${NC}"
}

die()
{
    error "$1"
    exit 1
}

separator()
{
    echo
    echo "============================================================"
    echo "$1"
    echo "============================================================"
}

# ============================================================
# Parse arguments
# ============================================================

if [ "${1:-}" = "--test" ]; then
    TEST_ONLY=1
fi

# ============================================================
# Root check
# ============================================================

if [ "$EUID" -ne 0 ]; then
    die "ERROR: Please run this script as root."
fi

# ============================================================
# Install dependencies
# ============================================================

separator "Installing dependencies"

echo "[Telegram Alert] Installing required packages..."

dnf install -y \
    curl \
    jq \
    sed \
    grep \
    gawk \
    systemd

# ============================================================
# Dependency check
# ============================================================

separator "Checking dependencies"

for CMD in curl jq sed grep awk systemctl; do
    if ! command -v "$CMD" >/dev/null 2>&1; then
        die "ERROR: Required command not found: $CMD"
    fi
done

success "Required commands are available."

# ============================================================
# Zabbix environment
# ============================================================

ZABBIX_URL_DEFAULT="http://127.0.0.1:8000/zabbix"

if [ -f "$ZABBIX_ENV" ]; then

    # shellcheck disable=SC1090
    source "$ZABBIX_ENV"

fi

ZABBIX_URL="${ZABBIX_URL:-$ZABBIX_URL_DEFAULT}"

# ============================================================
# Zabbix API credentials
# ============================================================

ZABBIX_USERNAME_DEFAULT="Admin"
ZABBIX_PASSWORD_DEFAULT="zabbix"

ZABBIX_USERNAME="${ZABBIX_USERNAME:-$ZABBIX_USERNAME_DEFAULT}"
ZABBIX_PASSWORD="${ZABBIX_PASSWORD:-$ZABBIX_PASSWORD_DEFAULT}"

echo
echo "Zabbix API username: ${ZABBIX_USERNAME}"
echo "Zabbix API password: [DEFAULT]"
echo

# ============================================================

# ============================================================
# Save Zabbix environment
# ============================================================


echo
echo "Zabbix URL: $ZABBIX_URL"
echo "Zabbix API authentication: Admin/zabbix"

ZABBIX_API_URL="${ZABBIX_URL%/}/api_jsonrpc.php"

separator "Logging into Zabbix API"



# ============================================================
# Zabbix API - authenticated
# ============================================================

ZABBIX_AUTH_TOKEN=""

zbx_api_login()
{
    local REQUEST
    local RESPONSE

    REQUEST="$(
        jq -n \
            --arg username "$ZABBIX_USERNAME" \
            --arg password "$ZABBIX_PASSWORD" '
            {
                jsonrpc: "2.0",
                method: "user.login",
                params: {
                    username: $username,
                    password: $password
                },
                id: 1
            }
            '
    )" || {
        echo
        error "ERROR: Failed to build Zabbix API login request."
        exit 1
    }



    RESPONSE="$(
        curl -sS \
            --fail-with-body \
            -H "Content-Type: application/json-rpc" \
            -X POST \
            "$ZABBIX_API_URL" \
            --data "$REQUEST"
    )" || {
        echo
        error "ERROR: Zabbix API login request failed."
        echo
        echo "$RESPONSE"
        exit 1
    }

    if echo "$RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo
        error "ERROR: Zabbix API login failed."
        echo
        echo "$RESPONSE" | jq .
        exit 1
    fi

    ZABBIX_AUTH_TOKEN="$(
        echo "$RESPONSE" | jq -r '.result'
    )"

    if [ -z "$ZABBIX_AUTH_TOKEN" ] || [ "$ZABBIX_AUTH_TOKEN" = "null" ]; then
        echo
        error "ERROR: Zabbix authentication token was not returned."
        exit 1
    fi

    success "Zabbix API authentication successful."
}

zbx_api()
{
    local METHOD="$1"
    local PARAMS="$2"
    local PARAMS_JSON
    local REQUEST
    local CURL_RESPONSE

    PARAMS_JSON="$(
        jq -n "$PARAMS"
    )" || {
        echo
        error "ERROR: Failed to parse Zabbix API parameters."
        exit 1
    }

    REQUEST="$(
        jq -n \
            --arg method "$METHOD" \
            --argjson params "$PARAMS_JSON" \
            --arg auth "$ZABBIX_AUTH_TOKEN" '
            {
                jsonrpc: "2.0",
                method: $method,
                params: $params,
                auth: $auth,
                id: 1
            }
            '
    )" || {
        echo
        error "ERROR: Failed to build Zabbix API request."
        exit 1
    }

    CURL_RESPONSE="$(
        curl -sS \
            --fail-with-body \
            -H "Content-Type: application/json-rpc" \
            -X POST \
            "$ZABBIX_API_URL" \
            --data "$REQUEST"
    )" || {
        echo
        error "ERROR: Zabbix API HTTP request failed."
        echo
        echo "$CURL_RESPONSE"
        exit 1
    }

    printf '%s
' "$CURL_RESPONSE"
}

# ============================================================
# Zabbix API - no authentication
# ============================================================

zbx_api_no_auth()
{
    local METHOD="$1"
    local PARAMS="$2"

    local PARAMS_JSON
    local REQUEST
    local CURL_RESPONSE

    PARAMS_JSON="$(
        jq -n "$PARAMS"
    )" || {
        echo
        error "ERROR: Failed to parse unauthenticated API parameters."
        exit 1
    }

    REQUEST="$(
        jq -n \
            --arg method "$METHOD" \
            --argjson params "$PARAMS_JSON" '
            {
                jsonrpc: "2.0",
                method: $method,
                params: $params,
                id: 1
            }
            '
    )" || {
        echo
        error "ERROR: Failed to build unauthenticated API request."
        exit 1
    }

    CURL_RESPONSE="$(
        curl -sS \
            --fail-with-body \
            -H "Content-Type: application/json-rpc" \
            -X POST \
            "$ZABBIX_API_URL" \
            --data "$REQUEST"
    )" || {
        echo
        error "ERROR: Zabbix API HTTP request failed."
        echo
        echo "$CURL_RESPONSE"
        exit 1
    }

    printf '%s\n' "$CURL_RESPONSE"
}

zbx_api_login

# ============================================================
# Check Zabbix API
# ============================================================

separator "Checking Zabbix API"

API_VERSION_JSON="$(
    zbx_api_no_auth \
        "apiinfo.version" \
        '{}'
)"

if echo "$API_VERSION_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$API_VERSION_JSON" | jq .
    die "ERROR: Unable to query Zabbix API version."
fi

ZABBIX_API_VERSION="$(
    echo "$API_VERSION_JSON" |
    jq -r '.result'
)"

if [ -z "$ZABBIX_API_VERSION" ] ||
   [ "$ZABBIX_API_VERSION" = "null" ]; then

    die "ERROR: Zabbix API version could not be determined."
fi

success "Zabbix API version: $ZABBIX_API_VERSION"

# ============================================================
# Check Zabbix API token
# ============================================================

separator "Checking Zabbix API token"

USER_JSON="$(
    zbx_api \
        "user.get" \
        '{
            output: [
                "userid",
                "username",
                "name",
                "surname",
                "roleid"
            ],
            limit: 1
        }'
)"

if echo "$USER_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$USER_JSON" | jq .
    die "ERROR: Zabbix API authentication failed."
fi

AUTH_USER_ID="$(
    echo "$USER_JSON" |
    jq -r '.result[0].userid // empty'
)"

AUTH_USERNAME="$(
    echo "$USER_JSON" |
    jq -r '.result[0].username // empty'
)"

AUTH_ROLE_ID="$(
    echo "$USER_JSON" |
    jq -r '.result[0].roleid // empty'
)"

if [ -z "$AUTH_USER_ID" ]; then
    die "ERROR: Unable to determine authenticated Zabbix user."
fi

echo "Authenticated user: $AUTH_USERNAME"
echo "User ID            : $AUTH_USER_ID"
echo "Role ID            : $AUTH_ROLE_ID"

# ============================================================
# Check Super Admin role
# ============================================================

ROLE_JSON="$(
    zbx_api \
        "role.get" \
        '{
            output: [
                "roleid",
                "name",
                "type"
            ]
        }'
)"

if echo "$ROLE_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$ROLE_JSON" | jq .
    die "ERROR: Unable to query Zabbix roles."
fi

AUTH_ROLE_TYPE="$(
    echo "$ROLE_JSON" |
    jq -r --arg roleid "$AUTH_ROLE_ID" '
        .result[]
        | select(.roleid == $roleid)
        | .type
    '
)"

AUTH_ROLE_NAME="$(
    echo "$ROLE_JSON" |
    jq -r --arg roleid "$AUTH_ROLE_ID" '
        .result[]
        | select(.roleid == $roleid)
        | .name
    '
)"

echo "Role type           : $AUTH_ROLE_TYPE"
echo "Role name           : $AUTH_ROLE_NAME"

if [ "$AUTH_ROLE_TYPE" != "3" ]; then
    die "ERROR: The API token must belong to a Super Admin user."
fi

success "Zabbix API token belongs to a Super Admin."

# ============================================================
# Telegram credentials
# ============================================================

if [ "$TEST_ONLY" -eq 1 ]; then

    separator "Telegram test mode"

    if [ ! -f "$TELEGRAM_ENV" ]; then
        die "ERROR: Telegram credential file not found: $TELEGRAM_ENV"
    fi

    # shellcheck disable=SC1090
    source "$TELEGRAM_ENV"

    if [ -z "${TELEGRAM_BOT_TOKEN:-}" ]; then
        die "ERROR: TELEGRAM_BOT_TOKEN is not configured."
    fi

    if [ -z "${TELEGRAM_CHAT_ID:-}" ]; then
        die "ERROR: TELEGRAM_CHAT_ID is not configured."
    fi

    echo "Using saved Telegram credentials."
    echo "Chat/Room ID: $TELEGRAM_CHAT_ID"

else

    separator "Telegram credentials"

    echo
    echo "A different Zabbix server may use a different Telegram bot."
    echo "The installer will always ask for new Telegram credentials."
    echo

    read -rsp "Enter Telegram Bot Token: " TELEGRAM_BOT_TOKEN
    echo

    if [ -z "$TELEGRAM_BOT_TOKEN" ]; then
        die "ERROR: Telegram Bot Token cannot be empty."
    fi

    read -rp "Enter Telegram Chat/Room ID: " TELEGRAM_CHAT_ID

    if [ -z "$TELEGRAM_CHAT_ID" ]; then
        die "ERROR: Telegram Chat/Room ID cannot be empty."
    fi

    echo

    cat > "$TELEGRAM_ENV" <<EOF
TELEGRAM_BOT_TOKEN="$TELEGRAM_BOT_TOKEN"
TELEGRAM_CHAT_ID="$TELEGRAM_CHAT_ID"
EOF

    chmod 600 "$TELEGRAM_ENV"

    success "Telegram credentials saved to $TELEGRAM_ENV"

fi

# ============================================================
# Test Telegram Bot
# ============================================================

separator "Testing Telegram Bot"

TELEGRAM_TEST_RESPONSE="$(
    curl -sS \
        --fail-with-body \
        --get \
        --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" \
        --data-urlencode "text=Zabbix Telegram direct test" \
        "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage"
)"

if echo "$TELEGRAM_TEST_RESPONSE" |
    jq -e '.ok == true' >/dev/null 2>&1; then

    TELEGRAM_BOT_USERNAME="$(
        echo "$TELEGRAM_TEST_RESPONSE" |
        jq -r '.result.from.username // empty'
    )"

    success "Telegram direct test successful."

    if [ -n "$TELEGRAM_BOT_USERNAME" ]; then
        echo "Telegram bot: @${TELEGRAM_BOT_USERNAME}"
    fi

else

    echo "$TELEGRAM_TEST_RESPONSE" |
        jq . 2>/dev/null || true

    die "ERROR: Telegram direct API test failed."

fi

# ============================================================
# Test-only mode
# ============================================================

if [ "$TEST_ONLY" -eq 1 ]; then

    separator "Telegram test completed"

    echo
    echo "Telegram Bot Token : [CONFIGURED]"
    echo "Telegram Chat ID   : $TELEGRAM_CHAT_ID"
    echo

    success "Telegram test completed successfully."

    exit 0
fi

# ============================================================
# Download official Telegram template
# ============================================================

separator "Downloading official Zabbix Telegram Webhook template"

rm -f "$TELEGRAM_TEMPLATE_FILE"

if ! curl -fsSL \
    --connect-timeout 5 \
    --max-time 20 \
    "$TELEGRAM_TEMPLATE_URL" \
    -o "$TELEGRAM_TEMPLATE_FILE"; then

    warning "Primary Zabbix Git server download failed."
    warning "Trying GitHub fallback..."

    rm -f "$TELEGRAM_TEMPLATE_FILE"

    if ! curl -fsSL \
        --connect-timeout 5 \
        --max-time 20 \
        "$TELEGRAM_TEMPLATE_FALLBACK_URL" \
        -o "$TELEGRAM_TEMPLATE_FILE"; then

        die "ERROR: Failed to download Telegram Webhook template from both Zabbix Git and GitHub."
    fi
fi

if [ ! -s "$TELEGRAM_TEMPLATE_FILE" ]; then
    die "ERROR: Telegram template file is empty."
fi

success "Telegram template downloaded successfully."

# ============================================================
# Import official Telegram Webhook Media Type
# ============================================================

separator "Importing Telegram Webhook Media Type"

IMPORT_PARAMS="$(
    jq -n \
        --rawfile source "$TELEGRAM_TEMPLATE_FILE" '
        {
            format: "yaml",
            rules: {
                mediaTypes: {
                    createMissing: true,
                    updateExisting: true
                }
            },
            source: $source
        }
        '
)"

IMPORT_RESPONSE="$(
    zbx_api \
        "configuration.import" \
        "$IMPORT_PARAMS"
)"

if echo "$IMPORT_RESPONSE" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$IMPORT_RESPONSE" | jq .
    die "ERROR: Failed to import Telegram Webhook Media Type."
fi

success "Telegram Webhook Media Type imported successfully."

# ============================================================
# Find Telegram Media Type
# ============================================================

separator "Finding Telegram Media Type"

TELEGRAM_MT_PARAMS='{
    output: "extend",
    filter: {
        name: [
            "Telegram"
        ]
    },
    selectParameters: "extend",
    selectMessageTemplates: "extend"
}'

TELEGRAM_MT_JSON="$(
    zbx_api \
        "mediatype.get" \
        "$TELEGRAM_MT_PARAMS"
)"

if echo "$TELEGRAM_MT_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$TELEGRAM_MT_JSON" | jq .
    die "ERROR: Failed to query Telegram Media Type."
fi

TELEGRAM_MT_COUNT="$(
    echo "$TELEGRAM_MT_JSON" |
    jq '.result | length'
)"

if [ "$TELEGRAM_MT_COUNT" -eq 0 ]; then
    die "ERROR: Telegram Media Type was not found."
fi

TELEGRAM_MT_ID="$(
    echo "$TELEGRAM_MT_JSON" |
    jq -r '.result[0].mediatypeid'
)"

TELEGRAM_MT_NAME="$(
    echo "$TELEGRAM_MT_JSON" |
    jq -r '.result[0].name'
)"

echo "Media Type ID : $TELEGRAM_MT_ID"
echo "Media Type    : $TELEGRAM_MT_NAME"

# ============================================================
# Read Telegram Media Type configuration
# ============================================================

separator "Reading Telegram Media Type configuration"

CURRENT_PARAMETERS="$(
    echo "$TELEGRAM_MT_JSON" |
    jq -c '
        if (.result | type) != "array" then
            error("API result is not an array")
        elif (.result | length) == 0 then
            error("Telegram Media Type not found")
        elif (.result[0].parameters | type) != "array" then
            error("Media Type parameters is not an array")
        else
            .result[0].parameters
            | flatten
            | map(select(type == "object"))
        end
    '
)"

CURRENT_TEMPLATES="$(
    echo "$TELEGRAM_MT_JSON" |
    jq -c '
        if (.result | type) != "array" then
            error("API result is not an array")
        elif (.result | length) == 0 then
            error("Telegram Media Type not found")
        elif (.result[0].message_templates | type) != "array" then
            error("message_templates is not an array")
        else
            .result[0].message_templates
        end
    '
)"

# ============================================================
# Configure Telegram Media Type parameters
# ============================================================

separator "Configuring Telegram Media Type parameters"

DESIRED_PARAMETERS="$(
    jq -n \
        --arg token "$TELEGRAM_BOT_TOKEN" \
        --arg tls_verify '{$HTTP.TLS.VERIFY:"Telegram"}' '
        [
            {
                name: "alert_message",
                value: "{ALERT.MESSAGE}"
            },
            {
                name: "alert_subject",
                value: "{ALERT.SUBJECT}"
            },
            {
                name: "api_chat_id",
                value: "{ALERT.SENDTO}"
            },
            {
                name: "api_parse_mode",
                value: "HTML"
            },
            {
                name: "api_token",
                value: $token
            },
            {
                name: "event_nseverity",
                value: "{EVENT.NSEVERITY}"
            },
            {
                name: "event_severity",
                value: "{EVENT.SEVERITY}"
            },
            {
                name: "event_source",
                value: "{EVENT.SOURCE}"
            },
            {
                name: "event_tags",
                value: "{EVENT.TAGSJSON}"
            },
            {
                name: "event_update_nseverity",
                value: "{EVENT.UPDATE.NSEVERITY}"
            },
            {
                name: "event_update_severity",
                value: "{EVENT.UPDATE.SEVERITY}"
            },
            {
                name: "event_update_status",
                value: "{EVENT.UPDATE.STATUS}"
            },
            {
                name: "event_value",
                value: "{EVENT.VALUE}"
            },
            {
                name: "tls_verify",
                value: $tls_verify
            }
        ]
        '
)"

# ============================================================
# Build unique parameters
# ============================================================

UPDATED_PARAMETERS="$(
    jq -n \
        --argjson current "$CURRENT_PARAMETERS" \
        --argjson desired "$DESIRED_PARAMETERS" '
        (
            reduce $current[] as $p
            ({};
                if ($p | type) != "object" then
                    .
                elif ($p.name == null) then
                    .
                elif ($p.name == "Token") then
                    .
                else
                    .[$p.name] = $p
                end
            )
        )
        as $current_map

        |

        (
            reduce $desired[] as $p
            ($current_map;
                .[$p.name] = $p
            )
        )

        |

        to_entries
        |
        map(.value)
        '
)"

echo
echo "Telegram Media Type parameters to be applied:"
echo

echo "$UPDATED_PARAMETERS" |
    jq -r '
        .[] |
        if .name == "api_token" then
            "\(.name) = [TOKEN CONFIGURED]"
        else
            "\(.name) = \(.value)"
        end
    '

EXPECTED_PARAMETER_COUNT=14

ACTUAL_PARAMETER_COUNT="$(
    echo "$UPDATED_PARAMETERS" |
    jq 'length'
)"

if [ "$ACTUAL_PARAMETER_COUNT" -ne "$EXPECTED_PARAMETER_COUNT" ]; then
    die "ERROR: Unexpected Telegram parameter count."
fi

UNIQUE_PARAMETER_COUNT="$(
    echo "$UPDATED_PARAMETERS" |
    jq '[.[].name] | unique | length'
)"

if [ "$UNIQUE_PARAMETER_COUNT" -ne "$EXPECTED_PARAMETER_COUNT" ]; then
    die "ERROR: Telegram parameters are not unique."
fi

success "Telegram parameters are unique and valid."

# ============================================================
# Update Media Type parameters
# ============================================================

UPDATED_MT_PARAMS="$(
    jq -n \
        --arg mediatypeid "$TELEGRAM_MT_ID" \
        --argjson parameters "$UPDATED_PARAMETERS" '
        {
            mediatypeid: $mediatypeid,
            parameters: $parameters
        }
        '
)"

UPDATE_MT_RESPONSE="$(
    zbx_api \
        "mediatype.update" \
        "$UPDATED_MT_PARAMS"
)"

if echo "$UPDATE_MT_RESPONSE" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$UPDATE_MT_RESPONSE" | jq .
    die "ERROR: Failed to update Telegram Media Type parameters."
fi

success "Telegram Media Type parameters updated successfully."

# ============================================================
# Enable Telegram Media Type
# ============================================================

separator "Enabling Telegram Media Type"

ENABLE_MT_PARAMS="$(
    jq -n \
        --arg mediatypeid "$TELEGRAM_MT_ID" '
        {
            mediatypeid: $mediatypeid,
            status: 0
        }
        '
)"

ENABLE_MT_RESPONSE="$(
    zbx_api \
        "mediatype.update" \
        "$ENABLE_MT_PARAMS"
)"

if echo "$ENABLE_MT_RESPONSE" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$ENABLE_MT_RESPONSE" | jq .
    die "ERROR: Failed to enable Telegram Media Type."
fi

success "Telegram Media Type enabled."

# ============================================================
# Verify Media Type
# ============================================================

separator "Verifying Telegram Media Type"

VERIFY_MT_JSON="$(
    zbx_api \
        "mediatype.get" \
        "$TELEGRAM_MT_PARAMS"
)"

if echo "$VERIFY_MT_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$VERIFY_MT_JSON" | jq .
    die "ERROR: Failed to verify Telegram Media Type."
fi

VERIFY_MT_ID="$(
    echo "$VERIFY_MT_JSON" |
    jq -r '.result[0].mediatypeid'
)"

VERIFY_MT_STATUS="$(
    echo "$VERIFY_MT_JSON" |
    jq -r '.result[0].status'
)"

VERIFY_PARAMETER_COUNT="$(
    echo "$VERIFY_MT_JSON" |
    jq '[.result[0].parameters[]] | length'
)"

VERIFY_UNIQUE_PARAMETER_COUNT="$(
    echo "$VERIFY_MT_JSON" |
    jq '[.result[0].parameters[].name] | unique | length'
)"

echo "Media Type ID       : $VERIFY_MT_ID"
echo "Status              : $VERIFY_MT_STATUS"
echo "Parameter count     : $VERIFY_PARAMETER_COUNT"
echo "Unique parameters   : $VERIFY_UNIQUE_PARAMETER_COUNT"

if [ "$VERIFY_MT_STATUS" != "0" ]; then
    die "ERROR: Telegram Media Type is not enabled."
fi

if [ "$VERIFY_PARAMETER_COUNT" -ne "$EXPECTED_PARAMETER_COUNT" ]; then
    die "ERROR: Telegram Media Type parameter count is incorrect."
fi

if [ "$VERIFY_UNIQUE_PARAMETER_COUNT" -ne "$EXPECTED_PARAMETER_COUNT" ]; then
    die "ERROR: Telegram Media Type contains duplicate parameters."
fi

API_CHAT_ID_VALUE="$(
    echo "$VERIFY_MT_JSON" |
    jq -r '
        .result[0].parameters[]
        | select(.name == "api_chat_id")
        | .value
    '
)"

API_TOKEN_VALUE="$(
    echo "$VERIFY_MT_JSON" |
    jq -r '
        .result[0].parameters[]
        | select(.name == "api_token")
        | .value
    '
)"

if [ "$API_CHAT_ID_VALUE" != "{ALERT.SENDTO}" ]; then
    die "ERROR: api_chat_id is not configured as {ALERT.SENDTO}."
fi

if [ -z "$API_TOKEN_VALUE" ]; then
    die "ERROR: api_token is empty."
fi

success "Telegram Media Type verification successful."

# ============================================================
# Configure Telegram Message Templates
# ============================================================

separator "Configuring Telegram Message Templates"

PROBLEM_SUBJECT='🚨 ZABBIX ALERT: {EVENT.NAME}'

PROBLEM_MESSAGE='Host: {HOST.NAME}
Problem: {EVENT.NAME}
Severity: {EVENT.SEVERITY}
Status: PROBLEM
Time: {EVENT.DATE} {EVENT.TIME}

Details: {EVENT.OPDATA}

Tags: {EVENT.TAGS}

Event ID: {EVENT.ID}'

RECOVERY_SUBJECT='✅ RECOVERY: {EVENT.NAME}'

RECOVERY_MESSAGE='Host: {HOST.NAME}
Problem: {EVENT.NAME}
Severity: {EVENT.SEVERITY}
Status: RESOLVED
Time: {EVENT.RECOVERY.DATE} {EVENT.RECOVERY.TIME}

Duration: {EVENT.DURATION}

Event ID: {EVENT.ID}'

UPDATED_MESSAGE_TEMPLATES="$(
    echo "$CURRENT_TEMPLATES" |
    jq \
        --arg problem_subject "$PROBLEM_SUBJECT" \
        --arg problem_message "$PROBLEM_MESSAGE" \
        --arg recovery_subject "$RECOVERY_SUBJECT" \
        --arg recovery_message "$RECOVERY_MESSAGE" '
        [
            .[] |

            if (
                (.eventsource | tostring) == "0"
                and
                (.recovery | tostring) == "0"
            )
            then
                {
                    eventsource: "0",
                    recovery: "0",
                    subject: $problem_subject,
                    message: $problem_message
                }

            elif (
                (.eventsource | tostring) == "0"
                and
                (.recovery | tostring) == "1"
            )
            then
                {
                    eventsource: "0",
                    recovery: "1",
                    subject: $recovery_subject,
                    message: $recovery_message
                }

            else
                .
            end
        ]
        '
)"

UPDATE_TEMPLATES_PARAMS="$(
    jq -n \
        --arg mediatypeid "$TELEGRAM_MT_ID" \
        --argjson message_templates "$UPDATED_MESSAGE_TEMPLATES" '
        {
            mediatypeid: $mediatypeid,
            message_templates: $message_templates
        }
        '
)"

UPDATE_TEMPLATES_RESPONSE="$(
    zbx_api \
        "mediatype.update" \
        "$UPDATE_TEMPLATES_PARAMS"
)"

if echo "$UPDATE_TEMPLATES_RESPONSE" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$UPDATE_TEMPLATES_RESPONSE" | jq .
    die "ERROR: Failed to update Telegram message templates."
fi

success "Telegram Problem/Recovery message templates updated."

# ============================================================
# Configure Telegram User Group
# ============================================================

separator "Configuring Telegram User Group"

TELEGRAM_GROUP_NAME="$TELEGRAM_GROUP_DEFAULT"

GROUP_PARAMS="$(
    jq -n \
        --arg name "$TELEGRAM_GROUP_NAME" '
        {
            output: "extend",
            filter: {
                name: [
                    $name
                ]
            }
        }
        '
)"

GROUP_JSON="$(
    zbx_api \
        "usergroup.get" \
        "$GROUP_PARAMS"
)"

if echo "$GROUP_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$GROUP_JSON" | jq .
    die "ERROR: Failed to query Telegram user group."
fi

TELEGRAM_GROUP_ID="$(
    echo "$GROUP_JSON" |
    jq -r '.result[0].usrgrpid // empty'
)"

if [ -z "$TELEGRAM_GROUP_ID" ]; then

    CREATE_GROUP_PARAMS="$(
        jq -n \
            --arg name "$TELEGRAM_GROUP_NAME" '
            {
                name: $name
            }
            '
    )"

    CREATE_GROUP_RESPONSE="$(
        zbx_api \
            "usergroup.create" \
            "$CREATE_GROUP_PARAMS"
    )"

    if echo "$CREATE_GROUP_RESPONSE" |
        jq -e '.error' >/dev/null 2>&1; then

        echo "$CREATE_GROUP_RESPONSE" | jq .
        die "ERROR: Failed to create Telegram user group."
    fi

    TELEGRAM_GROUP_ID="$(
        echo "$CREATE_GROUP_RESPONSE" |
        jq -r '.result.usrgrpids[0]'
    )"

    success "Created Telegram user group."

else

    echo "Telegram user group already exists."
    echo "Group ID: $TELEGRAM_GROUP_ID"

fi

# ============================================================
# Find normal User role
# ============================================================

separator "Finding normal Zabbix User role"

NORMAL_ROLE_PARAMS='{
    output: [
        "roleid",
        "name",
        "type"
    ],
    filter: {
        type: "1"
    }
}'

NORMAL_ROLE_JSON="$(
    zbx_api \
        "role.get" \
        "$NORMAL_ROLE_PARAMS"
)"

if echo "$NORMAL_ROLE_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$NORMAL_ROLE_JSON" | jq .
    die "ERROR: Failed to find normal User role."
fi

NORMAL_ROLE_ID="$(
    echo "$NORMAL_ROLE_JSON" |
    jq -r '.result[0].roleid // empty'
)"

if [ -z "$NORMAL_ROLE_ID" ]; then
    die "ERROR: Normal Zabbix User role was not found."
fi

echo "Normal User role ID: $NORMAL_ROLE_ID"

# ============================================================
# Telegram notification user
# ============================================================

separator "Configuring Telegram notification user"

read -rp "Telegram notification username [${TELEGRAM_USERNAME_DEFAULT}]: " TELEGRAM_USERNAME

if [ -z "$TELEGRAM_USERNAME" ]; then
    TELEGRAM_USERNAME="$TELEGRAM_USERNAME_DEFAULT"
fi

read -rp "Telegram notification display name [${TELEGRAM_NAME_DEFAULT}]: " TELEGRAM_DISPLAY_NAME

if [ -z "$TELEGRAM_DISPLAY_NAME" ]; then
    TELEGRAM_DISPLAY_NAME="$TELEGRAM_NAME_DEFAULT"
fi

read -rsp "Telegram notification user password: " TELEGRAM_USER_PASSWORD
echo

if [ -z "$TELEGRAM_USER_PASSWORD" ]; then
    die "ERROR: Telegram notification user password cannot be empty."
fi

# ============================================================
# Find Telegram user
# ============================================================

TELEGRAM_USER_PARAMS="$(
    jq -n \
        --arg username "$TELEGRAM_USERNAME" '
        {
            output: "extend",
            selectMedias: "extend",
            selectUsrgrps: "extend",
            filter: {
                username: [
                    $username
                ]
            }
        }
        '
)"

TELEGRAM_USER_JSON="$(
    zbx_api \
        "user.get" \
        "$TELEGRAM_USER_PARAMS"
)"

if echo "$TELEGRAM_USER_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$TELEGRAM_USER_JSON" | jq .
    die "ERROR: Failed to query Telegram notification user."
fi

TELEGRAM_USER_ID="$(
    echo "$TELEGRAM_USER_JSON" |
    jq -r '.result[0].userid // empty'
)"

# ============================================================
# Create or update Telegram user
# ============================================================

if [ -z "$TELEGRAM_USER_ID" ]; then

    CREATE_USER_PARAMS="$(
        jq -n \
            --arg username "$TELEGRAM_USERNAME" \
            --arg name "$TELEGRAM_DISPLAY_NAME" \
            --arg password "$TELEGRAM_USER_PASSWORD" \
            --arg roleid "$NORMAL_ROLE_ID" \
            --arg usrgrpid "$TELEGRAM_GROUP_ID" \
            --arg mediatypeid "$TELEGRAM_MT_ID" \
            --arg sendto "$TELEGRAM_CHAT_ID" '
            {
                username: $username,
                name: $name,
                passwd: $password,
                roleid: $roleid,

                usrgrps: [
                    {
                        usrgrpid: $usrgrpid
                    }
                ],

                medias: [
                    {
                        mediatypeid: $mediatypeid,
                        sendto: [
                            $sendto
                        ],
                        active: 0,
                        severity: 63,
                        period: "1-7,00:00-24:00"
                    }
                ]
            }
            '
    )"

    CREATE_USER_RESPONSE="$(
        zbx_api \
            "user.create" \
            "$CREATE_USER_PARAMS"
    )"

    if echo "$CREATE_USER_RESPONSE" |
        jq -e '.error' >/dev/null 2>&1; then

        echo "$CREATE_USER_RESPONSE" | jq .
        die "ERROR: Failed to create Telegram notification user."
    fi

    TELEGRAM_USER_ID="$(
        echo "$CREATE_USER_RESPONSE" |
        jq -r '.result.userids[0]'
    )"

    success "Telegram notification user created."

else

    echo "Telegram notification user already exists."
    echo "User ID: $TELEGRAM_USER_ID"

    EXISTING_MEDIA_ID="$(
        echo "$TELEGRAM_USER_JSON" |
        jq -r --arg mt "$TELEGRAM_MT_ID" '
            .result[0].medias[]
            | select(.mediatypeid == $mt)
            | .mediaid
        ' |
        head -n1
    )"

    if [ -n "$EXISTING_MEDIA_ID" ]; then

        UPDATE_USER_PARAMS="$(
            jq -n \
                --arg userid "$TELEGRAM_USER_ID" \
                --arg username "$TELEGRAM_USERNAME" \
                --arg name "$TELEGRAM_DISPLAY_NAME" \
                --arg password "$TELEGRAM_USER_PASSWORD" \
                --arg roleid "$NORMAL_ROLE_ID" \
                --arg usrgrpid "$TELEGRAM_GROUP_ID" \
                --arg mediaid "$EXISTING_MEDIA_ID" \
                --arg mediatypeid "$TELEGRAM_MT_ID" \
                --arg sendto "$TELEGRAM_CHAT_ID" '
                {
                    userid: $userid,
                    username: $username,
                    name: $name,
                    passwd: $password,
                    roleid: $roleid,

                    usrgrps: [
                        {
                            usrgrpid: $usrgrpid
                        }
                    ],

                    medias: [
                        {
                            mediaid: $mediaid,
                            mediatypeid: $mediatypeid,
                            sendto: [
                                $sendto
                            ],
                            active: 0,
                            severity: 63,
                            period: "1-7,00:00-24:00"
                        }
                    ]
                }
                '
        )"

    else

        UPDATE_USER_PARAMS="$(
            jq -n \
                --arg userid "$TELEGRAM_USER_ID" \
                --arg username "$TELEGRAM_USERNAME" \
                --arg name "$TELEGRAM_DISPLAY_NAME" \
                --arg password "$TELEGRAM_USER_PASSWORD" \
                --arg roleid "$NORMAL_ROLE_ID" \
                --arg usrgrpid "$TELEGRAM_GROUP_ID" \
                --arg mediatypeid "$TELEGRAM_MT_ID" \
                --arg sendto "$TELEGRAM_CHAT_ID" '
                {
                    userid: $userid,
                    username: $username,
                    name: $name,
                    passwd: $password,
                    roleid: $roleid,

                    usrgrps: [
                        {
                            usrgrpid: $usrgrpid
                        }
                    ],

                    medias: [
                        {
                            mediatypeid: $mediatypeid,
                            sendto: [
                                $sendto
                            ],
                            active: 0,
                            severity: 63,
                            period: "1-7,00:00-24:00"
                        }
                    ]
                }
                '
        )"

    fi

    UPDATE_USER_RESPONSE="$(
        zbx_api \
            "user.update" \
            "$UPDATE_USER_PARAMS"
    )"

    if echo "$UPDATE_USER_RESPONSE" |
        jq -e '.error' >/dev/null 2>&1; then

        echo "$UPDATE_USER_RESPONSE" | jq .
        die "ERROR: Failed to update Telegram notification user."
    fi

    success "Telegram notification user updated."

fi

echo
echo "Telegram notification user:"
echo "  Username : $TELEGRAM_USERNAME"
echo "  User ID  : $TELEGRAM_USER_ID"
echo "  Chat ID  : $TELEGRAM_CHAT_ID"

# ============================================================
# Configure User Group permissions
# ============================================================

separator "Configuring Telegram User Group permissions"

HOSTGROUPS_JSON="$(
    zbx_api \
        "hostgroup.get" \
        '{
            output: [
                "groupid",
                "name"
            ]
        }'
)"

if echo "$HOSTGROUPS_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$HOSTGROUPS_JSON" | jq .
    die "ERROR: Failed to retrieve host groups."
fi

PERMISSIONS="$(
    echo "$HOSTGROUPS_JSON" |
    jq '
        [
            .result[] |
            {
                id: .groupid,
                permission: 2
            }
        ]
    '
)"

UPDATE_GROUP_PARAMS="$(
    jq -n \
        --arg usrgrpid "$TELEGRAM_GROUP_ID" \
        --argjson permissions "$PERMISSIONS" '
        {
            usrgrpid: $usrgrpid,
            hostgroup_rights: $permissions
        }
        '
)"

UPDATE_GROUP_RESPONSE="$(
    zbx_api \
        "usergroup.update" \
        "$UPDATE_GROUP_PARAMS"
)"

if echo "$UPDATE_GROUP_RESPONSE" |
    jq -e '.error' >/dev/null 2>&1; then

    warning "WARNING: Unable to update all host group permissions."
    echo "$UPDATE_GROUP_RESPONSE" | jq .

else

    success "Telegram User Group host permissions configured."

fi

# ============================================================
# Configure Trigger Action
# ============================================================

separator "Configuring Trigger Action"

echo
echo "Action name: $ACTION_NAME"
echo "Event source: Trigger"
echo

ACTION_GET_PARAMS="$(
    jq -n \
        --arg name "$ACTION_NAME" '
        {
            output: "extend",
            selectOperations: "extend",
            selectRecoveryOperations: "extend",
            selectFilter: "extend",
            filter: {
                name: [
                    $name
                ]
            }
        }
        '
)"

ACTION_JSON="$(
    zbx_api \
        "action.get" \
        "$ACTION_GET_PARAMS"
)"

if echo "$ACTION_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$ACTION_JSON" | jq .
    die "ERROR: Failed to query Trigger Action."
fi

ACTION_COUNT="$(
    echo "$ACTION_JSON" |
    jq '.result | length'
)"

# ============================================================
# Problem operation
# ============================================================

TRIGGER_OPERATION="$(
    jq -n \
        --arg userid "$TELEGRAM_USER_ID" \
        --arg mediatypeid "$TELEGRAM_MT_ID" '
        {
            operationtype: 0,

            esc_step_from: 1,
            esc_step_to: 1,

            opmessage_usr: [
                {
                    userid: $userid
                }
            ],

            opmessage: {
                default_msg: 1,
                mediatypeid: $mediatypeid
            }
        }
        '
)"

# ============================================================
# Recovery operation
#
# IMPORTANT:
# Do NOT include esc_step_from / esc_step_to here.
# Zabbix rejects those parameters inside recovery_operations.
# ============================================================

TRIGGER_RECOVERY_OPERATION="$(
    jq -n \
        --arg userid "$TELEGRAM_USER_ID" \
        --arg mediatypeid "$TELEGRAM_MT_ID" '
        {
            operationtype: 0,

            opmessage_usr: [
                {
                    userid: $userid
                }
            ],

            opmessage: {
                default_msg: 1,
                mediatypeid: $mediatypeid
            }
        }
        '
)"

TRIGGER_OPERATIONS="$(
    jq -n \
        --argjson operation "$TRIGGER_OPERATION" '
        [
            $operation
        ]
        '
)"

TRIGGER_RECOVERY_OPERATIONS="$(
    jq -n \
        --argjson operation "$TRIGGER_RECOVERY_OPERATION" '
        [
            $operation
        ]
        '
)"

TRIGGER_FILTER="$(
    jq -n '
        {
            evaltype: 0,
            conditions: []
        }
        '
)"

# ============================================================
# Create Trigger Action
# ============================================================

if [ "$ACTION_COUNT" -eq 0 ]; then

    separator "Creating Trigger Action"

    CREATE_ACTION_PARAMS="$(
        jq -n \
            --arg name "$ACTION_NAME" \
            --argjson filter "$TRIGGER_FILTER" \
            --argjson operations "$TRIGGER_OPERATIONS" \
            --argjson recovery_operations "$TRIGGER_RECOVERY_OPERATIONS" '
            {
                name: $name,

                eventsource: 0,

                status: 0,

                esc_period: "60",

                filter: $filter,

                operations: $operations,

                recovery_operations: $recovery_operations
            }
            '
    )"

    echo
    echo "Creating:"
    echo "  Name        : $ACTION_NAME"
    echo "  Event source: Trigger"
    echo "  Status      : Enabled"
    echo "  Escalation  : 60 seconds"
    echo "  Problem     : Telegram notification user"
    echo "  Recovery    : Telegram notification user"
    echo

    CREATE_ACTION_RESPONSE="$(
        zbx_api \
            "action.create" \
            "$CREATE_ACTION_PARAMS"
    )"

    if echo "$CREATE_ACTION_RESPONSE" |
        jq -e '.error' >/dev/null 2>&1; then

        echo "$CREATE_ACTION_RESPONSE" | jq .
        die "ERROR: Failed to create Trigger Action."
    fi

    ACTION_ID="$(
        echo "$CREATE_ACTION_RESPONSE" |
        jq -r '.result.actionids[0] // empty'
    )"

    if [ -z "$ACTION_ID" ]; then
        die "ERROR: Zabbix did not return Trigger Action ID."
    fi

    success "Trigger Action created."
    echo "Action ID: $ACTION_ID"

# ============================================================
# Update existing Trigger Action
# ============================================================

elif [ "$ACTION_COUNT" -eq 1 ]; then

    ACTION_ID="$(
        echo "$ACTION_JSON" |
        jq -r '.result[0].actionid'
    )"

    separator "Updating existing Trigger Action"

    echo "Existing Action ID: $ACTION_ID"

    UPDATE_ACTION_PARAMS="$(
        jq -n \
            --arg actionid "$ACTION_ID" \
            --arg name "$ACTION_NAME" \
            --argjson filter "$TRIGGER_FILTER" \
            --argjson operations "$TRIGGER_OPERATIONS" \
            --argjson recovery_operations "$TRIGGER_RECOVERY_OPERATIONS" '
            {
                actionid: $actionid,

                name: $name,

                eventsource: 0,

                status: 0,

                esc_period: "60",

                filter: $filter,

                operations: $operations,

                recovery_operations: $recovery_operations
            }
            '
    )"

    UPDATE_ACTION_RESPONSE="$(
        zbx_api \
            "action.update" \
            "$UPDATE_ACTION_PARAMS"
    )"

    if echo "$UPDATE_ACTION_RESPONSE" |
        jq -e '.error' >/dev/null 2>&1; then

        echo "$UPDATE_ACTION_RESPONSE" | jq .
        die "ERROR: Failed to update Trigger Action."
    fi

    success "Existing Trigger Action updated."
    echo "Action ID: $ACTION_ID"

else

    die "ERROR: Multiple actions named '$ACTION_NAME' were found. Refusing to guess which one to modify."

fi

# ============================================================
# Verify Trigger Action
# ============================================================

separator "Verifying Trigger Action"

VERIFY_ACTION_PARAMS="$(
    jq -n \
        --arg actionid "$ACTION_ID" '
        {
            output: "extend",

            actionids: [
                $actionid
            ],

            selectOperations: "extend",
            selectRecoveryOperations: "extend",
            selectFilter: "extend"
        }
        '
)"

VERIFY_ACTION_JSON="$(
    zbx_api \
        "action.get" \
        "$VERIFY_ACTION_PARAMS"
)"

if echo "$VERIFY_ACTION_JSON" |
    jq -e '.error' >/dev/null 2>&1; then

    echo "$VERIFY_ACTION_JSON" | jq .
    die "ERROR: Failed to verify Trigger Action."
fi

VERIFY_ACTION_COUNT="$(
    echo "$VERIFY_ACTION_JSON" |
    jq '.result | length'
)"

if [ "$VERIFY_ACTION_COUNT" -ne 1 ]; then
    die "ERROR: Trigger Action was not found after configuration."
fi

VERIFY_ACTION_NAME="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '.result[0].name'
)"

VERIFY_ACTION_EVENTSOURCE="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '.result[0].eventsource'
)"

VERIFY_ACTION_STATUS="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '.result[0].status'
)"

VERIFY_ACTION_ESC_PERIOD="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '.result[0].esc_period'
)"

VERIFY_OPERATION_COUNT="$(
    echo "$VERIFY_ACTION_JSON" |
    jq '.result[0].operations | length'
)"

VERIFY_RECOVERY_OPERATION_COUNT="$(
    echo "$VERIFY_ACTION_JSON" |
    jq '.result[0].recovery_operations | length'
)"

echo
echo "Trigger Action verification:"
echo
echo "  Action ID              : $ACTION_ID"
echo "  Action name            : $VERIFY_ACTION_NAME"
echo "  Event source           : $VERIFY_ACTION_EVENTSOURCE"
echo "  Status                 : $VERIFY_ACTION_STATUS"
echo "  Escalation period      : $VERIFY_ACTION_ESC_PERIOD seconds"
echo "  Problem operations     : $VERIFY_OPERATION_COUNT"
echo "  Recovery operations    : $VERIFY_RECOVERY_OPERATION_COUNT"
echo

if [ "$VERIFY_ACTION_NAME" != "$ACTION_NAME" ]; then
    die "ERROR: Trigger Action name verification failed."
fi

if [ "$VERIFY_ACTION_EVENTSOURCE" != "0" ]; then
    die "ERROR: Trigger Action is not configured for Trigger events."
fi

if [ "$VERIFY_ACTION_STATUS" != "0" ]; then
    die "ERROR: Trigger Action is disabled."
fi

if [ "$VERIFY_ACTION_ESC_PERIOD" -lt 60 ] ||
   [ "$VERIFY_ACTION_ESC_PERIOD" -gt 604800 ]; then

    die "ERROR: Trigger Action escalation period is invalid."
fi

if [ "$VERIFY_OPERATION_COUNT" -lt 1 ]; then
    die "ERROR: Trigger Action has no Problem operation."
fi

if [ "$VERIFY_RECOVERY_OPERATION_COUNT" -lt 1 ]; then
    die "ERROR: Trigger Action has no Recovery operation."
fi

# ============================================================
# Verify Problem operation
# ============================================================

VERIFY_PROBLEM_USER_ID="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '
        .result[0].operations[0].opmessage_usr[0].userid // empty
    '
)"

VERIFY_PROBLEM_MEDIA_TYPE="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '
        .result[0].operations[0].opmessage.mediatypeid // empty
    '
)"

# ============================================================
# Verify Recovery operation
# ============================================================

VERIFY_RECOVERY_USER_ID="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '
        .result[0].recovery_operations[0].opmessage_usr[0].userid // empty
    '
)"

VERIFY_RECOVERY_MEDIA_TYPE="$(
    echo "$VERIFY_ACTION_JSON" |
    jq -r '
        .result[0].recovery_operations[0].opmessage.mediatypeid // empty
    '
)"

echo "  Problem Telegram User : $VERIFY_PROBLEM_USER_ID"
echo "  Problem Media Type    : $VERIFY_PROBLEM_MEDIA_TYPE"
echo "  Recovery Telegram User: $VERIFY_RECOVERY_USER_ID"
echo "  Recovery Media Type   : $VERIFY_RECOVERY_MEDIA_TYPE"
echo

if [ "$VERIFY_PROBLEM_USER_ID" != "$TELEGRAM_USER_ID" ]; then
    die "ERROR: Problem operation is not sending to Telegram notification user."
fi

if [ "$VERIFY_PROBLEM_MEDIA_TYPE" != "$TELEGRAM_MT_ID" ]; then
    die "ERROR: Problem operation is not using Telegram Media Type."
fi

if [ "$VERIFY_RECOVERY_USER_ID" != "$TELEGRAM_USER_ID" ]; then
    die "ERROR: Recovery operation is not sending to Telegram notification user."
fi

if [ "$VERIFY_RECOVERY_MEDIA_TYPE" != "$TELEGRAM_MT_ID" ]; then
    die "ERROR: Recovery operation is not using Telegram Media Type."
fi

success "Trigger Action verification successful."

# ============================================================
# Final Telegram verification
# ============================================================

separator "Final Telegram verification"

FINAL_TEST_RESPONSE="$(
    curl -sS \
        --fail-with-body \
        --get \
        --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" \
        --data-urlencode "text=Zabbix Telegram integration configured successfully." \
        "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage"
)"

if echo "$FINAL_TEST_RESPONSE" |
    jq -e '.ok == true' >/dev/null 2>&1; then

    success "Final Telegram test successful."

else

    echo "$FINAL_TEST_RESPONSE" |
        jq . 2>/dev/null || true

    die "ERROR: Final Telegram test failed."
fi

# ============================================================
# Final summary
# ============================================================

separator "Telegram installation completed"

echo
echo "Telegram configuration:"
echo
echo "  Media Type ID       : $TELEGRAM_MT_ID"
echo "  Media Type          : Telegram"
echo "  Media Type Status   : Enabled"
echo
echo "  Notification User   : $TELEGRAM_USERNAME"
echo "  Notification UserID : $TELEGRAM_USER_ID"
echo "  Telegram Chat ID    : $TELEGRAM_CHAT_ID"
echo
echo "  Trigger Action      : $ACTION_NAME"
echo "  Trigger Action ID   : $ACTION_ID"
echo "  Trigger Action      : ENABLED"
echo "  Event source        : Trigger"
echo "  Escalation period   : 60 seconds"
echo
echo "  Problem Subject:"
echo "    $PROBLEM_SUBJECT"
echo
echo "  Recovery Subject:"
echo "    $RECOVERY_SUBJECT"
echo
echo "IMPORTANT:"
echo
echo "1. Check:"
echo "   Alerts -> Media types -> Telegram -> Parameters"
echo
echo "2. Confirm:"
echo "   api_token      = your Telegram Bot Token"
echo "   api_chat_id    = {ALERT.SENDTO}"
echo "   api_parse_mode = HTML"
echo
echo "3. Check:"
echo "   Users -> Telegram Notifications -> Media"
echo
echo "4. Confirm 'Send to' contains:"
echo "   $TELEGRAM_CHAT_ID"
echo
echo "5. Check:"
echo "   Alerts -> Actions -> Trigger actions"
echo
echo "6. Find the configured Action:"
echo "   $ACTION_NAME"
echo
echo "7. Confirm:"
echo "   Action          = Enabled"
echo "   Event source    = Trigger"
echo "   Problem         = Telegram Notifications"
echo "   Recovery        = Telegram Notifications"
echo

success "Telegram integration completed successfully."
