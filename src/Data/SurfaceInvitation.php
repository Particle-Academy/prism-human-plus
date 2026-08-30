<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Data;

use InvalidArgumentException;

final readonly class SurfaceInvitation
{
    public function __construct(
        public string $relayBaseUrl,
        public string $sessionId,
        public string $token,
        public string $surfaceId,
        public string $application,
        public bool $allowInsecureLoopback = false,
    ) {
        $scheme = parse_url($relayBaseUrl, PHP_URL_SCHEME);
        $host = parse_url($relayBaseUrl, PHP_URL_HOST);
        $local = $allowInsecureLoopback && $scheme === 'http' && in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if (($scheme !== 'https' && ! $local) || ! is_string($host) || $host === '') {
            throw new InvalidArgumentException('A Human+ invitation requires an absolute HTTPS relay URL.');
        }
        if (! preg_match('/^[A-Za-z0-9_-]{4,64}$/', $sessionId)) {
            throw new InvalidArgumentException('Human+ relay session id is malformed.');
        }
        if (strlen($token) < 16) {
            throw new InvalidArgumentException('Human+ relay token is too short.');
        }
    }
}
