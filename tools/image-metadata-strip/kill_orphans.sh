#!/usr/bin/env bash
# Kills exiftool -stay_open processes left behind by finished/killed audit or strip runs
# (their parent is gone, so they were re-parented to PID 1 and would wait on stdin forever).
count=0
for pid in $(ps -eo pid=,ppid=,args= | awk '$2 == 1 && /Image-ExifTool-13.59\/exiftool -stay_open/ {print $1}'); do
    kill "$pid" && count=$((count + 1))
done
echo "killed $count orphaned exiftool processes"
