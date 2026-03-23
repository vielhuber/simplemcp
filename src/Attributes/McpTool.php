<?php

declare(strict_types=1);

namespace vielhuber\simplemcp\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class McpTool
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}
