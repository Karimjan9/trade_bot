<?php

return [
    'gateway_token' => env('TELEGRAM_GATEWAY_TOKEN'),
    'market_token' => env('MARKET_DATA_TOKEN'),
    'quote_max_age_seconds' => 30,
    'signal_max_age_seconds' => 180,
    'symbols' => [
        // USD account, USD profit currency. Verify contract values against your broker.
        'XAUUSD' => ['aliases' => ['GOLD', 'XAU/USD'], 'pip_size' => 0.1, 'value_per_unit' => 100, 'volume_min' => 0.01, 'volume_step' => 0.01, 'volume_max' => 10, 'slippage' => 0.05],
        'EURUSD' => ['aliases' => ['EUR/USD'], 'value_per_unit' => 100000, 'volume_min' => 0.01, 'volume_step' => 0.01, 'volume_max' => 10, 'slippage' => 0.00001],
        'GBPUSD' => ['aliases' => ['GBP/USD'], 'value_per_unit' => 100000, 'volume_min' => 0.01, 'volume_step' => 0.01, 'volume_max' => 10, 'slippage' => 0.00001],
    ],
    'commission_per_lot_round_trip' => 7.0,
    'entries' => ['enabled' => true, 'research_budget' => 100.0, 'max_ticks' => 20000, 'drawdown_budget_r' => 3.0],
    'strategies' => ['min_train' => 30, 'min_validation' => 10, 'min_test' => 10, 'min_exclusion_test' => 20, 'min_exclusion_days' => 5, 'exclusion_expectancy_r' => -0.05, 'min_improvement_r' => 0.05, 'max_episodes' => 200, 'max_source_rows' => 20000, 'lifetime_days' => 7],
    'arbitration' => ['collection_seconds' => 3, 'max_candidates' => 50, 'min_edge_r' => 0.1, 'adverse_penalty' => 0.15, 'holding_penalty_r_per_hour' => 0.02, 'min_history' => 20],
    'trajectory' => ['horizon_seconds' => 3600, 'checkpoints_seconds' => [300, 900, 3600], 'milestones_pips' => [50, 100, 150, 250], 'batch_size' => 100],
    'research' => ['min_train' => 20, 'min_validation' => 10, 'horizon_seconds' => 3600, 'max_quote_gap_seconds' => 30, 'max_episodes' => 500, 'policy_lifetime_days' => 7],
    'ml' => [
        'python' => env('ML_PYTHON', base_path(PHP_OS_FAMILY === 'Windows' ? 'services/ml/.venv/Scripts/python.exe' : 'services/ml/.venv/bin/python')),
        'min_train' => 60, 'min_calibration' => 20, 'min_test' => 20,
        'min_selected' => 10, 'min_class_samples' => 5,
        'min_history_minutes' => 15, 'history_minutes' => 60,
        'max_rows' => 2000, 'lifetime_days' => 7,
        'min_improvement_r_per_opportunity' => 0.01,
        'drift_window' => 30, 'drift_min_selected' => 10,
    ],
];
