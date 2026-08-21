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
# Everything here goes through Cloudflare, which means every answer has two
# possible authors: the application, or the edge in front of it. Telling them
# apart is not a detail — a bot-protection challenge is a 200 with an HTML body,
# so a check that only reads the status code passes while the app is down
# (SLO-162). The rule below is therefore: a check is green only when the answer
# carries proof it came from our middleware.
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

# --- Probing ---------------------------------------------------------------
# One request, three globals. The headers matter as much as the body: the proof
# that the application answered is a response header, not page content.
HEADERS="$(mktemp)"
BODY="$(mktemp)"
STATUS=""
trap 'rm -f "${HEADERS}" "${BODY}"' EXIT

# Every request identifies itself — including the public-page ones, which used
# to go out as a bare curl. A datacenter IP running curl with no user agent is
# exactly what a bot-protection rule exists to stop, and the smoke test has no
# business looking like an unknown crawler to our own edge. The token header is
# what a Cloudflare WAF "skip" rule matches on, so the test keeps measuring
# THROUGH the edge rather than around it (docs/16 §6.1). Matching on the user
# agent instead would be a bypass anyone could type.
USER_AGENT='slot4u-smoke/1 (+https://slot4u.hu)'

probe() {
    STATUS="$(curl -sS --max-time 30 \
        -A "${USER_AGENT}" \
        -H "X-Deploy-Token: ${TOKEN}" \
        -D "${HEADERS}" -o "${BODY}" -w '%{http_code}' "$1" || true)"
}

# The application's signature on a response: SecurityHeaders is prepended to the
# global middleware stack (SLO-145), so every response the app produces carries a
# CSP — and nothing in front of it does.
answered_by_app() {
    grep -qi '^content-security-policy:' "${HEADERS}"
}

# Cloudflare's bot protection, in its several disguises: the header it sets on a
# mitigated request, the script the interstitial loads, and the titles of the
# static block pages. Detected separately from "not our app" only so the failure
# can say what actually happened — a challenge is an edge decision about the
# caller, not evidence about the deploy.
challenged() {
    grep -qiE '^cf-mitigated:[[:space:]]*challenge' "${HEADERS}" && return 0
    grep -qE '/cdn-cgi/challenge-platform|__cf_chl|cf_chl_opt|cf-browser-verification' "${BODY}" && return 0
    grep -qiE '<title>[^<]*(just a moment|attention required|access denied|checking your browser)' "${BODY}" && return 0
    return 1
}

probe_reason() {
    if challenged; then
        printf 'HTTP %s, Cloudflare challenge' "${STATUS:-000}"
    elif [[ "${STATUS}" == "200" ]] && ! answered_by_app; then
        printf 'HTTP 200 but not from the application'
    else
        printf 'HTTP %s' "${STATUS:-000}"
    fi
}

# Probe until `ready` (the name of a predicate function) is satisfied, or the
# retries run out. Retrying is not only for a slow boot: a challenge is often
# transient, decided per IP and per minute, so one more attempt is worth more
# than a paragraph of guesswork in the deploy log.
probe_until() {
    local url="$1" ready="$2" attempt
    for attempt in $(seq 1 "${RETRIES}"); do
        probe "${url}"
        if "${ready}"; then
            return 0
        fi
        if [[ "${attempt}" -lt "${RETRIES}" ]]; then
            echo "  attempt ${attempt}: $(probe_reason), retrying in ${DELAY}s"
            sleep "${DELAY}"
        fi
    done
    return 1
}

not_challenged() { ! challenged; }
live() { [[ "${STATUS}" == "200" ]] && answered_by_app; }

# Said once, in full, wherever the edge got in the way: the deploy is not the
# suspect here, and the fix is a configuration change rather than a code one.
# Naming the wrong cause is how SLO-158 lost a round.
fail_challenge() {
    fail "$1: the Cloudflare edge answered with a bot-protection challenge, not the app.
       This says nothing about the deploy — verify it from another network before touching
       anything (docs/16 §6.1). To let this caller through, add a Cloudflare WAF *skip*
       rule for the smoke test's own header:
         (http.request.headers[\"x-deploy-token\"][0] eq \"<DEPLOY_HEALTH_TOKEN>\")"
}

# --- 1. Liveness -----------------------------------------------------------
# Retried: `artisan up` has just run, and the edge may still be serving the 503
# it cached a moment ago.
#
# The status code alone is not liveness. /up answering 200 from Cloudflare's
# challenge page looks identical to /up answering 200 from Laravel, and the
# version of this check that only read the status reported "ok" through an
# outage it was written to catch (SLO-162).
echo "==> Liveness (${BASE_URL}/up)"
if probe_until "${BASE_URL}/up" live; then
    pass "health endpoint returned 200 from the application"
elif challenged; then
    fail_challenge "the health endpoint is unreachable behind the edge"
elif [[ "${STATUS}" == "200" ]]; then
    fail "the health endpoint returned 200 without the app's security headers — something
       in front of the application answered (parked page, edge error page), not the app"
else
    fail "health endpoint returned ${STATUS:-000}"
fi

# --- 2. The running code is the release we shipped -------------------------
echo "==> Release (${BASE_URL}/_deploy/health)"
health_ok=1
if ! probe_until "${BASE_URL}/_deploy/health" not_challenged; then
    fail_challenge "cannot read the deployed release"
    health_ok=0
fi

health="$(cat "${BODY}")"
health_status="${STATUS}"

if [[ "${health_ok}" == "1" ]]; then
    if [[ "${health_status}" == "404" ]]; then
        # The endpoint answers 404 both when the route is missing and when the token
        # is wrong — hiding its existence is deliberate. Naming only one of the two
        # causes sent a real investigation down the wrong path once, so say both.
        fail "HTTP 404 from /_deploy/health. Either the deployed code predates the route
       (the deploy shipped an older commit than you think — check deploy-target-sha
       in the deploy log), or DEPLOY_HEALTH_TOKEN differs from the server's .env."
        health_ok=0
    elif [[ "${health_status}" != "200" ]]; then
        fail "HTTP ${health_status:-000} from /_deploy/health"
        health_ok=0
    fi
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

if [[ "${health_ok}" == "1" && -z "${release}" ]]; then
    fail "no release in the /_deploy/health answer: ${health:0:200}"
elif [[ "${health_ok}" == "1" ]]; then
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
if ! probe_until "${BASE_URL}/" not_challenged; then
    fail_challenge "cannot read the landing page"
else
    [[ "${STATUS}" == "200" ]] && pass "landing page returned 200" || fail "landing page returned ${STATUS:-000}"

    if [[ ! -s "${BODY}" ]]; then
        fail "the landing page returned no body — skipping the content checks"
    else
        # A stack trace on the public page is a deploy failure even when the status
        # is 200 — APP_DEBUG left on renders one for any downstream error.
        if grep -qiE 'whoops|stack trace|Illuminate\\Foundation' "${BODY}"; then
            fail "the landing page looks like a debug/error page"
        else
            pass "no debug output on the landing page"
        fi

        # The security headers ride on every response (SLO-145). Their absence means
        # the global middleware never ran — i.e. this is not our app answering.
        if answered_by_app; then
            pass "security headers present"
        else
            fail "no Content-Security-Policy header — this is not the slot4u app answering
       (a parked page or an edge error page would look exactly like this)"
        fi
    fi
fi

echo
if [[ "${failures}" -gt 0 ]]; then
    echo "SMOKE TEST FAILED (${failures} check(s))" >&2
    exit 1
fi
echo "Smoke test passed — ${EXPECTED_RELEASE} is live on ${BASE_URL}"
