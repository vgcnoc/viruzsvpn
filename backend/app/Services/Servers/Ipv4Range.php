<?php

namespace App\Services\Servers;

use InvalidArgumentException;

final class Ipv4Range
{
    public static function parse(string $value): array
    {
        $parts = explode('-', $value);
        if (count($parts) > 2) {
            throw new InvalidArgumentException('Invalid IPv4 range.');
        }
        $start = self::address($parts[0]);
        $end = self::address($parts[1] ?? $parts[0]);
        if ($start > $end) {
            throw new InvalidArgumentException('IPv4 range must start before it ends.');
        }
        return [$start, $end];
    }

    public static function overlaps(string $first, string $second): bool
    {
        [$firstStart, $firstEnd] = self::parse($first);
        [$secondStart, $secondEnd] = self::parse($second);
        return $firstStart <= $secondEnd && $secondStart <= $firstEnd;
    }

    public static function withinSubnet(string $range, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || ! ctype_digit($parts[1]) || (int) $parts[1] > 32) {
            return false;
        }
        $address = self::address($parts[0]);
        $mask = (0xffffffff << (32 - (int) $parts[1])) & 0xffffffff;
        $network = $address & $mask;
        $broadcast = $network | (~$mask & 0xffffffff);
        [$start, $end] = self::parse($range);
        return $start > $network && $end < $broadcast;
    }

    private static function address(string $value): int
    {
        if (! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException('Invalid IPv4 address.');
        }
        return (int) sprintf('%u', ip2long($value));
    }
}
