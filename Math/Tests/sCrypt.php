<?php
declare(strict_types=1);

putenv('SCRYPT_KEYS=k1:' . base64_encode(random_bytes(128)));
putenv('SCRYPT_PRIMARY=k1');

/**
 * Tests/crypto_selftest.php
 *
 * Usage:
 *   php Tests/crypto_selftest.php
 *
 * Expects:
 *   - Crypto include at: ../Crypto/simpleCrypto.inc.php  (adjust path if needed)
 *   - Env vars:
 *       SCRYPT_KEYS="k1:BASE64KEY,k2:BASE64KEY"
 *       SCRYPT_PRIMARY="k1" (optional)
 *     Optional for HMAC:
 *       HMAC_KEYS="h1:BASE64KEY,h2:BASE64KEY"
 *       HMAC_PRIMARY="h1" (optional)
 */


$IS_CLI = (PHP_SAPI === 'cli');

function out(string $s): void {
    global $IS_CLI;
    if ($IS_CLI) {
        echo $s, PHP_EOL;
    } else {
        echo htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), "<br>\n";
    }
}

function hr(): void {
    out(str_repeat('-', 72));
}

function ok(string $label): void {
    out("[OK]  $label");
}
function fail(string $label, string $detail = ''): void {
    out("[FAIL] $label" . ($detail !== '' ? " :: $detail" : ""));
}

function parseKeyringEnv(string $envName): array {
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

function expectThrows(callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

$exit = 0;

if (!$IS_CLI) echo "<pre>";

out("Crypto self-test");
hr();
out("PHP: " . PHP_VERSION);
out("SAPI: " . PHP_SAPI);

if (defined('OPENSSL_VERSION_TEXT')) {
    out("OpenSSL (PHP): " . OPENSSL_VERSION_TEXT);
} else {
    out("OpenSSL (PHP): not available");
}

hr();

// ---- Include crypto file ----
$cryptoPath = realpath(__DIR__ . '/../sCrypt.inc.php');
if (!$cryptoPath || !is_file($cryptoPath)) {
    $exit = 1;
    fail("Include crypto file", "Expected ../Crypto/simpleCrypto.inc.php (adjust path in this test)");
    if (!$IS_CLI) echo "</pre>";
    exit($exit);
}

require_once $cryptoPath;
ok("Included: $cryptoPath");

// ---- Check required functions exist ----
$requiredFns = ['sCrypt','sDecrypt','sHmac','sHash','sHashPass','sVerifyPass'];
foreach ($requiredFns as $fn) {
    if (!function_exists($fn)) {
        $exit = 1;
        fail("Function exists: $fn", "Missing");
    }
}
if ($exit) {
    if (!$IS_CLI) echo "</pre>";
    exit($exit);
}
ok("Public API present (sCrypt/sDecrypt/sHmac/sHash/sHashPass/sVerifyPass)");

hr();

// ---- Env keyring validation ----
$scryptKeys = parseKeyringEnv('SCRYPT_KEYS');
$scryptPrimary = getenv('SCRYPT_PRIMARY') ?: '';

if (!$scryptKeys) {
    $exit = 1;
    fail("SCRYPT_KEYS", "Missing or invalid. Example: SCRYPT_KEYS=\"k1:BASE64_32B\"");
} else {
    ok("SCRYPT_KEYS parsed: " . count($scryptKeys) . " key(s)");
    out("Kids: " . implode(', ', array_keys($scryptKeys)));
    if ($scryptPrimary !== '') {
        out("SCRYPT_PRIMARY: " . $scryptPrimary . (isset($scryptKeys[$scryptPrimary]) ? "" : " (NOT FOUND in SCRYPT_KEYS!)"));
        if (!isset($scryptKeys[$scryptPrimary])) {
            $exit = 1;
            fail("SCRYPT_PRIMARY valid", "Primary kid not present in SCRYPT_KEYS");
        } else {
            ok("SCRYPT_PRIMARY valid");
        }
    } else {
        out("SCRYPT_PRIMARY: (not set) -> library will use first key as primary");
    }

    foreach ($scryptKeys as $kid => $binKey) {
        $len = strlen($binKey);
        if ($len < 32) {
            $exit = 1;
            fail("Key length $kid", "Too short ($len bytes). Need >= 32 bytes (base64 of random_bytes(32))");
        } else {
            ok("Key length $kid ($len bytes)");
        }
    }
}

$hmacKeys = parseKeyringEnv('HMAC_KEYS');
$hmacPrimary = getenv('HMAC_PRIMARY') ?: '';

if ($hmacKeys) {
    ok("HMAC_KEYS parsed: " . count($hmacKeys) . " key(s)");
    out("HMAC kids: " . implode(', ', array_keys($hmacKeys)));
    if ($hmacPrimary !== '') {
        out("HMAC_PRIMARY: " . $hmacPrimary . (isset($hmacKeys[$hmacPrimary]) ? "" : " (NOT FOUND in HMAC_KEYS!)"));
        if (!isset($hmacKeys[$hmacPrimary])) {
            $exit = 1;
            fail("HMAC_PRIMARY valid", "Primary kid not present in HMAC_KEYS");
        } else {
            ok("HMAC_PRIMARY valid");
        }
    } else {
        out("HMAC_PRIMARY: (not set) -> library will use first HMAC key as primary");
    }
} else {
    out("HMAC_KEYS: (not set) -> sHmac() will reuse SCRYPT_KEYS keyring");
}

if ($exit) {
    hr();
    out("Environment problems detected. Fix env vars above and re-run.");
    if (!$IS_CLI) echo "</pre>";
    exit($exit);
}

hr();

// ---- Encryption / Decryption tests ----
try {
    $aad = 'selftest:v1';
    $pt  = "hello|" . bin2hex(random_bytes(16)) . "|t=" . time();

    $token = sCrypt($pt, $aad);
    $rt    = sDecrypt($token, $aad);

    if ($rt !== $pt) {
        $exit = 1;
        fail("Encrypt/Decrypt roundtrip", "Recovered plaintext mismatch");
    } else {
        ok("Encrypt/Decrypt roundtrip");
        out("Token: " . (strlen($token) > 180 ? substr($token, 0, 180) . "..." : $token));
    }

    // wrong AAD must fail
    $wrongAadOk = expectThrows(function() use ($token) {
        sDecrypt($token, 'wrong-context');
    });
    if (!$wrongAadOk) {
        $exit = 1;
        fail("Wrong AAD fails", "Decrypted with wrong AAD (should not happen)");
    } else {
        ok("Wrong AAD fails");
    }

    // tamper must fail
    $tampered = $token;
    $tampered[strlen($tampered) - 2] = ($tampered[strlen($tampered) - 2] === 'A') ? 'B' : 'A';
    $tamperOk = expectThrows(function() use ($tampered, $aad) {
        sDecrypt($tampered, $aad);
    });
    if (!$tamperOk) {
        $exit = 1;
        fail("Tamper detection", "Tampered token decrypted (should not happen)");
    } else {
        ok("Tamper detection");
    }

} catch (Throwable $e) {
    $exit = 1;
    fail("Encrypt/Decrypt tests", $e->getMessage());
}

hr();

// ---- HMAC tests ----
try {
    $sub = "prefs|brokerX|user@example.com";
    $a = sHmac($sub);
    $b = sHmac($sub);
    $c = sHmac($sub . "|different");

    if ($a !== $b) {
        $exit = 1;
        fail("sHmac determinism", "Same subject produced different outputs");
    } else {
        ok("sHmac determinism");
    }
    if ($a === $c) {
        $exit = 1;
        fail("sHmac uniqueness", "Different subjects produced same output (unlikely, but check)");
    } else {
        ok("sHmac uniqueness");
    }
    out("sHmac sample: $a");
} catch (Throwable $e) {
    $exit = 1;
    fail("sHmac tests", $e->getMessage());
}

hr();

// ---- Hash + password hash tests ----
try {
    $h = sHash("abc");
    if (!is_string($h) || strlen($h) < 32) {
        $exit = 1;
        fail("sHash output", "Unexpected hash output");
    } else {
        ok("sHash output");
        out("sHash('abc') = $h");
    }

    $pw = "test-" . bin2hex(random_bytes(6));
    $ph = sHashPass($pw);
    if (!sVerifyPass($pw, $ph)) {
        $exit = 1;
        fail("Password verify correct", "Verification failed");
    } else {
        ok("Password verify correct");
    }
    if (sVerifyPass($pw . "x", $ph)) {
        $exit = 1;
        fail("Password verify wrong", "Verification succeeded with wrong password");
    } else {
        ok("Password verify wrong");
    }
} catch (Throwable $e) {
    $exit = 1;
    fail("Hash/password tests", $e->getMessage());
}

hr();
out($exit === 0 ? "ALL TESTS PASSED" : "TESTS FAILED");
if (!$IS_CLI) echo "</pre>";
exit($exit);
