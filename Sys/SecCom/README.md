# Se[cure]Com[munication] between servers

This directory contains all cryptographic materials used for
inter-server communication.

## Files Included

- crypto/crypto.php  
- crypto/generate_manifest.sh  
- crypto/sign_manifest.sh  
- master_signing_public.pem  
- crypto_manifest.json  
- crypto_manifest.json.sig  
- keys/*.pem (public keys only)

## Integrity Protection

All files in this directory are protected by a signed manifest:

- `crypto_manifest.json` contains SHA-256 hashes of all files.
- `crypto_manifest.json.sig` contains a digital signature.
- `master_signing_public.pem` is used by all servers to verify authenticity.

Any modification to any file will cause integrity verification to fail.

## Initial Workflow 
### 1. Creating the master signing keys
(offline!!)

./create_master_keys.sh
git add master_signing_public.pem
git commit -m "Add master signing public key"

### 2. Creating a server’s keypair

./create_server_keys.sh serverA
# DO NOT commit the private key

keep keys/serverA_public.pem
in th repro and commit:

git add keys/serverA_public.pem
git commit -m "Add public key for serverA"


That's it, the Servers using the same repro should be able to verify eveything themselfs once repro updated.

The crypto.php system verifies:
- manifest signature
- all files (including scripts) match their hash
- keys are authentic


( old docu below (needds to be made better))
### 1. Generate Manifest

./generate_manifest.sh

This recreates `crypto_manifest.json`.

### 2. Sign Manifest

**Signing requires the offline master private key.**

Use the helper script:

./sign_manifest.sh /path/to/master_signing_private.pem


This creates/updates:

- `crypto_manifest.json.sig`

Commit both manifest files.

### 3. Deploy

All servers verify integrity on startup using:

- master_signing_public.pem
- crypto_manifest.json
- crypto_manifest.json.sig

Any tampering results in immediate failure.

## Notes

- The master private key must never be committed or stored online.
- Public keys may be freely stored in the repository.
- Private keys for individual servers are stored locally on each server.

