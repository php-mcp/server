<?php

use PhpMcp\Server\Protocol;
use PhpMcp\Server\Tests\Mocks\Clients\MockJsonHttpClient;
use PhpMcp\Server\Tests\Mocks\Clients\MockStreamHttpClient;
use React\ChildProcess\Process;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Stream\ReadableStreamInterface;

use function React\Async\await;
use function React\Promise\resolve;

const STREAMABLE_HTTP_SCRIPT_PATH = __DIR__ . '/../Fixtures/ServerScripts/StreamableHttpTestServer.php';
const STREAMABLE_HTTP_PROCESS_TIMEOUT = 9;
const STREAMABLE_HTTP_HOST = '127.0.0.1';
const STREAMABLE_MCP_PATH = 'mcp_streamable_json_mode';

beforeEach(function () {
    if (!is_file(STREAMABLE_HTTP_SCRIPT_PATH)) {
        $this->markTestSkipped("Server script not found: " . STREAMABLE_HTTP_SCRIPT_PATH);
    }
    if (!is_executable(STREAMABLE_HTTP_SCRIPT_PATH)) {
        chmod(STREAMABLE_HTTP_SCRIPT_PATH, 0755);
    }

    $phpPath = PHP_BINARY ?: 'php';
    $commandPhpPath = str_contains($phpPath, ' ') ? '"' . $phpPath . '"' : $phpPath;
    $commandScriptPath = escapeshellarg(STREAMABLE_HTTP_SCRIPT_PATH);
    $this->port = findFreePort();

    $jsonModeCommandArgs = [
        escapeshellarg(STREAMABLE_HTTP_HOST),
        escapeshellarg((string)$this->port),
        escapeshellarg(STREAMABLE_MCP_PATH),
        escapeshellarg('true'), // enableJsonResponse = true
    ];
    $this->jsonModeCommand = $commandPhpPath . ' ' . $commandScriptPath . ' ' . implode(' ', $jsonModeCommandArgs);

    $streamModeCommandArgs = [
        escapeshellarg(STREAMABLE_HTTP_HOST),
        escapeshellarg((string)$this->port),
        escapeshellarg(STREAMABLE_MCP_PATH),
        escapeshellarg('false'), // enableJsonResponse = false
    ];
    $this->streamModeCommand = $commandPhpPath . ' ' . $commandScriptPath . ' ' . implode(' ', $streamModeCommandArgs);

    $statelessModeCommandArgs = [
        escapeshellarg(STREAMABLE_HTTP_HOST),
        escapeshellarg((string)$this->port),
        escapeshellarg(STREAMABLE_MCP_PATH),
        escapeshellarg('true'), // enableJsonResponse = true
        escapeshellarg('false'), // useEventStore = false
        escapeshellarg('true'), // stateless = true
    ];
    $this->statelessModeCommand = $commandPhpPath . ' ' . $commandScriptPath . ' ' . implode(' ', $statelessModeCommandArgs);

    $this->process = null;
});

afterEach(function () {
    if ($this->process instanceof Process && $this->process->isRunning()) {
        if ($this->process->stdout instanceof ReadableStreamInterface) {
            $this->process->stdout->close();
        }
        if ($this->process->stderr instanceof ReadableStreamInterface) {
            $this->process->stderr->close();
        }

        $this->process->terminate(SIGTERM);
        try {
            await(delay(0.02));
        } catch (\Throwable $e) {
        }
        if ($this->process->isRunning()) {
            $this->process->terminate(SIGKILL);
        }
    }
    $this->process = null;
});

describe('JSON MODE', function () {
    beforeEach(function () {
        $this->process = new Process($this->jsonModeCommand, getcwd() ?: null, null, []);
        $this->process->start();

        $this->jsonClient = new MockJsonHttpClient(STREAMABLE_HTTP_HOST, $this->port, STREAMABLE_MCP_PATH);

        await(delay(0.2));
    });

    it('server starts, initializes via POST JSON, calls a tool, and closes', function () {
        // 1. Initialize
        $initResult = await($this->jsonClient->sendRequest('initialize', [
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-json-1'));

        expect($initResult['statusCode'])->toBe(200);
        expect($initResult['body']['id'])->toBe('init-json-1');
        expect($initResult['body'])->not->toHaveKey('error');
        expect($initResult['body']['result']['protocolVersion'])->toBe(Protocol::LATEST_PROTOCOL_VERSION);
        expect($initResult['body']['result']['serverInfo']['name'])->toBe('StreamableHttpIntegrationServer');
        expect($this->jsonClient->sessionId)->toBeString()->not->toBeEmpty();

        // 2. Initialized notification
        $notifResult = await($this->jsonClient->sendNotification('notifications/initialized'));
        expect($notifResult['statusCode'])->toBe(202);

        // 3. Call a tool
        $toolResult = await($this->jsonClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'JSON Mode User']
        ], 'tool-json-1'));

        expect($toolResult['statusCode'])->toBe(200);
        expect($toolResult['body']['id'])->toBe('tool-json-1');
        expect($toolResult['body'])->not->toHaveKey('error');
        expect($toolResult['body']['result']['content'][0]['text'])->toBe('Hello, JSON Mode User!');

        // Server process is terminated in afterEach
    })->group('integration', 'streamable_http_json');


    it('return HTTP 400 error response for invalid JSON in POST request', function () {
        $malformedJson = '{"jsonrpc":"2.0", "id": "bad-json-post-1", "method": "tools/list", "params": {"broken"}';

        $promise = $this->jsonClient->browser->post(
            $this->jsonClient->baseUrl,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $malformedJson
        );

        try {
            await(timeout($promise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));
        } catch (ResponseException $e) {
            expect($e->getResponse()->getStatusCode())->toBe(400);
            $bodyContent = (string) $e->getResponse()->getBody();
            $decodedBody = json_decode($bodyContent, true);

            expect($decodedBody['jsonrpc'])->toBe('2.0');
            expect($decodedBody['id'])->toBe('');
            expect($decodedBody['error']['code'])->toBe(-32700);
            expect($decodedBody['error']['message'])->toContain('Invalid JSON');
        }
    })->group('integration', 'streamable_http_json');

    it('returns JSON-RPC error result for request for non-existent method', function () {
        // 1. Initialize
        await($this->jsonClient->sendRequest('initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'], 'capabilities' => []], 'init-json-err'));
        await($this->jsonClient->sendNotification('notifications/initialized'));

        // 2. Request non-existent method
        $errorResult = await($this->jsonClient->sendRequest('non/existentToolViaJson', [], 'err-meth-json-1'));

        expect($errorResult['statusCode'])->toBe(200);
        expect($errorResult['body']['id'])->toBe('err-meth-json-1');
        expect($errorResult['body']['error']['code'])->toBe(-32601);
        expect($errorResult['body']['error']['message'])->toContain("Method 'non/existentToolViaJson' not found");
    })->group('integration', 'streamable_http_json');

    it('can handle batch requests correctly', function () {
        // 1. Initialize
        await($this->jsonClient->sendRequest('initialize', [
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-json-batch'));
        expect($this->jsonClient->sessionId)->toBeString()->not->toBeEmpty();
        await($this->jsonClient->sendNotification('notifications/initialized'));

        // 2. Send Batch Request
        $batchRequests = [
            ['jsonrpc' => '2.0', 'id' => 'batch-req-1', 'method' => 'tools/call', 'params' => ['name' => 'greet_streamable_tool', 'arguments' => ['name' => 'Batch Item 1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/something'],
            ['jsonrpc' => '2.0', 'id' => 'batch-req-2', 'method' => 'tools/call', 'params' => ['name' => 'sum_streamable_tool', 'arguments' => ['a' => 10, 'b' => 20]]],
            ['jsonrpc' => '2.0', 'id' => 'batch-req-3', 'method' => 'nonexistent/method']
        ];

        $batchResponse = await($this->jsonClient->sendBatchRequest($batchRequests));



        $findResponseById = function (array $batch, $id) {
            foreach ($batch as $item) {
                if (isset($item['id']) && $item['id'] === $id) {
                    return $item;
                }
            }
            return null;
        };

        expect($batchResponse['statusCode'])->toBe(200);
        expect($batchResponse['body'])->toBeArray()->toHaveCount(3);

        $response1 = $findResponseById($batchResponse['body'], 'batch-req-1');
        $response2 = $findResponseById($batchResponse['body'], 'batch-req-2');
        $response3 = $findResponseById($batchResponse['body'], 'batch-req-3');

        expect($response1['result']['content'][0]['text'])->toBe('Hello, Batch Item 1!');
        expect($response2['result']['content'][0]['text'])->toBe('30');
        expect($response3['error']['code'])->toBe(-32601);
        expect($response3['error']['message'])->toContain("Method 'nonexistent/method' not found");
    })->group('integration', 'streamable_http_json');

    it('can handle tool list request', function () {
        await($this->jsonClient->sendRequest('initialize', [
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-json-tools'));
        await($this->jsonClient->sendNotification('notifications/initialized'));

        $toolListResult = await($this->jsonClient->sendRequest('tools/list', [], 'tool-list-json-1'));

        expect($toolListResult['statusCode'])->toBe(200);
        expect($toolListResult['body']['id'])->toBe('tool-list-json-1');
        expect($toolListResult['body']['result']['tools'])->toBeArray();
        expect(count($toolListResult['body']['result']['tools']))->toBe(2);
        expect($toolListResult['body']['result']['tools'][0]['name'])->toBe('greet_streamable_tool');
        expect($toolListResult['body']['result']['tools'][1]['name'])->toBe('sum_streamable_tool');
    })->group('integration', 'streamable_http_json');

    it('can read a registered resource', function () {
        await($this->jsonClient->sendRequest('initialize', [
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-json-res'));
        await($this->jsonClient->sendNotification('notifications/initialized'));

        $resourceResult = await($this->jsonClient->sendRequest('resources/read', ['uri' => 'test://streamable/static'], 'res-read-json-1'));

        expect($resourceResult['statusCode'])->toBe(200);
        expect($resourceResult['body']['id'])->toBe('res-read-json-1');
        $contents = $resourceResult['body']['result']['contents'];
        expect($contents[0]['uri'])->toBe('test://streamable/static');
        expect($contents[0]['text'])->toBe(\PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture::$staticTextContent);
    })->group('integration', 'streamable_http_json');

    it('can get a registered prompt', function () {
        await($this->jsonClient->sendRequest('initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'], 'capabilities' => []], 'init-json-prompt'));
        await($this->jsonClient->sendNotification('notifications/initialized'));

        $promptResult = await($this->jsonClient->sendRequest('prompts/get', [
            'name' => 'simple_streamable_prompt',
            'arguments' => ['name' => 'JsonPromptUser', 'style' => 'terse']
        ], 'prompt-get-json-1'));

        expect($promptResult['statusCode'])->toBe(200);
        expect($promptResult['body']['id'])->toBe('prompt-get-json-1');
        $messages = $promptResult['body']['result']['messages'];
        expect($messages[0]['content']['text'])->toBe('Craft a terse greeting for JsonPromptUser.');
    })->group('integration', 'streamable_http_json');

    it('rejects subsequent requests if client does not send initialized notification', function () {
        // 1. Initialize ONLY
        await($this->jsonClient->sendRequest('initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'], 'capabilities' => []], 'init-json-noack'));
        // Client "forgets" to send notifications/initialized back

        // 2. Attempt to Call a tool
        $toolResult = await($this->jsonClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'NoAckJsonUser']
        ], 'tool-json-noack'));

        expect($toolResult['statusCode'])->toBe(200); // HTTP is fine
        expect($toolResult['body']['id'])->toBe('tool-json-noack');
        expect($toolResult['body']['error']['code'])->toBe(-32600); // Invalid Request
        expect($toolResult['body']['error']['message'])->toContain('Client session not initialized');
    })->group('integration', 'streamable_http_json');

    it('returns HTTP 400 error for non-initialize requests without Mcp-Session-Id', function () {
        await($this->jsonClient->sendRequest('initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'], 'capabilities' => []], 'init-sess-test'));
        $this->jsonClient->sessionId = null;

        try {
            await($this->jsonClient->sendRequest('tools/list', [], 'tools-list-no-session'));
        } catch (ResponseException $e) {
            expect($e->getResponse()->getStatusCode())->toBe(400);
            $bodyContent = (string) $e->getResponse()->getBody();
            $decodedBody = json_decode($bodyContent, true);

            expect($decodedBody['jsonrpc'])->toBe('2.0');
            expect($decodedBody['id'])->toBe('tools-list-no-session');
            expect($decodedBody['error']['code'])->toBe(-32600);
            expect($decodedBody['error']['message'])->toContain('Mcp-Session-Id header required');
        }
    })->group('integration', 'streamable_http_json');
});

describe('STREAM MODE', function () {
    beforeEach(function () {
        $this->process = new Process($this->streamModeCommand, getcwd() ?: null, null, []);
        $this->process->start();
        $this->streamClient = new MockStreamHttpClient(STREAMABLE_HTTP_HOST, $this->port, STREAMABLE_MCP_PATH);
        await(delay(0.2));
    });
    afterEach(function () {
        if ($this->streamClient ?? null) {
            $this->streamClient->closeMainSseStream();
        }
    });

    it('server starts, initializes via POST JSON, calls a tool, and closes', function () {
        // 1. Initialize Request
        $initResponse = await($this->streamClient->sendInitializeRequest([
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'StreamModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-stream-1'));

        expect($this->streamClient->sessionId)->toBeString()->not->toBeEmpty();
        expect($initResponse['id'])->toBe('init-stream-1');
        expect($initResponse)->not->toHaveKey('error');
        expect($initResponse['result']['protocolVersion'])->toBe(Protocol::LATEST_PROTOCOL_VERSION);
        expect($initResponse['result']['serverInfo']['name'])->toBe('StreamableHttpIntegrationServer');

        // 2. Send Initialized Notification
        $notifResult = await($this->streamClient->sendHttpNotification('notifications/initialized'));
        expect($notifResult['statusCode'])->toBe(202);

        // 3. Call a tool
        $toolResponse = await($this->streamClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'Stream Mode User']
        ], 'tool-stream-1'));

        expect($toolResponse['id'])->toBe('tool-stream-1');
        expect($toolResponse)->not->toHaveKey('error');
        expect($toolResponse['result']['content'][0]['text'])->toBe('Hello, Stream Mode User!');
    })->group('integration', 'streamable_http_stream');

    it('return HTTP 400 error response for invalid JSON in POST request', function () {
        $malformedJson = '{"jsonrpc":"2.0", "id": "bad-json-stream-1", "method": "tools/list", "params": {"broken"}';

        $postPromise = $this->streamClient->browser->post(
            $this->streamClient->baseMcpUrl,
            ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'],
            $malformedJson
        );

        try {
            await(timeout($postPromise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));
        } catch (ResponseException $e) {
            $httpResponse = $e->getResponse();
            $bodyContent = (string) $httpResponse->getBody();
            $decodedBody = json_decode($bodyContent, true);

            expect($httpResponse->getStatusCode())->toBe(400);
            expect($decodedBody['jsonrpc'])->toBe('2.0');
            expect($decodedBody['id'])->toBe('');
            expect($decodedBody['error']['code'])->toBe(-32700);
            expect($decodedBody['error']['message'])->toContain('Invalid JSON');
        }
    })->group('integration', 'streamable_http_stream');

    it('returns JSON-RPC error result for request for non-existent method', function () {
        // 1. Initialize
        await($this->streamClient->sendInitializeRequest([
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'StreamModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-stream-err'));
        await($this->streamClient->sendHttpNotification('notifications/initialized'));

        // 2. Send Request
        $errorResponse = await($this->streamClient->sendRequest('non/existentToolViaStream', [], 'err-meth-stream-1'));

        expect($errorResponse['id'])->toBe('err-meth-stream-1');
        expect($errorResponse['error']['code'])->toBe(-32601);
        expect($errorResponse['error']['message'])->toContain("Method 'non/existentToolViaStream' not found");
    })->group('integration', 'streamable_http_stream');

    it('can handle batch requests correctly', function () {
        await($this->streamClient->sendInitializeRequest([
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'StreamModeBatchClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-stream-batch'));
        expect($this->streamClient->sessionId)->toBeString()->not->toBeEmpty();
        await($this->streamClient->sendHttpNotification('notifications/initialized'));

        $batchRequests = [
            ['jsonrpc' => '2.0', 'id' => 'batch-req-1', 'method' => 'tools/call', 'params' => ['name' => 'greet_streamable_tool', 'arguments' => ['name' => 'Batch Item 1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/something'],
            ['jsonrpc' => '2.0', 'id' => 'batch-req-2', 'method' => 'tools/call', 'params' => ['name' => 'sum_streamable_tool', 'arguments' => ['a' => 10, 'b' => 20]]],
            ['jsonrpc' => '2.0', 'id' => 'batch-req-3', 'method' => 'nonexistent/method']
        ];

        $batchResponseArray = await($this->streamClient->sendBatchRequest($batchRequests));

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
        expect($response2['result']['content'][0]['text'])->toBe('30');
        expect($response3['error']['code'])->toBe(-32601);
        expect($response3['error']['message'])->toContain("Method 'nonexistent/method' not found");
    })->group('integration', 'streamable_http_stream');

    it('can handle tool list request', function () {
        await($this->streamClient->sendInitializeRequest(['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => []], 'init-stream-tools'));
        await($this->streamClient->sendHttpNotification('notifications/initialized'));

        $toolListResponse = await($this->streamClient->sendRequest('tools/list', [], 'tool-list-stream-1'));

        expect($toolListResponse['id'])->toBe('tool-list-stream-1');
        expect($toolListResponse)->not->toHaveKey('error');
        expect($toolListResponse['result']['tools'])->toBeArray();
        expect(count($toolListResponse['result']['tools']))->toBe(2);
        expect($toolListResponse['result']['tools'][0]['name'])->toBe('greet_streamable_tool');
        expect($toolListResponse['result']['tools'][1]['name'])->toBe('sum_streamable_tool');
    })->group('integration', 'streamable_http_stream');

    it('can read a registered resource', function () {
        await($this->streamClient->sendInitializeRequest(['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => []], 'init-stream-res'));
        await($this->streamClient->sendHttpNotification('notifications/initialized'));

        $resourceResponse = await($this->streamClient->sendRequest('resources/read', ['uri' => 'test://streamable/static'], 'res-read-stream-1'));

        expect($resourceResponse['id'])->toBe('res-read-stream-1');
        $contents = $resourceResponse['result']['contents'];
        expect($contents[0]['uri'])->toBe('test://streamable/static');
        expect($contents[0]['text'])->toBe(\PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture::$staticTextContent);
    })->group('integration', 'streamable_http_stream');

    it('can get a registered prompt', function () {
        await($this->streamClient->sendInitializeRequest(['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => []], 'init-stream-prompt'));
        await($this->streamClient->sendHttpNotification('notifications/initialized'));

        $promptResponse = await($this->streamClient->sendRequest('prompts/get', [
            'name' => 'simple_streamable_prompt',
            'arguments' => ['name' => 'StreamPromptUser', 'style' => 'formal']
        ], 'prompt-get-stream-1'));

        expect($promptResponse['id'])->toBe('prompt-get-stream-1');
        $messages = $promptResponse['result']['messages'];
        expect($messages[0]['content']['text'])->toBe('Craft a formal greeting for StreamPromptUser.');
    })->group('integration', 'streamable_http_stream');

    it('rejects subsequent requests if client does not send initialized notification', function () {
        await($this->streamClient->sendInitializeRequest([
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'StreamModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-stream-noack'));

        $toolResponse = await($this->streamClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'NoAckStreamUser']
        ], 'tool-stream-noack'));

        expect($toolResponse['id'])->toBe('tool-stream-noack');
        expect($toolResponse['error']['code'])->toBe(-32600);
        expect($toolResponse['error']['message'])->toContain('Client session not initialized');
    })->group('integration', 'streamable_http_stream');

    it('returns HTTP 400 error for non-initialize requests without Mcp-Session-Id', function () {
        await($this->streamClient->sendInitializeRequest([
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'StreamModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-stream-sess-test'));
        $validSessionId = $this->streamClient->sessionId;
        $this->streamClient->sessionId = null;

        try {
            await($this->streamClient->sendRequest('tools/list', [], 'tools-list-no-session-stream'));
            $this->fail("Expected request to tools/list to fail with 400, but it succeeded.");
        } catch (ResponseException $e) {
            expect($e->getResponse()->getStatusCode())->toBe(400);
            // Body can't be a json since the header accepts only text/event-stream
        }

        $this->streamClient->sessionId = $validSessionId;
    })->group('integration', 'streamable_http_stream');
});

/**
 * STATELESS MODE TESTS
 * 
 * Tests for the stateless mode of StreamableHttpServerTransport, which:
 * - Generates session IDs internally but doesn't expose them to clients
 * - Doesn't require session IDs in requests after initialization
 * - Doesn't include session IDs in response headers
 * - Disables GET requests (SSE streaming) 
 * - Makes DELETE requests meaningless (but returns 204)
 * - Treats each request as independent (no persistent session state)
 * 
 * This mode is designed to work with clients like OpenAI's MCP implementation
 * that have issues with session management in "never require approval" mode.
 */
describe('STATELESS MODE', function () {
    beforeEach(function () {
        $this->process = new Process($this->statelessModeCommand, getcwd() ?: null, null, []);
        $this->process->start();
        $this->statelessClient = new MockJsonHttpClient(STREAMABLE_HTTP_HOST, $this->port, STREAMABLE_MCP_PATH);
        await(delay(0.2));
    });

    it('allows tool calls without having to send initialized notification', function () {
        // 1. Initialize Request
        $initResult = await($this->statelessClient->sendRequest('initialize', [
            'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'StatelessModeClient', 'version' => '1.0'],
            'capabilities' => []
        ], 'init-stateless-1'));

        expect($initResult['statusCode'])->toBe(200);
        expect($initResult['body']['id'])->toBe('init-stateless-1');
        expect($initResult['body'])->not->toHaveKey('error');
        expect($initResult['body']['result']['protocolVersion'])->toBe(Protocol::LATEST_PROTOCOL_VERSION);
        expect($initResult['body']['result']['serverInfo']['name'])->toBe('StreamableHttpIntegrationServer');
        expect($this->statelessClient->sessionId)->toBeString()->toBeEmpty();

        // 2. Call a tool
        $toolResult = await($this->statelessClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'Stateless Mode User']
        ], 'tool-stateless-1'));

        expect($toolResult['statusCode'])->toBe(200);
        expect($toolResult['body']['id'])->toBe('tool-stateless-1');
        expect($toolResult['body'])->not->toHaveKey('error');
        expect($toolResult['body']['result']['content'][0]['text'])->toBe('Hello, Stateless Mode User!');
    })->group('integration', 'streamable_http_stateless');

    it('return HTTP 400 error response for invalid JSON in POST request', function () {
        $malformedJson = '{"jsonrpc":"2.0", "id": "bad-json-stateless-1", "method": "tools/list", "params": {"broken"}';

        $postPromise = $this->statelessClient->browser->post(
            $this->statelessClient->baseUrl,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $malformedJson
        );

        try {
            await(timeout($postPromise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));
        } catch (ResponseException $e) {
            $httpResponse = $e->getResponse();
            $bodyContent = (string) $httpResponse->getBody();
            $decodedBody = json_decode($bodyContent, true);

            expect($httpResponse->getStatusCode())->toBe(400);
            expect($decodedBody['jsonrpc'])->toBe('2.0');
            expect($decodedBody['id'])->toBe('');
            expect($decodedBody['error']['code'])->toBe(-32700);
            expect($decodedBody['error']['message'])->toContain('Invalid JSON');
        }
    })->group('integration', 'streamable_http_stateless');

    it('returns JSON-RPC error result for request for non-existent method', function () {
        $errorResult = await($this->statelessClient->sendRequest('non/existentToolViaStateless', [], 'err-meth-stateless-1'));

        expect($errorResult['statusCode'])->toBe(200);
        expect($errorResult['body']['id'])->toBe('err-meth-stateless-1');
        expect($errorResult['body']['error']['code'])->toBe(-32601);
        expect($errorResult['body']['error']['message'])->toContain("Method 'non/existentToolViaStateless' not found");
    })->group('integration', 'streamable_http_stateless');

    it('can handle batch requests correctly', function () {
        $batchRequests = [
            ['jsonrpc' => '2.0', 'id' => 'batch-req-1', 'method' => 'tools/call', 'params' => ['name' => 'greet_streamable_tool', 'arguments' => ['name' => 'Batch Item 1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/something'],
            ['jsonrpc' => '2.0', 'id' => 'batch-req-2', 'method' => 'tools/call', 'params' => ['name' => 'sum_streamable_tool', 'arguments' => ['a' => 10, 'b' => 20]]],
            ['jsonrpc' => '2.0', 'id' => 'batch-req-3', 'method' => 'nonexistent/method']
        ];

        $batchResponse = await($this->statelessClient->sendBatchRequest($batchRequests));

        $findResponseById = function (array $batch, $id) {
            foreach ($batch as $item) {
                if (isset($item['id']) && $item['id'] === $id) {
                    return $item;
                }
            }
            return null;
        };

        expect($batchResponse['statusCode'])->toBe(200);
        expect($batchResponse['body'])->toBeArray()->toHaveCount(3);

        $response1 = $findResponseById($batchResponse['body'], 'batch-req-1');
        $response2 = $findResponseById($batchResponse['body'], 'batch-req-2');
        $response3 = $findResponseById($batchResponse['body'], 'batch-req-3');

        expect($response1['result']['content'][0]['text'])->toBe('Hello, Batch Item 1!');
        expect($response2['result']['content'][0]['text'])->toBe('30');
        expect($response3['error']['code'])->toBe(-32601);
        expect($response3['error']['message'])->toContain("Method 'nonexistent/method' not found");
    })->group('integration', 'streamable_http_stateless');

    it('can handle tool list request', function () {
        $toolListResult = await($this->statelessClient->sendRequest('tools/list', [], 'tool-list-stateless-1'));

        expect($toolListResult['statusCode'])->toBe(200);
        expect($toolListResult['body']['id'])->toBe('tool-list-stateless-1');
        expect($toolListResult['body'])->not->toHaveKey('error');
        expect($toolListResult['body']['result']['tools'])->toBeArray();
        expect(count($toolListResult['body']['result']['tools']))->toBe(2);
        expect($toolListResult['body']['result']['tools'][0]['name'])->toBe('greet_streamable_tool');
        expect($toolListResult['body']['result']['tools'][1]['name'])->toBe('sum_streamable_tool');
    })->group('integration', 'streamable_http_stateless');

    it('can read a registered resource', function () {
        $resourceResult = await($this->statelessClient->sendRequest('resources/read', ['uri' => 'test://streamable/static'], 'res-read-stateless-1'));

        expect($resourceResult['statusCode'])->toBe(200);
        expect($resourceResult['body']['id'])->toBe('res-read-stateless-1');
        $contents = $resourceResult['body']['result']['contents'];
        expect($contents[0]['uri'])->toBe('test://streamable/static');
        expect($contents[0]['text'])->toBe(\PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture::$staticTextContent);
    })->group('integration', 'streamable_http_stateless');

    it('can get a registered prompt', function () {
        $promptResult = await($this->statelessClient->sendRequest('prompts/get', [
            'name' => 'simple_streamable_prompt',
            'arguments' => ['name' => 'StatelessPromptUser', 'style' => 'formal']
        ], 'prompt-get-stateless-1'));

        expect($promptResult['statusCode'])->toBe(200);
        expect($promptResult['body']['id'])->toBe('prompt-get-stateless-1');
        $messages = $promptResult['body']['result']['messages'];
        expect($messages[0]['content']['text'])->toBe('Craft a formal greeting for StatelessPromptUser.');
    })->group('integration', 'streamable_http_stateless');

    it('does not return session ID in response headers in stateless mode', function () {
        $promise = $this->statelessClient->browser->post(
            $this->statelessClient->baseUrl,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 'init-header-test',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
                    'clientInfo' => ['name' => 'StatelessHeaderTest', 'version' => '1.0'],
                    'capabilities' => []
                ]
            ])
        );

        $response = await(timeout($promise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));

        expect($response->getStatusCode())->toBe(200);
        expect($response->hasHeader('Mcp-Session-Id'))->toBeFalse();

        $body = json_decode((string) $response->getBody(), true);
        expect($body['id'])->toBe('init-header-test');
        expect($body)->not->toHaveKey('error');
    })->group('integration', 'streamable_http_stateless');

    it('returns HTTP 405 for GET requests (SSE disabled) in stateless mode', function () {
        try {
            $getPromise = $this->statelessClient->browser->get(
                $this->statelessClient->baseUrl,
                ['Accept' => 'text/event-stream']
            );
            await(timeout($getPromise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));
            $this->fail("Expected GET request to fail with 405, but it succeeded.");
        } catch (ResponseException $e) {
            expect($e->getResponse()->getStatusCode())->toBe(405);
            $bodyContent = (string) $e->getResponse()->getBody();
            $decodedBody = json_decode($bodyContent, true);
            expect($decodedBody['error']['message'])->toContain('GET requests (SSE streaming) are not supported in stateless mode');
        }
    })->group('integration', 'streamable_http_stateless');

    it('returns 204 for DELETE requests in stateless mode (but they are meaningless)', function () {
        $deletePromise = $this->statelessClient->browser->delete($this->statelessClient->baseUrl);
        $response = await(timeout($deletePromise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));

        expect($response->getStatusCode())->toBe(204);
        expect((string) $response->getBody())->toBeEmpty();
    })->group('integration', 'streamable_http_stateless');

    it('handles multiple independent tool calls in stateless mode', function () {
        $toolResult1 = await($this->statelessClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'User 1']
        ], 'tool-multi-1'));

        $toolResult2 = await($this->statelessClient->sendRequest('tools/call', [
            'name' => 'sum_streamable_tool',
            'arguments' => ['a' => 5, 'b' => 10]
        ], 'tool-multi-2'));

        $toolResult3 = await($this->statelessClient->sendRequest('tools/call', [
            'name' => 'greet_streamable_tool',
            'arguments' => ['name' => 'User 3']
        ], 'tool-multi-3'));

        expect($toolResult1['statusCode'])->toBe(200);
        expect($toolResult1['body']['id'])->toBe('tool-multi-1');
        expect($toolResult1['body']['result']['content'][0]['text'])->toBe('Hello, User 1!');

        expect($toolResult2['statusCode'])->toBe(200);
        expect($toolResult2['body']['id'])->toBe('tool-multi-2');
        expect($toolResult2['body']['result']['content'][0]['text'])->toBe('15');

        expect($toolResult3['statusCode'])->toBe(200);
        expect($toolResult3['body']['id'])->toBe('tool-multi-3');
        expect($toolResult3['body']['result']['content'][0]['text'])->toBe('Hello, User 3!');
    })->group('integration', 'streamable_http_stateless');
});

it('responds to OPTIONS request with CORS headers', function () {
    $this->process = new Process($this->jsonModeCommand, getcwd() ?: null, null, []);
    $this->process->start();
    $this->jsonClient = new MockJsonHttpClient(STREAMABLE_HTTP_HOST, $this->port, STREAMABLE_MCP_PATH);
    await(delay(0.1));

    $browser = new Browser();
    $optionsUrl = $this->jsonClient->baseUrl;

    $promise = $browser->request('OPTIONS', $optionsUrl);
    $response = await(timeout($promise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));

    expect($response->getStatusCode())->toBe(204);
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*');
    expect($response->getHeaderLine('Access-Control-Allow-Methods'))->toContain('POST');
    expect($response->getHeaderLine('Access-Control-Allow-Methods'))->toContain('GET');
    expect($response->getHeaderLine('Access-Control-Allow-Headers'))->toContain('Mcp-Session-Id');
})->group('integration', 'streamable_http');

it('returns 404 for unknown paths', function () {
    $this->process = new Process($this->jsonModeCommand, getcwd() ?: null, null, []);
    $this->process->start();
    $this->jsonClient = new MockJsonHttpClient(STREAMABLE_HTTP_HOST, $this->port, STREAMABLE_MCP_PATH);
    await(delay(0.1));

    $browser = new Browser();
    $unknownUrl = "http://" . STREAMABLE_HTTP_HOST . ":" . $this->port . "/completely/unknown/path";

    $promise = $browser->get($unknownUrl);

    try {
        await(timeout($promise, STREAMABLE_HTTP_PROCESS_TIMEOUT - 2));
        $this->fail("Request to unknown path should have failed with 404.");
    } catch (ResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(404);
        $decodedBody = json_decode((string)$e->getResponse()->getBody(), true);
        expect($decodedBody['error']['message'])->toContain('Not found');
    }
})->group('integration', 'streamable_http');

it('can delete client session with DELETE request', function () {
    $this->process = new Process($this->jsonModeCommand, getcwd() ?: null, null, []);
    $this->process->start();
    $this->jsonClient = new MockJsonHttpClient(STREAMABLE_HTTP_HOST, $this->port, STREAMABLE_MCP_PATH);
    await(delay(0.1));

    // 1. Initialize
    await($this->jsonClient->sendRequest('initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => ['name' => 'JsonModeClient', 'version' => '1.0'], 'capabilities' => []], 'init-delete-test'));
    $sessionIdForDelete = $this->jsonClient->sessionId;
    expect($sessionIdForDelete)->toBeString();
    await($this->jsonClient->sendNotification('notifications/initialized'));

    // 2. Establish a GET SSE connection
    $sseUrl = $this->jsonClient->baseUrl;
    $browserForSse = (new Browser())->withTimeout(3);
    $ssePromise = $browserForSse->requestStreaming('GET', $sseUrl, [
        'Accept' => 'text/event-stream',
        'Mcp-Session-Id' => $sessionIdForDelete
    ]);
    $ssePsrResponse = await(timeout($ssePromise, 3));
    expect($ssePsrResponse->getStatusCode())->toBe(200);
    expect($ssePsrResponse->getHeaderLine('Content-Type'))->toBe('text/event-stream');

    $sseStream = $ssePsrResponse->getBody();
    assert($sseStream instanceof ReadableStreamInterface);

    $isSseStreamClosed = false;
    $sseStream->on('close', function () use (&$isSseStreamClosed) {
        $isSseStreamClosed = true;
    });

    // 3. Send DELETE request
    $deleteResponse = await($this->jsonClient->sendDeleteRequest());
    expect($deleteResponse['statusCode'])->toBe(204);

    // 4. Assert that the GET SSE stream was closed
    await(delay(0.1));
    expect($isSseStreamClosed)->toBeTrue("The GET SSE stream for session {$sessionIdForDelete} was not closed after DELETE request.");

    // 5. Assert that the client session was deleted
    try {
        await($this->jsonClient->sendRequest('tools/list', [], 'tool-list-json-1'));
        $this->fail("Expected request to tools/list to fail with 400, but it succeeded.");
    } catch (ResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(404);
        $bodyContent = (string) $e->getResponse()->getBody();
        $decodedBody = json_decode($bodyContent, true);
        expect($decodedBody['error']['code'])->toBe(-32600);
        expect($decodedBody['error']['message'])->toContain('Invalid or expired session');
    }
})->group('integration', 'streamable_http_json');
