<?php

use PhpMcp\Server\Protocol;
use PhpMcp\Server\Tests\Fixtures\General\ResourceHandlerFixture;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Async\await;

const STDIO_SERVER_SCRIPT_PATH = __DIR__ . '/../Fixtures/ServerScripts/StdioTestServer.php';
const PROCESS_TIMEOUT_SECONDS = 5;

function sendRequestToServer(Process $process, string $requestId, string $method, array $params = []): void
{
    $request = json_encode([
        'jsonrpc' => '2.0',
        'id' => $requestId,
        'method' => $method,
        'params' => $params,
    ]);
    $process->stdin->write($request . "\n");
}

function sendNotificationToServer(Process $process, string $method, array $params = []): void
{
    $notification = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
    ]);

    $process->stdin->write($notification . "\n");
}

function readResponseFromServer(Process $process, string $expectedRequestId, LoopInterface $loop): PromiseInterface
{
    $deferred = new Deferred();
    $buffer = '';

    $dataListener = function ($chunk) use (&$buffer, $deferred, $expectedRequestId, $process, &$dataListener) {
        $buffer .= $chunk;
        if (str_contains($buffer, "\n")) {
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                if (empty(trim($line))) {
                    continue;
                }
                try {
                    $response = json_decode(trim($line), true);
                    if (array_key_exists('id', $response) && $response['id'] == $expectedRequestId) {
                        $process->stdout->removeListener('data', $dataListener);
                        $deferred->resolve($response);
                        return;
                    } elseif (isset($response['method']) && str_starts_with($response['method'], 'notifications/')) {
                        // It's a notification, log it or handle if necessary for a specific test, but don't resolve
                    }
                } catch (\JsonException $e) {
                    $process->stdout->removeListener('data', $dataListener);
                    $deferred->reject(new \RuntimeException("Failed to decode JSON response: " . $line, 0, $e));
                    return;
                }
            }
        }
    };

    $process->stdout->on('data', $dataListener);

    return timeout($deferred->promise(), PROCESS_TIMEOUT_SECONDS, $loop)
        ->catch(function ($reason) use ($expectedRequestId) {
            if ($reason instanceof \RuntimeException && str_contains($reason->getMessage(), 'Timed out after')) {
                throw new \RuntimeException("Timeout waiting for response to request ID '{$expectedRequestId}'");
            }
            throw $reason;
        })
        ->finally(function () use ($process, $dataListener) {
            $process->stdout->removeListener('data', $dataListener);
        });
}

beforeEach(function () {
    $this->loop = Loop::get();

    if (!is_executable(STDIO_SERVER_SCRIPT_PATH)) {
        chmod(STDIO_SERVER_SCRIPT_PATH, 0755);
    }

    $phpPath = PHP_BINARY ?: 'php';
    $command = escapeshellarg($phpPath) . ' ' . escapeshellarg(STDIO_SERVER_SCRIPT_PATH);
    $this->process = new Process($command);
    $this->process->start($this->loop);

    $this->processErrorOutput = '';
    $this->process->stderr->on('data', function ($chunk) {
        $this->processErrorOutput .= $chunk;
    });
});

afterEach(function () {
    if ($this->process instanceof Process && $this->process->isRunning()) {
        if ($this->process->stdin->isWritable()) {
            $this->process->stdin->end();
        }
        $this->process->stdout->close();
        $this->process->stdin->close();
        $this->process->stderr->close();
        $this->process->terminate(SIGTERM);
        await(delay(0.05, $this->loop));
        if ($this->process->isRunning()) {
            $this->process->terminate(SIGKILL);
        }
    }
    $this->process = null;
});

it('starts the stdio server, initializes, calls a tool, and closes', function () {
    // 1. Initialize Request
    sendRequestToServer($this->process, 'init-1', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'PestTestClient', 'version' => '1.0'],
        'capabilities' => []
    ]);
    $initResponse = await(readResponseFromServer($this->process, 'init-1', $this->loop));

    expect($initResponse['id'])->toBe('init-1');
    expect($initResponse)->not->toHaveKey('error');
    expect($initResponse['result']['protocolVersion'])->toBe(Protocol::LATEST_PROTOCOL_VERSION);
    expect($initResponse['result']['serverInfo']['name'])->toBe('StdioIntegrationTestServer');

    // 2. Initialized Notification
    sendNotificationToServer($this->process, 'notifications/initialized');

    await(delay(0.05, $this->loop));

    // 3. Call a tool
    sendRequestToServer($this->process, 'tool-call-1', 'tools/call', [
        'name' => 'greet_stdio_tool',
        'arguments' => ['name' => 'Integration Tester']
    ]);
    $toolResponse = await(readResponseFromServer($this->process, 'tool-call-1', $this->loop));

    expect($toolResponse['id'])->toBe('tool-call-1');
    expect($toolResponse)->not->toHaveKey('error');
    expect($toolResponse['result']['content'][0]['text'])->toBe('Hello, Integration Tester!');
    expect($toolResponse['result']['isError'])->toBeFalse();

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('can handle invalid JSON request from client', function () {
    $this->process->stdin->write("this is not json\n");

    $response = await(readResponseFromServer($this->process, '', $this->loop));

    expect($response['id'])->toBe('');
    expect($response['error']['code'])->toBe(-32700);
    expect($response['error']['message'])->toContain('Invalid JSON');

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('handles request for non-existent method', function () {
    sendRequestToServer($this->process, 'init-err', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]);
    await(readResponseFromServer($this->process, 'init-err', $this->loop));

    sendNotificationToServer($this->process, 'notifications/initialized');
    await(delay(0.05, $this->loop));

    sendRequestToServer($this->process, 'err-meth-1', 'non/existentMethod', []);
    $response = await(readResponseFromServer($this->process, 'err-meth-1', $this->loop));

    expect($response['id'])->toBe('err-meth-1');
    expect($response['error']['code'])->toBe(-32601);
    expect($response['error']['message'])->toContain("Method 'non/existentMethod' not found");

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('can handle batch requests correctly', function () {
    // 1. Initialize
    sendRequestToServer($this->process, 'init-batch', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'BatchClient', 'version' => '1.0'],
        'capabilities' => []
    ]);
    await(readResponseFromServer($this->process, 'init-batch', $this->loop));
    sendNotificationToServer($this->process, 'notifications/initialized');
    await(delay(0.05, $this->loop));

    // 2. Send Batch Request
    $batchRequests = [
        ['jsonrpc' => '2.0', 'id' => 'batch-req-1', 'method' => 'tools/call', 'params' => ['name' => 'greet_stdio_tool', 'arguments' => ['name' => 'Batch Item 1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/something'],
        ['jsonrpc' => '2.0', 'id' => 'batch-req-2', 'method' => 'tools/call', 'params' => ['name' => 'greet_stdio_tool', 'arguments' => ['name' => 'Batch Item 2']]],
        ['jsonrpc' => '2.0', 'id' => 'batch-req-3', 'method' => 'nonexistent/method']
    ];

    $rawBatchRequest = json_encode($batchRequests);
    $this->process->stdin->write($rawBatchRequest . "\n");

    // 3. Read Batch Response
    $batchResponsePromise = new Deferred();
    $fullBuffer = '';
    $batchDataListener = function ($chunk) use (&$fullBuffer, $batchResponsePromise, &$batchDataListener) {
        $fullBuffer .= $chunk;
        if (str_contains($fullBuffer, "\n")) {
            $line = trim($fullBuffer);
            $fullBuffer = '';
            try {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) { // Batch response is an array
                    $this->process->stdout->removeListener('data', $batchDataListener);
                    $batchResponsePromise->resolve($decoded);
                }
            } catch (\JsonException $e) {
                $this->process->stdout->removeListener('data', $batchDataListener);
                $batchResponsePromise->reject(new \RuntimeException("Batch JSON decode failed: " . $line, 0, $e));
            }
        }
    };
    $this->process->stdout->on('data', $batchDataListener);

    $batchResponseArray = await(timeout($batchResponsePromise->promise(), PROCESS_TIMEOUT_SECONDS, $this->loop));

    expect($batchResponseArray)->toBeArray()->toHaveCount(3); // greet1, greet2, error

    $response1 = array_values(array_filter($batchResponseArray, fn ($response) => $response['id'] === 'batch-req-1'))[0] ?? null;
    $response2 = array_values(array_filter($batchResponseArray, fn ($response) => $response['id'] === 'batch-req-2'))[0] ?? null;
    $response3 = array_values(array_filter($batchResponseArray, fn ($response) => $response['id'] === 'batch-req-3'))[0] ?? null;

    expect($response1['result']['content'][0]['text'])->toBe('Hello, Batch Item 1!');
    expect($response2['result']['content'][0]['text'])->toBe('Hello, Batch Item 2!');
    expect($response3['error']['code'])->toBe(-32601);
    expect($response3['error']['message'])->toContain("Method 'nonexistent/method' not found");


    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('can handle tool list request', function () {
    sendRequestToServer($this->process, 'init-tool-list', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]);
    await(readResponseFromServer($this->process, 'init-tool-list', $this->loop));
    sendNotificationToServer($this->process, 'notifications/initialized');
    await(delay(0.05, $this->loop));

    sendRequestToServer($this->process, 'tool-list-1', 'tools/list', []);
    $toolListResponse = await(readResponseFromServer($this->process, 'tool-list-1', $this->loop));

    expect($toolListResponse['id'])->toBe('tool-list-1');
    expect($toolListResponse)->not->toHaveKey('error');
    expect($toolListResponse['result']['tools'])->toBeArray()->toHaveCount(1);
    expect($toolListResponse['result']['tools'][0]['name'])->toBe('greet_stdio_tool');

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('can read a registered resource', function () {
    sendRequestToServer($this->process, 'init-res', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]);
    await(readResponseFromServer($this->process, 'init-res', $this->loop));
    sendNotificationToServer($this->process, 'notifications/initialized');
    await(delay(0.05, $this->loop));

    sendRequestToServer($this->process, 'res-read-1', 'resources/read', ['uri' => 'test://stdio/static']);
    $resourceResponse = await(readResponseFromServer($this->process, 'res-read-1', $this->loop));

    expect($resourceResponse['id'])->toBe('res-read-1');
    expect($resourceResponse)->not->toHaveKey('error');
    expect($resourceResponse['result']['contents'])->toBeArray()->toHaveCount(1);
    expect($resourceResponse['result']['contents'][0]['uri'])->toBe('test://stdio/static');
    expect($resourceResponse['result']['contents'][0]['text'])->toBe(ResourceHandlerFixture::$staticTextContent);
    expect($resourceResponse['result']['contents'][0]['mimeType'])->toBe('text/plain');

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('can get a registered prompt', function () {
    sendRequestToServer($this->process, 'init-prompt', 'initialize', ['protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION, 'clientInfo' => [], 'capabilities' => []]);
    await(readResponseFromServer($this->process, 'init-prompt', $this->loop));
    sendNotificationToServer($this->process, 'notifications/initialized');
    await(delay(0.05, $this->loop));

    sendRequestToServer($this->process, 'prompt-get-1', 'prompts/get', [
        'name' => 'simple_stdio_prompt',
        'arguments' => ['name' => 'StdioPromptUser']
    ]);
    $promptResponse = await(readResponseFromServer($this->process, 'prompt-get-1', $this->loop));

    expect($promptResponse['id'])->toBe('prompt-get-1');
    expect($promptResponse)->not->toHaveKey('error');
    expect($promptResponse['result']['messages'])->toBeArray()->toHaveCount(1);
    expect($promptResponse['result']['messages'][0]['role'])->toBe('user');
    expect($promptResponse['result']['messages'][0]['content']['text'])->toBe('Craft a friendly greeting for StdioPromptUser.');

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');

it('handles client not sending initialized notification before other requests', function () {
    sendRequestToServer($this->process, 'init-no-ack', 'initialize', [
        'protocolVersion' => Protocol::LATEST_PROTOCOL_VERSION,
        'clientInfo' => ['name' => 'ForgetfulClient', 'version' => '1.0'],
        'capabilities' => []
    ]);
    await(readResponseFromServer($this->process, 'init-no-ack', $this->loop));
    // Client "forgets" to send notifications/initialized


    sendRequestToServer($this->process, 'tool-call-no-ack', 'tools/call', [
        'name' => 'greet_stdio_tool',
        'arguments' => ['name' => 'NoAckUser']
    ]);
    $toolResponse = await(readResponseFromServer($this->process, 'tool-call-no-ack', $this->loop));

    expect($toolResponse['id'])->toBe('tool-call-no-ack');
    expect($toolResponse['error']['code'])->toBe(-32600);
    expect($toolResponse['error']['message'])->toContain('Client session not initialized');

    $this->process->stdin->end();
})->group('integration', 'stdio_transport');
