#!/bin/bash

# Validation test for corpus diagnostics
# Tests that diagnostics work correctly with a simple target

set -e

TARGET="test/diagnostics_test_target.php"
CORPUS_DIR=$(mktemp -d)
OUTPUT_DIR=$(mktemp -d)
LOG_FILE="$OUTPUT_DIR/log.txt"
EVENTS_LOG="$OUTPUT_DIR/corpus_events.csv"
SNAPSHOT_DIR="$OUTPUT_DIR/corpus_snapshots"
STABILITY_LOG="$OUTPUT_DIR/stability.csv"

echo "=== Corpus Diagnostics Validation Test ==="
echo "Target: $TARGET"
echo "Corpus: $CORPUS_DIR"
echo "Output: $OUTPUT_DIR"
echo ""

# Create initial corpus with one seed
echo "test123" > "$CORPUS_DIR/seed1.txt"

# Run fuzzer with diagnostics enabled
echo "Running fuzzer with diagnostics..."
php bin/php-fuzzer fuzz "$TARGET" "$CORPUS_DIR" "$OUTPUT_DIR" "$LOG_FILE" \
    --enable-corpus-diagnostics \
    --corpus-events-log="$EVENTS_LOG" \
    --corpus-snapshot-frequency=100 \
    --stability-log="$STABILITY_LOG" \
    --stability-interval=50 \
    --max-runs=200

echo ""
echo "=== Validation Checks ==="

# Check 1: Events log exists and has data
if [ ! -f "$EVENTS_LOG" ]; then
    echo "❌ FAIL: Events log file not created"
    exit 1
fi

EVENT_COUNT=$(wc -l < "$EVENTS_LOG" | tr -d ' ')
if [ "$EVENT_COUNT" -lt 2 ]; then
    echo "❌ FAIL: Events log has insufficient data (only $EVENT_COUNT lines)"
    exit 1
fi

echo "✓ Events log created with $EVENT_COUNT lines"

# Check 2: Snapshot directory exists and has snapshots
if [ ! -d "$SNAPSHOT_DIR" ]; then
    echo "❌ FAIL: Snapshot directory not created"
    exit 1
fi

SNAPSHOT_COUNT=$(find "$SNAPSHOT_DIR" -name "*.json" | wc -l | tr -d ' ')
if [ "$SNAPSHOT_COUNT" -eq 0 ]; then
    echo "❌ FAIL: No snapshots created"
    exit 1
fi

echo "✓ Snapshots created: $SNAPSHOT_COUNT files"

# Check 3: Stability log has extended metrics
if [ ! -f "$STABILITY_LOG" ]; then
    echo "❌ FAIL: Stability log not created"
    exit 1
fi

# Check header includes extended metrics
if ! grep -q "avg_seed_age" "$STABILITY_LOG"; then
    echo "❌ FAIL: Stability log missing extended metrics columns"
    exit 1
fi

echo "✓ Stability log includes extended metrics"

# Check 4: Verify snapshot JSON structure
FIRST_SNAPSHOT=$(find "$SNAPSHOT_DIR" -name "*.json" | head -1)
if [ -z "$FIRST_SNAPSHOT" ]; then
    echo "❌ FAIL: No snapshot files found"
    exit 1
fi

# Check JSON is valid and has expected structure
if ! php -r "json_decode(file_get_contents('$FIRST_SNAPSHOT'));" 2>/dev/null; then
    echo "❌ FAIL: Snapshot JSON is invalid"
    exit 1
fi

if ! php -r "\$data = json_decode(file_get_contents('$FIRST_SNAPSHOT'), true); if (!isset(\$data['run']) || !isset(\$data['seeds'])) { exit(1); }" 2>/dev/null; then
    echo "❌ FAIL: Snapshot JSON missing required fields"
    exit 1
fi

echo "✓ Snapshot JSON structure is valid"

# Check 5: Verify events log has expected columns
HEADER=$(head -1 "$EVENTS_LOG")
EXPECTED_COLUMNS="run,parent_seed_id,input_hash,size,coverage_before,coverage_after,delta,decision,reason"
if [ "$HEADER" != "$EXPECTED_COLUMNS" ]; then
    echo "❌ FAIL: Events log header mismatch"
    echo "Expected: $EXPECTED_COLUMNS"
    echo "Got:      $HEADER"
    exit 1
fi

echo "✓ Events log has correct column structure"

# Check 6: Verify at least some events were logged
DATA_LINES=$(tail -n +2 "$EVENTS_LOG" | wc -l | tr -d ' ')
if [ "$DATA_LINES" -eq 0 ]; then
    echo "❌ FAIL: No event data logged"
    exit 1
fi

echo "✓ Events log contains $DATA_LINES data rows"

echo ""
echo "=== All Validation Checks Passed ==="
echo ""
echo "Test artifacts:"
echo "  Events log: $EVENTS_LOG"
echo "  Snapshots: $SNAPSHOT_DIR"
echo "  Stability log: $STABILITY_LOG"
echo ""
echo "To inspect results:"
echo "  cat $EVENTS_LOG"
echo "  ls -la $SNAPSHOT_DIR"
echo "  head -5 $STABILITY_LOG"

# Cleanup
rm -rf "$CORPUS_DIR" "$OUTPUT_DIR"

echo ""
echo "✓ Test completed successfully"

