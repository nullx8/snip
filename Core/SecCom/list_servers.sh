#!/bin/bash
cd "$(dirname "$0")/keys"

echo "=== Registered servers ==="
for f in *_public.pem; do
    [ -e "$f" ] || continue
    echo " - ${f%%_public.pem}"
done
