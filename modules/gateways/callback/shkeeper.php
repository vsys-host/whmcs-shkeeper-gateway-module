<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
use WHMCS\Billing\Invoice;

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$getHeaders = function () {
    $headers = [];
    foreach ( $_SERVER as $name => $value ) {
        if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
            $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
        }
    }
    return $headers;
};
$getTriggeredTransaction = function ($request) {
    foreach($request->transactions as $transaction) {
      if($transaction->trigger) {
        return $transaction;
      }
    }

    $keys = array_keys($request->transactions);
    return $request->transactions[$keys[count($keys) - 1]];
};
$convertAmountIfNeed = function ($amount, $userCurrency, $requestObj) {
    global $gatewayModuleName;

    if ($userCurrency["code"] != $requestObj->fiat) {
        $paymentCurrencyID = WHMCS\Database\Capsule::table("tblcurrencies")->where("code", $requestObj->fiat)->value("id");
        if (is_null($paymentCurrencyID)) {
            logTransaction($gatewayModuleName, $requestObj, "Unsuccessful - Invalid Currency");
            exit;
        }
        $amount = convertCurrency($amount, $paymentCurrencyID, $userCurrency["id"]);
    }

    return $amount;
};

$request = file_get_contents('php://input');
$requestHeaders = $getHeaders();

if(!isset($requestHeaders['X-Shkeeper-Api-Key']) || $requestHeaders['X-Shkeeper-Api-Key'] !== $gatewayParams['apiKey']) {
    logModuleCall( $gatewayModuleName, "Callback called wrong api key",
        [
            'request body' => $request,
            'request headers' => $requestHeaders
        ],
        [],
        ['status code' => '204'],
        []
    );
    logTransaction( $gatewayModuleName, $request, "Missed or invalid request api key");
    http_response_code(204);
    exit;
}

$requestObj = json_decode($request);
if ($requestObj === null && json_last_error() !== JSON_ERROR_NONE) {
    logModuleCall( $gatewayModuleName, "Callback called wrong json",
        [
            'request body' => $request,
            'request headers' => $requestHeaders,
            'parsed json'     => $requestObj,
        ],
        [],
        ['status code' => '204'],
        []
    );
    http_response_code(204);
    exit;
}

$triggeredTransaction = $getTriggeredTransaction($requestObj);

//Reject scam transactions
if($triggeredTransaction->amount_fiat < $gatewayParams['minimalFiatTransaction']) {
  logTransaction($gatewayParams['name'], $triggeredTransaction, "Scam transaction");
  http_response_code(202);
  exit();
}

$invoiceId = checkCbInvoiceID($requestObj->external_id, $gatewayParams['name']);
$existTransaction = WHMCS\Database\Capsule::table('tblaccounts')
                        ->where('transid', $triggeredTransaction->txid)
                        ->where('invoiceid', $invoiceId)
                        ->first();

if($existTransaction) {
    logTransaction($gatewayParams['name'], $requestObj, "Transaction {$existTransaction->transid} for Invoice $invoiceId already exist");
    http_response_code(202);
    exit();
}

$invoice = Invoice::find($invoiceId);
$userCurrency = getCurrency($invoice->clientId);
//For calculate amount without shkeeper fee that was added to invoice amount
$feeMultiplier = (100 - $requestObj->fee_percent) / 100;

$paidAmount = $convertAmountIfNeed($triggeredTransaction->amount_fiat * $feeMultiplier, $userCurrency, $requestObj);

//PAID
if($requestObj->paid && $requestObj->status == 'PAID') {

    $invoiceBalance = $invoice->getBalanceAttribute();

    //Case when invoice already paid or paid partially in whmcs with different gateway/credit
    if($invoiceBalance != $invoice->subtotal ) {
        $amount = $gatewayParams['roundCreditAmount'] == 'on' ? floor($paidAmount) : $paidAmount;
    } else {
        $amount = $invoiceBalance;
    }
} else {
    //OVERPAID, PARTIAL
    //WHMCS don't like 0, so used max to prevent it
    $amount = $gatewayParams['roundCreditAmount'] == 'on' ? max(floor($paidAmount), 1) : $paidAmount;
}

$isTransactionAdded = addInvoicePayment(
        $invoiceId,
        $triggeredTransaction->txid,
        $amount,
        0.00,
        $gatewayModuleName
);

if(!$isTransactionAdded) {
    logTransaction($gatewayParams['name'], $requestObj, "Transaction add error");
    logActivity("[shkeeper]" . print_r("Transaction add error. Amount: {$amount}  Invoice ID:  {$invoiceId}", 1));
    http_response_code(204);
    exit;
}

logTransaction($gatewayParams['name'], $requestObj, "Transaction added");
http_response_code(202);
