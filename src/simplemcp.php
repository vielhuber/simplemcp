<?php

declare(strict_types=1);

namespace vielhuber\simplemcp;

use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

class simplemcp
{
    public const SERVER_VERSION = '1.0.0';
    public const PROTOCOL_VERSION = '2025-03-26';

    private array $instances = [];
    private array $tools = [];
    private array $instanceMap = [];

    public function __construct(
        private string $name,
        private string $log,
        private string $discovery,
        private readonly string $auth = 'static',
        private string $env = '.env'
    ) {
        // resolve relative paths against the calling script's directory
        $callerDir = dirname(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file']);
        if (!str_starts_with($this->log, '/')) {
            $this->log = $callerDir . '/' . $this->log;
        }
        if (!str_starts_with($this->discovery, '/')) {
            $this->discovery = $callerDir . '/' . $this->discovery;
        }
        if (!str_starts_with($this->env, '/')) {
            $this->env = $callerDir . '/' . $this->env;
        }

        $dotenv = \Dotenv\Dotenv::createImmutable(dirname($this->env), basename($this->env));
        $dotenv->load();

        $this->tools = $this->discoverTools();
        $this->instanceMap = $this->buildInstanceMap();

        if (php_sapi_name() === 'cli') {
            $this->handleStdio();
        } else {
            $this->handleHttp();
        }
    }

    // --- Transport: HTTP ---

    private function handleHttp(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $this->verifyToken();
        $this->log('request', ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode($this->buildError(null, -32700, 'Method Not Allowed'));
            exit();
        }

        try {
            $body = (string) file_get_contents('php://input');
            $request = json_decode($body, true);

            if ($request === null) {
                echo json_encode($this->buildError(null, -32700, 'Parse error'), JSON_THROW_ON_ERROR);
                exit();
            }

            $id = $request['id'] ?? null;
            $method = $request['method'] ?? '';
            $params = is_array($request['params'] ?? []) ? $request['params'] ?? [] : [];
            $isNotification = !array_key_exists('id', $request);

            // JSON-RPC notifications (e.g. "initialized") need no response
            if ($isNotification) {
                http_response_code(202);
                exit();
            }

            echo json_encode($this->dispatch($id, $method, $params), JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->log('error', 'JSON encoding error: ' . $e->getMessage());
            http_response_code(500);
            echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"JSON encoding error"}}';
        } catch (\Throwable $e) {
            $this->log('error', $e->getMessage());
            http_response_code(500);
            echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
        }
    }

    // --- Transport: stdio (newline-delimited JSON-RPC) ---

    private function handleStdio(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $isNotification = !array_key_exists('id', $request);

                // JSON-RPC notifications (e.g. "initialized") need no response
                if ($isNotification) {
                    continue;
                }

                $id = $request['id'] ?? null;
                $method = $request['method'] ?? '';
                $params = is_array($request['params'] ?? []) ? $request['params'] ?? [] : [];

                $response = json_encode(
                    $this->dispatch($id, $method, $params),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                );
                fwrite(STDOUT, $response . "\n");
            } catch (\JsonException $e) {
                $this->log('error', 'stdio JSON error: ' . $e->getMessage());
                fwrite(STDOUT, '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}' . "\n");
            } catch (\Throwable $e) {
                $this->log('error', $e->getMessage());
                fwrite(STDOUT, '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}' . "\n");
            }
        }
    }

    // --- Auth ---

    private function verifyToken(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $bearerToken = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';
        $token = $_ENV['MCP_TOKEN'] ?? '';

        if ($this->auth === 'totp') {
            if (!$this->verifyTotp($token, $bearerToken)) {
                $this->log('auth', 'Unauthorized request (invalid TOTP)');
                http_response_code(401);
                echo json_encode($this->buildError(null, -32600, 'Unauthorized'));
                exit();
            }
            return;
        }

        if (!hash_equals($token, $bearerToken)) {
            $this->log('auth', 'Unauthorized request');
            http_response_code(401);
            echo json_encode($this->buildError(null, -32600, 'Unauthorized'));
            exit();
        }
    }

    /**
     * Verify a TOTP token against a base32-encoded secret (RFC 6238).
     * Allows a ±1 time-step window to compensate for clock drift.
     */
    private function verifyTotp(string $base32Secret, string $token): bool
    {
        $timeStep = (int) floor(time() / 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->computeTotp($base32Secret, $timeStep + $offset), $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compute a 6-digit TOTP code for a given counter value (HMAC-SHA1, RFC 6238).
     */
    private function computeTotp(string $base32Secret, int $counter): string
    {
        $key = $this->base32Decode($base32Secret);
        $data = pack('J', $counter);
        $hash = hash_hmac('sha1', $data, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $code =
            (((ord($hash[$offset]) & 0x7f) << 24) |
                ((ord($hash[$offset + 1]) & 0xff) << 16) |
                ((ord($hash[$offset + 2]) & 0xff) << 8) |
                (ord($hash[$offset + 3]) & 0xff)) %
            1_000_000;
        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a base32-encoded string to raw bytes.
     */
    private function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(rtrim($input, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $result = '';
        foreach (str_split($input) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $result;
    }

    private function log(string $level, string $message): void
    {
        if ($this->log === '') {
            return;
        }
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        file_put_contents($this->log, $line, FILE_APPEND | LOCK_EX);
    }

    // --- Routing ---

    private function dispatch(mixed $id, string $method, array $params): array
    {
        return match ($method) {
            'initialize' => $this->buildResult($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => $this->name, 'version' => self::SERVER_VERSION]
            ]),
            'tools/list' => $this->buildResult($id, [
                'tools' => array_map(
                    fn($tool) => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'inputSchema' => $tool['inputSchema']
                    ],
                    $this->tools
                )
            ]),
            'tools/call' => $this->callTool($id, $params),
            default => $this->buildError($id, -32601, "Method not found: {$method}")
        };
    }

    // --- Tool discovery via #[McpTool] PHP Reflection ---

    private function discoverTools(): array
    {
        if ($this->discovery !== '' && is_dir($this->discovery)) {
            $this->autoloadDir($this->discovery);
            $realDiscovery = realpath($this->discovery);
            foreach (get_declared_classes() as $class) {
                $ref = new \ReflectionClass($class);
                // only consider classes whose file is inside the discovery directory
                $file = $ref->getFileName();
                if ($file === false || !str_starts_with(realpath($file) ?: '', $realDiscovery)) {
                    continue;
                }
                if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                    continue;
                }
                foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (!empty($method->getAttributes(McpTool::class))) {
                        $this->instances[] = $ref->newInstance();
                        break;
                    }
                }
            }
        }
        $tools = [];
        foreach ($this->instances as $instance) {
            $ref = new \ReflectionClass($instance);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attrs = $method->getAttributes(McpTool::class);
                if (empty($attrs)) {
                    continue;
                }
                /** @var McpTool $toolAttr */
                $toolAttr = $attrs[0]->newInstance();
                $tools[] = [
                    'name' => $toolAttr->name ?? $this->camelToSnake($method->getName()),
                    'description' => $toolAttr->description ?? $this->parseDocSummary($method->getDocComment() ?: ''),
                    'inputSchema' => $this->buildMethodSchema($method),
                    '_class' => get_class($instance),
                    '_method' => $method->getName()
                ];
            }
        }
        return $tools;
    }

    private function autoloadDir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // only include files that actually contain McpTool annotations to avoid
            // executing side effects (e.g. bootstrap code) in unrelated application files
            if (!str_contains(file_get_contents($file->getPathname()), 'McpTool')) {
                continue;
            }
            require_once $file->getPathname();
        }
    }

    private function buildInstanceMap(): array
    {
        $map = [];
        foreach ($this->instances as $instance) {
            $map[get_class($instance)] = $instance;
        }
        return $map;
    }

    // --- JSON Schema generation from method/parameter reflection ---

    private function buildMethodSchema(\ReflectionMethod $method): array
    {
        // method-level #[Schema(definition: [...])] takes full precedence
        $schemaAttrs = $method->getAttributes(Schema::class);
        $methodAttr = !empty($schemaAttrs) ? $schemaAttrs[0]->newInstance() : null;

        if ($methodAttr !== null && $methodAttr->definition !== null) {
            return $methodAttr->definition;
        }

        $properties = [];
        $required = [];
        foreach ($method->getParameters() as $param) {
            $properties[$param->getName()] = $this->buildParamSchema($param);
            if (!$param->isOptional()) {
                $required[] = $param->getName();
            }
        }

        // method-level #[Schema] without definition: merge properties/required/additionalProperties
        if ($methodAttr !== null) {
            if ($methodAttr->properties !== null) {
                foreach ($methodAttr->properties as $name => $propSchema) {
                    $properties[$name] = $propSchema;
                }
            }
            if ($methodAttr->required !== null) {
                $required = $methodAttr->required;
            }
        }

        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if (!empty($required)) {
            $schema['required'] = $required;
        }
        if ($methodAttr !== null && $methodAttr->additionalProperties !== null) {
            $schema['additionalProperties'] = $methodAttr->additionalProperties;
        }
        return $schema;
    }

    private function buildParamSchema(\ReflectionParameter $param): array
    {
        // Prefer explicit #[Schema] attribute on the parameter
        $schemaAttrs = $param->getAttributes(Schema::class);
        if (!empty($schemaAttrs)) {
            /** @var Schema $schemaAttr */
            $schemaAttr = $schemaAttrs[0]->newInstance();
            if ($schemaAttr->definition !== null) {
                return $schemaAttr->definition;
            }
            $schema = $this->buildFromSchemaAttr($schemaAttr);
            if (!isset($schema['type'])) {
                $type = $param->getType();
                if ($type !== null) {
                    $typeSchema = $this->buildTypeSchema($type);
                    if (isset($typeSchema['type'])) {
                        $schema['type'] = $typeSchema['type'];
                    }
                }
            }
            return $schema;
        }
        $type = $param->getType();
        return $type !== null ? $this->buildTypeSchema($type) : ['type' => 'string'];
    }

    private function buildFromSchemaAttr(Schema $attr): array
    {
        $schema = [];
        foreach (
            [
                'type',
                'description',
                'default',
                'enum',
                'format',
                'minLength',
                'maxLength',
                'pattern',
                'minimum',
                'maximum',
                'exclusiveMinimum',
                'exclusiveMaximum',
                'multipleOf',
                'items',
                'minItems',
                'maxItems',
                'uniqueItems',
                'properties',
                'required',
                'additionalProperties'
            ]
            as $key
        ) {
            if ($attr->$key !== null) {
                $schema[$key] = $attr->$key;
            }
        }
        return $schema ?: ['type' => 'string'];
    }

    private function buildTypeSchema(\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            $jsonType = $this->toJsonType($type->getName());
            return $type->allowsNull() ? ['type' => [$jsonType, 'null']] : ['type' => $jsonType];
        }
        if ($type instanceof \ReflectionUnionType) {
            $types = [];
            foreach ($type->getTypes() as $subType) {
                if ($subType instanceof \ReflectionNamedType) {
                    $json = $this->toJsonType($subType->getName());
                    if (!in_array($json, $types, true)) {
                        $types[] = $json;
                    }
                }
            }
            return ['type' => count($types) === 1 ? $types[0] : $types];
        }
        return ['type' => 'string'];
    }

    private function toJsonType(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'null' => 'null',
            default => 'string'
        };
    }

    // --- Tool execution ---

    private function callTool(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        foreach ($this->tools as $tool) {
            if ($tool['name'] !== $toolName) {
                continue;
            }
            try {
                $instance = $this->instanceMap[$tool['_class']];
                $refMethod = new \ReflectionMethod($instance, $tool['_method']);
                $result = $refMethod->invokeArgs($instance, $this->resolveArgs($refMethod, $arguments));
                $text = is_string($result)
                    ? $result
                    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                return $this->buildResult($id, [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'isError' => false
                ]);
            } catch (\Throwable $e) {
                return $this->buildResult($id, [
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'isError' => true
                ]);
            }
        }

        return $this->buildError($id, -32601, "Tool not found: {$toolName}");
    }

    private function resolveArgs(\ReflectionMethod $method, array $arguments): array
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $arguments)) {
                $args[] = $arguments[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }
        return $args;
    }

    // --- JSON-RPC helpers ---

    private function parseDocSummary(string $docblock): string
    {
        foreach (explode("\n", $docblock) as $line) {
            $line = trim($line, " \t*\/");
            if ($line !== '' && !str_starts_with($line, '@')) {
                return $line;
            }
        }
        return '';
    }

    private function camelToSnake(string $name): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
    }

    private function buildResult(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function buildError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
