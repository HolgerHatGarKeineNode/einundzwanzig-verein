#!/usr/bin/env bash
#
# Runs the engine suite (tests/Engine) against a real database engine in a
# throwaway container.
#
#   scripts/test-engine.sh [engine] [pest arguments…]
#
#   engine          pgsql (default), mysql or mariadb
#   ENGINE_IMAGE    container image; default postgres:17-alpine, mysql:8.4,
#                   mariadb:11.4 — set it to the version production runs
#   CONTAINER_RUNTIME  docker (default) or podman
#
# The default suite runs on SQLite in memory: one connection, no second
# writer, no row locks, and a transaction that survives a constraint
# violation. Whatever depends on those is tested here instead.
#
# Nothing persists. The container gets a random name, a random database name
# and a random password, keeps its data on a tmpfs, publishes its port on
# 127.0.0.1 only and is removed when the script ends, however it ends.
#
# Both database connections are pointed at the container explicitly. The
# second one ("einundzwanzig") reads its own DB_*_EINUNDZANZIG variables, and a
# developer's .env may aim those at a real database; tests/Engine refuses to
# start unless both connections match what is announced here
# (Tests\Engine\EngineTestCase).
#
# Only the pgsql path has been run. The mysql and mariadb paths are written
# from the images' documentation and stop at the driver check on a PHP without
# pdo_mysql (the case on the machine this was written on, 2026-11-14).

set -euo pipefail

cd "$(dirname "$0")/.."

engine="${1:-pgsql}"
[ "$#" -gt 0 ] && shift

runtime="${CONTAINER_RUNTIME:-docker}"

case "$engine" in
    pgsql)
        image="${ENGINE_IMAGE:-postgres:17-alpine}"
        container_port=5432
        php_extension=pdo_pgsql
        ;;
    mysql)
        image="${ENGINE_IMAGE:-mysql:8.4}"
        container_port=3306
        php_extension=pdo_mysql
        ;;
    mariadb)
        image="${ENGINE_IMAGE:-mariadb:11.4}"
        container_port=3306
        php_extension=pdo_mysql
        ;;
    *)
        echo "test-engine: unknown engine '$engine' (pgsql, mysql, mariadb)" >&2
        exit 64
        ;;
esac

if ! command -v "$runtime" >/dev/null 2>&1; then
    echo "test-engine: no container runtime '$runtime' on this machine — engine suite NOT RUN" >&2
    exit 69
fi

if ! php -m | grep -qix "$php_extension"; then
    echo "test-engine: this PHP has no $php_extension — engine suite NOT RUN for '$engine'" >&2
    exit 69
fi

random_hex() {
    od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'
}

run_id="$(random_hex 4)"
container="verein-engine-$run_id"
database="engine_$run_id"
username="engine"
# Exported so the container runtime and PHP read it from the environment; it
# never appears on a command line.
export ENGINE_PASSWORD
ENGINE_PASSWORD="$(random_hex 16)"

cleanup() {
    "$runtime" rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

case "$engine" in
    pgsql)
        "$runtime" run -d --rm --name "$container" \
            -e POSTGRES_USER="$username" -e POSTGRES_DB="$database" -e POSTGRES_PASSWORD="$ENGINE_PASSWORD" \
            --tmpfs /var/lib/postgresql \
            -p "127.0.0.1::$container_port" "$image" >/dev/null
        dsn_prefix="pgsql"
        ;;
    mysql | mariadb)
        "$runtime" run -d --rm --name "$container" \
            -e MYSQL_ROOT_PASSWORD="$ENGINE_PASSWORD" -e MARIADB_ROOT_PASSWORD="$ENGINE_PASSWORD" \
            -e MYSQL_DATABASE="$database" -e MARIADB_DATABASE="$database" \
            --tmpfs /var/lib/mysql \
            -p "127.0.0.1::$container_port" "$image" >/dev/null
        username="root"
        dsn_prefix="mysql"
        ;;
esac

port="$("$runtime" port "$container" "$container_port/tcp" | head -n1 | sed 's/.*://')"

# Ready means: the connection the tests will use works. The images start a
# temporary server during initialisation, so a listening port alone says
# nothing. Polls the condition and gives up after 90 seconds.
export ENGINE_DSN="$dsn_prefix:host=127.0.0.1;port=$port;dbname=$database"
export ENGINE_USERNAME="$username"
deadline=$((SECONDS + 90))
until php -r 'try { new PDO(getenv("ENGINE_DSN"), getenv("ENGINE_USERNAME"), getenv("ENGINE_PASSWORD")); } catch (Throwable $e) { exit(1); }' 2>/dev/null; do
    if [ "$SECONDS" -ge "$deadline" ]; then
        echo "test-engine: $image did not accept connections within 90 s" >&2
        "$runtime" logs --tail 20 "$container" >&2 || true
        exit 70
    fi
    if ! "$runtime" inspect "$container" >/dev/null 2>&1; then
        echo "test-engine: the container exited during start-up" >&2
        exit 70
    fi
    sleep 0.2
done

# The second connection has a fixed database name in config/database.php.
php -r '(new PDO(getenv("ENGINE_DSN"), getenv("ENGINE_USERNAME"), getenv("ENGINE_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec("CREATE DATABASE einundzwanzig");'

echo "test-engine: $engine ($image) on 127.0.0.1:$port, database $database"

# phpunit.xml sets DB_CONNECTION=sqlite without force, so the variables
# exported here win. Everything else in phpunit.xml applies unchanged.
set +e
DB_CONNECTION="$engine" \
    DB_URL="" \
    DB_HOST="127.0.0.1" \
    DB_PORT="$port" \
    DB_DATABASE="$database" \
    DB_USERNAME="$username" \
    DB_PASSWORD="$ENGINE_PASSWORD" \
    DB_URL_EINUNDZANZIG="" \
    DB_HOST_EINUNDZANZIG="127.0.0.1" \
    DB_PORT_EINUNDZANZIG="$port" \
    DB_USERNAME_EINUNDZANZIG="$username" \
    DB_PASSWORD_EINUNDZANZIG="$ENGINE_PASSWORD" \
    ENGINE_SUITE_DRIVER="$engine" \
    ENGINE_SUITE_PORT="$port" \
    ENGINE_SUITE_DATABASE="$database" \
    vendor/bin/pest tests/Engine "$@"
status=$?
set -e

exit "$status"
