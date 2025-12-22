#!/bin/bash
set -e

if [ -z "$1" ]; then
    echo "Usage: ./create_server_keys.sh <serverID>"
    echo "Example: ./create_server_keys.sh serverA"
    exit 1
fi

SERVER_ID="$1"

cd "$(dirname "$0")"

KEYDIR="./keys"
mkdir -p "$KEYDIR"

PRIVATE_KEY="private.pem"
PUBLIC_KEY="$KEYDIR/${SERVER_ID}_public.pem"

echo "=== Creating keypair for $SERVER_ID ==="
echo ""

if [ -f "$PRIVATE_KEY" ]; then
    echo "ERROR: Private key already exists: $PRIVATE_KEY"
    exit 1
fi

# Generate private key
openssl genrsa -out "$PRIVATE_KEY" 4096
chmod 600 "$PRIVATE_KEY"

# Export public key
openssl rsa -in "$PRIVATE_KEY" \
    -pubout \
    -out "$PUBLIC_KEY"

echo ""
echo "Keypair created for $SERVER_ID:"
echo " - $PRIVATE_KEY (keep local, DO NOT commit)"
echo " - $PUBLIC_KEY  (commit to repo)"
echo ""
