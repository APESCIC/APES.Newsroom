#!/usr/bin/env bash
#
# Thin Cloudron CLI wrapper for Cloud Agents / local ops.
# Requires env secrets (never commit these):
#   CLOUDRON_FQDN, CLOUDRON_TOKEN, CLOUDRON_APP_ID
# Optional: CLOUDRON_APP_ORIGIN_HOST (health checks)
#
# Examples:
#   ./scripts/cloudron.sh restart
#   ./scripts/cloudron.sh exec -- printenv | grep CLOUDRON_REDIS
#   ./scripts/cloudron.sh -- list
#
set -euo pipefail

if [[ -z "${1:-}" ]]; then
  echo "Usage: $0 <restart|exec|backup|sync|-- …cloudron args>" >&2
  exit 2
fi

missing=()
[[ -n "${CLOUDRON_FQDN:-}" ]] || missing+=(CLOUDRON_FQDN)
[[ -n "${CLOUDRON_TOKEN:-}" ]] || missing+=(CLOUDRON_TOKEN)
[[ -n "${CLOUDRON_APP_ID:-}" ]] || missing+=(CLOUDRON_APP_ID)

if ((${#missing[@]} > 0)); then
  echo "Missing required environment variables: ${missing[*]}" >&2
  echo "Add them as Cloud Agent / Actions secrets (same values as Deploy to Cloudron)." >&2
  exit 1
fi

if ! command -v cloudron >/dev/null 2>&1; then
  echo "cloudron CLI not found on PATH. Install with:" >&2
  echo "  npm install --prefix \"\$HOME/.local\" cloudron@^9" >&2
  echo "  export PATH=\"\$HOME/.local/node_modules/.bin:\$PATH\"" >&2
  exit 1
fi

base=(cloudron --server "${CLOUDRON_FQDN}" --token "${CLOUDRON_TOKEN}")

# Pass-through with --app for common ops when first arg is a verb.
case "${1}" in
  restart|backup|sync)
    exec "${base[@]}" "$@" --app "${CLOUDRON_APP_ID}"
    ;;
  exec)
    shift
    exec "${base[@]}" exec --app "${CLOUDRON_APP_ID}" "$@"
    ;;
  --)
    shift
    exec "${base[@]}" "$@"
    ;;
  *)
    # Allow full cloudron subcommands; inject --app if not already present.
    if printf '%s\n' "$@" | grep -qx -- '--app' || printf '%s\n' "$@" | grep -q -- "--app="; then
      exec "${base[@]}" "$@"
    fi
    exec "${base[@]}" "$@" --app "${CLOUDRON_APP_ID}"
    ;;
esac
