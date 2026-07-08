#!/bin/bash
# Search for each missing hash in trashdir
while IFS= read -r h; do
  dir1=${h:0:2}
  dir2=${h:2:2}
  if [ -f "/var/moodledata/filedir/$dir1/$dir2/$h" ]; then
    echo "PRESENT filedir $h"
  elif [ -f "/var/moodledata/trashdir/$dir1/$dir2/$h" ]; then
    echo "TRASH   $h"
  else
    echo "ABSENT  $h"
  fi
done < /tmp/hashes.txt
