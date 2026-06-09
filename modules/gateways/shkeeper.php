<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use WHMCS\Database\Capsule;
use Carbon\CarbonInterval;
use WHMCS\Billing\Payment\Transaction\Information;

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
    if (!Capsule::schema()->hasTable('shkeeper_user_crypto')) {
        Capsule::schema()->create(
            'shkeeper_user_crypto',
            function ($table) {
                $table->integer('user_id');
                $table->string('crypto');
                $table->integer('invoice_id');
                $table->unique('invoice_id');
            }
        );
    } else {
        if (!Capsule::schema()->hasColumn('shkeeper_user_crypto', 'invoice_id')) {
            Capsule::schema()->table(
                'shkeeper_user_crypto',
                function ($table) {
                    $table->integer('invoice_id');
                    $table->dropUnique('shkeeper_user_crypto_user_id_unique');
                }
            );
        }
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
 * @return string
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 */
function shkeeper_link($params)
{
    $isAutoGenerate = function () {
        $autogenerateVars = [
            'generateinvoices',
            'publishInvoice'
        ];
        foreach ($autogenerateVars as $var) {
            if (isset($_REQUEST[$var]) && $_REQUEST[$var] == true) {
                return true;
            }
        }
        return false;
    };

    try {
        $shkeeperApi = new ShkeeperAPI($params);

        $crypto = '';
        if ($isAutoGenerate()) {
            $crypto = Capsule::table('shkeeper_user_crypto')
                ->where('user_id', $params['clientdetails']['userid'])
                ->orderBy('invoice_id', 'desc')
                ->value('crypto');
        }

        $post_crypto = isset($_POST['crypto']) && is_string($_POST['crypto']) ? trim($_POST['crypto']) : '';
        if ($post_crypto !== '') {
            $crypto = $post_crypto;
        } else {
            if ($db_crypto = Capsule::table('shkeeper_user_crypto')->where('invoice_id', $params['invoiceid'])->value('crypto')) {
                $crypto = $db_crypto;
            }
        }

        if ($crypto && !preg_match('/^[A-Za-z0-9._-]{1,32}$/', $crypto)) {
            throw new Exception('Bad crypto provided');
        }

        if (!$crypto) {
            return shkeeper_RenderForm($shkeeperApi->getCryproList());
        }

        if ($post_crypto) {
            Capsule::table('shkeeper_user_crypto')
                ->updateOrInsert(
                    [
                        'invoice_id' => $params['invoiceid'],
                    ],
                    [
                        'crypto' => $crypto,
                        'user_id' => $params['clientdetails']['userid'],
                    ]
                );
        }

        $paymentRequest = $shkeeperApi->sendPaymentRequest($crypto);

        //Chosen crypto unavailable or disabled for some reason
        if (!$paymentRequest) {
            $deleted = Capsule::table('shkeeper_user_crypto')
                ->where('invoice_id', $params['invoiceid'])
                ->delete();
            if (!$deleted) {
                throw new Exception('Exception to prevent cycle loop. Can\'t delete stored crypto.');
            }

            unset($_POST['crypto']);
            return shkeeper_link($params);
        }
        $qrImg = ShkeeperAPI::getQrImg($paymentRequest->wallet, $paymentRequest->amount, $crypto);

        $walletHtml      = htmlspecialchars((string) $paymentRequest->wallet, ENT_QUOTES, 'UTF-8');
        $amountHtml      = htmlspecialchars((string) $paymentRequest->amount, ENT_QUOTES, 'UTF-8');
        $displayNameHtml = htmlspecialchars(strtoupper((string) $paymentRequest->display_name), ENT_QUOTES, 'UTF-8');

        $html = "<style>
    .invoice-address-wrapper {
      background: #f7f7f9;
      border: 1px solid #e2e2e5;
      padding: 12px 14px;
      border-radius: 6px;
      margin: 8px 0 12px;
      font-family: monospace;
      font-size: 14px;
      word-break: break-all;
      white-space: normal;
    }

    .invoice-address {
      text-align: left;
      word-break: break-all;
    }

    .invoice-copy-container {
      text-align: right;
    }

    .shk-copy-btn {
      align-self: end;
    }
    </style>

<script>
document.addEventListener('click', function(e) {
  if (e.target.matches('.shk-copy-btn')) {
    const text = e.target.dataset.copy;
    navigator.clipboard.writeText(text).then(() => {
      e.target.innerText = 'Copied!';
      setTimeout(() => e.target.innerText = 'Copy', 1200);
    });
  }
});
</script>";

        $html .= '<div>' . $qrImg;
        $html .= '<div class="invoice-thumb">';
        $html .= "<div></br>Send <b>$amountHtml</b> " . $displayNameHtml . " to wallet: \n</div>";

        $html .= "<div class='invoice-address-wrapper'>
                <div class='invoice-address'>$walletHtml</div>
              </div>
              <div class='invoice-copy-container'><button class='btn btn-success shk-copy-btn' data-copy='$walletHtml'>Copy</button></div>";

        if ($paymentRequest->recalculate_after) {
            $html .= "<i>Amount valid for " . CarbonInterval::hours($paymentRequest->recalculate_after)->cascade()->forHumans() . "</i>";
        }
        $html .= '</div></div>';
        $html .= shkeeper_RenderForm($shkeeperApi->getCryproList(), $crypto);
        return $html;
    } catch (Exception $e) {
        logActivity('[' . basename(__FILE__, '.php') . "] Payment gateway error with Invoice ID: {$params['invoiceid']} " . $e->getMessage());
        return 'Shkeeper connection error. Please try later, or choose another payment method';
    }
}

function shkeeper_RenderForm(array $cryptos = [], $selected_crypto = null)
{
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
    if ($selected_crypto) {
        echo '<hr /><h3 class="invoice-subtitle">Or select another crypto</h3>';
    } else {
        echo '<h3 class="invoice-subtitle">Choose crypto currency</h3>';
    }
    echo '<div class="invoice-box">';
    echo '<div>';
    echo '<select id="shkeeper_crypto" class="form-control invoice-select" name="crypto">';
    foreach ($cryptos as $crypto) {
        if ($crypto->name == $selected_crypto) continue;
        $cryptoName        = htmlspecialchars((string) $crypto->name, ENT_QUOTES, 'UTF-8');
        $cryptoDisplayName = htmlspecialchars((string) $crypto->display_name, ENT_QUOTES, 'UTF-8');
        echo "<option value='{$cryptoName}'>{$cryptoDisplayName}</option>";
    }
    echo '</select>';
    echo '</div>';
    echo '<div>';
    echo '<input type="submit" class="btn btn-success" name="sendrequest" value="Get address" />';
    echo '</div>';
    echo '</div>';
    echo '</form>';

    return ob_get_clean();
}

function shkeeper_TransactionInformation(array $params = []): Information
{
    if (preg_match('#/admin/billing/transaction/(\d+)/information#', $_SERVER['REQUEST_URI'], $matches)) {
        $transaction_id = $matches[1];
    } else {
        return new Information();
    }
    $tx = \WHMCS\Billing\Payment\Transaction::find($transaction_id);
    $txid = $tx->transid;
    $external_id = $tx->invoiceid;

    $info = (new ShkeeperAPI($params))->get_tx_info($txid, $external_id);

    $js = <<<EOF
<script>
$('.modal-dialog').css('width', '900px');
$('.modal-dialog .row div').css('overflow', 'auto');
</script>
EOF;

    $addrHtml   = htmlspecialchars((string) $info->addr, ENT_QUOTES, 'UTF-8');
    $cryptoHtml = htmlspecialchars((string) $info->crypto, ENT_QUOTES, 'UTF-8');

    return (new Information())
        ->setTransactionId($txid)
        ->setAmount($info->amount)
        ->setFee($tx->amountin > 0 && $info->amount > $tx->amountin ? $info->amount - $tx->amountin : 0)
        ->setAdditionalDatum('Our addr', $addrHtml . $js)
        ->setAdditionalDatum('Crypto', $cryptoHtml)
        ;
}

class ShkeeperAPI
{

    private $apiUrl;
    private $apiKey;
    private $whmcsParams;

    public function __construct($params)
    {
        $this->initSettings($params);
    }

    private function initSettings($params)
    {
        $this->apiUrl = $params['apiUrl'];
        $this->apiKey = $params['apiKey'];
        $this->whmcsParams = $params;
    }

    public function getCryproList()
    {
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
            []
        );

        throw new Exception($cryptos->message ?? 'Can not get available crypto from Shkeeper server');
    }

    public function sendPaymentRequest($crypto = 'BTC')
    {
        $paymentRequestData = [
            'external_id'  => $this->whmcsParams['invoiceid'],
            'fiat'         => $this->whmcsParams['currency'],
            'amount'       => $this->whmcsParams['amount'],
            'callback_url' => $this->whmcsParams['systemurl'] . 'modules/gateways/callback/' . basename(__FILE__),
        ];
        $endpoint = strtoupper($crypto) . "/payment_request";
        $method = 'POST';
        $paymentRequest = $this->request($endpoint, $method, json_encode($paymentRequestData, JSON_UNESCAPED_SLASHES));

        if ($paymentRequest->status == 'success') {
            return $paymentRequest;
        } elseif (stripos($paymentRequest->message, 'payment gateway is unavailable') !== false) {
            return false;
        }

        logModuleCall(
            basename(__FILE__, '.php'),
            "$method $endpoint",
            $paymentRequestData,
            $paymentRequest,
            $paymentRequest,
            []
        );

        throw new Exception($paymentRequest->message ?? 'Can not create payment request in Shkeeper server');
    }

    public function get_tx_info($txid, $external_id)
    {
        $res = $this->request("tx-info/$txid/$external_id");

        if ($res->info) {
            return $res->info;
        }

        logModuleCall(
            basename(__FILE__, '.php'),
            "get_tx_info $txid $external_id",
            [],
            $res,
            $res,
            []
        );

        throw new Exception($res->info ?? "Can not get tx info for txid=$txid external_id=$external_id");
    }


    public function request($endpoint, $method = 'GET', $data = [], $includeHeaders = false)
    {
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
            $options['timeout'] = 40;
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
                []
            );
            throw new Exception($e);
        }

        if ($response->getStatusCode() == 500) {
            logModuleCall(
                basename(__FILE__, '.php'),
                "$method $endpoint",
                $data,
                $response->getBody()->getContents(),
                $response->getBody()->getContents(),
                []
            );
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
                []
            );
            throw new Exception('Invalid response from Shkeeper server!');
        }
        if ($includeHeaders) {
            return [
                'headers' => $response->getHeaders(),
                'body'    => $jsonBodyObj,
            ];
        }

        return $jsonBodyObj;
    }

    public static function getQrImg($wallet, $cryptoAmount, $cryptoCurrency, $qrSize = '200x200')
    {

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
        $strForCode .= $wallet . "?amount=$cryptoAmount";

        if (class_exists('BaconQrCode\Renderer\ImageRenderer')) {
            $renderer = new BaconQrCode\Renderer\ImageRenderer(
                new BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
                new BaconQrCode\Renderer\Image\SvgImageBackEnd()
            );
            $writer = new BaconQrCode\Writer($renderer);
            $qrImage = $writer->writeString($strForCode);

            #WHMCS < 8.9 compatibility
        } else {
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=$qrSize";
            $qrSrc = $qrUrl . '&data=' . urlencode($strForCode);;
            $qrImage = "<img src='$qrSrc' title='QR code' />";
        }

        return $qrImage;
    }
}
