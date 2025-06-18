<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Mocks\Clients;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

use function React\Promise\reject;

class MockStreamHttpClient
{
    public Browser $browser;
    public string $baseMcpUrl;
    public ?string $sessionId = null;

    private ?ReadableStreamInterface $mainSseGetStream = null;
    private string $mainSseGetBuffer = '';
    private array $mainSseReceivedNotifications = [];

    public function __construct(string $host, int $port, string $mcpPath, int $timeout = 2)
    {
        $this->browser = (new Browser())->withTimeout($timeout);
        $this->baseMcpUrl = "http://{$host}:{$port}/{$mcpPath}";
    }

    public function connectMainSseStream(): PromiseInterface
    {
        if (!$this->sessionId) {
            return reject(new \LogicException("Cannot connect main SSE stream without a session ID. Initialize first."));
        }

        return $this->browser->requestStreaming('GET', $this->baseMcpUrl, [
            'Accept' => 'text/event-stream',
            'Mcp-Session-Id' => $this->sessionId
        ])
            ->then(function (ResponseInterface $response) {
                if ($response->getStatusCode() !== 200) {
                    $body = (string) $response->getBody();
                    throw new \RuntimeException("Main SSE GET connection failed with status {$response->getStatusCode()}: {$body}");
                }
                $stream = $response->getBody();
                assert($stream instanceof ReadableStreamInterface);
                $this->mainSseGetStream = $stream;

                $this->mainSseGetStream->on('data', function ($chunk) {
                    $this->mainSseGetBuffer .= $chunk;
                    $this->processBufferForNotifications($this->mainSseGetBuffer, $this->mainSseReceivedNotifications);
                });
                return $this;
            });
    }

    private function processBufferForNotifications(string &$buffer, array &$targetArray): void
    {
        while (($eventPos = strpos($buffer, "\n\n")) !== false) {
            $eventBlock = substr($buffer, 0, $eventPos);
            $buffer = substr($buffer, $eventPos + 2);
            $lines = explode("\n", $eventBlock);
            $eventData = '';
            foreach ($lines as $line) {
                if (str_starts_with($line, "data:")) {
                    $eventData .= (empty($eventData) ? "" : "\n") . trim(substr($line, strlen("data:")));
                }
            }
            if (!empty($eventData)) {
                try {
                    $decodedJson = json_decode($eventData, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($decodedJson['method']) && str_starts_with($decodedJson['method'], 'notifications/')) {
                        $targetArray[] = $decodedJson;
                    }
                } catch (\JsonException $e) { /* ignore non-json data lines or log */
                }
            }
        }
    }


    public function sendInitializeRequest(array $params, string $id = 'init-stream-1'): PromiseInterface
    {
        $payload = ['jsonrpc' => '2.0', 'method' => 'initialize', 'params' => $params, 'id' => $id];
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'];
        $body = json_encode($payload);

        return $this->browser->requestStreaming('POST', $this->baseMcpUrl, $headers, $body)
            ->then(function (ResponseInterface $response) use ($id) {
                $statusCode = $response->getStatusCode();

                if ($statusCode !== 200 || !str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
                    throw new \RuntimeException("Initialize POST failed or did not return SSE stream. Status: {$statusCode}");
                }

                $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');

                $stream = $response->getBody();
                assert($stream instanceof ReadableStreamInterface);
                return $this->collectSingleSseResponse($stream, $id, "Initialize");
            });
    }

    public function sendRequest(string $method, array $params, string $id): PromiseInterface
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => $id];
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'];
        if ($this->sessionId) $headers['Mcp-Session-Id'] = $this->sessionId;

        $body = json_encode($payload);

        return $this->browser->requestStreaming('POST', $this->baseMcpUrl, $headers, $body)
            ->then(function (ResponseInterface $response) use ($id, $method) {
                $statusCode = $response->getStatusCode();

                if ($statusCode !== 200 || !str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
                    $bodyContent = (string) $response->getBody();
                    throw new \RuntimeException("Request '{$method}' (ID: {$id}) POST failed or did not return SSE stream. Status: {$statusCode}, Body: {$bodyContent}");
                }

                $stream = $response->getBody();
                assert($stream instanceof ReadableStreamInterface);
                return $this->collectSingleSseResponse($stream, $id, $method);
            });
    }

    public function sendBatchRequest(array $batchPayload): PromiseInterface
    {
        if (!$this->sessionId) {
            return reject(new \LogicException("Session ID not set. Initialize first for batch request."));
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream', 'Mcp-Session-Id' => $this->sessionId];
        $body = json_encode($batchPayload);

        return $this->browser->requestStreaming('POST', $this->baseMcpUrl, $headers, $body)
            ->then(function (ResponseInterface $response) {
                $statusCode = $response->getStatusCode();

                if ($statusCode !== 200 || !str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
                    throw new \RuntimeException("Batch POST failed or did not return SSE stream. Status: {$statusCode}");
                }

                $stream = $response->getBody();
                assert($stream instanceof ReadableStreamInterface);
                return $this->collectSingleSseResponse($stream, null, "Batch", true);
            });
    }

    private function collectSingleSseResponse(ReadableStreamInterface $stream, ?string $expectedRequestId, string $contextHint, bool $expectBatchArray = false): PromiseInterface
    {
        $deferred = new Deferred();
        $buffer = '';
        $streamClosed = false;

        $dataListener = function ($chunk) use (&$buffer, $deferred, $expectedRequestId, $expectBatchArray, $contextHint, &$streamClosed, &$dataListener, $stream) {
            if ($streamClosed) return;
            $buffer .= $chunk;

            if (str_contains($buffer, "event: message\n")) {
                if (preg_match('/data: (.*)\n\n/s', $buffer, $matches)) {
                    $jsonData = trim($matches[1]);

                    try {
                        $decoded = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
                        $isValid = false;
                        if ($expectBatchArray) {
                            $isValid = is_array($decoded) && !isset($decoded['jsonrpc']);
                        } else {
                            $isValid = isset($decoded['id']) && $decoded['id'] === $expectedRequestId;
                        }

                        if ($isValid) {
                            $deferred->resolve($decoded);
                            $stream->removeListener('data', $dataListener);
                            $stream->close();
                            return;
                        }
                    } catch (\JsonException $e) {
                        $deferred->reject(new \RuntimeException("SSE JSON decode failed for {$contextHint}: {$jsonData}", 0, $e));
                        $stream->removeListener('data', $dataListener);
                        $stream->close();
                        return;
                    }
                }
            }
        };

        $stream->on('data', $dataListener);
        $stream->on('close', function () use ($deferred, $contextHint, &$streamClosed) {
            $streamClosed = true;
            $deferred->reject(new \RuntimeException("SSE stream for {$contextHint} closed before expected response was received."));
        });
        $stream->on('error', function ($err) use ($deferred, $contextHint, &$streamClosed) {
            $streamClosed = true;
            $deferred->reject(new \RuntimeException("SSE stream error for {$contextHint}.", 0, $err instanceof \Throwable ? $err : null));
        });

        return timeout($deferred->promise(), 2, Loop::get())
            ->finally(function () use ($stream, $dataListener) {
                if ($stream->isReadable()) {
                    $stream->removeListener('data', $dataListener);
                }
            });
    }

    public function sendHttpNotification(string $method, array $params = []): PromiseInterface
    {
        if (!$this->sessionId) {
            return reject(new \LogicException("Session ID not set for notification. Initialize first."));
        }
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Mcp-Session-Id' => $this->sessionId];
        $body = json_encode($payload);

        return $this->browser->post($this->baseMcpUrl, $headers, $body)
            ->then(function (ResponseInterface $response) {
                $statusCode = $response->getStatusCode();

                if ($statusCode !== 202) {
                    throw new \RuntimeException("POST Notification failed with status {$statusCode}: " . (string)$response->getBody());
                }

                return ['statusCode' => $statusCode, 'body' => null];
            });
    }

    public function sendDeleteRequest(): PromiseInterface
    {
        if (!$this->sessionId) {
            return reject(new \LogicException("Session ID not set for DELETE request. Initialize first."));
        }

        $headers = ['Mcp-Session-Id' => $this->sessionId];

        return $this->browser->request('DELETE', $this->baseMcpUrl, $headers)
            ->then(function (ResponseInterface $response) {
                $statusCode = $response->getStatusCode();
                return ['statusCode' => $statusCode, 'body' => (string)$response->getBody()];
            });
    }

    public function closeMainSseStream(): void
    {
        if ($this->mainSseGetStream) {
            $this->mainSseGetStream->close();
            $this->mainSseGetStream = null;
        }
    }
}
