<?php

declare(strict_types=1);

namespace PhpMcp\Server\Bridge\Symfony\Command;

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp-server:start', description: 'Starts MCP server')]
class McpServerStartCommand extends Command
{
    public function __construct(private Server $server)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->server->listen(new StdioServerTransport());

        return Command::SUCCESS;
    }
}
