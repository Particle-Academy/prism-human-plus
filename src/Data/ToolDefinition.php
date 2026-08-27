<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use UnexpectedValueException;

final readonly class ToolDefinition
{
    /** @param array<string, mixed> $inputSchema */
    public function __construct(public string $name, public string $description, public array $inputSchema) {}

    /** @param array<string, mixed> $value */
    public static function from(array $value): self
    {
        if (! is_string($value['name'] ?? null) || trim($value['name']) === '') {
            throw new UnexpectedValueException('Human+ surface returned a tool without a usable name.');
        }
        $schema = $value['inputSchema'] ?? [];
        if (! is_array($schema)) {
            throw new UnexpectedValueException('Human+ surface returned a malformed tool schema.');
        }

        return new self($value['name'], is_string($value['description'] ?? null) ? $value['description'] : '', $schema);
    }

    public function digest(): string
    {
        return 'sha256:'.substr(hash('sha256', json_encode(self::sortDeep(['name' => $this->name, 'description' => $this->description, 'inputSchema' => $this->inputSchema]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 32);
    }

    private static function sortDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sortDeep(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
