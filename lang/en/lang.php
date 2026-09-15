<?php return [
    'plugin' => [
        'name'        => 'Paysera for Shopaholic',
        'description' => 'Paysera (WebToPay) payment gateway for Lovata OrdersShopaholic.',
    ],
    'field' => [
        'project_id'         => 'Paysera project ID',
        'project_id_comment' => 'Numeric project ID from the Paysera project settings.',
        'password'           => 'Paysera sign password',
        'password_comment'   => 'The project password used to sign requests and verify callbacks.',
        'test_mode'          => 'Test mode',
        'test_mode_comment'  => 'Sends test=1. Paysera only accepts test payments while the project has test mode enabled.',
        'country'            => 'Payer country',
        'country_comment'    => 'Preselects the payment method list on the Paysera side.',
        'country_any'        => 'Let Paysera detect',
    ],
    'hint' => [
        'callback_title' => 'Callback URL',
        'callback_text'  => 'Paysera calls this URL after every payment. It is sent automatically with each request, no project setting is needed. Keep it reachable from the internet.',
    ],
];
