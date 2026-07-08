#!/bin/bash
while IFS= read -r h; do
  if [[ "$h" == da39a3ee* ]]; then continue; fi
  dir1=${h:0:2}
  dir2=${h:2:2}
  if [ -f "/mnt/forensic/var/moodledata/filedir/$dir1/$dir2/$h" ]; then
    echo "PRESENT $h"
  else
    echo "MISSING $h"
  fi
done < /tmp/hashes.txt
