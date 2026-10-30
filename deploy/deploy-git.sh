#!/usr/bin/env bash
#
# Обновление Компаса на AHost (cPanel Terminal) из ветки `release` на GitHub. Ветку собирает GitHub Actions (workflow Release)
# при каждом пуше в main: в ней уже лежат vendor/ и собранное приложение в public/, на сервере не нужны ни composer, ни node.
#
# Запуск из корня приложения (там, где artisan и .git):
#
#   cd ~/apps/kompas && bash deploy/deploy-git.sh
#
# Что делает (каждый шаг останавливает скрипт при ошибке, пока сайт не тронут):
#   1. проверки: PHP 8.3 по явному пути и его расширения, git, .env, отсутствие правок в отслеживаемых файлах;
#   2. git fetch origin release; если коммит тот же — «уже последняя версия» и выход;
#   3. если между версиями есть новые миграции — дамп базы (старый код, сайт ещё работает) в BACKUP_PATH/predeploy/;
#   4. php artisan down; git reset --hard origin/release (.env и storage/ не отслеживаются и переживают обновление;
#      правки cPanel в public/.htaccess и public/.user.ini сохраняются);
#   5. migrate --force, только если есть ожидающие; при ошибке код возвращается на прежний коммит, сайт остаётся выключенным;
#   6. optimize:clear, optimize, up;
#   7. проверка /up на APP_URL; печать прежнего и нового коммитов и команды отката.
# Если /up не отвечает 200: без миграций код откатывается сам; с миграциями сайт остаётся в режиме обслуживания (решать по дампу).
#
# Параметры:
#   --no-down      не включать режим обслуживания
#   --no-backup    не снимать дамп перед миграциями
#   --branch X     брать ветку X (по умолчанию release)
#   --force        выкатить заново, даже если коммит не изменился
# Окружение: PHP_BIN (по умолчанию /opt/alt/php83/usr/bin/php), HEALTH_URL (по умолчанию APP_URL из .env).

set -euo pipefail

# git reset перезаписывает и этот файл, а bash читает скрипт по ходу выполнения. Поэтому сначала копия во временный файл.
if [ "${DEPLOY_GIT_SELF_COPY:-}" != "1" ]; then
    SELF_COPY="$(mktemp)"
    cp "$0" "$SELF_COPY"
    trap 'rm -f "$SELF_COPY"' EXIT
    export DEPLOY_GIT_SELF_COPY=1
    code=0
    bash "$SELF_COPY" "$@" || code=$?
    exit $code
fi

BRANCH="release"
USE_DOWN=1
USE_BACKUP=1
FORCE=0
ROOT="$PWD"
PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

while [ $# -gt 0 ]; do
    case "$1" in
        --no-down) USE_DOWN=0; shift ;;
        --no-backup) USE_BACKUP=0; shift ;;
        --force) FORCE=1; shift ;;
        --branch) BRANCH="${2:?--branch требует значение}"; shift 2 ;;
        -h|--help) sed -n '2,/^set -euo pipefail/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Неизвестный параметр: $1 (см. --help)" >&2; exit 2 ;;
    esac
done

step() { printf '\n==> %s\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '    ВНИМАНИЕ: %s\n' "$1"; }
fail() { printf '\nОШИБКА: %s\n' "$1" >&2; exit 1; }

cd "$ROOT"
export GIT_TERMINAL_PROMPT=0

# ---------------------------------------------------------------- 1. проверки

step "Проверки"
[ -x "$PHP_BIN" ] || fail "нет $PHP_BIN. Посмотрите ls /opt/alt и запустите так: PHP_BIN=/opt/alt/phpXX/usr/bin/php bash deploy/deploy-git.sh"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || fail "$PHP_BIN — старше PHP 8.3"
# alt-php84 на AHost принудительно грузит PECL psr: его интерфейсы ломают Guzzle и Monolog, artisan падает молча.
"$PHP_BIN" -r 'exit(extension_loaded("psr") ? 1 : 0);' \
    || fail "$PHP_BIN загружает расширение psr, с ним Laravel не работает. Нужен PHP 8.3: PHP_BIN=/opt/alt/php83/usr/bin/php"
missing=""
for ext in bcmath ctype curl dom fileinfo intl mbstring openssl pdo_mysql tokenizer xml; do
    "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing="$missing $ext"
done
[ -z "$missing" ] || fail "у PHP нет расширений:$missing. Включите их в cPanel → Select PHP Version → Extensions"
command -v git >/dev/null 2>&1 || fail "нет git в PATH"
[ -d .git ] || fail "в $ROOT нет .git: первая установка делается по docs/DEPLOY.md в репозитории"
[ -f .env ] || fail "в $ROOT нет .env: создайте его из deploy/.env.production.example (docs/DEPLOY.md в репозитории, шаг 5)"

env_value() {
    local key="$1" line value
    line="$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}=" .env 2>/dev/null | tail -n 1 | tr -d '\r')" || true
    value="${line#*=}"
    [ -n "$line" ] || value=""
    case "$value" in
        \"*) value="${value#\"}"; value="${value%%\"*}" ;;
        \'*) value="${value#\'}"; value="${value%%\'*}" ;;
        *) value="${value%%[[:space:]]#*}"; value="${value%"${value##*[![:space:]]}"}" ;;
    esac
    printf '%s' "$value"
}

art() { "$PHP_BIN" artisan "$@"; }

# chmod на сервере меняет биты прав у отслеживаемых файлов: для git это не правка
git config core.fileMode false

# правки отслеживаемых файлов на сервере reset --hard стёр бы молча. Два файла public/ cPanel переписывает сам (лимиты PHP):
# их копия откладывается перед reset и возвращается после.
HOST_FILES="public/.htaccess public/.user.ini"
dirty="$(git status --porcelain --untracked-files=no -- . ':(exclude)public/.htaccess' ':(exclude)public/.user.ini')"
if [ -n "$dirty" ]; then
    printf '%s\n' "$dirty" | head -n 15 >&2
    fail "на сервере изменены файлы кода (список выше). Ветка release перезаписывается целиком: правки делаются в репозитории. Выбросить серверные правки: git checkout -- .  и запустить скрипт ещё раз"
fi
info "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;'), git $(git --version | awk '{print $3}'), ветка $BRANCH"

prev="$(git rev-parse --verify --quiet HEAD || true)"

# ---------------------------------------------------------------- 2. fetch

step "git fetch origin $BRANCH"
git fetch --quiet origin "+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH" \
    || fail "git fetch не удался. Проверьте ключ GitHub: ssh -T -o ConnectTimeout=10 git@github-kompas (docs/DEPLOY.md в репозитории, шаг 3)"
new="$(git rev-parse --verify "origin/$BRANCH")"
info "сейчас:  ${prev:0:12} ${prev:+$(git log -1 --format=%s "$prev" 2>/dev/null || true)}"
info "новая:   ${new:0:12} $(git log -1 --format=%s "$new")"

if [ "$prev" = "$new" ] && [ "$FORCE" != "1" ]; then
    echo
    echo "Уже последняя версия (${new:0:12}): ничего не делаю. Принудительно: --force"
    exit 0
fi

expect_migrations=0
if [ -n "$prev" ] && git cat-file -e "$prev^{commit}" 2>/dev/null; then
    if [ -n "$(git diff --name-only "$prev" "$new" -- database/migrations)" ]; then expect_migrations=1; fi
else
    expect_migrations=1
fi

# ---------------------------------------------------------------- 3. дамп

DUMP_FILE=""
dump_db() {
    local dir tool db user pass host port cnf esc part
    dir="$(env_value BACKUP_PATH)"
    [ -n "$dir" ] || dir="$HOME/backups/kompas"
    dir="${dir%/}/predeploy"
    mkdir -p "$dir" && chmod 700 "$dir" || fail "нельзя создать $dir"
    tool=""
    for name in mariadb-dump mysqldump; do
        if command -v "$name" >/dev/null 2>&1; then tool="$(command -v "$name")"; break; fi
    done
    [ -n "$tool" ] || fail "нет mariadb-dump/mysqldump. Снимите дамп в phpMyAdmin (Export) и запустите с --no-backup"
    db="$(env_value DB_DATABASE)"; user="$(env_value DB_USERNAME)"; pass="$(env_value DB_PASSWORD)"
    host="$(env_value DB_HOST)"; host="${host:-localhost}"; port="$(env_value DB_PORT)"; port="${port:-3306}"
    [ -n "$db" ] && [ -n "$user" ] || fail "DB_DATABASE или DB_USERNAME в .env пусты"
    # пароль идёт через временный файл с правами 600, а не в командной строке (её видят другие процессы).
    # --defaults-file, а не --defaults-extra-file: с extra ~/.my.cnf читается позже и перебивает логин, а на общем
    # аккаунте cPanel он бывает от другого сайта (Access denied for user 'другой'@'localhost' to database ...)
    cnf="$(mktemp "${TMPDIR:-/tmp}/kompas-dump.XXXXXX")"
    esc="$(printf '%s' "$pass" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
    ( umask 077; printf '[client]\nuser="%s"\npassword="%s"\nhost="%s"\nport=%s\n' "$user" "$esc" "$host" "$port" > "$cnf" )
    DUMP_FILE="$dir/predeploy_$(date +%Y%m%d_%H%M%S)_${prev:0:8}.sql.gz"
    part="$DUMP_FILE.part"
    if ! "$tool" --defaults-file="$cnf" --single-transaction --quick --routines --events --triggers --hex-blob \
            --no-tablespaces --default-character-set=utf8mb4 "$db" | gzip -6 > "$part"; then
        rm -f "$cnf" "$part"
        fail "дамп не снялся, сайт НЕ тронут. Снимите дамп в phpMyAdmin и запустите с --no-backup"
    fi
    rm -f "$cnf"
    if ! gzip -dc "$part" | tail -c 1024 | grep -q -- '-- Dump completed'; then
        rm -f "$part"
        fail "дамп оборван (нет строки «-- Dump completed»), сайт НЕ тронут"
    fi
    mv "$part" "$DUMP_FILE"
    chmod 600 "$DUMP_FILE"
    info "дамп: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"
    # хранятся 5 последних дампов: диск общий на все сайты
    # shellcheck disable=SC2012
    ls -t "$dir"/predeploy_*.sql.gz 2>/dev/null | tail -n +6 | while read -r old; do rm -f "$old"; done
}

if [ "$expect_migrations" = "1" ] && [ "$USE_BACKUP" = "1" ] && [ -n "$prev" ]; then
    step "Дамп базы перед миграциями (старый код, сайт работает)"
    dump_db
elif [ "$USE_BACKUP" != "1" ]; then
    warn "дамп пропущен (--no-backup): убедитесь, что свежий дамп есть"
elif [ -z "$prev" ]; then
    info "первая выкладка: дамп пустой базы не нужен"
else
    info "новых миграций между версиями нет: дамп не нужен"
fi

# ---------------------------------------------------------------- 4. down и новый код

HOST_KEEP="$(mktemp -d)"
host_files_save() {
    for f in $HOST_FILES; do
        [ -f "$f" ] && cp -p "$f" "$HOST_KEEP/$(echo "$f" | tr / _)" || true
    done
}
# .htaccess берётся новый из релиза, и в конец дописывается блок «BEGIN cPanel-generated … END» из сохранённого.
# .user.ini возвращается целиком: его пишет cPanel.
host_files_restore() {
    local saved="$HOST_KEEP/public_.htaccess"
    if [ -f "$saved" ] && grep -q 'BEGIN cPanel-generated' "$saved" && ! grep -q 'BEGIN cPanel-generated' public/.htaccess; then
        { printf '\n'; sed -n '/BEGIN cPanel-generated/,/END cPanel-generated/p' "$saved"; } >> public/.htaccess
    fi
    saved="$HOST_KEEP/public_.user.ini"
    if [ -f "$saved" ] && grep -q 'cPanel-generated' "$saved"; then
        cp -p "$saved" public/.user.ini
    fi
}

DOWN_DONE=0
step "Режим обслуживания и новый код"
if [ "$USE_DOWN" = "1" ] && [ -f artisan ]; then
    art down --retry=15 >/dev/null && DOWN_DONE=1 || warn "down не удался (возможно, сайт уже выключен), продолжаю"
fi
host_files_save
git reset --hard "$new" --quiet
host_files_restore
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
info "код: ${new:0:12} (.env, storage/ и файлы cPanel не тронуты)"

rollback_code() {
    printf '\nОткат кода на %s\n' "${prev:0:12}" >&2
    if [ -n "$prev" ]; then
        git reset --hard "$prev" --quiet
        host_files_restore
        art optimize:clear >/dev/null 2>&1 || true
        art optimize >/dev/null 2>&1 || true
    fi
}

# ---------------------------------------------------------------- 5. миграции

step "Миграции"
status_out="$(art migrate:status --pending --no-ansi 2>&1)" || {
    # на пустой базе нет таблицы migrations: migrate:status отвечает «Migration table not found»
    if printf '%s' "$status_out" | grep -qi "migration table not found"; then status_out="Pending (first install)"
    else echo "$status_out" | tail -n 8 >&2; rollback_code; fail "migrate:status не отработал (база недоступна? проверьте DB_* в .env). Код возвращён на прежний коммит"; fi
}
MIGRATED=0
if printf '%s' "$status_out" | grep -q "No pending migrations"; then
    info "ожидающих миграций нет"
elif printf '%s' "$status_out" | grep -q "Pending"; then
    info "есть ожидающие миграции"
    if [ "$USE_BACKUP" = "1" ] && [ -z "$DUMP_FILE" ] && [ -n "$prev" ]; then
        warn "дамп ещё не снят (миграции нашлись уже в новом коде): снимаю сейчас, сайт выключен"
        dump_db
    fi
    if ! art migrate --force; then
        rollback_code
        printf '\nmigrate упал. Код возвращён на %s, сайт оставлен в режиме обслуживания: миграции могли примениться частично.\n' "${prev:0:12}" >&2
        [ -z "$DUMP_FILE" ] || printf 'Дамп до миграций: %s (восстановление: docs/DEPLOY.md, «Если что-то пошло не так»). Потом: %s artisan up\n' "$DUMP_FILE" "$PHP_BIN" >&2
        exit 1
    fi
    MIGRATED=1
else
    echo "$status_out" | tail -n 8 >&2
    rollback_code
    fail "не разобрал вывод migrate:status (выше)"
fi

# ---------------------------------------------------------------- 6. кэши, up

step "Кэши"
art optimize:clear >/dev/null
art optimize >/dev/null
info "optimize выполнен"
if [ "$DOWN_DONE" = "1" ]; then
    art up >/dev/null
    info "сайт включён"
fi

# ---------------------------------------------------------------- 7. проверка

step "Проверка"
url="${HEALTH_URL:-$(env_value APP_URL)}"
health="200"
broken=""
if command -v curl >/dev/null 2>&1 && [ -n "$url" ]; then
    # /up — Laravel поднимается; / — собранное приложение на месте (index.html)
    for path in /up /; do
        code="000"
        for attempt in 1 2 3; do
            code="$(curl -s -o /dev/null -m 15 -w '%{http_code}' "${url%/}$path" || true)"
            [ "$code" = "200" ] && break
            sleep 2
        done
        info "${url%/}$path -> HTTP $code"
        if [ "$code" != "200" ]; then health="$code"; broken="$path"; fi
    done
else
    warn "нет curl или APP_URL: проверка пропущена"
fi

if [ "$health" != "200" ]; then
    if [ "$MIGRATED" = "0" ] && [ -n "$prev" ]; then
        rollback_code
        fail "$broken не ответил 200 (HTTP $health). Миграций не было, код возвращён на ${prev:0:12}. Смотрите storage/logs/laravel-*.log"
    fi
    printf '\n%s не ответил 200 (HTTP %s). Смотрите storage/logs/laravel-*.log и docs/DEPLOY.md, «Если что-то пошло не так».\n' "$broken" "$health" >&2
    exit 1
fi

printf '\nГотово.\n'
printf '  было:   %s\n  стало:  %s  %s\n' "${prev:-—}" "$new" "$(git log -1 --format=%s "$new")"
[ -z "$DUMP_FILE" ] || printf '  дамп до миграций: %s\n' "$DUMP_FILE"
if [ -n "$prev" ]; then
    printf '\nОткат кода, если надо вернуться:\n  cd %s && git reset --hard %s && %s artisan optimize:clear && %s artisan optimize\n' "$ROOT" "$prev" "$PHP_BIN" "$PHP_BIN"
    printf 'Миграции назад сами не откатываются: для этого дамп выше.\n'
fi
