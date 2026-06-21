```php
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'database.php';

/*
|--------------------------------------------------------------------------
| RECEIVE FORM DATA
|--------------------------------------------------------------------------
*/

$phone  = trim($_POST['phone'] ?? '');
$amount = (int)($_POST['amount'] ?? 0);
$mac    = trim($_POST['mac'] ?? '');
$ip     = trim($_POST['ip'] ?? '');

if (empty($phone)) {
    die("Phone number is required");
}

if ($amount <= 0) {
    die("Invalid package amount");
}

/*
|--------------------------------------------------------------------------
| FORMAT PHONE NUMBER
|--------------------------------------------------------------------------
| Converts:
| 0712345678 -> 254712345678
| 254712345678 -> 254712345678
*/

$phone = preg_replace('/\D/', '', $phone);

if (substr($phone, 0, 1) == "0") {
    $phone = "254" . substr($phone, 1);
}

if (substr($phone, 0, 3) != "254") {
    die("Phone number must start with 07 or 254");
}

/*
|--------------------------------------------------------------------------
| GET ACCESS TOKEN
|--------------------------------------------------------------------------
*/

$credentials = base64_encode(
    CONSUMER_KEY . ':' . CONSUMER_SECRET
);

$tokenUrl =
    "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";

$curl = curl_init();

curl_setopt($curl, CURLOPT_URL, $tokenUrl);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Basic $credentials"
]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($curl);

if (curl_errno($curl)) {
    die("Access Token Error: " . curl_error($curl));
}

curl_close($curl);

$tokenData = json_decode($response);

if (
    !$tokenData ||
    !isset($tokenData->access_token)
) {
    die("Failed to obtain access token");
}

$accessToken = $tokenData->access_token;

/*
|--------------------------------------------------------------------------
| GENERATE STK PASSWORD
|--------------------------------------------------------------------------
*/

$timestamp = date("YmdHis");

$password = base64_encode(
    BUSINESS_SHORT_CODE .
    PASSKEY .
    $timestamp
);

/*
|--------------------------------------------------------------------------
| STK PUSH REQUEST
|--------------------------------------------------------------------------
*/

$stkUrl =
    "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";

$requestData = [
    "BusinessShortCode" => BUSINESS_SHORT_CODE,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerPayBillOnline",
    "Amount" => $amount,
    "PartyA" => $phone,
    "PartyB" => BUSINESS_SHORT_CODE,
    "PhoneNumber" => $phone,
    "CallBackURL" => CALLBACK_URL,
    "AccountReference" => "Hotspot",
    "TransactionDesc" => "Internet Access"
];

$curl = curl_init();

curl_setopt($curl, CURLOPT_URL, $stkUrl);

curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $accessToken,
    "Content-Type: application/json"
]);

curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

curl_setopt(
    $curl,
    CURLOPT_POSTFIELDS,
    json_encode($requestData)
);

$response = curl_exec($curl);

if (curl_errno($curl)) {
    die("STK Error: " . curl_error($curl));
}

curl_close($curl);

$result = json_decode($response, true);

/*
|--------------------------------------------------------------------------
| LOG RESPONSE
|--------------------------------------------------------------------------
*/

file_put_contents(
    __DIR__ . '/logs/stkpush.log',
    date('Y-m-d H:i:s') .
    PHP_EOL .
    $response .
    PHP_EOL .
    str_repeat('-', 60) .
    PHP_EOL,
    FILE_APPEND
);

/*
|--------------------------------------------------------------------------
| CHECK SUCCESSFUL REQUEST
|--------------------------------------------------------------------------
*/

if (
    isset($result['ResponseCode']) &&
    $result['ResponseCode'] == "0"
) {

    $merchantRequestId =
        $result['MerchantRequestID'];

    $checkoutRequestId =
        $result['CheckoutRequestID'];

    /*
    ----------------------------------------------------------------------
    STORE PENDING TRANSACTION
    ----------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        "INSERT INTO payments
        (
            merchant_request_id,
            checkout_request_id,
            phone,
            amount,
            mac_address,
            status
        )
        VALUES
        (
            ?, ?, ?, ?, ?, 'PENDING'
        )"
    );

    $stmt->bind_param(
        "sssds",
        $merchantRequestId,
        $checkoutRequestId,
        $phone,
        $amount,
        $mac
    );

    $stmt->execute();

    $stmt->close();

    echo "
    <!DOCTYPE html>
    <html>
    <head>
        <title>Payment Request Sent</title>
    </head>
    <body style='font-family:Arial;text-align:center;padding:50px;'>

        <h2>Payment Request Sent</h2>

        <p>
            Check your phone and enter your
            M-Pesa PIN to complete payment.
        </p>

        <p>
            Phone: <strong>$phone</strong>
        </p>

        <p>
            Amount: <strong>KES $amount</strong>
        </p>

    </body>
    </html>
    ";

} else {

    echo "<h3>STK Push Failed</h3>";

    echo "<pre>";
    print_r($result);
    echo "</pre>";
}
?>
```
