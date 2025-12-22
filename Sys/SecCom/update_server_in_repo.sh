#!/bin/bash
set -e

if [ -z "$1" ]; then
    echo "Usage: ./update_server_in_repo.sh <serverID>"
    exit 1
fi

SERVER_ID="$1"
cd "$(dirname "$0")"

PUB_KEY="./keys/${SERVER_ID}_public.pem"

if [ ! -f "$PUB_KEY" ]; then
    echo "ERROR: Public key does not exist: $PUB_KEY"
    exit 1
fi

echo "Public key for $SERVER_ID is ready to commit:"
echo "  $PUB_KEY"
echo ""
echo "Commit it with:"
echo "git add $PUB_KEY && git commit -m \"Add public key for $SERVER_ID\""
echo ""
