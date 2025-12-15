#!/bin/bash
set -euo pipefail

# ---------------- Helpers ----------------

# Temperature (optional)
get_temp() {
    if command -v sensors >/dev/null 2>&1; then
        # First temp1 value; strip + and °C
        sensors 2>/dev/null | awk '/temp1/ {print $2; exit}' | tr -d '+°C'
    else
        echo ""
    fi
}

# Disk free on /
get_disk() {
    df -h / | awk 'NR==2 {print $4}'
}

# JSON-encode stdin as a JSON string (escapes control chars, quotes, etc.)
json_quote_stdin() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null
        return $?
    fi
    if command -v jq >/dev/null 2>&1; then
        jq -Rs . 2>/dev/null
        return $?
    fi
    # Fallback: strip control chars and do minimal escaping
    # (Less ideal, but still outputs valid JSON string)
    local s
    s="$(cat | LC_ALL=C tr -d '\000-\037' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
    printf '"%s"' "$s"
}

# Validate a string is JSON (requires jq or python3). If neither exists, do a cheap check.
is_valid_json() {
    local input="$1"
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import json,sys; json.loads(sys.stdin.read());' <<<"$input" >/dev/null 2>&1
        return $?
    fi
    if command -v jq >/dev/null 2>&1; then
        jq -e . >/dev/null 2>&1 <<<"$input"
        return $?
    fi
    # Cheap fallback: starts with { or [ (not perfect, but better than nothing)
    [[ "$input" =~ ^[[:space:]]*[\{\[] ]]
}

# ---------------- System Stats ----------------

load_1=$(cut -d ' ' -f1 /proc/loadavg)
load_5=$(cut -d ' ' -f2 /proc/loadavg)
load_15=$(cut -d ' ' -f3 /proc/loadavg)

uptime_seconds=$(cut -d ' ' -f1 < /proc/uptime)

mem_total=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
mem_free=$(awk '/MemFree/ {print $2}' /proc/meminfo)
mem_avail=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)

temp="$(get_temp)"
disk_free="$(get_disk)"

systemname="$(hostname)"

# ---------------- Tailscale ----------------

tailscale_json="null"
tailscale_nearest_derp="null"

if command -v tailscale >/dev/null 2>&1; then
   # tailscale status --json (ensure it's valid JSON; otherwise "null")
   ts_out="$(tailscale status --json 2>/dev/null || true)"
   if [ -n "$ts_out" ] && command -v jq >/dev/null 2>&1; then
       tailscale_json="$(printf '%s' "$ts_out" | jq -c 'del(.Peer, .User)' 2>/dev/null || echo "null")"
    else
       tailscale_json="null"
    fi

    # tailscale netcheck nearest DERP line (encode safely to JSON string)
    # netcheck can be slow/hang in odd cases; keep it bounded if timeout exists
    if command -v timeout >/dev/null 2>&1; then
        nearest_line="$(timeout 3 tailscale netcheck 2>/dev/null | grep -m1 'Nearest' || true)"
    else
        nearest_line="$(tailscale netcheck 2>/dev/null | grep -m1 'Nearest' || true)"
    fi

    if [ -n "${nearest_line:-}" ]; then
        # Encode as JSON string (handles tabs/escapes/control chars correctly)
        tailscale_nearest_derp="$(printf '%s' "$nearest_line" | json_quote_stdin || echo "null")"
    else
        tailscale_nearest_derp="null"
    fi
fi

# ---------------- Output JSON ----------------

cat <<EOF
{
  "hostname": "$(hostname)",
  "system": "$systemname",
  "updated": $(date +%s),
  "uptime_seconds": $uptime_seconds,
  "load_average": {
      "1min": $load_1,
      "5min": $load_5,
      "15min": $load_15
  },
  "memory_kb": {
      "total": $mem_total,
      "free": $mem_free,
      "available": $mem_avail
  },
  "disk": {
      "root_free": "$disk_free"
  },
  "temperature_celsius": ${temp:-null},
  "tailscale_nearest_derp": $tailscale_nearest_derp,
  "tailscale": $tailscale_json
}
EOF
