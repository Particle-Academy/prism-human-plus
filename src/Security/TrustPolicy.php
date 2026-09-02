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
        if (! self::isWellFormedName($tool->name)) {
            throw new ToolRefused(sprintf('Human+ tool name [%s] is not a well-formed tool name.', $tool->name));
        }
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
        return self::isWellFormedName($name)
            && ! $this->isHumanOnly($name)
            && ($this->everyTool || in_array($name, $this->allowedTools ?? [], true));
    }

    /**
     * What a tool name may BE, checked before anything is asked about it.
     *
     * ASCII letters and digits, underscore, dot, colon and hyphen; a letter,
     * digit or underscore first; at most 128 characters. That accepts every
     * name this ecosystem actually uses — `terminal_confirm`, `sheet_write`,
     * `web_search`, `fetch_url`, namespaced `vendor.tool` — and refuses
     * everything else.
     *
     * ASCII-ONLY IS THE POINT, and it is what makes a homoglyph impossible. A
     * surface can otherwise declare `сonfirm` with a Cyrillic `с`: it is not
     * the reserved word, so the reservation correctly does not fire, and a
     * human reading the allowlist cannot tell it from the real one. That is not
     * a hole in the regex — it is a hole in the HUMAN's ability to audit the
     * trust config, which is the other half of the same trust model.
     *
     * Interior whitespace and control characters go the same way, which makes
     * this the outer guard for the class of problem the trailing-whitespace
     * normalisation below fixed one instance of. Both are kept: normalisation
     * stays as defence in depth in case this is ever relaxed.
     *
     * Anchored with `\z`, never `$` — `$` in PCRE also matches before a final
     * newline, which is precisely how `terminal_confirm\n` slipped past the
     * reservation before (G-33/G-36). A validator with that bug in it would
     * accept the very names it exists to refuse.
     */
    private static function isWellFormedName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,127}\z/', $name) === 1;
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
