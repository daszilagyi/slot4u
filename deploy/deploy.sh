#!/usr/bin/env bash
#
# slot4u — production deploy (SLO-152, docs/16-deploy-pipeline.md)
#
# Runs ON the target server. GitHub Actions pipes it in over ssh
# (`ssh host bash -s -- <ref> < deploy/deploy.sh`), so the script that runs is
# always the one committed at the ref being deployed; it can equally be run by
# hand on the server (`bash deploy/deploy.sh v0.8.0-M8`) when the pipeline is
# unavailable.
#
# It assumes the built frontend assets are ALREADY in public/build — the server
# has no Node (docs/13). Uploading them is the caller's job, on purpose: it
# happens before the maintenance window opens.
#
# Usage: deploy.sh <git-ref>
#
set -Eeuo pipefail

# Bash reads a script incrementally, and this deploy checks out another ref over
# the working tree — including over this very file. Run from the repo, the
# script therefore steps outside it first. (Piped in over ssh there is no file
# to rewrite: BASH_SOURCE is unset and this is a no-op.)
if [[ "${DEPLOY_DETACHED_SELF:-0}" != "1" && -f "${BASH_SOURCE[0]:-}" ]]; then
    self="$(mktemp "${TMPDIR:-/tmp}/slot4u-deploy.XXXXXX")"
    cp "${BASH_SOURCE[0]}" "${self}"
    export DEPLOY_DETACHED_SELF=1
    set +e
    bash "${self}" "$@"
    code=$?
    set -e
    rm -f "${self}"
    exit "${code}"
fi

REF="${1:-}"
if [[ -z "${REF}" ]]; then
    echo "usage: deploy.sh <git-ref>" >&2
    exit 64
fi

APP_DIR="${DEPLOY_PATH:-${HOME}/slot4u}"
# A caller passing DEPLOY_PATH='~/slot4u' hands us a literal tilde: the quotes
# that keep the value intact through `ssh` also stop the shell expanding it, and
# `cd '~/slot4u'` fails. rsync's remote path does get expanded (the remote shell
# sees it unquoted), so without this the upload would succeed and the deploy
# would not — the most confusing failure of the pair.
APP_DIR="${APP_DIR/#\~/${HOME}}"
PHP="${DEPLOY_PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}"
COMPOSER="${DEPLOY_COMPOSER:-}"
SKIP_MIGRATIONS="${DEPLOY_SKIP_MIGRATIONS:-0}"
ASSET_RETENTION_DAYS="${DEPLOY_ASSET_RETENTION_DAYS:-30}"

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

cd "${APP_DIR}"

# ---------------------------------------------------------------------------
# Preflight — everything that can refuse the deploy happens while the site is
# still up. Nothing below this block may fail for a reason we could have seen.
# ---------------------------------------------------------------------------
log "Preflight (${APP_DIR})"

[[ -x "${PHP}" ]] || { echo "PHP CLI not executable: ${PHP}" >&2; exit 1; }

if [[ -z "${COMPOSER}" ]]; then
    COMPOSER="$(command -v composer || true)"
fi
[[ -n "${COMPOSER}" ]] || { echo "composer not found; set DEPLOY_COMPOSER" >&2; exit 1; }

[[ -f .env ]] || { echo ".env missing in ${APP_DIR}" >&2; exit 1; }

# Host-owned Apache directives. cPanel's MultiPHP writes the PHP handler into
# the docroot's .htaccess — a tracked file — so the forced checkout below would
# wipe it and drop the site to the host's default PHP, on which this app's
# dependencies do not run. They are not committed because they name a hosting
# account and this repository is public. Kept here, re-applied after checkout.
HOST_HTACCESS="${APP_DIR}/.htaccess.host"

# A modified tracked file means someone edited the server by hand; the forced
# checkout below would silently throw that work away, so stop and let a human
# look. Untracked files are explicitly not a reason to refuse: the server always
# has some (.env, .release, public/build, logs), and a checkout does not eat
# them — an untracked-file check would simply make every deploy fail.
DIRTY="$(git status --porcelain --untracked-files=no)"

if [[ -f "${HOST_HTACCESS}" ]]; then
    # The one expected difference: this script itself put it there last time.
    DIRTY="$(printf '%s\n' "${DIRTY}" | grep -v ' public/\.htaccess$' || true)"
fi

if [[ -n "${DIRTY//[[:space:]]/}" ]]; then
    echo "Tracked files are modified on the server — refusing to deploy:" >&2
    printf '%s\n' "${DIRTY}" >&2
    exit 1
fi

git fetch --tags --prune --force origin

TARGET="$(git rev-parse --verify "${REF}^{commit}")"
PREVIOUS_SHA="$(git rev-parse --verify HEAD)"
PREVIOUS_REF="$(git describe --tags --exact-match HEAD 2>/dev/null || echo "${PREVIOUS_SHA}")"

# The one line the workflow (and a human) needs in order to roll back.
echo "deploy-previous-ref=${PREVIOUS_REF}"
echo "deploy-previous-sha=${PREVIOUS_SHA}"
echo "deploy-target-ref=${REF}"
echo "deploy-target-sha=${TARGET}"

if [[ "${TARGET}" == "${PREVIOUS_SHA}" ]]; then
    log "Already at ${REF} (${TARGET:0:7}) — re-running the release steps"
fi

# Composer is the slowest step by far. Running it only when the lockfile
# actually moved keeps the routine deploy's maintenance window at a few seconds.
NEEDS_COMPOSER=1
if [[ -d vendor ]] && git diff --quiet "${PREVIOUS_SHA}" "${TARGET}" -- composer.lock; then
    NEEDS_COMPOSER=0
fi

# ---------------------------------------------------------------------------
# Maintenance window opens here.
# ---------------------------------------------------------------------------
# On failure the site deliberately stays down: a half-migrated app answering
# requests is worse than a 503, and the operator gets the exact way back.
on_error() {
    local code=$?
    cat >&2 <<EOF

!! DEPLOY FAILED (exit ${code}). The site is still in maintenance mode.
!! Roll back with:
!!     cd ${APP_DIR} && bash deploy/rollback.sh ${PREVIOUS_REF}
!! Or, once the cause is fixed, re-run:
!!     cd ${APP_DIR} && bash deploy/deploy.sh ${REF}
EOF
    exit "${code}"
}
trap on_error ERR

log "Maintenance mode on"
# `down` also parks the cron-driven queue worker and the scheduler: both check
# maintenance mode before doing work, so no job runs against half-updated code.
"${PHP}" artisan down --retry=15

log "Checking out ${REF} (${TARGET:0:7})"
# Detached: a tag is not a branch, and the server tracks releases, not a branch.
git checkout --detach --force "${TARGET}"

if [[ "${NEEDS_COMPOSER}" == "1" ]]; then
    log "Installing PHP dependencies (composer.lock changed)"
    "${PHP}" "${COMPOSER}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
else
    log "Skipping composer install (composer.lock unchanged)"
fi

# Put the host's Apache directives back. The checkout just reverted .htaccess to
# what the repository says, which on this host means "serve PHP with whatever
# version cPanel defaults to" — a working deploy followed by an immediate
# outage. Idempotent: skipped when the block is already present, so a cPanel that
# re-adds it on its own cannot produce a duplicate.
if [[ -f "${HOST_HTACCESS}" ]]; then
    marker="$(head -n 1 "${HOST_HTACCESS}")"

    if [[ -n "${marker}" ]] && ! grep -qxF "${marker}" public/.htaccess 2>/dev/null; then
        log "Re-applying the host's Apache directives"
        printf '\n' >> public/.htaccess
        cat "${HOST_HTACCESS}" >> public/.htaccess
    fi
fi

# The asset manifest belonging to THIS release. Uploads never delete, so the
# hashed files of several releases coexist happily — but manifest.json is a
# single file that the newest upload overwrites. Without this restore a rollback
# would run old PHP against the new bundle, which is exactly the combination the
# rollback exists to escape.
MANIFEST_SNAPSHOT="public/build/manifests/${REF//\//-}.json"
if [[ -f "${MANIFEST_SNAPSHOT}" ]]; then
    log "Restoring the asset manifest of ${REF}"
    cp "${MANIFEST_SNAPSHOT}" public/build/manifest.json
fi

# What the app reports at /_deploy/health. Written before config:cache so the
# value is baked into the cached config.
printf '%s\n' "${REF}" > .release

if [[ "${SKIP_MIGRATIONS}" == "1" ]]; then
    # Rollback path. Migrations are forward-only by project rule, so going back
    # to older code against the newer schema is the intended direction.
    log "Skipping migrations (DEPLOY_SKIP_MIGRATIONS=1)"
else
    log "Running migrations"
    "${PHP}" artisan migrate --force
fi

# `artisan storage:link` cannot work here — symlink() is disabled on this shared
# host (docs/13) — but the shell's ln can. Idempotent.
ln -sfn "${APP_DIR}/storage/app/public" "${APP_DIR}/public/storage"

log "Rebuilding caches"
"${PHP}" artisan config:cache
"${PHP}" artisan route:cache
"${PHP}" artisan view:clear
"${PHP}" artisan view:cache
"${PHP}" artisan event:cache

# Tells any worker started before this deploy to exit after its current job
# rather than keep running the old code out of memory.
"${PHP}" artisan queue:restart

log "Maintenance mode off"
"${PHP}" artisan up
trap - ERR

# ---------------------------------------------------------------------------
# Housekeeping — after the site is back, so it can never extend the window.
# ---------------------------------------------------------------------------
# Asset uploads never delete: the page a visitor loaded a second ago still
# references the previous build's hashed files, and a rollback needs them.
# Files the current build no longer produces keep their old mtime, so pruning by
# age removes exactly the ones no release has referenced for a month.
if [[ "${ASSET_RETENTION_DAYS}" != "0" && -d public/build ]]; then
    log "Pruning build assets older than ${ASSET_RETENTION_DAYS} days"
    find public/build -type f -mtime "+${ASSET_RETENTION_DAYS}" -delete || true
    find public/build -type d -empty -delete || true
fi

log "Deployed ${REF} (${TARGET:0:7}), previous was ${PREVIOUS_REF}"
