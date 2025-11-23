#!/bin/bash
set -e

# This script documents HOW to sign the manifest.
# It does NOT contain the private key.
# You must supply the master signing private key manually.

cd "$(dirname "$0")"

if [ ! -f crypto_manifest.json ]; then
    echo "Error: crypto_manifest.json not found. Run generate_manifest.sh first."
    exit 1
fi

echo "Signing crypto_manifest.json"
echo "You must provide path to master_signing_private.pem:"
echo "   ./sign_manifest.sh /path/to/master_signing_private.pem"
echo ""

PRIVATE_KEY="$1"

if [ -z "$PRIVATE_KEY" ]; then
    echo "Usage: ./sign_manifest.sh <private_key_path>"
    exit 1
fi

if [ ! -f "$PRIVATE_KEY" ]; then
    echo "Error: private key file not found: $PRIVATE_KEY"
    exit 1
fi

# Sign the manifest
openssl dgst -sha256 \
    -sign "$PRIVATE_KEY" \
    -out crypto_manifest.bin.sig \
    crypto_manifest.json

# Convert to base64
base64 crypto_manifest.bin.sig > crypto_manifest.json.sig
rm crypto_manifest.bin.sig

echo "Signature written to crypto_manifest.json.sig"
echo "Commit both crypto_manifest.json and crypto_manifest.json.sig."
