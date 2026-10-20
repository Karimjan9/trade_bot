<?php

return [
    'gateway_token' => env('TELEGRAM_GATEWAY_TOKEN'),
    'market_token' => env('MARKET_DATA_TOKEN'),
    'quote_max_age_seconds' => 30,
    'signal_max_age_seconds' => 180,
    'symbols' => [
        // USD account, USD profit currency. Verify contract values against your broker.
        'XAUUSD' => ['aliases' => ['GOLD', 'XAU/USD'], 'value_per_unit' => 100, 'volume_min' => 0.01, 'volume_step' => 0.01, 'volume_max' => 10, 'slippage' => 0.05],
        'EURUSD' => ['aliases' => ['EUR/USD'], 'value_per_unit' => 100000, 'volume_min' => 0.01, 'volume_step' => 0.01, 'volume_max' => 10, 'slippage' => 0.00001],
        'GBPUSD' => ['aliases' => ['GBP/USD'], 'value_per_unit' => 100000, 'volume_min' => 0.01, 'volume_step' => 0.01, 'volume_max' => 10, 'slippage' => 0.00001],
    ],
    'commission_per_lot_round_trip' => 7.0,
];
