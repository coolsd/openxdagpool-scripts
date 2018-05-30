#!/bin/bash

WAN_IP="Enter your IP here"
MINERS_PORT="Enter your miners port number here"

# what pool do you want to redirect your miners to?
REDIRECT_TO="195.201.168.17:13654"

iptables -t nat -A PREROUTING -p tcp -d "$WAN_IP" --dport "$MINERS_PORT" -j REDIRECT --to-port 9999

echo Redirecting miners to "$REDIRECT_TO"...
socat "TCP-LISTEN:9999,fork" TCP:"$REDIRECT_TO" &>/dev/null < /dev/null &

echo -n "Initialized socat process IDs: "
sleep 1
pidof socat
