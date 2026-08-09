#!/usr/bin/env bash
#
# slot4u — roll production back to an earlier release (SLO-152, docs/16).
#
# Deliberately a thin wrapper around deploy.sh rather than its own copy of the
# steps: a rollback path that drifts from the deploy path is a rollback that has
# never really been tested. The only differences are the two below.
#
#   1. Migrations are NOT run. They are forward-only by project rule (a released
#      migration is never modified or reversed), so the older code is expected to
#      meet the newer schema. If a release genuinely cannot run against the new
#      schema, the way out is a fix-forward migration, not `migrate:rollback`.
#   2. Assets: deploy.sh restores the manifest snapshot uploaded with that
#      release, so the old code gets the bundle it was built against. This is
#      why uploads never delete and old builds are kept for a month.
#
# Usage: rollback.sh <previous-git-ref>
#        (the ref is printed as `deploy-previous-ref=…` by every deploy)
#
set -Eeuo pipefail

REF="${1:-}"
if [[ -z "${REF}" ]]; then
    echo "usage: rollback.sh <previous-git-ref>" >&2
    exit 64
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Rolling back to ${REF} (migrations are left alone)"

DEPLOY_SKIP_MIGRATIONS=1 exec bash "${HERE}/deploy.sh" "${REF}"
