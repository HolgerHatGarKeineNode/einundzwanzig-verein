#!/usr/bin/env bash
#
# Election and DM centre in a real browser, in one command:
#
#   scripts/e2e-election.sh [run] [--port N] [-- <extra playwright args>]
#
# Fresh SQLite file, migrated once; a php -S server on 127.0.0.1:<port>
# (default 8181) behind tests/e2e/support/e2e-server.php; the election and DM
# specs; teardown. The exit code is the result of the specs.
#
# Nothing here touches the developer's database, sessions or log: everything
# lives in a temporary directory that is removed afterwards. The server's
# relay transport is an in-memory fake, the board is a throwaway key, and
# every value of .env that points at a live service is overridden below.
# The browser refuses every non-local request and WebSocket
# (tests/e2e/support/election.js).
#
# Host Chromium (/bin/chromium, playwright.config.js), no downloaded browser.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

port=8181
playwright_args=()

while [ $# -gt 0 ]; do
    case "$1" in
        run) shift ;;
        --port) port="$2"; shift 2 ;;
        --) shift; playwright_args=("$@"); break ;;
        *) playwright_args+=("$1"); shift ;;
    esac
done

if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
    echo "e2e-election: port $port is taken; pass --port N" >&2
    exit 2
fi

state="$(mktemp -d "${TMPDIR:-/tmp}/e2e-election.XXXXXX")"
server_pid=''

cleanup() {
    local rc=$?
    if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [ "$rc" -ne 0 ] && [ -s "$state/laravel.log" ]; then
        echo "--- server log (last 40 lines) ---" >&2
        tail -n 40 "$state/laravel.log" >&2
    fi
    rm -rf "$state"
    exit "$rc"
}
trap cleanup EXIT INT TERM

mkdir -p "$state/sessions"

# A real env var wins over .env (Dotenv is immutable), so these override the
# developer's values without touching the file.
export E2E_STATE_DIR="$state"
export E2E_BASE_URL="http://127.0.0.1:$port"
export APP_ENV=e2e
export APP_DEBUG=false
export APP_URL="$E2E_BASE_URL"
export DB_CONNECTION=sqlite
export DB_DATABASE="$state/e2e.sqlite"
export SESSION_DRIVER=file
export CACHE_STORE=database
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array
export LOG_CHANNEL=single
export LOG_STACK=single
export LOG_LEVEL=debug
export BROADCAST_CONNECTION=log
export SENTRY_LARAVEL_DSN=''
export NIGHTWATCH_ENABLED=false
export PULSE_ENABLED=false
export TELESCOPE_ENABLED=false
export NOSTR_RELAY=''
export NOSTR_P=''
export NOSTR_SPACE_URL=''
export NOSTR_PROFILE_RELAYS=''
export NOSTR_CLIENT_SK=''
export NOSTR_BUNKER_URL=''
export BTC_PAY_API_KEY=''
export BTC_PAY_WEBHOOK_SECRET=''
export BTC_PAY_BASE_URL='http://127.0.0.1:9'
export DM_REPEAT_LOCK_HOURS=24
NOSTR_NOTIFICATION_KEY="$(php -r 'require "vendor/autoload.php"; echo str_pad((new swentel\nostr\Key\Key)->generatePrivateKey(), 64, "0", STR_PAD_LEFT);')"
export NOSTR_NOTIFICATION_KEY

if [ -e bootstrap/cache/config.php ]; then
    echo "e2e-election: bootstrap/cache/config.php exists; a cached config ignores every override above. Run php artisan config:clear." >&2
    exit 2
fi

if [ ! -f public/build/manifest.json ]; then
    echo "e2e-election: public/build is missing; run npm run build" >&2
    exit 2
fi

started=$(date +%s)

[ -e public/storage ] || php artisan storage:link --no-interaction >/dev/null

DB_DATABASE="$state/base.sqlite" php artisan migrate --force --no-interaction >"$state/migrate.log" 2>&1 || {
    cat "$state/migrate.log" >&2
    exit 2
}

# Several workers: Livewire polls and the page's own requests run side by side.
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$port" -t public tests/e2e/support/e2e-server.php >"$state/server.log" 2>&1 &
server_pid=$!

for _ in $(seq 1 100); do
    if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
        break
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
        cat "$state/server.log" >&2
        exit 2
    fi
    sleep 0.1
done

# E2E_SPECS narrows the run while working on one spec, e.g.
# E2E_SPECS=tests/e2e/dm-centre.spec.js scripts/e2e-election.sh
specs=(
    tests/e2e/election-member.spec.js
    tests/e2e/election-board.spec.js
    tests/e2e/election-share.spec.js
    tests/e2e/dm-centre.spec.js
    tests/e2e/election-channels-positive-control.spec.js
    tests/e2e/election-layout.spec.js
)
if [ -n "${E2E_SPECS:-}" ]; then
    read -r -a specs <<<"$E2E_SPECS"
fi

set +e
npx playwright test "${specs[@]}" "${playwright_args[@]}"
rc=$?
set -e

echo "e2e-election: specs exit $rc, wall $(( $(date +%s) - started )) s"
exit "$rc"
