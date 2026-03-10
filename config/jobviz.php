<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Jobviz API Key
    |--------------------------------------------------------------------------
    |
    | Your project's API key from the Jobviz dashboard. The agent will not
    | start if this is empty.
    |
    */
    'api_key' => env('JOBVIZ_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | The Jobviz API endpoint. Override for self-hosted or development.
    |
    */
    'endpoint' => env('JOBVIZ_ENDPOINT', 'https://app.jobviz.dev'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Defaults to Laravel's app environment. Override to tag events with a
    | custom environment name.
    |
    */
    'env' => env('JOBVIZ_ENV'),

    /*
    |--------------------------------------------------------------------------
    | Queue Filtering
    |--------------------------------------------------------------------------
    |
    | Set to an array of queue names to only monitor specific queues.
    | Set to null to monitor all queues.
    |
    */
    'queues' => null,

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    |
    | Events are batched before sending to minimize HTTP overhead.
    |
    */
    'batch_size' => 100,
    'flush_interval' => 3.0, // seconds
    'max_buffer_size' => 10000,

    /*
    |--------------------------------------------------------------------------
    | Data Capture
    |--------------------------------------------------------------------------
    */
    'capture_input' => true,
    'capture_stack_traces' => true,

    /*
    |--------------------------------------------------------------------------
    | Key Redaction
    |--------------------------------------------------------------------------
    |
    | true = redact default sensitive keys (password, secret, token, etc.)
    | false = disable redaction
    | array = merge custom keys with defaults
    |
    */
    'redact_keys' => true,

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => env('JOBVIZ_DEBUG', false),

];
