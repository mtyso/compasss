#!/usr/bin/env bash
#
# Assembles the production tree of Компас into DIR (D13): what AHost needs and nothing else.
# Run from the repository root after
#   (cd server && composer install --no-dev --optimize-autoloader)
#   (cd app && npm ci && npm run build)
# The Release workflow (.github/workflows/release.yml) does exactly that and commits DIR to the branch `release`.
#
#   bash deploy/build-release.sh DIR
#
# DIR gets the Laravel app from server/ (no tests, no dev tools), its vendor/, the built SPA in public/, the storage skeleton,
# deploy/deploy-git.sh with the .env example, RELEASE.txt and the branch's own .gitignore. The host needs neither composer nor node.

set -euo pipefail

OUT="${1:?usage: bash deploy/build-release.sh DIR}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER="$ROOT/server"
DIST="$ROOT/app/dist"

fail() { printf 'build-release: %s\n' "$1" >&2; exit 1; }

[ -f "$SERVER/vendor/autoload.php" ] || fail "no server/vendor: run composer install --no-dev --optimize-autoloader in server/ first"
[ -d "$SERVER/vendor/pestphp" ] && fail "server/vendor has dev packages: run composer install --no-dev --optimize-autoloader"
[ -f "$DIST/index.html" ] || fail "no app/dist/index.html: run npm run build in app/ first"
[ ! -e "$OUT" ] || [ -z "$(ls -A "$OUT")" ] || fail "$OUT is not empty"
mkdir -p "$OUT"

# the app itself
for path in app bootstrap config database lang resources routes vendor artisan composer.json composer.lock; do
    cp -a "$SERVER/$path" "$OUT/"
done
rm -f "$OUT"/bootstrap/cache/*.php
# a package installed from source carries its own .git: git would commit it as a broken submodule
find "$OUT/vendor" -name .git -prune -exec rm -rf {} +

# public/: the server's own files, then the built SPA (index.html, assets/, favicon.svg)
mkdir -p "$OUT/public"
(cd "$SERVER" && git ls-files public) | while read -r file; do cp -a "$SERVER/$file" "$OUT/$file"; done
cp -a "$DIST"/. "$OUT/public/"

# storage/: only the skeleton of folders and their .gitignore files, never logs, sessions or keys
(cd "$SERVER" && git ls-files storage) | while read -r file; do
    mkdir -p "$OUT/$(dirname "$file")"
    cp -a "$SERVER/$file" "$OUT/$file"
done

# the server update script and the .env example travel with the code
mkdir -p "$OUT/deploy"
cp -a "$ROOT/deploy/deploy-git.sh" "$ROOT/deploy/.env.production.example" "$OUT/deploy/"
chmod +x "$OUT/deploy/deploy-git.sh"

# the branch's own rules: what lives only on the server stays untracked and survives `git reset --hard`
cat > "$OUT/.gitignore" <<'EOF'
# The generated branch `release` (.github/workflows/release.yml). Do not edit by hand: the next release overwrites it.
/.env
/.env.*
/bootstrap/cache/*.php
/public/hot
/public/storage
/storage/logs/*.log
/*.zip
EOF
# bytes exactly as built: no line-ending conversion of vendor/ files
echo '* -text' > "$OUT/.gitattributes"

source_sha="$(git -C "$ROOT" rev-parse HEAD)"
{
    echo "source: $source_sha"
    echo "subject: $(git -C "$ROOT" log -1 --format=%s HEAD)"
} > "$OUT/RELEASE.txt"

# nothing that must never ship
for bad in .env tests node_modules phpunit.xml; do
    [ ! -e "$OUT/$bad" ] || fail "$bad ended up in the release tree"
done
[ -z "$(find "$OUT/storage" -type f ! -name .gitignore -print -quit)" ] || fail "storage/ holds more than the skeleton"

printf 'release tree: %s files, %s in %s\n' "$(find "$OUT" -type f | wc -l | tr -d ' ')" "$(du -sh "$OUT" | cut -f1)" "$OUT"
