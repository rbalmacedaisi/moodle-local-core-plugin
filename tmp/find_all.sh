#!/bin/bash
BASE=${1:-/mnt/forensic}
echo "Looking for all hashes in $BASE/var/moodledata/..."
while IFS= read -r h; do
  if [[ "$h" == da39a3ee* ]]; then continue; fi
  found=$(find "$BASE/var/moodledata/" -name "$h" 2>/dev/null | head -1)
  if [[ -n "$found" ]]; then
    echo "FOUND $h -- $found"
  else
    echo "NOTFOUND $h"
  fi
done < /tmp/hashes.txt
