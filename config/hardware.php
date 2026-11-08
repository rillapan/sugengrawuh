<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Hardware Driver
    |--------------------------------------------------------------------------
    | Options: 'none', 'mock', 'real'.
    | In G4-B1, 'none' remains default. 'real' driver requires safety guards.
    */
    'driver' => env('HARDWARE_DRIVER', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Real Hardware Execution Safety Guard (G4-B1 & G4-B2)
    |--------------------------------------------------------------------------
    | Master safety switch for real physical network execution.
    | In G4-B1, this is strictly FALSE by default. Physical socket/HTTP calls
    | are blocked unless explicitly authorized and verified in G4-B2.
    */
    'real_execution_enabled' => (bool) env('HARDWARE_REAL_EXECUTION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Real Allowed Device IDs Allowlist
    |--------------------------------------------------------------------------
    | Comma-separated list or array of physical device IDs approved for real commands.
    */
    'real_allowed_device_ids' => array_filter(explode(',', (string) env('HARDWARE_REAL_ALLOWED_DEVICES', ''))),

    /*
    |--------------------------------------------------------------------------
    | Hardware Simulation Enabled (G4-A Mock Lab)
    |--------------------------------------------------------------------------
    | Master switch for mock simulation endpoints, background state, and UI.
    | Default is false.
    */
    'simulation_enabled' => (bool) env('HARDWARE_SIMULATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | IoT Hardware Automation Master Switch (G5)
    |--------------------------------------------------------------------------
    | Master switch for transactional outbox intent generation & worker.
    | When false, billing engine runs purely in legacy mode without touching
    | G5 tables/columns. Default is false (safety first on legacy DBs).
    */
    'automation_enabled' => (bool) env('HARDWARE_AUTOMATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Network Timeouts (ms)
    |--------------------------------------------------------------------------
    | Timeout thresholds for device connection and command execution.
    */
    'timeout_ms' => (int) env('HARDWARE_TIMEOUT_MS', 3000),
    'connect_timeout_ms' => (int) env('HARDWARE_CONNECT_TIMEOUT_MS', 3000),
    'request_timeout_ms' => (int) env('HARDWARE_REQUEST_TIMEOUT_MS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Application Identity for Smart TV Pairing Handshake
    |--------------------------------------------------------------------------
    */
    'app_name' => env('HARDWARE_APP_NAME', 'Sugeng Rawuh POS'),

    /*
    |--------------------------------------------------------------------------
    | Port Allowlists by Hardware Brand
    |--------------------------------------------------------------------------
    */
    'allowed_ports' => [
        'SAMSUNG' => [8001, 8002],
        'SHELLY' => [80, 8080],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Target Ports
    |--------------------------------------------------------------------------
    */
    'default_ports' => [
        'SAMSUNG_WS' => 8001,
        'SAMSUNG_WSS' => 8002,
        'SHELLY_HTTP' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Simulation & Diagnostic Environments
    |--------------------------------------------------------------------------
    | Mock simulation and diagnostic endpoints are restricted to these environments.
    */
    'allowed_environments' => ['local', 'testing'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Simulation Databases
    |--------------------------------------------------------------------------
    | Simulator strictly blocks execution if active database is in production.
    */
    'allowed_databases' => ['kasir_ps_test'],

    /*
    |--------------------------------------------------------------------------
    | Samsung TV Alert & Control Prototype Settings (SR-TV-ALERT-POC-001)
    |--------------------------------------------------------------------------
    */
    'tv_control' => [
        'default_volume' => 30,
        'volume_step' => 5,
        'alert_threshold_minutes' => 10,
        'rate_limit_ms' => 400,
        'simulator_token' => env('HARDWARE_TV_SIMULATOR_TOKEN', null),
    ],
];
