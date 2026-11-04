#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
page_file=$(mktemp)
trap 'rm -f "$page_file"' EXIT
curl --fail --silent --show-error http://localhost:8080/ -o "$page_file"
grep -q 'Project initialization is complete' "$page_file"
if grep -Eq 'Fatal error|<b>Warning</b>|<b>Notice</b>' "$page_file"; then
  echo 'PHP diagnostic found in page' >&2
  exit 1
fi
for asset in app.css app.js; do
  curl --fail --silent --show-error "http://localhost:8080/wp-content/themes/ai-skills-hub/assets/dist/$asset" -o /dev/null
  grep -q "assets/dist/$asset" "$page_file"
done
docker compose exec -T wordpress php -r '
require "/var/www/html/wp-load.php";
if (get_template() !== "ai-skills-hub" || !defined("AISkillsHub\\Core\\VERSION")) { exit(1); }
if (post_type_exists("aish_skill")) { exit(1); }
if (!AISkillsHub\Core\Core::boot()->migrator()->isCurrent()) { exit(1); }
echo "Theme active, Core loaded, schema current, no CPT shadow storage.\n";
'
echo 'WordPress HTTP and CSS/JS smoke checks passed.'
