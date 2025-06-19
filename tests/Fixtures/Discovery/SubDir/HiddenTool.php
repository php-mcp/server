<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery\SubDir;

use PhpMcp\Server\Attributes\McpTool;

class HiddenTool
{
    #[McpTool(name: 'hidden_subdir_tool')]
    public function run()
    {
    }
}
