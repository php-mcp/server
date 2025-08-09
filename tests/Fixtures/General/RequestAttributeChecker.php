<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\General;

use PhpMcp\Schema\Content\TextContent;
use PhpMcp\Server\Context;

class RequestAttributeChecker
{
    public function checkAttribute(Context $context): TextContent
    {
        $attribute = $context->request->getAttribute('middleware-attr');
        if ($attribute === 'middleware-value') {
            return TextContent::make('middleware-value-found: ' . $attribute);
        }

        return TextContent::make('middleware-value-not-found: ' . $attribute);
    }
}
