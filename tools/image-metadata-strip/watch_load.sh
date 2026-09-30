#!/usr/bin/env bash
# Every 30 s while the strip job runs: box load, CPU idle/iowait, and how fast production answers
# (liveness through Traefik, a puzzle page straight from a web container, a cached thumbnail via cdn.*).
# Output: /root/msp-exif-2026-09-30/load.log  (stop with: pkill -f watch_load.sh)
out=/root/msp-exif-2026-09-30/load.log
while true; do
    ts=$(date -u +%H:%M:%S)
    load=$(cut -d' ' -f1-3 /proc/loadavg)
    cpu=$(vmstat 1 2 | tail -1 | awk '{print "idle=" $15 "% wa=" $16 "%"}')
    live=$(curl -s -o /dev/null -m 20 -w "%{http_code}/%{time_total}" https://myspeedpuzzling.com/-/health-check/liveness)
    web=$(docker ps --format '{{.Names}}' | grep -m1 -E '^myspeedpuzzling-web-')
    page=$(docker exec "$web" curl -s -o /dev/null -m 20 -H "Host: myspeedpuzzling.com" -H "X-Forwarded-Proto: https" \
        -w "%{http_code}/%{time_total}" "http://127.0.0.1:8080/en/puzzle/0191eea0-426d-737b-971c-28334aff92eb" 2>/dev/null)
    thumb=$(curl -s -o /dev/null -m 20 -w "%{http_code}/%{time_total}" "https://cdn.myspeedpuzzling.com/preset:puzzle_small/plain/ravensburger-boston-2189-1000.jpg")
    echo "$ts load=$load $cpu liveness=$live page=$page thumb=$thumb" >> "$out"
    sleep 28
done
