<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
use vielhuber\simplemcp\simplemcp;

new simplemcp(
    name: 'my-mcp-server',
    log: 'mcp-server.log',
    discovery: '.',
    auth: 'static',
    env: '.env'
);