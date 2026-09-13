#!/usr/bin/env bash
#
# slot4u — expose the app's static files through the bridge docroot (SLO-233).
#
# On this host the apex domain is served from `~/public_html`, a bridge holding
# its own index.php (which boots the app from `~/slot4u`) and .htaccess. The
# app's `public/` directory is NOT the docroot — so a file that lives there and
# is not a PHP route is simply not on the web. Measured on 2026-09-13:
# `https://slot4u.hu/img/og-image.png` answered 404, the favicon and the
# apple-touch-icon too, while the same paths on a tenant subdomain (whose
# docroot IS `~/slot4u/public`) answered 200. Every link preview the platform
# had ever produced went out without its image.
#
# `build/` and `storage/` worked only because somebody linked them by hand once.
# This script makes that the deploy's job, for every top-level entry of
# `public/`, so a new directory there reaches the web the day it is committed:
#
#   - missing in the docroot         → symlinked
#   - already our symlink            → refreshed (idempotent)
#   - a real file or directory there → LEFT ALONE, with a warning. It belongs to
#     the hosting account (the bridge's index.php and .htaccess are skipped by
#     name; anything else found there was put there by a person, on purpose)
#   - a symlink into public/ whose target is gone → removed, so a deleted
#     directory does not linger as a dead link
#   - a symlink pointing anywhere else → left alone
#
# Usage: link-docroot.sh <app-public-dir> <docroot>
# Exits 0 unless it cannot do its job at all; a skipped entry is a warning.
#
set -Eeuo pipefail

PUBLIC_DIR="${1:-}"
DOCROOT="${2:-}"

if [[ -z "${PUBLIC_DIR}" || -z "${DOCROOT}" ]]; then
    echo "usage: link-docroot.sh <app-public-dir> <docroot>" >&2
    exit 64
fi

if [[ ! -d "${PUBLIC_DIR}" ]]; then
    echo "link-docroot: ${PUBLIC_DIR} is not a directory" >&2
    exit 1
fi

if [[ ! -d "${DOCROOT}" ]]; then
    echo "link-docroot: no docroot at ${DOCROOT} — nothing to link"
    exit 0
fi

PUBLIC_DIR="$(cd "${PUBLIC_DIR}" && pwd -P)"
DOCROOT_REAL="$(cd "${DOCROOT}" && pwd -P)"

# A host where the docroot IS the app's public directory needs none of this.
if [[ "${PUBLIC_DIR}" == "${DOCROOT_REAL}" ]]; then
    echo "link-docroot: the docroot is the app's public directory — nothing to link"
    exit 0
fi

# The bridge's own files, and files that only mean something in development.
is_skipped() {
    case "$1" in
        index.php | .htaccess | .htaccess.host | hot | .gitignore) return 0 ;;
        *) return 1 ;;
    esac
}

linked=0
kept=0

shopt -s dotglob nullglob

for source in "${PUBLIC_DIR}"/*; do
    name="$(basename "${source}")"
    target="${DOCROOT}/${name}"

    is_skipped "${name}" && continue

    if [[ -L "${target}" ]]; then
        # Ours when it already resolves to this entry (the hand-made `build`
        # and `storage` links do). A link somewhere else was set up for a
        # reason this script cannot know.
        if [[ "$(readlink -f "${target}" || true)" == "$(readlink -f "${source}")" ]]; then
            ln -sfn "${source}" "${target}"
            linked=$((linked + 1))
        else
            echo "link-docroot: WARNING ${target} links somewhere else ($(readlink "${target}")) — left alone"
            kept=$((kept + 1))
        fi
    elif [[ -e "${target}" ]]; then
        echo "link-docroot: WARNING ${target} is a real file or directory, not ours — left alone"
        kept=$((kept + 1))
    else
        ln -s "${source}" "${target}"
        echo "link-docroot: linked ${name}"
        linked=$((linked + 1))
    fi
done

# Dead links into public/: an entry that was deleted from the repository.
for target in "${DOCROOT}"/*; do
    [[ -L "${target}" && ! -e "${target}" ]] || continue

    pointee="$(readlink "${target}")"
    if [[ "${pointee}" == "${PUBLIC_DIR}/"* ]]; then
        rm -f "${target}"
        echo "link-docroot: removed dead link $(basename "${target}")"
    fi
done

echo "link-docroot: ${linked} linked, ${kept} left alone"
