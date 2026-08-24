#!/bin/bash
#
# Runs the current test suite against src/ as it was before the fix, and
# insists that it fails.
#
# A test that passes on the broken code is not a regression test, it is
# decoration. tests/bootstrap.php prepends an autoloader for
# $SPAMTROLL_SRC_DIR, so the same tests can be pointed at the pre-fix
# sources without touching the working tree.
#
# Usage: bash dev/prove-regression.sh [baseRef]     (default: main)

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE_REF="${1:-main}"
OUT="build/regression-src"

rm -rf "$OUT"
mkdir -p "$OUT"

if ! git archive "$BASE_REF" src | tar -x -C "$OUT" 2>/dev/null; then
  echo "Cannot read src/ at ${BASE_REF}" >&2
  exit 2
fi

echo "src/ from ${BASE_REF} written to ${OUT}/src. Running the suite against it."
echo

# ArchTest is excluded: it inspects the classes Composer maps, not the ones
# the override autoloader serves, so it says nothing about the old sources.
SPAMTROLL_SRC_DIR="${OUT}/src" vendor/bin/pest \
  tests/FailOpenContractTest.php \
  tests/FeedbackTest.php \
  tests/ClientTest.php \
  tests/ClientConfigTest.php \
  tests/Request \
  tests/Response \
  --colors=never
status=$?

echo
if [ "$status" -eq 0 ]; then
  echo "REGRESSION PROOF FAILED: the suite passes against ${BASE_REF}'s src/."
  echo "Those tests do not distinguish the fix from the defect."
  exit 1
fi

echo "Regression proof holds: the suite is red against ${BASE_REF}'s src/ and green against the current one."
