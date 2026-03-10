<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Jobviz\Agent\EventType;
use Jobviz\Agent\HttpTransport;
use Jobviz\Agent\JobEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class HttpTransportTest extends TestCase
{
    private function makeEvent(string $jobId = 'job-1'): JobEvent
    {
        return new JobEvent(
            jobId: $jobId,
            jobName: 'TestJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
        );
    }

    private function createTransportWithMock(
        MockHandler $mock,
        array &$history = [],
        ?string $apiKey = 'test-api-key-1234',
        ?Closure $onError = null,
        ?string $agentVersion = '0.1.0',
    ): HttpTransport {
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $client = new Client(['handler' => $handlerStack]);

        // Use reflection to inject the mock client
        $transport = new HttpTransport(
            endpoint: 'https://app.jobviz.dev',
            apiKey: $apiKey,
            onError: $onError,
            agentVersion: $agentVersion,
        );

        $ref = new ReflectionClass($transport);
        $clientProp = $ref->getProperty('client');
        $clientProp->setValue($transport, $client);

        return $transport;
    }

    #[Test]
    public function sends_events_with_correct_headers(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{"accepted":1}')]);
        $transport = $this->createTransportWithMock($mock, $history);

        $transport->send([$this->makeEvent()]);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/events', $request->getUri()->getPath());
        $this->assertSame('Bearer test-api-key-1234', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('0.1.0', $request->getHeaderLine('X-Jobviz-Agent-Version'));
    }

    #[Test]
    public function sends_correct_json_payload(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200)]);
        $transport = $this->createTransportWithMock($mock, $history);

        $event = $this->makeEvent('job-42');
        $transport->send([$event]);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('events', $body);
        $this->assertCount(1, $body['events']);
        $this->assertSame('job-42', $body['events'][0]['jobId']);
        $this->assertSame('TestJob', $body['events'][0]['jobName']);
        $this->assertSame('default', $body['events'][0]['queue']);
        $this->assertSame('active', $body['events'][0]['event']);
    }

    #[Test]
    public function omits_version_header_when_null(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200)]);
        $transport = $this->createTransportWithMock($mock, $history, agentVersion: null);

        $transport->send([$this->makeEvent()]);

        $this->assertFalse($history[0]['request']->hasHeader('X-Jobviz-Agent-Version'));
    }

    #[Test]
    public function reports_error_on_4xx_without_retrying(): void
    {
        $errors = [];
        $mock = new MockHandler([
            new Response(422, [], 'Validation failed'),
        ]);
        $transport = $this->createTransportWithMock(
            $mock,
            onError: function (Throwable $e, int $dropped) use (&$errors) {
                $errors[] = ['message' => $e->getMessage(), 'dropped' => $dropped];
            },
        );

        $transport->send([$this->makeEvent()]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('422', $errors[0]['message']);
        $this->assertSame(1, $errors[0]['dropped']);
    }

    #[Test]
    public function does_not_report_error_on_success(): void
    {
        $errors = [];
        $mock = new MockHandler([new Response(200)]);
        $transport = $this->createTransportWithMock(
            $mock,
            onError: function (Throwable $e, int $dropped) use (&$errors) {
                $errors[] = $e;
            },
        );

        $transport->send([$this->makeEvent()]);

        $this->assertEmpty($errors);
    }

    #[Test]
    public function chunks_large_batches_into_500_event_chunks(): void
    {
        $history = [];
        $responses = array_fill(0, 3, new Response(200));
        $mock = new MockHandler($responses);
        $transport = $this->createTransportWithMock($mock, $history);

        // Send 1200 events — should be chunked into 3 requests (500 + 500 + 200)
        $events = [];
        for ($i = 0; $i < 1200; $i++) {
            $events[] = $this->makeEvent("job-{$i}");
        }

        $transport->send($events);

        $this->assertCount(3, $history);

        $chunk1 = json_decode((string) $history[0]['request']->getBody(), true);
        $chunk2 = json_decode((string) $history[1]['request']->getBody(), true);
        $chunk3 = json_decode((string) $history[2]['request']->getBody(), true);

        $this->assertCount(500, $chunk1['events']);
        $this->assertCount(500, $chunk2['events']);
        $this->assertCount(200, $chunk3['events']);
    }

    #[Test]
    public function reports_oversized_payload_error(): void
    {
        $errors = [];
        $mock = new MockHandler([]); // Should never be called
        $transport = $this->createTransportWithMock(
            $mock,
            onError: function (Throwable $e, int $dropped) use (&$errors) {
                $errors[] = $e->getMessage();
            },
        );

        // Create an event with huge data that exceeds 5 MB
        $event = new JobEvent(
            jobId: 'job-1',
            jobName: 'HugeJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
            data: ['payload' => str_repeat('x', 5 * 1024 * 1024)],
        );

        $transport->send([$event]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('5 MB', $errors[0]);
    }

    #[Test]
    public function silently_ignores_errors_when_no_onError_callback(): void
    {
        $mock = new MockHandler([new Response(500, [], 'Server Error')]);
        // Provide enough responses for all retry attempts
        for ($i = 0; $i < 3; $i++) {
            $mock->append(new Response(500, [], 'Server Error'));
        }
        $transport = $this->createTransportWithMock($mock, onError: null);

        // Should not throw
        $transport->send([$this->makeEvent()]);

        $this->assertTrue(true); // If we reach here, no exception was thrown
    }

    #[Test]
    public function handles_empty_event_array(): void
    {
        $history = [];
        $mock = new MockHandler([]);
        $transport = $this->createTransportWithMock($mock, $history);

        $transport->send([]);

        // No requests should be made for empty array
        $this->assertEmpty($history);
    }
}
