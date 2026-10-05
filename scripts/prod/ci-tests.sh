#!/usr/bin/env bash
# Run the whole test suite, one process per test file, minus the files listed in
# tests/avail-ci-skip.txt. Used by the production workflow as the deploy gate.
#
#   scripts/prod/ci-tests.sh [outdir]      (outdir defaults to a temp dir)
#
# One process per file because the upstream suite is order-dependent and a single run crashes
# partway; files that pass alone are fine here. In-memory SQLite makes parallel runs safe.
# Exit code is non-zero when any non-skipped file fails.
set -uo pipefail

SKIP_FILE="${SKIP_FILE:-tests/avail-ci-skip.txt}"
OUT="${1:-$(mktemp -d)}"
JOBS="${JOBS:-$(nproc)}"
mkdir -p "$OUT/logs"

# Skip list: see tests/avail-ci-skip.txt ("path" skips a file, "path :: regex" skips matching tests).
sed -e 's/[[:space:]]#.*//' -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "$SKIP_FILE" 2>/dev/null >"$OUT/skip.txt" || true
grep -v '::' "$OUT/skip.txt" | awk '{print $1}' | sort -u >"$OUT/skip-files.txt" || true
grep '::' "$OUT/skip.txt" | sed 's/[[:space:]]*::[[:space:]]*/	/' | sed 's/[[:space:]]*$//' >"$OUT/skip-tests.tsv" || true

find tests/Feature tests/Unit -name '*Test.php' | sort >"$OUT/all.txt"
if [ -s "$OUT/skip-files.txt" ]; then
    grep -vxFf "$OUT/skip-files.txt" "$OUT/all.txt" >"$OUT/run.txt" || true
else
    cp "$OUT/all.txt" "$OUT/run.txt"
fi
stale="$( { awk '{print $1}' "$OUT/skip-files.txt"; cut -f1 "$OUT/skip-tests.tsv"; } | sort -u | grep -vxFf "$OUT/all.txt" || true)"
[ -z "$stale" ] || { echo "::warning::skip list names files that no longer exist:"; echo "$stale"; }
echo "Running $(wc -l <"$OUT/run.txt") test files ($(wc -l <"$OUT/skip-files.txt") files and $(wc -l <"$OUT/skip-tests.tsv") tests skipped), $JOBS at a time"

# Pest arguments for one file: unlimited memory, and --exclude-filter for its skipped tests.
pest_file() {
    local f="$1" out="$2" pattern
    pattern="$(awk -F'	' -v f="$f" '$1==f {print $2}' "$out/skip-tests.tsv" | paste -sd'|' -)"
    if [ -n "$pattern" ]; then
        php -d memory_limit=-1 vendor/bin/pest "$f" --colors=never --exclude-filter "$pattern"
    else
        php -d memory_limit=-1 vendor/bin/pest "$f" --colors=never
    fi
}

run_one() {
    local f="$1" out="$2" log
    log="$out/logs/$(echo "$f" | tr '/' '_').log"
    if timeout 300 bash -c "$(declare -f pest_file); pest_file '$f' '$out'" >"$log" 2>&1; then
        echo "PASS $f"
    else
        echo "FAIL $f"
    fi
}
export -f pest_file run_one

xargs -a "$OUT/run.txt" -P "$JOBS" -I{} bash -c 'run_one "$1" "$2"' _ {} "$OUT" >"$OUT/results.txt"

# Parallel runs share a filesystem and CPU: give every failure one more try on its own, so only
# files that fail twice count.
grep '^FAIL ' "$OUT/results.txt" | sed 's/^FAIL //' | sort >"$OUT/first-failed.txt" || true
: >"$OUT/failed.txt"
while read -r f; do
    [ -n "$f" ] || continue
    if [ "$(run_one "$f" "$OUT")" = "PASS $f" ]; then
        echo "flaky under parallel load, passed on retry: $f"
    else
        echo "$f" >>"$OUT/failed.txt"
    fi
done <"$OUT/first-failed.txt"

echo "files run: $(wc -l <"$OUT/run.txt")  failed: $(wc -l <"$OUT/failed.txt")"
if [ -s "$OUT/failed.txt" ]; then
    echo "Failing files (logs in $OUT/logs):"
    sed 's/^/  /' "$OUT/failed.txt"
    exit 1
fi
