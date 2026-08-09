#!/usr/bin/env bash
#
# slot4u — post-deploy smoke test (SLO-152, docs/16).
#
# Runs from the caller's side (GitHub Actions, or a laptop), over the public
# internet, against the real edge. "The deploy script exited 0" only proves the
# commands ran; this proves the site answers, that the code answering is the
# release we meant to ship, and that its migrations are in.
#
# Usage: smoke.sh <base-url> <expected-release> [expected-commit]
#   env: DEPLOY_HEALTH_TOKEN (required)  — shared secret for /_deploy/health
#        SMOKE_RETRIES (default 10), SMOKE_DELAY seconds (default 6)
#
# The commit argument is the one that proves anything. A release name is a label
# that moves: a deploy of `main` that shipped a months-old commit still called
# itself "main" and would have passed a name comparison (SLO-158).
#
set -Eeuo pipefail

BASE_URL="${1:-}"
EXPECTED_RELEASE="${2:-}"
EXPECTED_COMMIT="${3:-}"
TOKEN="${DEPLOY_HEALTH_TOKEN:-}"
RETRIES="${SMOKE_RETRIES:-10}"
DELAY="${SMOKE_DELAY:-6}"

if [[ -z "${BASE_URL}" || -z "${EXPECTED_RELEASE}" ]]; then
    echo "usage: smoke.sh <base-url> <expected-release>" >&2
    exit 64
fi
if [[ -z "${TOKEN}" ]]; then
    echo "DEPLOY_HEALTH_TOKEN is not set — the smoke test cannot verify the release" >&2
    exit 64
fi

BASE_URL="${BASE_URL%/}"
failures=0

pass() { printf '  \033[32mok\033[0m   %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; failures=$((failures + 1)); }

# --- 1. Liveness -----------------------------------------------------------
# Retried: `artisan up` has just run, and the edge may still be serving the 503
# it cached a moment ago.
echo "==> Liveness (${BASE_URL}/up)"
status=""
for attempt in $(seq 1 "${RETRIES}"); do
    # curl prints 000 itself when it never got a response, so no fallback echo.
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${BASE_URL}/up" || true)"
    [[ "${status}" == "200" ]] && break
    echo "  attempt ${attempt}: HTTP ${status:-000}, retrying in ${DELAY}s"
    sleep "${DELAY}"
done
[[ "${status}" == "200" ]] && pass "health endpoint returned 200" || fail "health endpoint returned ${status}"

# --- 2. The running code is the release we shipped -------------------------
echo "==> Release (${BASE_URL}/_deploy/health)"
health_body="$(mktemp)"
health_status="$(curl -sS --max-time 20 -o "${health_body}" -w '%{http_code}' \
    -H "X-Deploy-Token: ${TOKEN}" "${BASE_URL}/_deploy/health" || true)"
health="$(cat "${health_body}")"
rm -f "${health_body}"

if [[ "${health_status}" == "404" ]]; then
    # The endpoint answers 404 both when the route is missing and when the token
    # is wrong — hiding its existence is deliberate. Naming only one of the two
    # causes sent a real investigation down the wrong path once, so say both.
    fail "HTTP 404 from /_deploy/health. Either the deployed code predates the route
       (the deploy shipped an older commit than you think — check deploy-target-sha
       in the deploy log), or DEPLOY_HEALTH_TOKEN differs from the server's .env."
elif [[ "${health_status}" != "200" ]]; then
    fail "HTTP ${health_status:-000} from /_deploy/health"
fi

json_field() {
    # No jq on a bare runner image is a possibility; this needs no dependency.
    printf '%s' "${health}" | sed -n "s/.*\"$1\":[[:space:]]*\"\{0,1\}\([^,\"}]*\)\"\{0,1\}.*/\1/p" | head -n 1
}

release="$(json_field release)"
commit="$(json_field commit)"
environment="$(json_field environment)"
config_cached="$(json_field config_cached)"
pending="$(json_field pending_migrations)"

if [[ -z "${release}" ]]; then
    fail "no release in the /_deploy/health answer: ${health:0:200}"
else
    [[ "${release}" == "${EXPECTED_RELEASE}" ]] \
        && pass "serving ${release}" \
        || fail "serving ${release}, expected ${EXPECTED_RELEASE}"

    # The check that cannot be satisfied by a stale deploy wearing the right name.
    if [[ -n "${EXPECTED_COMMIT}" ]]; then
        [[ "${commit}" == "${EXPECTED_COMMIT}" ]] \
            && pass "serving commit ${commit:0:7}" \
            || fail "serving commit ${commit:-unknown}, expected ${EXPECTED_COMMIT} — the server deployed a different commit under the same name"
    fi

    [[ "${environment}" == "production" ]] \
        && pass "environment is production" \
        || fail "environment is ${environment}, expected production"

    [[ "${config_cached}" == "true" ]] \
        && pass "config is cached" \
        || fail "config is NOT cached — the deploy did not finish its cache step"

    [[ "${pending}" == "0" ]] \
        && pass "no pending migrations" \
        || fail "pending migrations: ${pending:-unknown} (empty/unknown means the database could not be read)"
fi

# --- 3. The public page renders, and not a debug page ----------------------
echo "==> Public page (${BASE_URL}/)"
headers="$(mktemp)"
body="$(mktemp)"
page="$(curl -sS --max-time 30 -D "${headers}" -o "${body}" -w '%{http_code}' "${BASE_URL}/" || true)"
[[ "${page}" == "200" ]] && pass "landing page returned 200" || fail "landing page returned ${page:-000}"

if [[ ! -s "${body}" ]]; then
    fail "the landing page returned no body — skipping the content checks"
else
    # A stack trace on the public page is a deploy failure even when the status
    # is 200 — APP_DEBUG left on renders one for any downstream error.
    if grep -qiE 'whoops|stack trace|Illuminate\\Foundation' "${body}"; then
        fail "the landing page looks like a debug/error page"
    else
        pass "no debug output on the landing page"
    fi

    # The security headers ride on every response (SLO-145). Their absence means
    # the global middleware never ran — i.e. this is not our app answering.
    if grep -qi '^content-security-policy:' "${headers}"; then
        pass "security headers present"
    else
        fail "no Content-Security-Policy header — is this really the slot4u app?"
    fi
fi

rm -f "${headers}" "${body}"

echo
if [[ "${failures}" -gt 0 ]]; then
    echo "SMOKE TEST FAILED (${failures} check(s))" >&2
    exit 1
fi
echo "Smoke test passed — ${EXPECTED_RELEASE} is live on ${BASE_URL}"
