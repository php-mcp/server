<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Enums;

enum StatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}
