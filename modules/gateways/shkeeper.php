<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use WHMCS\Database\Capsule;
use Carbon\CarbonInterval;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function shkeeper_MetaData()
{
    return [
        'DisplayName' => 'Shkeeper Crypto Payments',
        'APIVersion' => '1.0', // Use API Version 1.0
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define shkeeper gateway configuration options.
 *
 * @return array
 */
function shkeeper_config()
{
    if(!Capsule::schema()->hasTable('shkeeper_user_crypto')) {
        Capsule::schema()->create(
            'shkeeper_user_crypto',
            function ($table) {
                $table->integer('user_id');
                $table->string('crypto');
                $table->unique('user_id');
            }
        );
    }

    return [

        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Shkeeper',
        ],

        'apiUrl' => [
            'FriendlyName' => 'API Url',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter shkeeper api url here',
        ],

        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter shkeeper api key here',
        ],

        'minimalFiatTransaction' => [
            'FriendlyName' => 'Minimal transaction amount',
            'Type' => 'text',
            'Size' => '3',
            'Default' => '0.1',
            'Description' => 'Transactions with less amounts in fiat will be marked as scam and will not be added',
        ],

        'roundCreditAmount' => [
            'FriendlyName' => 'Round credit amount',
            'Type' => 'yesno',
            'Description' => 'Tick to round down credit amount that will be added in case of overpaid',
        ],

    ];
}

/**
 * Crypto payments info.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function shkeeper_link($params)
{
    $isAutoGenerate = function () {
        $autogenerateVars = [
            'generateinvoices',
            'publishInvoice'
        ];
        foreach ($autogenerateVars as $var) {
            if(isset($_REQUEST[$var]) && $_REQUEST[$var] == true) {
                return true;
            }
        }
        return false;
    };

    try {
        $shkeeperApi = new ShkeeperAPI($params);
        $crypto = isset($_POST['crypto']) ? htmlspecialchars(trim($_POST['crypto'])) : '';

        if($isAutoGenerate()) {
            $crypto = Capsule::table('shkeeper_user_crypto')
                ->where('user_id', $params['clientdetails']['userid'])
                ->value('crypto');
        }

        if (!$crypto) {
            return shkeeper_RenderForm($shkeeperApi->getCryproList());
        }

        if(!$isAutoGenerate()) {
            Capsule::table('shkeeper_user_crypto')
                ->updateOrInsert(
                    [
                        'user_id' => $params['clientdetails']['userid'],
                    ],
                    [
                        'crypto' => $crypto,
                    ]
                );
        }

        $paymentRequest = $shkeeperApi->sendPaymentRequest($crypto);
        $qrSrc = ShkeeperAPI::getQrSrcLink($paymentRequest->wallet, $paymentRequest->amount, $crypto);

        $html = "<style>
.invoice-address {
  background-color: #fff;
  overflow: auto;
}       
    
@media screen and (max-width: 767px) {
  .invoice-address {
    border: none;
  }
}
</style>";
        $html .= '<div><img src="' . $qrSrc . ' title="QR code" />';
        $html .= '<div class="invoice-thumb">';
        $html .= "<div></br>Send <b>$paymentRequest->amount</b> " . strtoupper($paymentRequest->display_name) . " to wallet: \n</div>";
        $html .= "</br><b style='font-size: 13px'><div class='well well-sm invoice-address'>$paymentRequest->wallet</div></b>";
        if($paymentRequest->recalculate_after) {
            $html .= "<i>Amount valid for " . CarbonInterval::hours($paymentRequest->recalculate_after)->cascade()->forHumans() . "</i>";
        }
        $html .= '</div>';
        return $html;
    } catch (Exception $e) {
        logActivity('[' . basename(__FILE__, '.php') . "] Payment gateway error with Invoice ID: {$params['invoiceid']} " . $e->getMessage() );
        return 'Shkeeper connection error. Please try later, or choose another payment method';
    }
}

function shkeeper_RenderForm(array $cryptos = []) {
    ob_start();
    echo "<style>                                 
.invoice-subtitle {
  text-transform: uppercase;
  font-size: 14px;
  font-weight: bold;
  margin-bottom: 20px;
}

.invoice-select {
  width: 100%;
}
.invoice-box {
  display: flex;
  justify-content: center;
  gap: 30px;
}

@media screen and (max-width: 767px) {

  .invoice-select {
    width: 50%;
    margin-bottom: 10px;
  }

}
</style>";
    echo '<form method="POST" action="" >';
    echo '<h3 class="invoice-subtitle">Choose crypto currency</h3>';
    echo '<div class="invoice-box">';
    echo '<div>';
    echo '<select id="shkeeper_crypto" class="form-control invoice-select" name="crypto">';
    foreach ($cryptos as $crypto) {
        echo '<option value="' . $crypto->name . '">' . $crypto->display_name . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div>';
    echo '<input type="submit" class="btn btn-success" ame="sendrequest" value="Get address" />';
    echo '</div>';
    echo '</div>';
    echo '</form>';

    return ob_get_clean();
}

class ShkeeperAPI {

    private $apiUrl;
    private $apiKey;
    private $whmcsParams;

    public function __construct($params) {
        $this->initSettings($params);
    }

    private function initSettings($params) {
        $this->apiUrl = $params['apiUrl'];
        $this->apiKey = $params['apiKey'];
        $this->whmcsParams = $params;
    }

    public function getCryproList() {
        $method = 'GET';
        $endpoint = 'crypto';
        $cryptos = $this->request($endpoint, $method);
        if($cryptos->status == 'success' && $cryptos->crypto_list) {
            return $cryptos->crypto_list;
        }

        logModuleCall(
            basename(__FILE__, '.php'),
            "$method $endpoint",
            [],
            $cryptos,
            $cryptos,
            []);

        throw new Exception($cryptos->message ?? 'Can not get available crypto from Shkeeper server');
    }

    public function sendPaymentRequest($crypto = 'BTC') {
        $paymentRequestData = [
            'external_id'  => $this->whmcsParams['invoiceid'],
            'fiat'         => $this->whmcsParams['currency'],
            'amount'       => $this->whmcsParams['amount'],
            'callback_url' => $this->whmcsParams['systemurl'] . 'modules/gateways/callback/' . basename(__FILE__),
        ];
        $endpoint = strtoupper($crypto) . "/payment_request";
        $method = 'POST';
        $paymentRequest = $this->request($endpoint, $method, json_encode($paymentRequestData, JSON_UNESCAPED_SLASHES));

        if($paymentRequest->status == 'success') {
            return $paymentRequest;
        }

        logModuleCall(
            basename(__FILE__, '.php'),
            "$method $endpoint",
            $paymentRequestData,
            $paymentRequest,
            $paymentRequest,
            []);

        throw new Exception($paymentRequest->message ?? 'Can not create payment request in Shkeeper server');
    }

    public function request($endpoint, $method = 'GET', $data = [], $includeHeaders = false) {
        try {
            switch ($method) {
                case 'POST':
                    $options = [
                        'body' => $data,
                    ];
                    break;
                default:
                    $options = [];
            }

            $client = new GuzzleHttp\Client([
                'base_uri' => $this->apiUrl,
                'headers' => ['X-Shkeeper-API-Key' => $this->apiKey],
            ]);

            $response = $client->request($method, $endpoint, $options);
        } catch (ClientException $e) {
            logModuleCall(
                basename(__FILE__, '.php'),
                "$method $endpoint",
                $data,
                $e,
                $e->getMessage(),
                []);
            throw new Exception($e);
        }

        if($response->getStatusCode() == 500) {
            logModuleCall(
                basename(__FILE__, '.php'),
                "$method $endpoint",
                $data,
                $response->getBody()->getContents(),
                $response->getBody()->getContents(),
                []);
            throw new Exception('There is a problem connecting to the Shkeeper server!');
        }

        $jsonBodyObj = json_decode($response->getBody()->getContents());
        if ($jsonBodyObj === null && json_last_error() !== JSON_ERROR_NONE) {
            logModuleCall(
                basename(__FILE__, '.php'),
                "$method $endpoint",
                $data,
                $response->getBody()->getContents(),
                $jsonBodyObj,
                []);
            throw new Exception('Invalid response from Shkeeper server!');
        }
        if($includeHeaders) {
            return [
                'headers' => $response->getHeaders(),
                'body'    => $jsonBodyObj,
            ];
        }

        return $jsonBodyObj;
    }

    public static function getQrSrcLink($wallet, $cryptoAmount, $cryptoCurrency, $qrSize = '200x200') {
        $qrUrl = "https://chart.googleapis.com/chart?chs=$qrSize&cht=qr";
        $strForCode = '';

        switch (strtolower($cryptoCurrency)) {
            case 'btc':
                $strForCode .= 'bitcoin:';
                break;
            case 'ltc':
                $strForCode .= 'litecoin:';
                break;
            case 'doge':
                $strForCode .= 'dogecoin:';
                break;
        }
        $strForCode .= $wallet ."?amount=$cryptoAmount";

        return $qrUrl . '&chl=' . urlencode($strForCode) . '&choe=UTF-8';
    }
}
