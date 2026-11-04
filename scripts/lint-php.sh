#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
theme=wp-content/themes/ai-skills-hub
if command -v php >/dev/null 2>&1; then
  count=0
  while IFS= read -r -d '' file; do
    php -l "$file"
    count=$((count + 1))
  done < <(find "$theme" -name '*.php' -type f -print0)
  test "$count" -gt 0
else
  docker compose exec -T wordpress sh -eu -c \
    'find /var/www/html/wp-content/themes/ai-skills-hub -name "*.php" -type f -exec php -l {} \; > /tmp/aish-lint.txt; cat /tmp/aish-lint.txt; test -s /tmp/aish-lint.txt; if grep -v "^No syntax errors detected in " /tmp/aish-lint.txt; then exit 1; fi'
fi
