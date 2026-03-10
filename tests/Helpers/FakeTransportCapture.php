<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Jobviz\Agent\HttpTransport;
use Jobviz\Agent\JobEvent;
use ReflectionClass;

/**
 * Helper to create an HttpTransport with a mock Guzzle client
 * that always returns 200 and captures sent events.
 */
final class FakeTransportCapture
{
    /** @var JobEvent[][] */
    public array $sentBatches = [];

    public HttpTransport $transport;

    public function __construct()
    {
        // Create a transport that always succeeds — we'll intercept via reflection
        $this->transport = new HttpTransport(
            endpoint: 'https://app.jobviz.dev',
            apiKey: 'test-key-long-enough',
        );

        // Replace the client with a mock
        $mock = new MockHandler([]);
        // Add enough responses
        for ($i = 0; $i < 1000; $i++) {
            $mock->append(new Response(200, [], '{"accepted":1}'));
        }

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $ref = new ReflectionClass($this->transport);
        $prop = $ref->getProperty('client');
        $prop->setValue($this->transport, $client);
    }
}
