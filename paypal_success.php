<?php

define("DEBUG", 1);
define("USE_SANDBOX",1);
define("LOG_FILE", "paypal_ipn.log");

$rawInputData = file_get_contents('php://input');
$rawInputDataArr = explode("&", $rawInputData);
$inputDataArr = array();
foreach($inputDataArr as $inputKeyValue) {
	$inputKeyValueArr = explode("=", $inputKeyValue);
	if(count($inputKeyValueArr) ==2) {
		$inputDataArr[$inputKeyValueArr[0]] = urldecode($inputKeyValueArr[1]);
	}
}

$reqData = 'cmd=_notify-validate';
foreach($inputDataArr as $key => $value) {
	$value = urlencode($value);
	$req .= "&$key=$value";
}

if(USE_SANDBOX== true) {
	$paypalUrl = "https://www.sandbox.paypal.com/cgi-bin/webscr";
} else {
	$paypalUrl = "https://www.paypal.com/cgi-bin/webscr";
}
$curl = curl_init($paypalUrl);
if($curl == FALSE) {
	return FALSE;
}
curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($curl, CURLOPT_POST, 1);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_POSTFIELDS, $reqData);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
if(DEBUG== true) {
	curl_setopt($curl, CURLOPT_HEADER, 1);
	curl_setopt($curl, CURLINFO_HEADER_OUT, 1);
}
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Connection: Close'));



// CONFIG: Please download 'cacert.pem' from "http://curl.haxx.se/docs/caextract.html" and set the directory path
// of the certificate as shown below. Ensure the file is readable by the webserver.
// This is mandatory for some environments.
//$cert = __DIR__ . "./cacert.pem";
//curl_setopt($ch, CURLOPT_CAINFO, $cert);


$curlRes = curl_exec($curl);
if(curl_errno($curl) != 0) {
	if(DEBUG == true) {
		error_log(date('[Y-m-d H:i e] '). " failed to connect to Paypal to validate IPN message : ". curl_error($curl) . PHP_EOL, 3, LOG_FILE);
	}	
	curl_close($curl);
	exit;
} else {
	// Log the entire HTTP response if debug is switched on.
	if(DEBUG == true) {
		error_log(date('[Y-m-d H:i e] '). "HTTP request of validation request:". curl_getinfo($ch, CURLINFO_HEADER_OUT) ." for IPN payload: $req" . PHP_EOL, 3, LOG_FILE);
		error_log(date('[Y-m-d H:i e] '). "HTTP response of validation request: $res" . PHP_EOL, 3, LOG_FILE);
	}
	curl_close($ch);
}
$tokens = explode("\r\n\r\n", trim($curlRes));
$res = trim(end($tokens));
if (strcmp ($res, "VERIFIED") == 0) {
	// assign posted variables to local variables
	$item_name = $_POST['item_name'];
	$item_number = $_POST['item_number'];
	$payment_status = $_POST['payment_status'];
	$payment_amount = $_POST['mc_gross'];
	$payment_currency = $_POST['mc_currency'];
	$txn_id = $_POST['txn_id'];
	$receiver_email = $_POST['receiver_email'];
	$payer_email = $_POST['payer_email'];
	
	include("DBController.php");
	$db = new DBController();
	
	// check whether the payment_status is Completed
	$isPaymentCompleted = false;
	if($payment_status == "Completed") {
		$isPaymentCompleted = true;
	}
	// check that txn_id has not been previously processed
	$isUniqueTxnId = false; 
	$param_type="s";
	$param_value_array = array($txn_id);
	$result = $db->runQuery("SELECT * FROM payment WHERE txn_id = ?",$param_type,$param_value_array);
	if(empty($result)) {
        $isUniqueTxnId = true;
	}	
	// check that receiver_email is your PayPal email
	// check that payment_amount/payment_currency are correct
	if($isPaymentCompleted) {
	    $param_type = "sssdss";
	    $param_value_array = array($item_number, $item_name, $payment_status, $payment_amount, $payment_currency, $txn_id);
	    $payment_id = $db->insert("INSERT INTO payment(item_number, item_name, payment_status, payment_amount, payment_currency, txn_id) VALUES(?, ?, ?, ?, ?, ?)", $param_type, $param_value_array);
	    
	} 
	// process payment and mark item as paid.
	
	
	if(DEBUG == true) {
		error_log(date('[Y-m-d H:i e] '). "Verified IPN: $req ". PHP_EOL, 3, LOG_FILE);
	}
	
} else if (strcmp ($res, "INVALID") == 0) {
	// log for manual investigation
	// Add business logic here which deals with invalid IPN messages
	if(DEBUG == true) {
		error_log(date('[Y-m-d H:i e] '). "Invalid IPN: $req" . PHP_EOL, 3, LOG_FILE);
	}
}
