<?php

use PhpMcp\Server\Protocol;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use PhpMcp\Server\Tests\Mocks\Clients\MockSseClient;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Http\Message\Uri;
use React\Stream\ReadableStreamInterface;

use function React\Async\await;

const HTTP_SERVER_SCRIPT_PATH = __DIR__ . '/../Fixtures/ServerScripts/HttpTestServer.php';
const HTTP_PROCESS_TIMEOUT_SECONDS = 8;
const HTTP_SERVER_HOST = '127.0.0.1';
const HTTP_MCP_PATH_PREFIX = 'mcp_http_integration';

beforeEach(function () {
    $this->loop = Loop::get();
    $this->port = findFreePort();

    if (!is_file(HTTP_SERVER_SCRIPT_PATH)) {
        $this->markTestSkipped("Server script not found: " . HTTP_SERVER_SCRIPT_PATH);
    }
    if (!is_executable(HTTP_SERVER_SCRIPT_PATH)) {
        chmod(HTTP_SERVER_SCRIPT_PATH, 0755);
    }

    $phpPath = PHP_BINARY ?: 'php';
    $commandPhpPath = str_contains($phpPath, ' ') ? '"' . $phpPath . '"' : $phpPath;
    $commandArgs = [
        escapeshellarg(HTTP_SERVER_HOST),
        escapeshellarg((string)$this->port),
        escapeshellarg(HTTP_MCP_PATH_PREFIX)
    ];
    $commandScriptPath = escapeshellarg(HTTP_SERVER_SCRIPT_PATH);
    $command = $commandPhpPath . ' ' . $commandScriptPath . ' ' . implode(' ', $commandArgs);

    $this->process = new Process($command, getcwd() ?: null, null, []);
    $this->process->start($this->loop);

    $this->processErrorOutput = '';
    if ($this->process->stderr instanceof ReadableStreamInterface) {
        $this->process->stderr->on('data', function ($chunk) {
            $this->processErrorOutput .= $chunk;
        });
    }

    return await(delay(0.2, $this->loop));
});

afterEach(function () {
    if ($this->sseClient ?? null) {
        $this->sseClient->close();
    }

    if ($this->process instanceof Process && $this->process->isRunning()) {
        if ($this->process->stdout instanceof ReadableStreamInterface) {
            $this->process->stdout->close();
        }
        if ($this->process->stderr instanceof ReadableStreamInterface) {
            $this->process->stderr->close();
        }

        $this->process->terminate(SIGTERM);
        try {
            await(delay(0.02, $this->loop));
        } catch (\Throwable $e) {
        }

        if ($this->process->isRunning()) {
            $this->process->terminate(SIGKILL);
        }
    }
    $this->process = null;
});

afterAll(function () {
    // Loop::stop();
});

it('starts the http server, initializes, calls a tool, and closes', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";

    // 1. Connect
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));
    expect($this->sseClient->endpointUrl)->toBeString();
    expect($this->sseClient->clientId)->toBeString();

    // 2. Initialize Request
    await($this->sseClient->sendHttpRequest('init-http-1', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'HttpPestClient', 'version' => '1.0'],
        'capabilities' => []
    ]));
    $initResponse = await($this->sseClient->getNextMessageResponse('init-http-1'));

    expect($initResponse['id'])->toBe('init-http-1');
    expect($initResponse)->not->toHaveKey('error');
    expect($initResponse['result']['protocolVersion'])->toBe(Protocol::LATEST_PROTOCOL_VERSION);
    expect($initResponse['result']['serverInfo']['name'])->toBe('HttpIntegrationTestServer');

    // 3. Initialized Notification
    await($this->sseClient->sendHttpNotification('notifications/initialized', ['messageQueueSupported' => true]));
    await(delay(0.05, $this->loop));

    // 4. Call a tool
    await($this->sseClient->sendHttpRequest('tool-http-1', 'tools/call', [
        'name' => 'greet_http_tool',
        'arguments' => ['name' => 'HTTP Integration User']
    ]));
    $toolResponse = await($this->sseClient->getNextMessageResponse('tool-http-1'));

    expect($toolResponse['id'])->toBe('tool-http-1');
    expect($toolResponse)->not->toHaveKey('error');
    expect($toolResponse['result']['content'][0]['text'])->toBe('Hello, HTTP Integration User!');
    expect($toolResponse['result']['isError'])->toBeFalse();

    // 5. Close
    $this->sseClient->close();
})->group('integration', 'http_transport');

it('can handle invalid JSON from client', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";

    // 1. Connect
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));

    expect($this->sseClient->endpointUrl)->toBeString();

    $malformedJson = '{"jsonrpc":"2.0", "id": "bad-json-1", "method": "tools/list", "params": {"broken"}';

    // 2. Send invalid JSON
    $postPromise = $this->sseClient->browser->post(
        $this->sseClient->endpointUrl,
        ['Content-Type' => 'application/json'],
        $malformedJson
    );

    // 3. Expect error response
    try {
        await(timeout($postPromise, HTTP_PROCESS_TIMEOUT_SECONDS - 2, $this->loop));
    } catch (ResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(400);

        $errorResponse = json_decode($e->getResponse()->getBody(), true);
        expect($errorResponse['jsonrpc'])->toBe('2.0');
        expect($errorResponse['id'])->toBe('');
        expect($errorResponse['error']['code'])->toBe(-32700);
        expect($errorResponse['error']['message'])->toContain('Invalid JSON-RPC message');
    }
})->group('integration', 'http_transport');

it('can handle request for non-existent method after initialization', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";

    // 1. Connect
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));
    expect($this->sseClient->endpointUrl)->toBeString();

    // 2. Initialize Request
    await($this->sseClient->sendHttpRequest('init-http-nonexist', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'Test'],
        'capabilities' => []
    ]));
    await($this->sseClient->getNextMessageResponse('init-http-nonexist'));
    await($this->sseClient->sendHttpNotification('notifications/initialized', ['messageQueueSupported' => true]));
    await(delay(0.05, $this->loop));

    // 3. Send request for non-existent method
    await($this->sseClient->sendHttpRequest('err-meth-http-1', 'non/existentHttpTool', []));
    $errorResponse = await($this->sseClient->getNextMessageResponse('err-meth-http-1'));

    // 4. Expect error response
    expect($errorResponse['id'])->toBe('err-meth-http-1');
    expect($errorResponse['error']['code'])->toBe(-32601);
    expect($errorResponse['error']['message'])->toContain("Method 'non/existentHttpTool' not found");
})->group('integration', 'http_transport');

it('can handle batch requests correctly over HTTP/SSE', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";

    // 1. Connect
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));
    expect($this->sseClient->endpointUrl)->toBeString();

    // 2. Initialize Request
    await($this->sseClient->sendHttpRequest('init-batch-http', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'HttpBatchClient', 'version' => '1.0'],
        'capabilities' => []
    ]));
    await($this->sseClient->getNextMessageResponse('init-batch-http'));

    // 3. Initialized notification
    await($this->sseClient->sendHttpNotification('notifications/initialized', ['messageQueueSupported' => true]));
    await(delay(0.05, $this->loop));

    // 4. Send Batch Request
    $batchRequests = [
        ['jsonrpc' => '2.0', 'id' => 'batch-req-1', 'method' => 'tools/call', 'params' => ['name' => 'greet_http_tool', 'arguments' => ['name' => 'Batch Item 1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/something'],
        ['jsonrpc' => '2.0', 'id' => 'batch-req-2', 'method' => 'tools/call', 'params' => ['name' => 'greet_http_tool', 'arguments' => ['name' => 'Batch Item 2']]],
        ['jsonrpc' => '2.0', 'id' => 'batch-req-3', 'method' => 'nonexistent/method']
    ];

    await($this->sseClient->sendHttpBatchRequest($batchRequests));

    // 5. Read Batch Response
    $batchResponseArray = await($this->sseClient->getNextBatchMessageResponse(3));

    expect($batchResponseArray)->toBeArray()->toHaveCount(3);

    $findResponseById = function (array $batch, $id) {
        foreach ($batch as $item) {
            if (isset($item['id']) && $item['id'] === $id) {
                return $item;
            }
        }
        return null;
    };

    $response1 = $findResponseById($batchResponseArray, 'batch-req-1');
    $response2 = $findResponseById($batchResponseArray, 'batch-req-2');
    $response3 = $findResponseById($batchResponseArray, 'batch-req-3');

    expect($response1['result']['content'][0]['text'])->toBe('Hello, Batch Item 1!');
    expect($response2['result']['content'][0]['text'])->toBe('Hello, Batch Item 2!');
    expect($response3['error']['code'])->toBe(-32601);
    expect($response3['error']['message'])->toContain("Method 'nonexistent/method' not found");

    $this->sseClient->close();
})->group('integration', 'http_transport');

it('can handle tool list request over HTTP/SSE', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));

    await($this->sseClient->sendHttpRequest('init-http-tools', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]));
    await($this->sseClient->getNextMessageResponse('init-http-tools'));
    await($this->sseClient->sendHttpNotification('notifications/initialized'));
    await(delay(0.1, $this->loop));

    await($this->sseClient->sendHttpRequest('tool-list-http-1', 'tools/list', []));
    $toolListResponse = await($this->sseClient->getNextMessageResponse('tool-list-http-1'));

    expect($toolListResponse['id'])->toBe('tool-list-http-1');
    expect($toolListResponse)->not->toHaveKey('error');
    expect($toolListResponse['result']['tools'])->toBeArray()->toHaveCount(1);
    expect($toolListResponse['result']['tools'][0]['name'])->toBe('greet_http_tool');

    $this->sseClient->close();
})->group('integration', 'http_transport');

it('can read a registered resource over HTTP/SSE', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));

    await($this->sseClient->sendHttpRequest('init-http-res', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]));
    await($this->sseClient->getNextMessageResponse('init-http-res'));
    await($this->sseClient->sendHttpNotification('notifications/initialized'));
    await(delay(0.1, $this->loop));

    await($this->sseClient->sendHttpRequest('res-read-http-1', 'resources/read', ['uri' => 'test://http/static']));
    $resourceResponse = await($this->sseClient->getNextMessageResponse('res-read-http-1'));

    expect($resourceResponse['id'])->toBe('res-read-http-1');
    expect($resourceResponse)->not->toHaveKey('error');
    expect($resourceResponse['result']['contents'])->toBeArray()->toHaveCount(1);
    expect($resourceResponse['result']['contents'][0]['uri'])->toBe('test://http/static');
    expect($resourceResponse['result']['contents'][0]['text'])->toBe(ResourceHandlerFixture::$staticTextContent);
    expect($resourceResponse['result']['contents'][0]['mimeType'])->toBe('text/plain');

    $this->sseClient->close();
})->group('integration', 'http_transport');

it('can get a registered prompt over HTTP/SSE', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));

    await($this->sseClient->sendHttpRequest('init-http-prompt', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]));
    await($this->sseClient->getNextMessageResponse('init-http-prompt'));
    await($this->sseClient->sendHttpNotification('notifications/initialized'));
    await(delay(0.1, $this->loop));

    await($this->sseClient->sendHttpRequest('prompt-get-http-1', 'prompts/get', [
        'name' => 'simple_http_prompt',
        'arguments' => ['name' => 'HttpPromptUser', 'style' => 'polite']
    ]));
    $promptResponse = await($this->sseClient->getNextMessageResponse('prompt-get-http-1'));

    expect($promptResponse['id'])->toBe('prompt-get-http-1');
    expect($promptResponse)->not->toHaveKey('error');
    expect($promptResponse['result']['messages'])->toBeArray()->toHaveCount(1);
    expect($promptResponse['result']['messages'][0]['role'])->toBe('user');
    expect($promptResponse['result']['messages'][0]['content']['text'])->toBe('Craft a polite greeting for HttpPromptUser.');

    $this->sseClient->close();
})->group('integration', 'http_transport');

it('rejects subsequent requests if client does not send initialized notification', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));

    // 1. Send Initialize
    await($this->sseClient->sendHttpRequest('init-http-no-ack', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'HttpForgetfulClient', 'version' => '1.0'],
        'capabilities' => []
    ]));
    await($this->sseClient->getNextMessageResponse('init-http-no-ack'));
    // Client "forgets" to send notifications/initialized back

    await(delay(0.1, $this->loop));

    // 2. Attempt to Call a tool
    await($this->sseClient->sendHttpRequest('tool-call-http-no-ack', 'tools/call', [
        'name' => 'greet_http_tool',
        'arguments' => ['name' => 'NoAckHttpUser']
    ]));
    $toolResponse = await($this->sseClient->getNextMessageResponse('tool-call-http-no-ack'));

    expect($toolResponse['id'])->toBe('tool-call-http-no-ack');
    expect($toolResponse['error']['code'])->toBe(-32600); // Invalid Request
    expect($toolResponse['error']['message'])->toContain('Client session not initialized');

    $this->sseClient->close();
})->group('integration', 'http_transport');

it('returns 404 for POST to /message without valid clientId in query', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";
    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));
    $validEndpointUrl = $this->sseClient->endpointUrl;
    $this->sseClient->close();

    $malformedEndpoint = (string) (new Uri($validEndpointUrl))->withQuery('');

    $payload = ['jsonrpc' => '2.0', 'id' => 'post-no-clientid', 'method' => 'ping', 'params' => []];
    $postPromise = $this->sseClient->browser->post(
        $malformedEndpoint,
        ['Content-Type' => 'application/json'],
        json_encode($payload)
    );

    try {
        await(timeout($postPromise, HTTP_PROCESS_TIMEOUT_SECONDS - 2, $this->loop));
    } catch (ResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(400);
        $bodyContent = (string) $e->getResponse()->getBody();
        $errorData = json_decode($bodyContent, true);
        expect($errorData['error']['message'])->toContain('Missing or invalid clientId');
    }
})->group('integration', 'http_transport');

it('returns 404 for POST to /message with clientId for a disconnected SSE stream', function () {
    $this->sseClient = new MockSseClient();
    $sseBaseUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/" . HTTP_MCP_PATH_PREFIX . "/sse";

    await($this->sseClient->connect($sseBaseUrl));
    await(delay(0.05, $this->loop));
    $originalEndpointUrl = $this->sseClient->endpointUrl;
    $this->sseClient->close();

    await(delay(0.1, $this->loop));

    $payload = ['jsonrpc' => '2.0', 'id' => 'post-stale-clientid', 'method' => 'ping', 'params' => []];
    $postPromise = $this->sseClient->browser->post(
        $originalEndpointUrl,
        ['Content-Type' => 'application/json'],
        json_encode($payload)
    );

    try {
        await(timeout($postPromise, HTTP_PROCESS_TIMEOUT_SECONDS - 2, $this->loop));
    } catch (ResponseException $e) {
        $bodyContent = (string) $e->getResponse()->getBody();
        $errorData = json_decode($bodyContent, true);
        expect($errorData['error']['message'])->toContain('Session ID not found or disconnected');
    }
})->group('integration', 'http_transport');

it('returns 404 for unknown paths', function () {
    $browser = new Browser($this->loop);
    $unknownUrl = "http://" . HTTP_SERVER_HOST . ":" . $this->port . "/unknown/path";

    $promise = $browser->get($unknownUrl);

    try {
        await(timeout($promise, HTTP_PROCESS_TIMEOUT_SECONDS - 2, $this->loop));
        $this->fail("Request to unknown path should have failed with 404.");
    } catch (ResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(404);
        $body = (string) $e->getResponse()->getBody();
        expect($body)->toContain("Not Found");
    } catch (\Throwable $e) {
        $this->fail("Request to unknown path failed with unexpected error: " . $e->getMessage());
    }
})->group('integration', 'http_transport');
