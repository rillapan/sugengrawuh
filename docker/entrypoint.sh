#!/bin/sh
set -e

umask 0002

ROLE="${CONTAINER_ROLE:-app}"
APP_DIR="/var/www/html"

cd "$APP_DIR"

log_info() {
    echo "[SUGENG-RAWUH][${ROLE}][INFO] $*"
}

log_error() {
    echo "[SUGENG-RAWUH][${ROLE}][ERROR] $*" >&2
}

init_storage_and_permissions() {
    mkdir -p \
        "$APP_DIR/storage/app/public" \
        "$APP_DIR/storage/framework/cache/data" \
        "$APP_DIR/storage/framework/sessions" \
        "$APP_DIR/storage/framework/testing" \
        "$APP_DIR/storage/framework/views" \
        "$APP_DIR/storage/logs" \
        "$APP_DIR/bootstrap/cache"

    if [ -d "/usr/src/storage-skel" ]; then
        cp -rn /usr/src/storage-skel/. "$APP_DIR/storage/" 2>/dev/null || true
    fi

    if [ -d "$APP_DIR/public-shared" ] && [ "$ROLE" = "app" ]; then
        log_info "Syncing static public assets to shared volume..."
        cp -a "$APP_DIR/public/." "$APP_DIR/public-shared/"
    fi

    chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
    chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
}

check_app_key() {
    EFFECTIVE_KEY="${APP_KEY:-}"
    if [ -z "$EFFECTIVE_KEY" ] && [ -f "$APP_DIR/.env" ]; then
        EFFECTIVE_KEY="$(grep -E '^APP_KEY=' "$APP_DIR/.env" | tail -n 1 | cut -d '=' -f2- | tr -d '[:space:]"'"'" || true)"
    fi

    if [ -z "$EFFECTIVE_KEY" ]; then
        log_error "APP_KEY is empty or missing! Refusing to start in production without a valid APP_KEY."
        log_error "Run the deployment setup script to generate APP_KEY in .env.production before starting containers."
        exit 1
    fi
}

wait_for_mysql() {
    MAX_TRIES="${DB_WAIT_TIMEOUT_SECONDS:-90}"
    ATTEMPT=1
    log_info "Waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306} (timeout: ${MAX_TRIES}s)..."

    while [ "$ATTEMPT" -le "$MAX_TRIES" ]; do
        if php -r '
            $host = getenv("DB_HOST") ?: "db";
            $port = getenv("DB_PORT") ?: "3306";
            $db   = getenv("DB_DATABASE") ?: "sugeng_rawuh_pos";
            $user = getenv("DB_USERNAME") ?: "sugeng_pos";
            $pass = getenv("DB_PASSWORD") ?: "";
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }
        '; then
            log_info "MySQL connection established."
            return 0
        fi
        sleep 1
        ATTEMPT=$((ATTEMPT + 1))
    done

    log_error "Timed out waiting for MySQL after ${MAX_TRIES} seconds."
    exit 1
}

wait_for_schema() {
    MAX_TRIES="${SCHEMA_WAIT_TIMEOUT_SECONDS:-90}"
    ATTEMPT=1
    log_info "Waiting for database schema (users & transactions tables)..."

    while [ "$ATTEMPT" -le "$MAX_TRIES" ]; do
        if php -r '
            $host = getenv("DB_HOST") ?: "db";
            $port = getenv("DB_PORT") ?: "3306";
            $db   = getenv("DB_DATABASE") ?: "sugeng_rawuh_pos";
            $user = getenv("DB_USERNAME") ?: "sugeng_pos";
            $pass = getenv("DB_PASSWORD") ?: "";
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $u = $pdo->query("SHOW TABLES LIKE \"users\"")->fetchColumn();
                $t = $pdo->query("SHOW TABLES LIKE \"transactions\"")->fetchColumn();
                if ($u && $t) {
                    exit(0);
                }
                exit(1);
            } catch (Throwable $e) {
                exit(1);
            }
        '; then
            log_info "Database schema verified."
            return 0
        fi
        sleep 2
        ATTEMPT=$((ATTEMPT + 1))
    done

    log_error "Timed out waiting for database schema."
    exit 1
}

init_storage_and_permissions

case "$ROLE" in
    app)
        check_app_key
        wait_for_mysql

        log_info "Running incremental database migrations..."
        gosu www-data php "$APP_DIR/artisan" migrate --force --no-interaction

        log_info "Running production baseline check and session reconciliation..."
        gosu www-data php "$APP_DIR/artisan" app:production-init --reconcile --no-interaction

        log_info "Refreshing configuration and view caches (Closure-safe)..."
        gosu www-data php "$APP_DIR/artisan" route:clear --no-interaction
        gosu www-data php "$APP_DIR/artisan" config:cache --no-interaction
        gosu www-data php "$APP_DIR/artisan" view:cache --no-interaction

        chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true

        log_info "Starting PHP-FPM server..."
        exec "$@"
        ;;

    daemon)
        check_app_key
        wait_for_mysql
        wait_for_schema

        log_info "Running initial billing session reconciliation..."
        gosu www-data php "$APP_DIR/artisan" billing:reconcile --no-interaction || true

        INTERVAL_MS="${BILLING_DAEMON_INTERVAL_MS:-1000}"
        LEASE_SEC="${BILLING_DAEMON_LEASE_SECONDS:-60}"
        log_info "Starting sub-minute billing daemon (interval=${INTERVAL_MS}ms, lease=${LEASE_SEC}s)..."
        exec gosu www-data php "$APP_DIR/artisan" billing:daemon \
            --interval="$INTERVAL_MS" \
            --lease="$LEASE_SEC" \
            --no-interaction
        ;;

    scheduler)
        check_app_key
        wait_for_mysql
        wait_for_schema

        STOP_REQUESTED=0
        trap 'STOP_REQUESTED=1; log_info "Shutdown signal received, stopping scheduler..."' INT TERM

        log_info "Starting Laravel scheduler loop (60s interval)..."
        while [ "$STOP_REQUESTED" -eq 0 ]; do
            gosu www-data php "$APP_DIR/artisan" schedule:run --verbose --no-interaction || true
            SLEEP_COUNT=0
            while [ "$SLEEP_COUNT" -lt 60 ] && [ "$STOP_REQUESTED" -eq 0 ]; do
                sleep 1
                SLEEP_COUNT=$((SLEEP_COUNT + 1))
            done
        done
        exit 0
        ;;

    cli)
        exec gosu www-data "$@"
        ;;

    *)
        log_error "Unknown CONTAINER_ROLE: ${ROLE} (allowed: app, daemon, scheduler, cli)"
        exit 1
        ;;
esac

