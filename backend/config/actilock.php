<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ACTILOCK Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the ACTILOCK interlock engine integration.
    | The Python bridge (interlock_bridge.py) reads its own config from
    | environment variables, but this file provides defaults for the
    | Laravel backend (API endpoints, monitoring, admin UI).
    |
    */

    // Legacy PHP FFI path (deprecated — use Python bridge instead)
    'so_path' => env('ACTILOCK_SO_PATH', '/usr/lib/lib_actilock.so'),

    // FFI timeout (legacy, kept for backward compatibility)
    'ffi_timeout_seconds' => env('ACTILOCK_FFI_TIMEOUT', 10),

    // TCP probe timeout (used by test connection endpoint)
    'tcp_probe_timeout' => env('ACTILOCK_TCP_PROBE_TIMEOUT', 3),

    // Bridge API token (for authenticating Python bridge requests)
    'bridge_api_token' => env('ACTILOCK_BRIDGE_API_TOKEN', ''),

    // Default connection settings
    'defaults' => [
        'listen_host' => env('ACTILOCK_LISTEN_HOST', '0.0.0.0'),
        'listen_port' => env('ACTILOCK_LISTEN_PORT', 5000),
        'max_plc_connections' => env('ACTILOCK_MAX_CONNECTIONS', 50),
        'engine_host' => env('ACTILOCK_ENGINE_HOST', '192.168.1.1'),
        'engine_port' => env('ACTILOCK_ENGINE_PORT', 3129),
    ],

];
