<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Support;

use InvalidArgumentException;

final class OwnerAddress
{
    public static function from(string|object $owner): string
    {
        if (is_string($owner) && trim($owner) !== '') {
            return $owner;
        }
        if (is_object($owner) && method_exists($owner, 'key')) {
            $key = $owner->key();
            if (is_string($key) && trim($key) !== '') {
                return $key;
            }
        }
        throw new InvalidArgumentException('Human+ owner must be a nonempty string or expose key(): string.');
    }
}
