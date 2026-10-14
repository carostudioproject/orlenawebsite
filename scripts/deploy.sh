#!/usr/bin/env bash
# Deploys the committed code (HEAD) plus freshly built assets to a server over SSH.
#
#   scripts/deploy.sh dev        reads scripts/deploy.dev.env
#   scripts/deploy.sh live       reads scripts/deploy.live.env
#
# The target file (not in git) sets DEPLOY_HOST, DEPLOY_PORT, DEPLOY_USER, DEPLOY_KEY and DEPLOY_APP_DIR;
# see scripts/deploy.example.env. The server keeps its own .env, storage/ and vendor/ (refreshed by composer).
set -euo pipefail

target="${1:-}"
root="$(cd "$(dirname "$0")/.." && pwd)"
config="$root/scripts/deploy.$target.env"
if [[ -z "$target" || ! -f "$config" ]]; then
    echo "Usage: scripts/deploy.sh <target>  (needs scripts/deploy.<target>.env, see scripts/deploy.example.env)" >&2
    exit 1
fi
# shellcheck source=/dev/null
source "$config"
: "${DEPLOY_HOST:?}" "${DEPLOY_USER:?}" "${DEPLOY_APP_DIR:?}"
port="${DEPLOY_PORT:-22}"
key="${DEPLOY_KEY:-$HOME/.ssh/id_ed25519}"
ssh_opts=(-i "$key" -o BatchMode=yes)

cd "$root"
if [[ -n "$(git status --porcelain)" ]]; then
    echo "Uncommitted changes: only the last commit is deployed. Commit first, or Ctrl+C now." >&2
    sleep 5
fi
echo "Deploying $(git log -1 --format='%h %s') to $target ($DEPLOY_HOST:$DEPLOY_APP_DIR)"

npm run build
release="$(mktemp -d)"
trap 'rm -rf "$release"' EXIT
# Code from the last commit, without tests and docs; the built assets come from public/build.
git archive --format=tar HEAD -- . ':!tests' ':!docs' ':!scripts' ':!.github' > "$release/release.tar"
tar -rf "$release/release.tar" public/build
gzip "$release/release.tar"

scp -P "$port" "${ssh_opts[@]}" "$release/release.tar.gz" "$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_APP_DIR/../orlena-release.tgz"
ssh -p "$port" "${ssh_opts[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "bash -s" "$DEPLOY_APP_DIR" <<'REMOTE'
set -euo pipefail
cd "$1"
# Old assets go away only after the new ones are in place.
rm -rf public/build.old
[[ -d public/build ]] && mv public/build public/build.old
tar -xzf ../orlena-release.tgz
rm ../orlena-release.tgz
composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan migrate --force --no-interaction
php artisan optimize:clear > /dev/null
php artisan optimize > /dev/null
rm -rf public/build.old
php artisan schedule:list --no-ansi | grep -c "artisan" | xargs -I{} echo "Scheduled commands: {}"
echo "Deployed."
REMOTE
