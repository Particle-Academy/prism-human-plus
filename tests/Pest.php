<?php

declare(strict_types=1);

use Prism\HumanPlus\Data\Participant;
use Prism\HumanPlus\Data\SurfaceAttachment;
use Prism\HumanPlus\Data\SurfaceInvitation;

/**
 * An attachment pointing at a relay, for the transport suites.
 *
 * Here rather than in one of the test files because three of them need it, and
 * a helper defined in a sibling file only exists when Pest happens to have
 * loaded that file first — a suite that passes on the full run and fails on a
 * single `--filter` is worse than no helper at all.
 */
function relayAttachment(string $relayBaseUrl = 'https://relay.example.com'): SurfaceAttachment
{
    return new SurfaceAttachment(
        'surface_1', 'session:one',
        new SurfaceInvitation($relayBaseUrl, 'demo_001', str_repeat('a', 32), 'sheet:one', 'Demo'),
        new Participant('agent:one', 'One', '#000000'), 'php_worker_1',
    );
}
