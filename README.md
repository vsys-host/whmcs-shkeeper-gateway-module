# whmcs-shkeeper-gateway-module
Shkeeper payment gateway module for WHMCS

*Module has been tested on WHMCS Version: 8.10.1*

## Installation
1. Unzip folder modules to WHMCS root directory
2. Activate the plugin through the 'System Settings->Payment Gateways->All Payment Gateways' menu and set settings.
    * Show on Order Form - Enable/Disable displaying module for checkout
    * Display Name - How customers will see module on checkout page
    * API Url - Shkeeper server api entry point. Trailing slash is mandatory. http[s]://shkeeper_server_api_hostname/api/v1/
    * API Key - Authorization and identification Shkeeper key. You can generate it in Shkeeper admin panel for any crypto wallet.
    * Round credit amount - Round down amount that will be added to client credit balance.
    * Convert To For Processing - You should choose USD here, in case if you are using multiply currencies in your billing system. Shkeeper supports only USD for the moment.