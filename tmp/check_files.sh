#!/bin/bash
missing=0
present=0
total=0
while IFS= read -r h; do
  total=$((total+1))
  dir1=${h:0:2}
  dir2=${h:2:2}
  if [ -f "/var/moodledata/filedir/$dir1/$dir2/$h" ]; then
    present=$((present+1))
  else
    missing=$((missing+1))
    echo "MISSING $h"
  fi
done < /tmp/hashes.txt
echo "TOTAL=$total PRESENT=$present MISSING=$missing"
