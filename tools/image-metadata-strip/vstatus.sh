#!/usr/bin/env bash
# Progress of the verification parts c (pixels) and d (rendering) + production probes.
cd /root/msp-exif-2026-09-30 || exit 1
echo "box $(date -u +%T)  c started $(cat verify-c.started 2>/dev/null)  d started $(cat verify-d.started 2>/dev/null)"
awk -F, 'NR > 1 { n++; if ($2 != "True") bad++ } END { printf "c: %d checked, %d bad\n", n, bad }' full/verify-c.csv
[ -f full/verify-d.csv ] && awk -F, 'NR > 1 { n++; if ($4 != "True") bad++ } END { printf "d: %d renders checked, %d bad\n", n, bad }' full/verify-d.csv
tail -n 1 verify-c.log
tail -n 1 verify-d.log 2>/dev/null
tail -n 2 load.log
pgrep -f "[v]erify_full.py --run-dir full --part c" > /dev/null && echo "c running" || echo "c NOT running"
pgrep -f "[v]erify_full.py --run-dir full --part d" > /dev/null && echo "d running" || echo "d NOT running"
