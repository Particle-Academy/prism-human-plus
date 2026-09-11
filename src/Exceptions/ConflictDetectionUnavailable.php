<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Exceptions;

use Prism\HumanPlus\Contracts\HasErrorCode;
use Prism\HumanPlus\HumanPlusManager;

/**
 * This surface does not mint revisions, so a lost update cannot be detected —
 * and the host asked to be told rather than to proceed.
 *
 * ## Why this exists at all, which is the point of the whole feature
 *
 * Optimistic concurrency here depends entirely on the surface supplying a
 * revision marker. If it never does, the package carries nothing, pins nothing,
 * and **detects nothing** — while every other part of the machinery looks
 * exactly the same from the outside. Configured, present, doing nothing.
 *
 * That is the failure mode this ecosystem keeps finding: a guard that reports
 * success because it was never asked a question it could answer. `/lab/team`
 * called a broken property green because its probe used a clean tool name;
 * `hasBase64()` passed every SSRF guard written with it. A conflict guard
 * against a revision-less surface is the same shape, and it would be the worst
 * instance of the three, because the whole reason a host turns it on is that it
 * has two writers and cannot afford a silent lost update.
 *
 * So `requireRevision: true` makes the absence LOUD. The first call is always
 * allowed — there is no way to know what a surface supplies before it has
 * answered once, and refusing it would refuse the read that finds out. From the
 * second call on, a surface that has never minted a revision is refused.
 *
 * ## What to do about it
 *
 * The fix is not here. A surface that wants the agent's writes pinned has to
 * mint a revision and reject a stale one — see the wire contract in
 * `README.md`. Until it does, the honest choices are to accept the risk
 * (`requireRevision: false`, the default) or to stop writing, and only the host
 * can decide which.
 *
 * {@see HumanPlusManager::conflictDetection()} answers the same
 * question without raising, so a host can assert it at attach time instead of
 * discovering it mid-turn.
 */
final class ConflictDetectionUnavailable extends HumanPlusException implements HasErrorCode
{
    public function code(): string
    {
        return 'conflict_detection_unavailable';
    }

    public static function forSurface(string $surface, string $tool): self
    {
        return new self(
            "The surface [{$surface}] has answered at least one call and has never supplied a revision marker, so "
            ."`{$tool}` was refused.\n\n"
            .'Without a revision there is nothing to pin a write to: a person editing this surface at the same time '
            .'would have their change overwritten, and neither they nor you would be told. This host is configured '
            ."with `requireRevision: true`, which turns that silence into this refusal.\n\n"
            .'To allow it, either have the surface mint a revision on its results and reject a stale one, or set '
            .'`requireRevision: false` and accept that concurrent edits can be lost. The second is a real choice for '
            .'a single-writer surface and a bad one for a shared canvas.'
        );
    }
}
