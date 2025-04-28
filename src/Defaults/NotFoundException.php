<?php

namespace PhpMcp\Server\Defaults;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Basic PSR-11 Not Found Exception.
 */
class NotFoundException extends \Exception implements NotFoundExceptionInterface
{
}
