<?php

const TEST_DISCOVERY_DIR = __DIR__.'/../_temp_discovery';
const TEST_STUBS_DIR = __DIR__.'/Mocks/DiscoveryStubs';

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
