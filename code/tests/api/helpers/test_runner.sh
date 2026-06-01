# Test runner core
HELPER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HELPER_DIR/auth.sh"

PASS_COUNT=0
FAIL_COUNT=0

test_section() {
  local name="${1:-}"
  [ -z "$name" ] && return
  echo ""
  echo "=========================================="
  echo "  $name"
  echo "=========================================="
}

test_pass() {
  local label="${1:-}"
  echo "  PASS: $label"
  PASS_COUNT=$((PASS_COUNT + 1))
}

test_fail() {
  local label="${1:-}"
  echo "  FAIL: $label"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

assert_eq() {
  local expected="${1:-}" actual="${2:-}" label="${3:-}"
  if [ "$expected" = "$actual" ]; then
    test_pass "$label"
  else
    test_fail "$label"
    echo "    (expected: '$expected', got: '$actual')"
  fi
}

assert_neq() {
  local unexpected="${1:-}" actual="${2:-}" label="${3:-}"
  if [ "$unexpected" != "$actual" ]; then
    test_pass "$label"
  else
    test_fail "$label"
    echo "    (should not be: '$unexpected')"
  fi
}

assert_contains() {
  local haystack="${1:-}" needle="${2:-}" label="${3:-}"
  if echo "$haystack" | grep -q "$needle"; then
    test_pass "$label"
  else
    test_fail "$label"
    echo "    (expected to contain '$needle')"
  fi
}

assert_not_contains() {
  local haystack="${1:-}" needle="${2:-}" label="${3:-}"
  if echo "$haystack" | grep -q "$needle"; then
    test_fail "$label"
    echo "    (should NOT contain '$needle')"
  else
    test_pass "$label"
  fi
}

summary() {
  local total=$((PASS_COUNT + FAIL_COUNT))
  echo ""
  echo "=========================================="
  if [ "$FAIL_COUNT" -eq 0 ]; then
    echo "  All $PASS_COUNT tests passed!"
  else
    echo "  $PASS_COUNT/$total passed — FAILURES: $FAIL_COUNT"
  fi
  echo "=========================================="
  return "$FAIL_COUNT"
}
