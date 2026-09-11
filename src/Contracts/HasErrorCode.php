<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Contracts;

use Prism\HumanPlus\Exceptions\AttachmentUnauthorized;
use Prism\HumanPlus\Exceptions\SurfaceUnavailable;
use Prism\HumanPlus\Exceptions\ToolRefused;

/**
 * A failure a caller can branch on without reading English.
 *
 * The same contract `prism-harness` carries, and for the same reason: a
 * consumer that has to `str_contains` a message to tell one failure from
 * another turns every wording improvement into a silent breaking change, and
 * the suite that pinned the prose becomes a reason not to improve it.
 *
 * **Only the failures added from v0.3.0 implement this.** The ones that predate
 * it — {@see ToolRefused},
 * {@see SurfaceUnavailable},
 * {@see AttachmentUnauthorized} — do not, so
 * catching the base class does not guarantee a code is there. Retrofitting them
 * is worth doing and is not bundled here; doing it properly means deciding a
 * stable string for each, which is a separate decision from this one.
 */
interface HasErrorCode
{
    /** A stable, lower_snake_case identifier. Identical in every language. */
    public function code(): string;
}
