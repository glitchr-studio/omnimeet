<?php

/**
 * The gateways, their options from the environment (.env): a gateway is
 * configured only when every key under "needs" is set. "example" is what
 * the bare script and the demo use instead when it is not - nothing is
 * called with it.
 *
 * @return array<string, array{factory: string, needs: list<string>, options: array<string, mixed>, example: array<string, mixed>}>
 */
$env = static fn (string $key, mixed $default = null): mixed => (false !== ($v = getenv($key)) && '' !== $v) ? $v : $default;

return [
    'direct' => [
        'factory' => 'direct',
        'needs' => [],
        'options' => ['turn_secret' => $env('TURN_SECRET'), 'turn_urls' => $env('TURN_URLS', []), 'stun_urls' => $env('STUN_URLS', [])],
        'example' => [],
    ],
    'jitsi' => [
        'factory' => 'jitsi',
        'needs' => ['JITSI_DOMAIN'],
        'options' => [
            'domain' => $env('JITSI_DOMAIN'),
            'app_id' => $env('JITSI_APP_ID'),
            'app_secret' => $env('JITSI_APP_SECRET'),
            'private_key' => $env('JITSI_PRIVATE_KEY'),
            'key_id' => $env('JITSI_KEY_ID'),
            'webhook_secret' => $env('JAAS_WEBHOOK_SECRET'),
        ],
        'example' => ['domain' => 'meet.example.org', 'app_id' => 'example_app', 'app_secret' => 'example-secret-known-to-the-instance'],
    ],
];
