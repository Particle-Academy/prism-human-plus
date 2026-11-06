<?php

declare(strict_types=1);

namespace Prism\HumanPlus\Transport;

/** @internal Explicit shared range policy; embedded IPv4 is classified first. */
final class RelayAddressPolicy
{
    private const NON_PUBLIC = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
        ['224.0.0.0', 4], ['240.0.0.0', 4],
        ['::', 96], ['::ffff:0:0', 96], ['64:ff9b:1::', 48], ['100::', 64],
        ['2001::', 32], ['2001:db8::', 32], ['fc00::', 7], ['fe80::', 10],
        ['fec0::', 10], ['ff00::', 8],
    ];

    /**
     * NAT64 and 6to4 carry an IPv4 address inside an IPv6 one, and the packet
     * goes to the embedded address, so that is what is judged. Not refused
     * outright: a DNS64 network gives public sites 64:ff9b:: addresses.
     * [prefix, bits, byte offset of the embedded IPv4]
     */
    private const EMBEDDING = [
        ['::ffff:0:0', 96, 12],
        ['64:ff9b::', 96, 12],
        ['2002::', 16, 2],
    ];

    public static function isNonPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        foreach (self::EMBEDDING as [$prefix, $bits, $offset]) {
            if (strlen($packed) === 16 && self::inRange($packed, $prefix, $bits)) {
                return self::isNonPublicAddress((string) inet_ntop(substr($packed, $offset, 4)));
            }
        }

        foreach (self::NON_PUBLIC as [$network, $bits]) {
            if (self::inRange($packed, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $network, int $bits): bool
    {
        $base = (string) inet_pton($network);

        if (strlen($packed) !== strlen($base)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $base, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
