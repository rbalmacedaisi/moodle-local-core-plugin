#!/bin/bash
MISSING=0
PRESENT=0
TOTAL=0
while IFS= read -r h; do
  TOTAL=$((TOTAL+1))
  dir1=${h:0:2}
  dir2=${h:2:2}
  if [ -f "/var/moodledata/filedir/$dir1/$dir2/$h" ]; then
    PRESENT=$((PRESENT+1))
    echo "PRESENT $h"
  else
    MISSING=$((MISSING+1))
    echo "MISSING $h"
  fi
done < /tmp/infoa_hashes.txt
echo "TOTAL=$TOTAL PRESENT=$PRESENT MISSING=$MISSING"