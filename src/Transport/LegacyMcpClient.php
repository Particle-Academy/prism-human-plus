<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

use Prism\HumanPlus\Contracts\RelayTransport;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceRevision;
use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\Exceptions\HumanPlusException;
use Prism\HumanPlus\Exceptions\SurfaceRevisionRejected;

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
    public function call(SurfaceAttachment $attachment, string $name, array $arguments, ?SurfaceRevision $revision = null): array
    {
        $this->initialize($attachment);

        $params = ['name' => $name, 'arguments' => $arguments];

        // PINNED ON EVERY CALL, not only on the ones that look like writes.
        //
        // The package cannot tell a read from a write: tool names come from the
        // surface, and MCP's `readOnlyHint` is explicitly a hint the spec says
        // not to trust for security decisions. Deciding from it would mean a
        // surface could mark a mutating tool read-only and have its writes go
        // out unpinned — the one direction that must not be possible.
        //
        // Pinning a read costs nothing: a read does not overwrite anything, so
        // the worst case is a surface choosing to refuse a stale read, which is
        // its call to make and recoverable because a rejection drops the marker.
        if ($revision instanceof SurfaceRevision) {
            $params['_meta'] = ['revision' => $revision->token];
        }

        return $this->request($attachment, 'tools/call', $params);
    }

    /**
     * Is this error the surface saying "your revision is stale"?
     *
     * Several spellings because this half of the wire is the surface's. JSON-RPC
     * has no precondition code of its own, so implementations reach for an
     * application code in `data`, a string code, or the HTTP status they would
     * have sent. Recognising one shape only would mean a surface that protects
     * its state correctly still loses updates through this client.
     *
     * @param  array<string, mixed>  $error
     */
    private static function rejectsRevision(array $error): bool
    {
        $candidates = [$error['code'] ?? null];
        if (is_array($error['data'] ?? null)) {
            $candidates[] = $error['data']['code'] ?? null;
            $candidates[] = $error['data']['reason'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate === 409) {
                return true;
            }
            if (is_string($candidate) && in_array(strtolower(trim($candidate)), [
                'conflict',
                'revision_mismatch',
                'revision_stale',
                'precondition_failed',
                'stale_revision',
            ], true)) {
                return true;
            }
        }

        return false;
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
            $error = is_array($response['error']) ? $response['error'] : [];

            if (self::rejectsRevision($error)) {
                throw new SurfaceRevisionRejected('The Fancy surface rejected the revision this call was pinned to.');
            }

            // The surface's own reason used to be DISCARDED here, so a
            // misconfigured tool, a refused argument and an internal error were
            // one indistinguishable sentence. The reason is the only part that
            // tells anyone what to do about it.
            $code = $error['code'] ?? null;
            $message = $error['message'] ?? null;

            throw new HumanPlusException(sprintf(
                'Fancy surface returned a JSON-RPC error%s%s.',
                is_scalar($code) ? ' ['.$code.']' : '',
                is_string($message) && trim($message) !== '' ? ': '.$message : '',
            ));
        }
        $result = $response['result'] ?? null;
        if (! is_array($result)) {
            throw new HumanPlusException('Fancy surface returned a malformed JSON-RPC result.');
        }

        return $result;
    }
}
