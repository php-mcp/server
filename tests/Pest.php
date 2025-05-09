<?php

// --- Discovery Test Helpers ---

const TEST_DISCOVERY_DIR = __DIR__.'/../_temp_discovery';
const TEST_STUBS_DIR = __DIR__.'/Mocks/DiscoveryStubs';

// Helper to recursively delete a directory
function deleteDirectory(string $dir): bool
{
    if (! is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteDirectory("$dir/$file") : unlink("$dir/$file");
    }

    return rmdir($dir);
}

// Helper to manage temp directory
function setupTempDir(): void
{
    if (is_dir(TEST_DISCOVERY_DIR)) {
        deleteDirectory(TEST_DISCOVERY_DIR);
    }
    mkdir(TEST_DISCOVERY_DIR, 0777, true);
}

function cleanupTempDir(): void
{
    if (is_dir(TEST_DISCOVERY_DIR)) {
        deleteDirectory(TEST_DISCOVERY_DIR);
    }
}

/**
 * Creates a test file in the temporary discovery directory by copying a stub.
 *
 * @param  string  $stubName  The name of the stub file (without .php) in TEST_STUBS_DIR.
 * @param  string|null  $targetFileName  The desired name for the file in TEST_DISCOVERY_DIR (defaults to stubName.php).
 * @return string The full path to the created file.
 *
 * @throws \Exception If the stub file does not exist.
 */
function createDiscoveryTestFile(string $stubName, ?string $targetFileName = null): string
{
    $stubPath = TEST_STUBS_DIR.'/'.$stubName.'.php';
    $targetName = $targetFileName ?? ($stubName.'.php');
    $targetPath = TEST_DISCOVERY_DIR.'/'.$targetName;

    if (! file_exists($stubPath)) {
        throw new \Exception("Discovery test stub file not found: {$stubPath}");
    }

    if (! copy($stubPath, $targetPath)) {
        throw new \Exception("Failed to copy discovery test stub '{$stubName}' to '{$targetName}'");
    }

    return $targetPath;
}

// --- Registry Test Helpers ---

function createTestTool(string $name = 'test-tool'): \PhpMcp\Server\Definitions\ToolDefinition
{
    return new \PhpMcp\Server\Definitions\ToolDefinition(
        className: 'Test\\ToolClass',
        methodName: 'runTool',
        toolName: $name,
        description: 'A test tool',
        inputSchema: ['type' => 'object', 'properties' => ['arg1' => ['type' => 'string']]]
    );
}

function createTestResource(string $uri = 'file:///test.res', string $name = 'test-resource'): \PhpMcp\Server\Definitions\ResourceDefinition
{
    return new \PhpMcp\Server\Definitions\ResourceDefinition(
        className: 'Test\\ResourceClass',
        methodName: 'readResource',
        uri: $uri,
        name: $name,
        description: 'A test resource',
        mimeType: 'text/plain',
        size: null,
        annotations: []
    );
}

function createTestPrompt(string $name = 'test-prompt'): \PhpMcp\Server\Definitions\PromptDefinition
{
    return new \PhpMcp\Server\Definitions\PromptDefinition(
        className: 'Test\\PromptClass',
        methodName: 'getPrompt',
        promptName: $name,
        description: 'A test prompt',
        arguments: []
    );
}

function createTestTemplate(string $uriTemplate = 'tmpl://{id}/data', string $name = 'test-template'): \PhpMcp\Server\Definitions\ResourceTemplateDefinition
{
    return new \PhpMcp\Server\Definitions\ResourceTemplateDefinition(
        className: 'Test\\TemplateClass',
        methodName: 'readTemplate',
        uriTemplate: $uriTemplate,
        name: $name,
        description: 'A test template',
        mimeType: 'application/json',
        annotations: []
    );
}
