#!/usr/bin/env bash
set -euo pipefail

tracked_env_files="$(git ls-files | grep -E '(^|/)\.env($|\.)' | grep -vE '\.example$' || true)"
if [[ -n "$tracked_env_files" ]]; then
    echo "Tracked local environment files are forbidden:"
    echo "$tracked_env_files"
    exit 1
fi

if git grep -I -l -E -- '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}' -- ':!composer.lock' ':!scripts/ci/check-secrets.sh' >/tmp/core-r-secret-files.txt; then
    echo "Potential credential material found in tracked files:"
    cat /tmp/core-r-secret-files.txt
    exit 1
fi

echo "No tracked local environment files or obvious credential material found."
