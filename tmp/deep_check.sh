#!/bin/bash
BASE=${1:-/mnt/snap}
hash=${2:-f9a43a24910dc8d055406c3016f0ef4e009d00f2}
echo "Searching for $hash in $BASE..."
find "$BASE/var/moodledata" -name "$hash" 2>/dev/null
echo '---trashdir contents (top 10)---'
ls -la "$BASE/var/moodledata/trashdir/" 2>&1 | head -10
echo '---trashdir file count---'
find "$BASE/var/moodledata/trashdir" -type f 2>/dev/null | wc -l
echo '---filedir structure (compare)---'
ls "$BASE/var/moodledata/filedir/" | wc -l
