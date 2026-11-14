<?php
// /raj/includes/encryption.php
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'MAKE ENCRYPTION KEY PASSKEY');
}

function encryptData($data) {
    if (empty($data)) return '';
    try {
        $key = hex2bin(ENCRYPTION_KEY);
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    } catch (Exception $e) {
        error_log("Encryption error: " . $e->getMessage());
        return $data; // Return plain text if encryption fails
    }
}

function decryptData($encrypted) {
    if (empty($encrypted)) return '';
    try {
        // Check if it's base64 encoded
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            // Not base64 encoded, return as is (might be plain text)
            return $encrypted;
        }
        
        $method = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($method);
        $iv = substr($data, 0, $ivLength);
        $cipher = substr($data, $ivLength);
        
        // Check if IV is correct length
        if (strlen($iv) !== $ivLength) {
            // IV is wrong length, pad it or return as is
            error_log("IV length is " . strlen($iv) . " but expected $ivLength");
            return $encrypted; // Return as is
        }
        
        $key = hex2bin(ENCRYPTION_KEY);
        $decrypted = openssl_decrypt($cipher, $method, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : $encrypted;
    } catch (Exception $e) {
        error_log("Decryption error: " . $e->getMessage());
        return $encrypted;
    }
}

// Simple function to check if data is encrypted
function isEncrypted($data) {
    if (empty($data)) return false;
    // Check if it looks like base64 encoded data
    return preg_match('/^[A-Za-z0-9+\/=]+$/', $data) && strlen($data) > 20;
}

// Safe decrypt function that handles errors gracefully
function safeDecrypt($data) {
    if (empty($data)) return '';
    
    // If it's plain text, return as is
    if (!isEncrypted($data)) {
        return $data;
    }
    
    // Try to decrypt
    $result = decryptData($data);
    
    // If decryption failed or returned garbage, return original
    if ($result === $data || empty($result)) {
        return $data;
    }
    
    // Check if decrypted result looks like valid data
    if (is_numeric($result) || preg_match('/^-?\d+\.?\d*$/', $result)) {
        return $result;
    }
    
    return $result;
}
?>