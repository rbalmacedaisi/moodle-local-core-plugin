#!/bin/bash
PASS='zLK9UjZVtCyYJTvDg4rk8F*'
SQL=$(cat "$1")
mysql -h 52.20.149.225 -u isi -p"$PASS" isidb -N -B -e "$SQL" 2>&1