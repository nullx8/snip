<?php
/**
 * simpleCrypto.inc.php
 *
 * Public API:
 *   sCrypt(string $plaintext, string $aad = '') : string
 *   sDecrypt(string $token, string $aad = '') : string
 *   sHmac(string $subject, ?string $kid = null) : string         (base64url)
 *   sHash(string $data, string $algo='sha256') : string          (hex)
 *   sHashPass(string $password) : string                         (bcrypt)
 *   sVerifyPass(string $password, string $hash) : bool
 *
 * Encryption scheme: AES-256-CBC + HMAC-SHA256 (Encrypt-then-MAC)
 * Token format: "AC1.<kid>.<b64url(iv|cipher|tag)>"
 *
 * ENV:
 *   SCRYPT_KEYS="k1:BASE64KEY,k2:BASE64KEY"   (each key decodes to >= 32 bytes)
 *   SCRYPT_PRIMARY="k1"
 *
 * Optional separate keyring for sHmac (else SCRYPT_KEYS is reused):
 *   HMAC_KEYS="h1:BASE64KEY,h2:BASE64KEY"
 *   HMAC_PRIMARY="h1"
 */

/* ----------------- internal helpers ----------------- */

function _b64url_enc(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function _b64url_dec(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $bin = base64_decode($s, true);
    if ($bin === false) throw new RuntimeException("Invalid base64url");
    return $bin;
}

function _hash_equals_safe(string $a, string $b): bool {
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
    return $res === 0;
}

function _parse_keyring(string $envName): array {
    $raw = getenv($envName);
    if (!$raw) return [];
    $items = array_filter(array_map('trim', explode(',', $raw)));
    $out = [];
    foreach ($items as $it) {
        $parts = explode(':', $it, 2);
        if (count($parts) !== 2) continue;
        [$kid, $b64] = $parts;
        $kid = trim($kid);
        $b64 = trim($b64);
        if ($kid === '' || $b64 === '') continue;

        $key = base64_decode($b64, true);
        if ($key === false) continue;

        $out[$kid] = $key;
    }
    return $out;
}

function _scrypt_keyring(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $keys = _parse_keyring('SCRYPT_KEYS');
    if (!$keys) throw new RuntimeException("SCRYPT_KEYS missing/empty");

    $primary = getenv('SCRYPT_PRIMARY') ?: '';
    if ($primary === '' || !isset($keys[$primary])) {
        $primary = array_key_first($keys);
    }

    $cache = ['keys'=>$keys, 'primary'=>$primary];
    return $cache;
}

function _hmac_keyring(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $keys = _parse_keyring('HMAC_KEYS');
    $primary = getenv('HMAC_PRIMARY') ?: '';

    if (!$keys) {
        $c = _scrypt_keyring();
        $keys = $c['keys'];
        $primary = $c['primary'];
    } else {
        if ($primary === '' || !isset($keys[$primary])) $primary = array_key_first($keys);
    }

    $cache = ['keys'=>$keys, 'primary'=>$primary];
    return $cache;
}

/** Derive a 32-byte subkey from master key using HMAC-SHA256 label. */
function _kdf(string $masterKey, string $label): string {
    return hash_hmac('sha256', $label, $masterKey, true);
}

/* ----------------- public API ----------------- */

function sCrypt(string $plaintext, string $aad = ''): string {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException("OpenSSL not available");

    $kr = _scrypt_keyring();
    $kid = $kr['primary'];
    $raw = $kr['keys'][$kid];
    if (strlen($raw) < 32) throw new RuntimeException("Key '$kid' too short (need 32+ bytes)");
    $master = substr($raw, 0, 32);

    $encKey = _kdf($master, "enc|AC1");
    $macKey = _kdf($master, "mac|AC1");

    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) throw new RuntimeException("openssl_encrypt failed");

    // Verify-on-decrypt via Encrypt-then-MAC (covers version, kid, iv, cipher, aad)
    $tag = hash_hmac('sha256', "AC1|$kid|" . $iv . $cipher . $aad, $macKey, true);

    return "AC1.$kid." . _b64url_enc($iv . $cipher . $tag);
}

function sDecrypt(string $token, string $aad = ''): string {
    if (!function_exists('openssl_decrypt')) throw new RuntimeException("OpenSSL not available");

    $parts = explode('.', $token, 3);
    if (count($parts) !== 3) throw new RuntimeException("Invalid token format");
    [$ver, $kid, $payload] = $parts;
    if ($ver !== 'AC1') throw new RuntimeException("Unsupported version: $ver");

    $kr = _scrypt_keyring();
    if (!isset($kr['keys'][$kid])) throw new RuntimeException("Unknown key id: $kid");

    $raw = $kr['keys'][$kid];
    if (strlen($raw) < 32) throw new RuntimeException("Key '$kid' too short");
    $master = substr($raw, 0, 32);

    $encKey = _kdf($master, "enc|AC1");
    $macKey = _kdf($master, "mac|AC1");

    $bin = _b64url_dec($payload);
    if (strlen($bin) < 16 + 32) throw new RuntimeException("Token too short");

    $iv = substr($bin, 0, 16);
    $tag = substr($bin, -32);
    $cipher = substr($bin, 16, -32);

    // Verify MAC BEFORE decrypt (critical)
    $calc = hash_hmac('sha256', "AC1|$kid|" . $iv . $cipher . $aad, $macKey, true);
    if (!_hash_equals_safe($tag, $calc)) throw new RuntimeException("Decrypt failed (bad MAC)");

    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) throw new RuntimeException("openssl_decrypt failed");

    return $plain;
}

/** Deterministic keyed hash (base64url). Great for anonymized IDs/filenames. */
function sHmac(string $subject, ?string $kid = null): string {
    $kr = _hmac_keyring();
    $kid = $kid ?: $kr['primary'];
    if (!isset($kr['keys'][$kid])) throw new RuntimeException("Unknown HMAC key id: $kid");

    $raw = $kr['keys'][$kid];
    if (strlen($raw) < 32) throw new RuntimeException("HMAC key '$kid' too short");
    $key = substr($raw, 0, 32);

    $mac = hash_hmac('sha256', $subject, $key, true);
    return _b64url_enc($mac);
}

/** Plain hash (hex). */
function sHash(string $data, string $algo = 'sha256'): string {
    $h = hash($algo, $data, false);
    if ($h === false) throw new RuntimeException("Hash algo not supported: $algo");
    return $h;
}

/** Password hash (pinned to bcrypt for portability/predictability). */
function sHashPass(string $password): string {
    $h = password_hash($password, PASSWORD_BCRYPT);
    if ($h === false) throw new RuntimeException("password_hash failed");
    return $h;
}

function sVerifyPass(string $password, string $hash): bool {
    return password_verify($password, $hash);
}
