<?php

if (!isset($SERVER_ID)) {
    die("SERVER_ID must be defined before loading crypto.php");
}

$BASEDIR = __DIR__;
$KEYDIR  = __DIR__ . "/keys";

// ------------------------------------------------------------
// ROOT OF TRUST: MASTER SIGNING PUBLIC KEY
// ------------------------------------------------------------

$MASTER_PUBLIC_KEY = file_get_contents("$BASEDIR/master_signing_public.pem");

if (!$MASTER_PUBLIC_KEY) {
    die("Missing master signing public key");
}

// ------------------------------------------------------------
// VERIFY MANIFEST SIGNATURE
// ------------------------------------------------------------

$manifestPath = "$BASEDIR/crypto_manifest.json";
$sigPath      = "$BASEDIR/crypto_manifest.json.sig";

$manifest = file_get_contents($manifestPath);
$sig      = base64_decode(file_get_contents($sigPath));

if (!$manifest || !$sig) {
    die("Missing manifest or signature");
}

$verified = openssl_verify(
    $manifest,
    $sig,
    $MASTER_PUBLIC_KEY,
    OPENSSL_ALGO_SHA256
);

if ($verified !== 1) {
    die("CRITICAL: Manifest signature INVALID — crypto directory is not trusted.");
}

// ------------------------------------------------------------
// VERIFY HASH OF EVERY FILE IN THE MANIFEST
// ------------------------------------------------------------

$manifestData = json_decode($manifest, true);

foreach ($manifestData as $file => $expectedHash) {
    $full = "$BASEDIR/$file";
    if (!file_exists($full)) {
        die("CRITICAL: Expected file missing: $file");
    }
    $current = hash_file("sha256", $full);
    if ($current !== $expectedHash) {
        die("CRITICAL: TAMPERED FILE DETECTED: $file");
    }
}

// At this point, the entire crypto folder (including the bash script)
// has passed a cryptographic integrity check.

// ------------------------------------------------------------
// LOAD PUBLIC KEYS (all verified by manifest)
// ------------------------------------------------------------

$PUBLIC_KEYS = [];
foreach (glob("$KEYDIR/*_public.pem") as $file) {
    $id = explode("_", basename($file))[0];
    $PUBLIC_KEYS[$id] = file_get_contents($file);
}

// ------------------------------------------------------------
// LOAD PRIVATE KEY FOR THIS SERVER
// ------------------------------------------------------------

$PRIVATE_KEY_PATH = "server_private.pem";

if (!file_exists($PRIVATE_KEY_PATH)) {
    die("Private key missing for server: $SERVER_ID");
}

$PRIVATE_KEY = file_get_contents($PRIVATE_KEY_PATH);

// ------------------------------------------------------------
// HYBRID RSA + AES ENCRYPTION FUNCTIONS
// ------------------------------------------------------------

function rsa_encrypt_to($serverId, $plaintext, $PUBLIC_KEYS) {
    if (!isset($PUBLIC_KEYS[$serverId])) return false;

    openssl_public_encrypt(
        $plaintext,
        $encrypted,
        $PUBLIC_KEYS[$serverId],
        OPENSSL_PKCS1_OAEP_PADDING
    );

    return base64_encode($encrypted);
}

function rsa_decrypt_local($ciphertext, $PRIVATE_KEY) {
    $data = base64_decode($ciphertext);
    openssl_private_decrypt(
        $data,
        $out,
        $PRIVATE_KEY,
        OPENSSL_PKCS1_OAEP_PADDING
    );
    return $out;
}

function hybrid_encrypt($toServerId, $plaintext, $PUBLIC_KEYS) {

    $aes_key = random_bytes(32);
    $iv      = random_bytes(16);

    $cipher = openssl_encrypt(
        $plaintext,
        "AES-256-CBC",
        $aes_key,
        OPENSSL_RAW_DATA,
        $iv
    );

    $enc_key = rsa_encrypt_to($toServerId, $aes_key, $PUBLIC_KEYS);

    return json_encode([
        "to"   => $toServerId,
        "iv"   => base64_encode($iv),
        "key"  => $enc_key,
        "data" => base64_encode($cipher),
    ]);
}

function hybrid_decrypt($blob, $PRIVATE_KEY) {
    $obj = json_decode($blob, true);
    if (!$obj) return false;

    $aes_key = rsa_decrypt_local($obj["key"], $PRIVATE_KEY);
    if (!$aes_key) return false;

    $iv     = base64_decode($obj["iv"]);
    $cipher = base64_decode($obj["data"]);

    return openssl_decrypt(
        $cipher,
        "AES-256-CBC",
        $aes_key,
        OPENSSL_RAW_DATA,
        $iv
    );
}