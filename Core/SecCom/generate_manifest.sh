#!/bin/bash
set -e

# Generate cryptographic manifest of all crypto directory files.
# Excludes the manifest and signature files themselves.

cd "$(dirname "$0")"

OUT="crypto_manifest.json"

echo "{" > "$OUT"

FILES=$(find . -type f \
    ! -name "crypto_manifest.json" \
    ! -name "crypto_manifest.json.sig")

for f in $FILES; do
    HASH=$(sha256sum "$f" | awk '{print $1}')
    f_clean="${f#./}"
    echo "  \"${f_clean}\": \"${HASH}\"," >> "$OUT"
done

truncate -s -2 "$OUT"
echo "" >> "$OUT"
echo "}" >> "$OUT"

echo "Manifest generated: $OUT"
