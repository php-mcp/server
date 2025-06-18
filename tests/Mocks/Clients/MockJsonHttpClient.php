<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Mocks\Clients;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

class MockJsonHttpClient
{
    public Browser $browser;
    public string $baseUrl;
    public ?string $sessionId = null;

    public function __construct(string $host, int $port, string $mcpPath, int $timeout = 2)
    {
        $this->browser = (new Browser())->withTimeout($timeout);
        $this->baseUrl = "http://{$host}:{$port}/{$mcpPath}";
    }

    public function sendRequest(string $method, array $params = [], ?string $id = null): PromiseInterface
    {
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if ($this->sessionId && $method !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        $body = json_encode($payload);

        return $this->browser->post($this->baseUrl, $headers, $body)
            ->then(function (ResponseInterface $response) use ($method) {
                $bodyContent = (string) $response->getBody()->getContents();
                $statusCode = $response->getStatusCode();

                if ($method === 'initialize' && $statusCode === 200) {
                    $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
                }

                if ($statusCode === 202) {
                    if ($bodyContent !== '') {
                        throw new \RuntimeException("Expected empty body for 202 response, got: {$bodyContent}");
                    }
                    return ['statusCode' => $statusCode, 'body' => null, 'headers' => $response->getHeaders()];
                }

                try {
                    $decoded = json_decode($bodyContent, true, 512, JSON_THROW_ON_ERROR);
                    return ['statusCode' => $statusCode, 'body' => $decoded, 'headers' => $response->getHeaders()];
                } catch (\JsonException $e) {
                    throw new \RuntimeException("Failed to decode JSON response body: {$bodyContent} Error: {$e->getMessage()}", $statusCode, $e);
                }
            });
    }

    public function sendBatchRequest(array $batchRequestObjects): PromiseInterface
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }
        $body = json_encode($batchRequestObjects);

        return $this->browser->post($this->baseUrl, $headers, $body)
            ->then(function (ResponseInterface $response) {
                $bodyContent = (string) $response->getBody()->getContents();
                $statusCode = $response->getStatusCode();
                if ($statusCode === 202) {
                    if ($bodyContent !== '') {
                        throw new \RuntimeException("Expected empty body for 202 response, got: {$bodyContent}");
                    }
                    return ['statusCode' => $statusCode, 'body' => null, 'headers' => $response->getHeaders()];
                }

                try {
                    $decoded = json_decode($bodyContent, true, 512, JSON_THROW_ON_ERROR);
                    return ['statusCode' => $statusCode, 'body' => $decoded, 'headers' => $response->getHeaders()];
                } catch (\JsonException $e) {
                    throw new \RuntimeException("Failed to decode JSON response body: {$bodyContent} Error: {$e->getMessage()}", $statusCode, $e);
                }
            });
    }

    public function sendDeleteRequest(): PromiseInterface
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return $this->browser->delete($this->baseUrl, $headers)
            ->then(function (ResponseInterface $response) {
                $bodyContent = (string) $response->getBody()->getContents();
                $statusCode = $response->getStatusCode();
                return ['statusCode' => $statusCode, 'body' => $bodyContent, 'headers' => $response->getHeaders()];
            });
    }

    public function sendNotification(string $method, array $params = []): PromiseInterface
    {
        return $this->sendRequest($method, $params, null);
    }

    public function connectSseForNotifications(): PromiseInterface
    {
        return resolve(null);
    }
}
