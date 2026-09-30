#!/usr/bin/env bash
# One-shot status of the full strip run: rows per status, last progress line, load, production probes.
cd /root/msp-exif-2026-09-30 || exit 1
echo "box $(date -u +%T)  started $(cat full.started 2>/dev/null)"
awk -F, 'NR > 1 { n[$3]++ } END { for (s in n) printf "%s=%d ", s, n[s]; print "" }' full/manifest.csv
grep -E "^[0-9]+/|DONE|Traceback" full.log | tail -1
tail -n 3 load.log
df -h / | tail -1 | awk '{print "disk used " $3 " free " $4}'
pgrep -f "strip.py --run-dir /root/msp-exif-2026-09-30/full" > /dev/null && echo "strip.py running" || echo "strip.py NOT running"
