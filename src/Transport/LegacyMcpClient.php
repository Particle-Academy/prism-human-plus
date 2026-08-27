<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\Exceptions\HumanPlusException;

final class LegacyMcpClient
{
    private int $nextId = 1;

    /** @var array<string, true> */
    private array $initialized = [];

    public function __construct(private readonly RelayTransport $transport) {}

    public function initialize(SurfaceAttachment $attachment): void
    {
        $key = $attachment->id.':'.$attachment->generation;
        if (isset($this->initialized[$key])) {
            return;
        }
        $response = $this->request($attachment, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'prism-human-plus', 'version' => '0.1.0'],
        ]);
        $version = $response['protocolVersion'] ?? null;
        if ($version !== '2025-06-18') {
            throw new HumanPlusException(sprintf('Fancy surface negotiated unsupported MCP revision [%s].', is_scalar($version) ? (string) $version : 'missing'));
        }
        $this->transport->notify($attachment, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->initialized[$key] = true;
    }

    /** @return list<ToolDefinition> */
    public function tools(SurfaceAttachment $attachment): array
    {
        $this->initialize($attachment);
        $result = $this->request($attachment, 'tools/list');
        $tools = $result['tools'] ?? null;
        if (! is_array($tools)) {
            throw new HumanPlusException('Fancy surface returned a malformed tools/list result.');
        }

        return array_values(array_map(fn (mixed $tool): ToolDefinition => ToolDefinition::from(is_array($tool) ? $tool : []), $tools));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(SurfaceAttachment $attachment, string $name, array $arguments): array
    {
        $this->initialize($attachment);

        return $this->request($attachment, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(SurfaceAttachment $attachment, string $method, array $params = []): array
    {
        $id = $this->nextId++;
        $frame = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== []) {
            $frame['params'] = $params;
        }
        $response = $this->transport->exchange($attachment, $frame);
        if (($response['id'] ?? null) !== $id) {
            throw new HumanPlusException('Fancy relay returned an uncorrelated JSON-RPC response.');
        }
        if (isset($response['error'])) {
            throw new HumanPlusException('Fancy surface returned a JSON-RPC error.');
        }
        $result = $response['result'] ?? null;
        if (! is_array($result)) {
            throw new HumanPlusException('Fancy surface returned a malformed JSON-RPC result.');
        }

        return $result;
    }
}
