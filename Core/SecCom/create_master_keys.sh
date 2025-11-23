#!/bin/bash
set -e

echo "=== Creating MASTER signing keypair ==="
echo "This MUST be done offline. Never run this on a production server."
echo ""

cd "$(dirname "$0")"

if [ -f master_signing_private.pem ]; then
    echo "ERROR: master_signing_private.pem already exists."
    echo "Refusing to overwrite."
    exit 1
fi

# Generate private key
openssl genrsa -out master_signing_private.pem 4096
chmod 600 master_signing_private.pem

# Export public key
openssl rsa -in master_signing_private.pem \
    -pubout \
    -out master_signing_public.pem

echo ""
echo "Master signing keypair created:"
echo " - master_signing_private.pem  (KEEP OFFLINE!)"
echo " - master_signing_public.pem   (commit to repo)"
echo ""