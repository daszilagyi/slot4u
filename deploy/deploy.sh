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

# The SSR renderer's directory and the docroot the web server actually serves
# (SLO-212). Both are OUTSIDE the application directory, and the second one is
# the surprise: on this host the docroot is `~/public_html` with a bridge
# index.php booting the app from `~/slot4u`, NOT `~/slot4u/public`. The same
# tilde expansion as APP_DIR, for the same reason.
SSR_DIR="${DEPLOY_SSR_PATH:-${HOME}/ssr}"
SSR_DIR="${SSR_DIR/#\~/${HOME}}"
DOCROOT="${DEPLOY_DOCROOT:-${HOME}/public_html}"
DOCROOT="${DOCROOT/#\~/${HOME}}"

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

# --- The SSR renderer's preconditions (SLO-212) ----------------------------
#
# ⚠️ Shipping the bundle is what TURNS SSR ON. `INERTIA_SSR_ENABLED` defaults to
# true (config/inertia.php); the only thing stopping server rendering on this
# host was that `bootstrap/ssr` never reached it, and Inertia falls back to the
# client without a word when the bundle is missing. So from the first deploy
# that carries it, the four conditions below stop being optional — and each of
# them fails the same way when unmet: not an error, just a page with no markup
# in it.
#
# Checked HERE, in preflight, rather than left to the smoke test: this refuses
# while the site is still up and untouched, and says exactly what to add. A
# smoke failure would say the same thing after the deploy had already happened.
ssr_env() { sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" .env | tail -n 1 | tr -d '"'"'"'\r'; }

SSR_ENABLED_ENV="$(ssr_env INERTIA_SSR_ENABLED)"

if [[ "${SSR_ENABLED_ENV,,}" != "false" && "${SSR_ENABLED_ENV,,}" != "0" ]]; then
    if [[ -z "$(ssr_env INERTIA_SSR_URL)" ]]; then
        echo "INERTIA_SSR_URL is not set in ${APP_DIR}/.env, and server rendering is on." >&2
        echo "Its default (http://127.0.0.1:13714) is wrong on this host: the renderer runs" >&2
        echo "under Passenger, which owns the socket and listens on no port of its own." >&2
        echo "Set it to the mount (e.g. https://slot4u.hu/_ssr), or set" >&2
        echo "INERTIA_SSR_ENABLED=false to ship without server rendering." >&2
        exit 1
    fi

    # ⚠️ The check Inertia makes BEFORE it ever calls the renderer:
    #
    #     if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists())
    #         return null;
    #
    # and `bundleExists()` looks for `base_path('bootstrap/ssr/ssr.js')` — inside
    # the APPLICATION. On this host the bundle is not there and cannot be: it
    # lives in the renderer's own directory, which Passenger owns, and
    # `/bootstrap/ssr` is gitignored so the checkout never brings one either.
    #
    # So with the default left alone, every other piece of this could be
    # correct — bundle shipped, renderer answering, secret matching — and the
    # app would still return null and ship an empty shell, without a word. The
    # check means something where the bundle and the app share a directory
    # (docker, CI); here it is a switch that turns SSR off silently.
    SSR_ENSURE_ENV="$(ssr_env INERTIA_SSR_ENSURE_BUNDLE_EXISTS)"

    if [[ "${SSR_ENSURE_ENV,,}" != "false" && "${SSR_ENSURE_ENV,,}" != "0" \
        && ! -f bootstrap/ssr/ssr.js ]]; then
        echo "There is no bootstrap/ssr/ssr.js in ${APP_DIR}, and Inertia is set to require one." >&2
        echo "It checks for that file before it calls the renderer at all, so server rendering" >&2
        echo "would stay off no matter how well the renderer itself works — silently, as a" >&2
        echo "client-side fallback." >&2
        echo "The bundle lives in the renderer's own directory on this host. Add to .env:" >&2
        echo '    INERTIA_SSR_ENSURE_BUNDLE_EXISTS=false' >&2
        exit 1
    fi

    # The mount is inside the public site, so `/render` is reachable from the
    # internet, and an unauthenticated one will render whatever props anyone
    # posts to it — arbitrary HTML out of our own domain, and a CPU to burn.
    #
    # ⚠️ Loopback is the exemption, not a whitelist of trusted hosts: a renderer
    # on 127.0.0.1 can only be called by this machine. Anything else — this
    # host's `/_ssr` included — has an audience, and the secret is what turns it
    # away. Both `config/inertia.php` and `.env.example` already say the secret
    # is not optional in production; without this they only say it.
    SSR_HOST_ENV="$(ssr_env INERTIA_SSR_URL)"
    SSR_HOST_ENV="${SSR_HOST_ENV#*://}"
    SSR_HOST_ENV="${SSR_HOST_ENV%%[:/]*}"

    if [[ "${SSR_HOST_ENV}" != "127.0.0.1" && "${SSR_HOST_ENV}" != "localhost" \
        && "${SSR_HOST_ENV}" != "::1" && -z "$(ssr_env SSR_SHARED_SECRET)" ]]; then
        echo "SSR_SHARED_SECRET is empty in ${APP_DIR}/.env, and the renderer at" >&2
        echo "${SSR_HOST_ENV} is not on loopback — anyone who finds the mount can post a" >&2
        echo "page object and have this server render it into HTML." >&2
        echo "Set the SAME value in two places: ${APP_DIR}/.env, and the Node application's" >&2
        echo "own environment (cPanel -> the app -> Environment variables). See docs/16." >&2
        exit 1
    fi

    # The renderer is mounted inside the public site, and the docroot's rewrite
    # sends every unmatched path to the front controller. Without an exception
    # the mount's sub-paths — /render among them — reach Laravel and 404, so SSR
    # silently degrades to client rendering.
    #
    # ⚠️ This file is NOT the one the deploy manages. `.htaccess.host` protects
    # ${APP_DIR}/public/.htaccess, which on this host the web server never reads:
    # the docroot is ${DOCROOT}, with a bridge index.php booting the app. That
    # mismatch is why this is a check and not a rewrite — the file belongs to the
    # hosting setup, and a deploy that edited it would be claiming ownership it
    # does not have.
    if [[ -f "${DOCROOT}/.htaccess" ]] && ! grep -q '_ssr' "${DOCROOT}/.htaccess"; then
        echo "${DOCROOT}/.htaccess has no /_ssr exception, and server rendering is on." >&2
        echo "The front-controller rewrite will swallow the renderer's sub-paths and Laravel" >&2
        echo "will 404 them, which Inertia turns into a silent client-side fallback." >&2
        echo "Add this ABOVE the front-controller rule (docs/16):" >&2
        echo '    RewriteCond %{REQUEST_URI} ^/_ssr(/|$)' >&2
        echo '    RewriteRule ^ - [L]' >&2
        exit 1
    fi
fi

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

# Resolving the ref is where a deploy can go quietly wrong, so the order is
# explicit (SLO-158). The server's LOCAL branches are never checked out and
# never updated — `git fetch` moves `origin/main`, not `main` — so a bare
# `git rev-parse main` here means "main as it stood when this server was
# cloned". That is how a deploy of `main` shipped a months-old commit and
# reported success.
resolve_ref() {
    local ref="$1"

    # A tag: the normal case, and unambiguous after the fetch above.
    git rev-parse --verify --quiet "refs/tags/${ref}^{commit}" && return 0

    # A branch name can only sensibly mean the remote branch.
    git rev-parse --verify --quiet "refs/remotes/origin/${ref}^{commit}" && return 0

    # A raw sha, or anything else git understands.
    git rev-parse --verify --quiet "${ref}^{commit}"
}

if ! TARGET="$(resolve_ref "${REF}")"; then
    echo "Cannot resolve '${REF}' to a commit — not a tag, not a branch on origin, not a sha." >&2
    exit 1
fi
PREVIOUS_SHA="$(git rev-parse --verify HEAD)"
PREVIOUS_REF="$(git describe --tags --exact-match HEAD 2>/dev/null || echo "${PREVIOUS_SHA}")"

# The one line the workflow (and a human) needs in order to roll back.
echo "deploy-previous-ref=${PREVIOUS_REF}"
echo "deploy-previous-sha=${PREVIOUS_SHA}"
echo "deploy-target-ref=${REF}"
echo "deploy-target-sha=${TARGET}"

# The pipeline built the assets from a specific commit and tells us which. If
# this server resolves the same ref to something else, the two halves of the
# deploy disagree — new assets over old code — and the honest move is to stop.
# Without this the mismatch is invisible: every step "succeeds".
if [[ -n "${DEPLOY_EXPECT_SHA:-}" && "${TARGET}" != "${DEPLOY_EXPECT_SHA}" ]]; then
    cat >&2 <<EOF
Refusing to deploy: '${REF}' resolves to ${TARGET} on this server,
but the pipeline built ${DEPLOY_EXPECT_SHA}.
The fetch did not bring the ref up to date, or the ref moved mid-run.
EOF
    exit 1
fi

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

# What the app reports at /_deploy/health, written before config:cache so the
# value is baked into the cached config.
#
# Two lines, and the second one is the point: a ref name is not evidence. `main`
# means whatever main meant at some moment, so a smoke test comparing ref names
# would happily pass against code from last month (SLO-158). The commit is what
# the deploy can actually be held to.
printf '%s\n%s\n' "${REF}" "${TARGET}" > .release

if [[ "${SKIP_MIGRATIONS}" == "1" ]]; then
    # Rollback path. Migrations are forward-only by project rule, so going back
    # to older code against the newer schema is the intended direction.
    log "Skipping migrations (DEPLOY_SKIP_MIGRATIONS=1)"
else
    log "Running migrations"
    "${PHP}" artisan migrate --force
fi

# Required platform data (SLO-166). Idempotent by construction — every seeder it
# calls returns early when its rows exist — so it runs on every deploy rather
# than being a step somebody has to remember for the release that needs it.
#
# It ran nowhere before this, and the gap surfaced the way it always does: the
# consent machinery shipped to production and sat inert because no platform
# document existed. Everything was green and the feature quietly did nothing.
#
# Skipped on a rollback for the same reason migrations are: going backwards
# should change as little as possible.
if [[ "${SKIP_MIGRATIONS}" == "1" ]]; then
    log "Skipping the required-data seed (rollback)"
else
    log "Seeding required platform data"
    "${PHP}" artisan db:seed --class=ProductionSeeder --force
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

# ---------------------------------------------------------------------------
# The SSR renderer (SLO-212)
# ---------------------------------------------------------------------------
# The bundle was uploaded before this script ran. Passenger keeps serving the
# OLD one until it is told otherwise, and the symptom of forgetting is not an
# error — it is quietly stale HTML, which is the same shape of silence that let
# the missing renderer live for months.
if [[ -d "${SSR_DIR}" ]]; then
    # What tells Node the bundle is an ES module. Written only when missing:
    # it belongs to the renderer's directory rather than to a release, and
    # overwriting it every deploy would be this script claiming ownership of
    # something Passenger's own tooling also writes into.
    if [[ ! -f "${SSR_DIR}/package.json" ]]; then
        log "Declaring the SSR bundle an ES module"
        printf '{\n  "name": "slot4u-ssr",\n  "private": true,\n  "type": "module"\n}\n' \
            > "${SSR_DIR}/package.json"
    fi

    log "Restarting the SSR renderer"
    mkdir -p "${SSR_DIR}/tmp"
    touch "${SSR_DIR}/tmp/restart.txt"
else
    echo "No SSR directory at ${SSR_DIR} — skipping the renderer restart." >&2
fi

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

# The renderer's own chunks, on the same terms and for the same reason: the
# upload does not delete, and the SSR build is code-split, so every release
# leaves ~5 MB of hashed files behind. On a shared host that is a quota problem
# a hundred deploys from now.
#
# ⚠️ `assets/` ONLY. `package.json` is written once and never again, so an
# age-based sweep of the whole directory would eventually delete the one file
# that tells Node the bundle is an ES module — and the renderer would stop
# booting a month after the last change to it, for no visible reason.
if [[ "${ASSET_RETENTION_DAYS}" != "0" && -d "${SSR_DIR}/assets" ]]; then
    log "Pruning SSR chunks older than ${ASSET_RETENTION_DAYS} days"
    find "${SSR_DIR}/assets" -type f -mtime "+${ASSET_RETENTION_DAYS}" -delete || true
fi

log "Deployed ${REF} (${TARGET:0:7}), previous was ${PREVIOUS_REF}"
