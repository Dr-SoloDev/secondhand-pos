#!/usr/bin/env bash
# Secondhand POS — API Test Suite
# Usage:
#   ./run.sh                    # Run all tests
#   ./run.sh auth sellers       # Run specific test groups
#   ./run.sh --api-base http://localhost:8080/api/index.php

set -euo pipefail

RUN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$RUN_DIR/helpers/test_runner.sh"

# Parse args
API_BASE="${API_BASE:-http://localhost:8080/api/index.php}"
ARGS=()
for arg in "$@"; do
  case "$arg" in
    --api-base=*) API_BASE="${arg#*=}" ;;
    --api-base) echo "--api-base requires a value"; exit 1 ;;
    *) ARGS+=("$arg") ;;
  esac
done
export API_BASE

echo "API Test Suite — Secondhand POS"
echo "  API Base: $API_BASE"
echo "  Date:     $(date '+%Y-%m-%d %H:%M')"

# Source all test files
for f in "$RUN_DIR"/test_*.sh; do
  source "$f"
done

# Collect test function names from test_*.sh files only
declare -a TESTS=()
for tf in "$RUN_DIR"/test_*.sh; do
  while IFS= read -r func; do
    TESTS+=("$func")
  done < <(sed -n 's/^\(test_[a-z_]*\)().*/\1/p' "$tf")
done

# Filter by args if specified
if [ ${#ARGS[@]} -gt 0 ]; then
  FILTERED=()
  for arg in "${ARGS[@]}"; do
    for t in "${TESTS[@]}"; do
      if [[ "$t" == "test_$arg" ]]; then
        FILTERED+=("$t")
      fi
    done
  done
  TESTS=("${FILTERED[@]}")
fi

# Pre-login so token is available for all tests
echo "  Login: getting auth token..."
if ! login > /dev/null 2>&1; then
  echo "ERROR: Login failed — cannot run tests"
  exit 1
fi
echo "  OK"

# Run tests
for t in "${TESTS[@]}"; do
  $t
done

summary
