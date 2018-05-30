#!/bin/bash

for XDAG in 1 2; do
	DIR="/home/pool/xdag""$XDAG"/
	LOG="$DIR"/xdag.log

	if [ ! -f "$LOG" ]; then
		continue
	fi

	SIZE="`stat -c %s \"$LOG\"`"

	# 100MB size limit
	if [ "$SIZE" -lt 104857600 ]; then
		continue
	fi

	NEWNAME="$LOG"."`date +%Y%m%d-%H%M`".ARCHIVE

	if [ -f "$NEWNAME" ]; then
		continue
	fi

	# move the log file
	mv "$LOG" "$NEWNAME"
	touch "$LOG"
	gzip "$NEWNAME"
done
