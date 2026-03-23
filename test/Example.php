<?php

declare(strict_types=1);

namespace test;

use vielhuber\simplemcp\Attributes\McpTool;

class Example
{
    /**
     * Simple test function that returns the number 42.
     *
     * @return int
     */
    #[McpTool(name: 'test_function', description: 'Simple test function that returns the number 42.')]
    public function testFunction(): int
    {
        return 42;
    }
}
