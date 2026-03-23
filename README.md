# 🐘 simplemcp 🐘

simplemcp is a slim php http mcp server. it auto-discovers tool classes via reflection, loads them from a directory you point it at, and authenticates requests via a static bearer token or rotating totp codes (rfc 6238).

## installation

```sh
composer require vielhuber/simplemcp
```

## configuration

generate a token and write it directly to `.env` (works for both modes):

```sh
python3 -c "import pyotp; print('MCP_TOKEN=' + pyotp.random_base32())" > .env
```

## authentication

the auth mode is set in the `simplemcp` constructor via `auth`:

**`static`**

```
Authorization: Bearer <MCP_TOKEN>
```

**`totp`**

`MCP_TOKEN` is a base32-encoded shared secret (rfc 6238). the bearer is a fresh 6-digit totp code (30-second window, ±1 step tolerance). the server implements the algorithm natively — no extra library needed. on the client (python/fastmcp):

```python
import pyotp
token = pyotp.TOTP("MCP_TOKEN").now()
# send as: Authorization: Bearer <token>
```

## usage

configure the constructor at the bottom of `mcp-server.php`:

```php
require __DIR__ . '/vendor/autoload.php';
use vielhuber\simplemcp\simplemcp;
new simplemcp(
    name: 'my-mcp-server',
    log: 'mcp-server.log',
    discovery: '.',
    auth: 'static', // 'static'|'totp'
    env: '.env'
);
```

## adding tools

> **note:** simplemcp uses the same `#[McpTool]` and `#[Schema]` attribute syntax as [`php-mcp/server`](https://github.com/php-mcp/server). existing tool classes can be migrated by replacing `use PhpMcp\Server\Attributes\McpTool;` with `use vielhuber\simplemcp\Attributes\McpTool;` (and the same for `Schema`).

annotate any public method with `#[McpTool]` and drop the class file into your `discoveryDir`:

```php
use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

class MyTools
{
    /**
     * Returns the sum of two numbers.
     *
     * @return int
     */
    #[McpTool(name: 'add', description: 'Returns the sum of two numbers.')]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * Greets a person by name.
     *
     * @return string
     */
    #[McpTool]
    public function greet(
        #[Schema(type: 'string', description: 'The name to greet.')]
        string $name
    ): string {
        return "Hello, {$name}!";
    }
}
```

the server scans `discoveryDir` recursively, instantiates every class that has at least one `#[McpTool]` method, and registers its tools automatically. tool names default to `snake_case` of the method name when no `name` is provided. parameter types are inferred from php type hints and can be overridden via `#[Schema]`.

## mcp server

**http mode** (recommended for remote servers)

```json
{
    "mcpServers": {
        "simplemcp": {
            "url": "https://example.com/mcp-server.php",
            "headers": {
                "Authorization": "Bearer <MCP_TOKEN>"
            }
        }
    }
}
```

**stdio mode** (local, via php cli)

```json
{
    "mcpServers": {
        "simplemcp": {
            "command": "/usr/bin/php",
            "args": ["/path/to/project/mcp-server.php"]
        }
    }
}
```

## apache configuration

add the following to your `.htaccess` to ensure the `Authorization` header is forwarded to php:

```apache
RewriteEngine on
RewriteBase /
CGIPassAuth On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
```
