<?php
declare(strict_types=1);

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "OpenSSL extension is required.\n");
    exit(1);
}

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);

if ($key === false) {
    fwrite(STDERR, "Failed to generate VAPID key pair.\n");
    exit(1);
}

$details = openssl_pkey_get_details($key);
if (!$details || empty($details['ec']) || empty($details['ec']['point']) || empty($details['ec']['d'])) {
    fwrite(STDERR, "Unable to extract EC key details.\n");
    exit(1);
}

$publicKey = rtrim(strtr(base64_encode($details['ec']['point']), '+/', '-_'), '=');
$privateKey = rtrim(strtr(base64_encode($details['ec']['d']), '+/', '-_'), '=');

fwrite(STDOUT, "VAPID PUBLIC KEY:\n$publicKey\n\n");
fwrite(STDOUT, "VAPID PRIVATE KEY:\n$privateKey\n\n");
fwrite(STDOUT, "Add these to config.php (WEB_PUSH_VAPID_PUBLIC_KEY / WEB_PUSH_VAPID_PRIVATE_KEY) and set WEB_PUSH_VAPID_SUBJECT to a mailto: address.\n");
