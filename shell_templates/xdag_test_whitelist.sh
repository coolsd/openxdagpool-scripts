#!/bin/bash

while read -u 10 line; do
    IP="`echo \"$line\" | cut -d ':' -f 1`"
    PORT="`echo \"$line\" | cut -d ':' -f 2`"
    echo -n "$IP":"$PORT" "is "
    nc -z -w5 "$IP" "$PORT"
    if [ $? -eq 0 ]; then
        echo OPEN
    else
        echo CLOSED
    fi
done 10<netdb-white.txt
