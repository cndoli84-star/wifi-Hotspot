```php id="cbk001"
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "database.php";
require_once "config.php";

/*
|--------------------------------------------------------------------------
| READ RAW CALLBACK FROM SAFARICOM
|--------------------------------------------------------------------------
*/

$stkCallback = file_get_contents('php://input');
$data = json_decode($stkCallback, true);

/*
|--------------------------------------------------------------------------
| LOG CALLBACK
|--------------------------------------------------------------------------
*/

file_put_contents(
    __DIR__ . "/logs/callback.log",
    date("Y-m-d H:i:s") . PHP_EOL . $stkCallback . PHP_EOL . "-------------------" . PHP_EOL,
    FILE_APPEND
);

/*
|--------------------------------------------------------------------------
| VALIDATE CALLBACK
|--------------------------------------------------------------------------
*/

if (!isset($data['Body']['stkCallback'])) {
    exit;
}

$callback = $data['Body']['stkCallback'];

$resultCode = $callback['ResultCode'];

/*
|--------------------------------------------------------------------------
| PAYMENT FAILED
|--------------------------------------------------------------------------
*/

if ($resultCode != 0) {

    $checkoutRequestID = $callback['CheckoutRequestID'];

    $conn->query("
        UPDATE payments 
        SET status='FAILED'
        WHERE checkout_request_id='$checkoutRequestID'
    ");

    exit;
}

/*
|--------------------------------------------------------------------------
| EXTRACT SUCCESS DATA
|--------------------------------------------------------------------------
*/

$checkoutRequestID = $callback['CheckoutRequestID'];

$items = $callback['CallbackMetadata']['Item'];

$mpesaReceipt = '';
$phone = '';
$amount = 0;

foreach ($items as $item) {

    if ($item['Name'] == 'MpesaReceiptNumber') {
        $mpesaReceipt = $item['Value'];
    }

    if ($item['Name'] == 'PhoneNumber') {
        $phone = $item['Value'];
    }

    if ($item['Name'] == 'Amount') {
        $amount = $item['Value'];
    }
}

/*
|--------------------------------------------------------------------------
| GET PAYMENT RECORD
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT mac_address, amount 
    FROM payments 
    WHERE checkout_request_id = ?
");

$stmt->bind_param("s", $checkoutRequestID);
$stmt->execute();
$result = $stmt->get_result();

$row = $result->fetch_assoc();

$mac = $row['mac_address'];
$paidAmount = $row['amount'];

$stmt->close();

/*
|--------------------------------------------------------------------------
| SET EXPIRY TIME
|--------------------------------------------------------------------------
*/

$expiryMinutes = 0;

switch ($paidAmount) {

    case 10:
        $expiryMinutes = 60; // 1 hour
        break;

    case 20:
        $expiryMinutes = 180; // 3 hours
        break;

    case 50:
        $expiryMinutes = 1440; // 24 hours
        break;

    case 250:
        $expiryMinutes = 10080; // 7 days
        break;

    case 800:
        $expiryMinutes = 43200; // 30 days
        break;

    default:
        $expiryMinutes = 60;
}

$expiryTime = date("Y-m-d H:i:s", strtotime("+$expiryMinutes minutes"));

/*
|--------------------------------------------------------------------------
| UPDATE PAYMENT AS PAID
|--------------------------------------------------------------------------
*/

$conn->query("
    UPDATE payments 
    SET 
        status='PAID',
        mpesa_receipt='$mpesaReceipt',
        expiry='$expiryTime'
    WHERE checkout_request_id='$checkoutRequestID'
");

/*
|--------------------------------------------------------------------------
| ADD MAC TO MIKROTIK (BYPASS)
|--------------------------------------------------------------------------
*/

require_once "lib/routeros_api.php";

$API = new RouterosAPI();

if ($API->connect(MIKROTIK_IP, MIKROTIK_USER, MIKROTIK_PASS)) {

    $API->write("/ip/hotspot/ip-binding/add");
    $API->write("=mac-address=" . $mac);
    $API->write("=type=bypassed");
    $API->write("=comment=PAID_USER");

    $API->disconnect();
}

/*
|--------------------------------------------------------------------------
| DONE
|--------------------------------------------------------------------------
*/

echo "OK";

?>
```
