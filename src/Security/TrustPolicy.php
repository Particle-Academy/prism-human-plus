<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Security;

use Prism\HumanPlus\Data\ToolDefinition;
use Prism\HumanPlus\Exceptions\ToolRefused;

final readonly class TrustPolicy
{
    /**
     * @param  list<string>|null  $allowedTools
     * @param  array<string, string>  $pins
     */
    private function __construct(private ?array $allowedTools, private bool $everyTool, private array $pins) {}

    public static function undeclared(): self
    {
        return new self(null, false, []);
    }

    /**
     * @param  list<string>  $tools
     * @param  array<string, string>  $pins
     */
    public static function allowing(array $tools, array $pins = []): self
    {
        return new self($tools, false, $pins);
    }

    /** @param array<string, string> $pins */
    public static function everyTool(array $pins = []): self
    {
        return new self(null, true, $pins);
    }

    public function assertDeclared(): void
    {
        if ($this->everyTool) {
            return;
        }
        if ($this->allowedTools === null) {
            throw new ToolRefused('Human+ surface trust is undeclared; no discovery request was sent.');
        }
        if ($this->allowedTools === []) {
            throw new ToolRefused('Human+ surface trust declares an empty allowlist.');
        }
    }

    public function assertAllows(ToolDefinition $tool): void
    {
        if ($this->isHumanOnly($tool->name)) {
            throw new ToolRefused(sprintf('Human+ tool [%s] is reserved for the human confirmation surface.', $tool->name));
        }
        if (! $this->everyTool && ! in_array($tool->name, $this->allowedTools ?? [], true)) {
            throw new ToolRefused(sprintf('Human+ tool [%s] is not allowed.', $tool->name));
        }
        $expected = $this->pins[$tool->name] ?? null;
        if ($expected !== null && ! hash_equals($expected, $tool->digest())) {
            throw new ToolRefused(sprintf('Human+ tool definition pin changed for [%s].', $tool->name));
        }
    }

    public function allows(string $name): bool
    {
        return ! $this->isHumanOnly($name) && ($this->everyTool || in_array($name, $this->allowedTools ?? [], true));
    }

    private function isHumanOnly(string $name): bool
    {
        return preg_match('/(?:^|_)(?:confirm|reject|accept|approve|deny)$/i', $name) === 1;
    }
}
