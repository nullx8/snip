<?php
function encrypt($string, $key) {
    $encrypted = base64_encode(hash_hmac('sha256', $string, $key, true));
    return $encrypted;
}

function decrypt($string, $key) {
    $decoded = base64_decode($string);
    $decrypted = hash_hmac('sha256', $string, $key, true);
    if ($decoded === $decrypted) {
        return $string;
    } else {
        return 'Decryption failed';
    }
}

/*

// Set a shared key
$sharedKey = "YourSharedKey";

// String to encrypt
$string = "Sensitive Data";

// Encrypt the string
$encryptedString = encrypt($string, $sharedKey);
echo "Encrypted string: " . $encryptedString . "\n";

// Decrypt the string
$decryptedString = decrypt($encryptedString, $sharedKey);
echo "Decrypted string: " . $decryptedString . "\n";

*/
?>
