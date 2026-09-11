<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Exceptions;

use Prism\HumanPlus\Data\SurfaceRevision;

/**
 * The surface moved between the agent's read and its write.
 *
 * ## What this replaces, which is nothing
 *
 * Before this existed, a human committing an edit while an agent was mid-turn
 * produced **no failure at all**. The agent's write landed on top, the human's
 * change was gone, and the only party who could tell was the person watching
 * their work disappear. A lost update reports nothing by construction: both
 * writes succeeded, and that is exactly the problem.
 *
 * So this is not a nicer error for an existing failure. It is the first time
 * that failure is visible.
 *
 * ## Why the package refuses rather than merges
 *
 * It cannot merge. The package never models what a surface's state is — tools
 * come from the surface's own `tools/list` and the data behind them is opaque
 * here. Anything that looked like a merge would be this package guessing at a
 * document it cannot read.
 *
 * Refusing is not a lesser outcome. An agent that is told its read is stale can
 * re-read and decide, which is the only party in the exchange that knows what
 * it was trying to achieve. A merge invented here would take that decision away
 * and be wrong silently.
 *
 * ## It is raised for the agent, not only for the log
 *
 * The message is written to be read by a MODEL mid-turn, because that is who
 * receives it: the turn continues, the agent sees the refusal as a tool result,
 * and the useful next move — re-read, then decide — has to be legible from the
 * text alone. The stable `code()` is there so a host can branch without
 * matching prose.
 */
final class SurfaceChangedUnderYou extends HumanPlusException
{
    public function code(): string
    {
        return 'surface_changed_under_you';
    }

    public static function while(string $tool, ?SurfaceRevision $sent): self
    {
        $seen = $sent instanceof SurfaceRevision
            ? "You were working from the surface as it looked at revision {$sent->token}, observed when you called `{$sent->observedFrom}`."
            : 'You were working from a surface state whose revision was never recorded.';

        return new self(
            "The surface changed while you were working on it, so `{$tool}` was NOT applied.\n\n"
            ."{$seen} Someone else — a person editing the same surface, or another "
            ."participant — has committed a change since then.\n\n"
            .'Nothing was written and nothing was lost. Read the surface again before deciding what to do: the '
            .'change may already have done what you intended, may conflict with it, or may be unrelated. Do not '
            ."simply repeat `{$tool}` with the same arguments — that is how the other change gets overwritten, "
            .'which is the outcome this refusal exists to prevent.'
        );
    }
}
