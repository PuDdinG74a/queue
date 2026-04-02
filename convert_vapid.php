<?php
function b64url_encode_no_pad(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$privPem = file_get_contents(__DIR__ . '/private_key.pem');
$pubDer  = file_get_contents(__DIR__ . '/public_key.der');

if ($privPem === false || $pubDer === false) {
    die('อ่านไฟล์ key ไม่ได้');
}

$privRes = openssl_pkey_get_private($privPem);
if ($privRes === false) {
    die('อ่าน private key ไม่สำเร็จ');
}

$details = openssl_pkey_get_details($privRes);
if ($details === false || empty($details['ec']['d'])) {
    die('ดึงค่า private key EC ไม่สำเร็จ');
}

$publicKeyRaw = substr($pubDer, -65);
$privateKeyRaw = $details['ec']['d'];

echo "<pre>";
echo "publicKey:\n" . b64url_encode_no_pad($publicKeyRaw) . "\n\n";
echo "privateKey:\n" . b64url_encode_no_pad($privateKeyRaw) . "\n";
echo "</pre>";