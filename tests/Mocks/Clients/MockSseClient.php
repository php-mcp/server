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

class MockSseClient
{
    public Browser $browser;
    private ?ReadableStreamInterface $stream = null;
    private string $buffer = '';
    private array $receivedMessages = []; // Stores decoded JSON-RPC messages
    private array $receivedSseEvents = []; // Stores raw SSE events (type, data, id)
    public ?string $endpointUrl = null; // The /message endpoint URL provided by server
    public ?string $clientId = null; // The clientId from the /message endpoint URL

    public function __construct(int $timeout = 2)
    {
        $this->browser = (new Browser())->withTimeout($timeout);
    }

    public function connect(string $sseBaseUrl): PromiseInterface
    {
        return $this->browser->requestStreaming('GET', $sseBaseUrl)
            ->then(function (ResponseInterface $response) {
                if ($response->getStatusCode() !== 200) {
                    $body = (string) $response->getBody();
                    throw new \RuntimeException("SSE connection failed with status {$response->getStatusCode()}: {$body}");
                }
                $stream = $response->getBody();
                assert($stream instanceof ReadableStreamInterface, "SSE response body is not a readable stream");
                $this->stream = $stream;
                $this->stream->on('data', [$this, 'handleSseData']);
                $this->stream->on('close', function () {
                    $this->stream = null;
                });
                return $this;
            });
    }

    public function handleSseData(string $chunk): void
    {
        $this->buffer .= $chunk;

        while (($eventPos = strpos($this->buffer, "\n\n")) !== false) {
            $eventBlock = substr($this->buffer, 0, $eventPos);
            $this->buffer = substr($this->buffer, $eventPos + 2);

            $lines = explode("\n", $eventBlock);
            $event = ['type' => 'message', 'data' => '', 'id' => null];

            foreach ($lines as $line) {
                if (str_starts_with($line, "event:")) {
                    $event['type'] = trim(substr($line, strlen("event:")));
                } elseif (str_starts_with($line, "data:")) {
                    $event['data'] .= (empty($event['data']) ? "" : "\n") . trim(substr($line, strlen("data:")));
                } elseif (str_starts_with($line, "id:")) {
                    $event['id'] = trim(substr($line, strlen("id:")));
                }
            }
            $this->receivedSseEvents[] = $event;

            if ($event['type'] === 'endpoint' && $event['data']) {
                $this->endpointUrl = $event['data'];
                $query = parse_url($this->endpointUrl, PHP_URL_QUERY);
                if ($query) {
                    parse_str($query, $params);
                    $this->clientId = $params['clientId'] ?? null;
                }
            } elseif ($event['type'] === 'message' && $event['data']) {
                try {
                    $decodedJson = json_decode($event['data'], true, 512, JSON_THROW_ON_ERROR);
                    $this->receivedMessages[] = $decodedJson;
                } catch (\JsonException $e) {
                }
            }
        }
    }

    public function getNextMessageResponse(string $expectedRequestId, int $timeoutSecs = 2): PromiseInterface
    {
        $deferred = new Deferred();
        $startTime = microtime(true);

        $checkMessages = null;
        $checkMessages = function () use (&$checkMessages, $deferred, $expectedRequestId, $startTime, $timeoutSecs) {
            foreach ($this->receivedMessages as $i => $msg) {
                if (isset($msg['id']) && $msg['id'] === $expectedRequestId) {
                    unset($this->receivedMessages[$i]); // Consume message
                    $this->receivedMessages = array_values($this->receivedMessages);
                    $deferred->resolve($msg);
                    return;
                }
            }

            if (microtime(true) - $startTime > $timeoutSecs) {
                $deferred->reject(new \RuntimeException("Timeout waiting for SSE message with ID '{$expectedRequestId}'"));
                return;
            }

            if ($this->stream) {
                Loop::addTimer(0.05, $checkMessages);
            } else {
                $deferred->reject(new \RuntimeException("SSE Stream closed while waiting for message ID '{$expectedRequestId}'"));
            }
        };

        $checkMessages(); // Start checking
        return $deferred->promise();
    }

    public function getNextBatchMessageResponse(int $expectedItemCount, int $timeoutSecs = 2): PromiseInterface
    {
        $deferred = new Deferred();
        $startTime = microtime(true);

        $checkMessages = null;
        $checkMessages = function () use (&$checkMessages, $deferred, $expectedItemCount, $startTime, $timeoutSecs) {
            foreach ($this->receivedMessages as $i => $msg) {
                if (is_array($msg) && !isset($msg['jsonrpc']) && count($msg) === $expectedItemCount) {
                    $isLikelyBatchResponse = true;
                    if (empty($msg) && $expectedItemCount === 0) {
                    } elseif (empty($msg) && $expectedItemCount > 0) {
                        $isLikelyBatchResponse = false;
                    } else {
                        foreach ($msg as $item) {
                            if (!is_array($item) || (!isset($item['id']) && !isset($item['method']))) {
                                $isLikelyBatchResponse = false;
                                break;
                            }
                        }
                    }

                    if ($isLikelyBatchResponse) {
                        unset($this->receivedMessages[$i]);
                        $this->receivedMessages = array_values($this->receivedMessages);
                        $deferred->resolve($msg);
                        return;
                    }
                }
            }

            if (microtime(true) - $startTime > $timeoutSecs) {
                $deferred->reject(new \RuntimeException("Timeout waiting for SSE Batch Response with {$expectedItemCount} items."));
                return;
            }

            if ($this->stream) {
                Loop::addTimer(0.05, $checkMessages);
            } else {
                $deferred->reject(new \RuntimeException("SSE Stream closed while waiting for Batch Response."));
            }
        };

        $checkMessages();
        return $deferred->promise();
    }

    public function sendHttpRequest(string $requestId, string $method, array $params = []): PromiseInterface
    {
        if (!$this->endpointUrl || !$this->clientId) {
            return reject(new \LogicException("SSE Client not fully initialized (endpoint or clientId missing)."));
        }
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ];
        $body = json_encode($payload);

        return $this->browser->post($this->endpointUrl, ['Content-Type' => 'application/json'], $body)
            ->then(function (ResponseInterface $response) use ($requestId) {
                $bodyContent = (string) $response->getBody();
                if ($response->getStatusCode() !== 202) {
                    throw new \RuntimeException("HTTP POST request failed with status {$response->getStatusCode()}: {$bodyContent}");
                }
                return $response;
            });
    }

    public function sendHttpBatchRequest(array $batchRequestObjects): PromiseInterface
    {
        if (!$this->endpointUrl || !$this->clientId) {
            return reject(new \LogicException("SSE Client not fully initialized (endpoint or clientId missing)."));
        }
        $body = json_encode($batchRequestObjects);

        return $this->browser->post($this->endpointUrl, ['Content-Type' => 'application/json'], $body)
            ->then(function (ResponseInterface $response) {
                $bodyContent = (string) $response->getBody();
                if ($response->getStatusCode() !== 202) {
                    throw new \RuntimeException("HTTP BATCH POST request failed with status {$response->getStatusCode()}: {$bodyContent}");
                }
                return $response;
            });
    }

    public function sendHttpNotification(string $method, array $params = []): PromiseInterface
    {
        if (!$this->endpointUrl || !$this->clientId) {
            return reject(new \LogicException("SSE Client not fully initialized (endpoint or clientId missing)."));
        }
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];
        $body = json_encode($payload);
        return $this->browser->post($this->endpointUrl, ['Content-Type' => 'application/json'], $body)
            ->then(function (ResponseInterface $response) {
                $bodyContent = (string) $response->getBody();
                if ($response->getStatusCode() !== 202) {
                    throw new \RuntimeException("HTTP POST notification failed with status {$response->getStatusCode()}: {$bodyContent}");
                }
                return null;
            });
    }

    public function close(): void
    {
        if ($this->stream) {
            $this->stream->close();
            $this->stream = null;
        }
    }
}
