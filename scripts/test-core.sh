#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose cp tests/integration/core.php wordpress:/tmp/aish-core-integration.php
docker compose exec -T wordpress php /tmp/aish-core-integration.php
