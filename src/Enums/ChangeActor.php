<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Enums;

use Prism\HumanPlus\Data\SurfaceChanges;
use Prism\HumanPlus\Data\SurfaceRevision;

/**
 * Who made a change — the field the whole change feed exists for.
 *
 * ## Why this is the load-bearing part
 *
 * "What changed" without "who" does not stop the failure this package's first
 * outside consumer predicts: the agent re-reads a surface, decides it has
 * drifted from what it intended, and puts it back — over a human's edit, with
 * no error anywhere. A list of changed handles does not prevent that, because
 * the agent's own last write appears in it and looks the same as a person's.
 *
 * What stops it is knowing a PERSON did this deliberately.
 *
 * ## Why {@see self::Unknown} is a case and not a null
 *
 * A change whose actor the surface did not name is not a change nobody made,
 * and it is not a change this agent made. Collapsing it into either is the
 * mistake; an agent that treats an unattributed change as its own will revert
 * it, and one that treats it as a human's will freeze on its own writes.
 *
 * Unknown means: something moved, and this surface cannot tell you whose hand
 * it was. That is a real answer and a caller should be able to see it.
 */
enum ChangeActor: string
{
    /** A person. The case the revert-guard is for. */
    case Human = 'human';

    /** This agent's own earlier write, reflected back. */
    case Agent = 'agent';

    /** Another agent, another session, a background job — not this one, not a person. */
    case Other = 'other';

    /** The surface reported a change and did not say whose it was. */
    case Unknown = 'unknown';

    /**
     * Map whatever the surface called it onto a case, without guessing.
     *
     * Several spellings because this half of the wire is the surface's, the
     * same reason {@see SurfaceRevision::fromResult()}
     * accepts several key names. Anything unrecognised is {@see self::Unknown}
     * rather than a default — a surface that says `"actor": "operator"` means
     * something, and this package quietly deciding it means `agent` would be
     * the revert bug arriving through the parser.
     */
    public static function parse(mixed $value): self
    {
        if (! is_string($value)) {
            return self::Unknown;
        }

        return match (strtolower(trim($value))) {
            'human', 'user', 'person', 'operator' => self::Human,
            'agent', 'assistant', 'self', 'me' => self::Agent,
            'other', 'system', 'job', 'service' => self::Other,
            default => self::Unknown,
        };
    }

    /**
     * Should an agent leave this change alone rather than correct it?
     *
     * **Only meaningful when the feed is {@see ChangeFeed::Attributed}.** Ask
     * {@see SurfaceChanges::deferTo()} instead, which
     * knows whether this surface can attribute anything at all.
     *
     * The distinction is not pedantry. A first draft of this method answered
     * `true` for {@see self::Unknown} on the grounds that undoing a human's
     * work is the expensive mistake — which reads well until you meet a surface
     * where NOTHING is attributed. The first one we asked is exactly that: every
     * write path is an agent tool, so every change would come back Unknown, and
     * an agent deferring to all of them could never correct its own work. It
     * would not be cautious, it would be inert.
     *
     * Unknown-because-this-change-was-anonymous and
     * unknown-because-this-surface-has-no-concept-of-who are different facts,
     * and only the second one is knowable here.
     */
    public function deservesDeference(): bool
    {
        return $this !== self::Agent;
    }
}
