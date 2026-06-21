<?php

$consumerKey = "JkjkTYqZnmS0R1puRHFBQ6se94p7Lv3x6PM6dIg3R7CNoR8N";
$consumerSecret = "lDtI0ZvsYnBcVhXueC4SvGQRQkLBngRiocyiyOnBtOLe9Cr9OmA7A0pSFjvXW8gj";

$url = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";

$curl = curl_init();

curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_USERPWD, $consumerKey . ":" . $consumerSecret);

$response = curl_exec($curl);

curl_close($curl);

$result = json_decode($response);

if (!$result || !isset($result->access_token)) {
    die("Failed to obtain access token");
}

$accessToken = $result->access_token;

echo "Access Token: " . $accessToken;