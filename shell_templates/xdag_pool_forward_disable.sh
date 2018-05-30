#!/bin/bash

WAN_IP="Enter your IP here"
MINERS_PORT="Enter your miners port number here"

iptables -t nat -D PREROUTING -p tcp -d "$WAN_IP" --dport "$MINERS_PORT" -j REDIRECT --to-port 9999

killall socat

echo "Miners redirection disabled."
