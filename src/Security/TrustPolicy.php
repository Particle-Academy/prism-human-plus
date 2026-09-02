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

    /**
     * Characters that are INVISIBLE at the end of a tool name.
     *
     * Spelled out by codepoint, and identically in all three languages,
     * because the built-ins do not agree: PHP's `trim()` strips none of the
     * Unicode ones, JavaScript's `.trim()` strips all of them including
     * U+FEFF, and Python's `.strip()` strips them except U+FEFF. Using each
     * language's own idea of "whitespace" here would close one hole and open
     * three new divergences — see G-36.
     *
     * Zero-width characters (U+200B..U+200D, U+FEFF) are in the set for the
     * same reason the spaces are: they cannot be seen, and they defeat an
     * end-anchored pattern just as effectively.
     */
    private const INVISIBLE = '\x{0000}\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * Is this name reserved for the human confirmation surface?
     *
     * The name is NORMALISED first. A tool name is chosen by the SURFACE, and
     * `$` in this pattern anchors at the end — so before this was normalised, a
     * surface could name its tool `terminal_confirm ` (one trailing space) and
     * the reservation simply did not fire. That handed the confirmation tool to
     * the agent under every trust level including the wildcard, with nothing
     * raised anywhere. G-36.
     *
     * Trimming only ever makes this check MORE inclusive: it can reserve a name
     * that was previously callable, and can never un-reserve one. The allowlist
     * is matched against the RAW name and is deliberately untouched.
     */
    private function isHumanOnly(string $name): bool
    {
        $normalised = preg_replace('/^['.self::INVISIBLE.']+|['.self::INVISIBLE.']+$/u', '', $name);

        // A name that is not valid UTF-8 cannot be normalised, so it is treated
        // as reserved rather than passed through: refusing an unusable name
        // costs a broken surface a tool, while admitting it is the whole bug.
        if (! is_string($normalised)) {
            return true;
        }

        return preg_match('/(?:^|_)(?:confirm|reject|accept|approve|deny)$/i', $normalised) === 1;
    }
}
