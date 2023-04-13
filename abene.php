<?php
// Enabling errors for debugging.
// Make sure to comment it before pushing it to production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Variables to be replaced
// Ideally we would store them securely outside of this script
//$app_secret = 'app_secret';
//$access_token = 'replace_with_your_access_token';
//$verify_token = 'replace_with_your_verify_token';
require_once("secrets.php");

// We need to response to the challenge when we save changes for webhooks in the Workplace Integrations panel
if (isset($_GET['hub_verify_token']) && $_GET['hub_verify_token'] == $verify_token) {
	echo $_GET['hub_challenge'];
	logging_to_txt_file("Webhook subscribed/modified");
	exit;
}

// CODE TO VERIFY THE WEBHOOK REQUESTS - Getting the headers and comparing the signature
$headers = getallheaders();
$request_body = file_get_contents('php://input');
$signature = "sha1=" . hash_hmac('sha1', $request_body, $app_secret);
//logging_to_txt_file("calculated signature " . $signature);
//logging_to_txt_file("headers " . json_encode($headers, true));

if (!isset($headers['X-Hub-Signature']) || ($headers['X-Hub-Signature'] != $signature)) {
        logging_to_txt_file("X-Hub-Signature not matching");
        exit("X-Hub-Signature not matching");
}

// Obtain data sent by the webhook
$data = json_decode($request_body, true);
logging_to_txt_file($request_body);
// Obtain recipient id from the webhook event data
$recipient = $data['entry'][0]['messaging'][0]['sender']['id'];
// Obtain message from the webhook event data
$received_text = $data['entry'][0]['messaging'][0]['message']['text'];

// We setup a curl to interact with the Workplace Messaging API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,"https://graph.facebook.com/v13.0/me/messages");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Content-Type:application/json',
    'User-Agent:GithubRep-SupportBot',
    'Authorization:Bearer ' . $access_token
));

// We send a mark as seen to the user who sent the message while we process it
// This action is optional
$fields = array(
	"sender_action" => "mark_seen",
	"recipient" => array("id" => $recipient)
 );
$fields_string = json_encode($fields);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);

$server_output = curl_exec($ch); // We can optionally process the server output



$transferMatch = $redeemMatch = [];
preg_match('/^send (?<amount>[0-9]+) \@(?<name>\w+)/', $received_text, $transferMatch);
preg_match('/^redeem (?<code>\w+)/', $received_text, $redeemMatch);
$mention = isset($data['entry'][0]['messaging'][0]['mentions']) ? $data['entry'][0]['messaging'][0]['mentions'][0]['id'] : null;


if ("balance" == $received_text) {
	$fields = array(
		"message" => array("text" => "Current balance is: " . get_balance($recipient) . " abenes"),
		"recipient" => array("id" => $recipient),
		"messaging_type" => "RESPONSE"
	);
} elseif (isset($redeemMatch['code'])) {
	if (redeem($recipient, $redeemMatch['code'])) {
		$fields = array(
			"message" => array("text" => "🤑 Successfully redeemed code _{$redeemMatch['code']}_." . PHP_EOL . "Current balance is: " . get_balance($recipient) . " abenes"),
			"recipient" => array("id" => $recipient),
			"messaging_type" => "RESPONSE"
		);
	} else {
		$fields = array(
			"message" => array("text" => "🫣 Failed to redeem code _{$redeemMatch['code']}_." . PHP_EOL . "Current balance is: " . get_balance($recipient) . " abenes"),
			"recipient" => array("id" => $recipient),
			"messaging_type" => "RESPONSE"
		);
	}
} elseif (isset($transferMatch['name']) && isset($transferMatch['amount']) && $mention) {
	$transferMessage = transfer($recipient, $mention, $transferMatch['amount']);
	$fields = array(
		"message" => array("text" => $transferMessage . PHP_EOL . 
			"Sender balance: " . get_balance($recipient) . PHP_EOL . 
			"Receiver balance: " . get_balance($mention) 
		),
		// "recipient" => array("id" => $recipient),
		"recipient" => array("thread_key" => $data['entry'][0]['messaging'][0]['thread']['id']),
		"messaging_type" => "RESPONSE"
	);
} else {
	$fields = array(
		"message" => array("text" =>
			"Sorry, I didn't understand that." . PHP_EOL . 
			"Currently supported commands are: " . PHP_EOL . 
			'    send _amount @target_ (this must be sent in a chat with the sender, receiver and Ereba. You also _must_ tag the receiver)' . PHP_EOL .
			'    redeem _your code_' . PHP_EOL .
			'    balance'
		),
		"recipient" => array("id" => $recipient),
		"messaging_type" => "RESPONSE"
	);
}

// We send the response to the Workplace API so the message can be delivered to the user
$fields_string = json_encode($fields);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
$server_output = curl_exec($ch);
curl_close ($ch);

// Further processing ...
if ($server_output == "OK") { echo "ALRIGHTY!"; } else { echo "NOPE. " . $server_output; }

// FUNCTIONS
function logging_to_txt_file($text_to_log) {
    $fp = fopen('my_log_file.txt', 'a');
    $datetime_now = date('Y-m-d H:i:s');
    fwrite($fp, '[' . $datetime_now . '] ' . $text_to_log . "\r\n");
    fclose($fp);
}



// this and next method are vulnerable to a certain attack. Please don't break it during presentation. Bonus abenes to the first to describe the attack
function redeem($who, $code) {
	$codes = require 'codes.php';
	if (code_redeemed($code) || !isset($codes[$code])) {
		return false;
		
	}

	mark_redeemed($who, $code);

	if (add_funds($who, $codes[$code], 'redeem')) {
		update_ledger($who, $codes[$code], 'redeem');

		return true;
	}

	return false;
}

function transfer($sender, $receiver, $amount) {
	if (!subtract_funds($sender, $amount, "transfer")) {
		return "🫣 Transfer failed: sender does not have enough funds";
	}

	if (!add_funds($receiver, $amount, "transfer")) {
		return "🫣 Transfer failed: could not add funds to account";
	}

	return "🤑 Transfer successful!";
}

function code_redeemed($code) {
	return in_array($code, explode(PHP_EOL, file_get_contents('redeemed.txt')));
}

function mark_redeemed($who, $code) {
	file_put_contents('redeemed.txt', $code . PHP_EOL, FILE_APPEND);
}

function add_funds($who, $amount, $operation) {
	$accounts = get_accounts();
	if (!isset($accounts[$who])) {
		$accounts[$who] = 0;
	}
	$accounts[$who] += $amount;
	update_accounts($accounts);
	update_ledger($who, $amount, $operation);

	return true;
}

function subtract_funds($who, $amount, $operation) {
	$accounts = get_accounts();
	if (!isset($accounts[$who]) || $accounts[$who] < $amount) {
		return false;
	}
	$accounts[$who] -= $amount;
	update_accounts($accounts);
	update_ledger($who, -$amount, $operation);

	return true;
}

function update_accounts($accounts) {
	$merged = '';
	foreach ($accounts as $name => $amount) {
		$merged .= "$name,$amount" . PHP_EOL;
	}
	file_put_contents('accounts.txt', $merged);
}

function get_accounts() {
	$funds = [];
	$fh = fopen('accounts.txt', 'r');
	while (($line = fgetcsv($fh)) !== false) {
		$funds[$line[0]] = $line[1];
	}
	fclose($fh);
	return $funds;
}

function get_balance($who) {
	return get_accounts()[$who] ?? 0;
}

function update_ledger($who, $amount, $operation) {
	file_put_contents('ledger.txt', date('Y-m-d H:i:s') . " $operation $who $amount" . PHP_EOL, FILE_APPEND);
}

// almost forgot. This makes it crypto
crypt('abene', '$1$brah$');
