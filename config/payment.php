<?php

return [

    // channel key => gateway class implementing App\Services\Payments\PaymentGatewayInterface.
    // Adding a new payment method (e.g. a second bank, a card processor) means
    // implementing the interface and adding one line here — nothing else in
    // PaymentService changes.
    'gateways' => [
        'mpesa_stk'     => \App\Services\Payments\MpesaStkGateway::class,
        'bank_transfer' => \App\Services\Payments\BankTransferGateway::class,
    ],

    'mpesa' => [
        'env'             => env('MPESA_ENV', 'sandbox'), // sandbox | production
        'consumer_key'    => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode'       => env('MPESA_SHORTCODE'),
        'passkey'         => env('MPESA_PASSKEY'),
        'callback_url'    => env('MPESA_STK_CALLBACK_URL'),
        'base_url'        => env('MPESA_ENV', 'sandbox') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke',
    ],

    'bank' => [
        // Displayed to the payer as transfer instructions when no live bank
        // API is wired up yet — see BankTransferGateway.
        'account_name'   => env('BANK_TRANSFER_ACCOUNT_NAME'),
        'account_number' => env('BANK_TRANSFER_ACCOUNT_NUMBER'),
        'bank_name'      => env('BANK_TRANSFER_BANK_NAME'),
        'branch'         => env('BANK_TRANSFER_BRANCH'),
        'swift'          => env('BANK_TRANSFER_SWIFT'),
    ],

];
