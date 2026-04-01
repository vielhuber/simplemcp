[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/simplemcp)](https://github.com/vielhuber/simplemcp/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/simplemcp)](https://github.com/vielhuber/simplemcp/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/simplemcp)](https://github.com/vielhuber/simplemcp/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/simplemcp)](https://packagist.org/packages/vielhuber/simplemcp)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/simplemcp)](https://packagist.org/packages/vielhuber/simplemcp)

# 🐘 simplemcp 🐘

simplemcp is a simple php mcp server. it auto-discovers tool classes via reflection, loads them from a directory you point it at, and authenticates requests via a static bearer token or rotating totp codes (rfc 6238).

## installation

```sh
composer require vielhuber/simplemcp
```

## configuration

```sh
php -r '$b="ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";$r=random_bytes(20);$bits="";for($i=0;$i<20;$i++){$bits.=str_pad(decbin(ord($r[$i])),8,"0",STR_PAD_LEFT);}$s="";for($i=0;$i+5<=strlen($bits);$i+=5){$s.=$b[bindec(substr($bits,$i,5))];}echo "MCP_TOKEN=".$s.PHP_EOL;' > .env
```

## authentication

### `static` mode

```
Authorization: Bearer <MCP_TOKEN>
```

### `totp` mode

`MCP_TOKEN` is a base32-encoded shared secret (rfc 6238). the bearer is a fresh 6-digit totp code (30-second window, ±1 step tolerance). the server implements the algorithm natively, no extra library needed. on the client (python/fastmcp):

```php
$authorization_token = (static fn($h) => (static fn($o) => str_pad(((ord($h[$o]) & 0x7f) << 24 | (ord($h[$o+1]) & 0xff) << 16 | (ord($h[$o+2]) & 0xff) << 8 | ord($h[$o+3]) & 0xff) % 1_000_000, 6, '0', STR_PAD_LEFT))(ord($h[19]) & 0xf))(
    (static fn($key) => hash_hmac('sha1', pack('N*', 0) . pack('N*', (int) floor(time() / 30)), $key, true))(
        (static fn($bits) => implode('', array_map(fn($i) => chr(bindec(substr($bits, $i, 8))), range(0, strlen($bits) - 8, 8))))(
            implode('', array_map(fn($c) => str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)), 5, '0', STR_PAD_LEFT), str_split(strtoupper($_ENV['MCP_TOKEN']))))
        )
    ));
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

**stdio mode** (local, via php cli, no auth needed)

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

```apache
# forward Authorization header
RewriteEngine on
RewriteBase /
CGIPassAuth On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]

# block public access to .env
<Files ".env">
    Require all denied
</Files>
```
