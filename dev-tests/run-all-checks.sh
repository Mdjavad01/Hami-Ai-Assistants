#!/bin/bash
# Run every tmp-checks suite; report a one-line verdict each.
cd "$(dirname "$0")/.." || exit 1
PHP="${PHP:-/home/z/my-project/tools/php}"
total_fail=0
suites=(
  dev-tests/tmp-checks.php
  dev-tests/tmp-checks2.php
  dev-tests/tmp-checks3.php
  dev-tests/tmp-checks6.php
  dev-tests/tmp-checks7.php
  dev-tests/tmp-checks8.php
  dev-tests/tmp-checks9.php
  dev-tests/tmp-checks10.php
  dev-tests/tmp-checks11.php
  dev-tests/tmp-checks12.php
  dev-tests/tmp-checks13.php
  dev-tests/tmp-checks14.php
  dev-tests/tmp-checks15.php
  dev-tests/tmp-checks16-handoff.php
  dev-tests/tmp-checks17-failover.php
  dev-tests/tmp-checks18-geo403.php
  dev-tests/tmp-checks19-proxy.php
  dev-tests/tmp-checks20-modelsauth.php
  dev-tests/tmp-checks21-siteanswer.php
  dev-tests/tmp-checks22-relaybench-elementor.php
  dev-tests/tmp-checks23-alternation.php
  dev-tests/tmp-checks24-vault.php
  dev-tests/tmp-checks25-crawl.php
  dev-tests/tmp-checks26-crawlctl.php
  dev-tests/tmp-checks27-crawlboot.php
  dev-tests/tmp-checks28-crawldb.php
  dev-tests/tmp-checks29-catprices.php
  dev-tests/tmp-checks30-chatfallback.php
)
for s in "${suites[@]}"; do
  out=$("$PHP" "$s" 2>&1)
  if [ $? -eq 0 ]; then
    verdict="PASS"
  else
    verdict="FAIL"
    total_fail=$((total_fail+1))
  fi
  last=$(echo "$out" | tail -1)
  printf '%-46s %s   %s\n' "$(basename "$s")" "$verdict" "$last"
  if [ "$verdict" = "FAIL" ]; then
    echo "$out" | grep -E "FAIL|Fatal|Warning" | head -6
  fi
done
echo "----"
if [ $total_fail -eq 0 ]; then echo "ALL SUITES GREEN"; else echo "$total_fail SUITES FAILED"; fi
exit $total_fail
