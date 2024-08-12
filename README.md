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
  
![image](https://github.com/user-attachments/assets/d4981e5c-a6d2-45e5-a474-0d46cf9db739)
![image1](https://github.com/user-attachments/assets/b9ee7677-1b0a-4eec-875f-fa4839d74d29)
![image2](https://github.com/user-attachments/assets/5f28d12f-4134-4ae6-bb6d-5e5e531aca1d)
![image3](https://github.com/user-attachments/assets/5a39fb55-f212-4f91-a6d9-0044fddd16cb)
![image4](https://github.com/user-attachments/assets/8e18aca3-b993-42b1-8377-63a8c161f60e)
![image5](https://github.com/user-attachments/assets/091f9feb-08d1-4513-b47d-7d482446e205)
