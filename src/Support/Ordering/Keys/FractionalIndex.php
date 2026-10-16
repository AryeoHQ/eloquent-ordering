<?php

declare(strict_types=1);

namespace Support\Ordering\Keys;

use InvalidArgumentException;
use LogicException;
use Support\Ordering\Keys\Exceptions\InvalidKey;

final class FractionalIndex
{
    private const string DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const string HEADS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const string SMALLEST_INTEGER = 'A00000000000000000000000000';

    private const string FIRST_KEY = 'a0';

    public static function keyBetween(string|null $a, string|null $b): string
    {
        if ($a !== null) {
            self::validate($a);
        }

        if ($b !== null) {
            self::validate($b);
        }

        if ($a !== null && $b !== null && strcmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }

        if ($a === null) {
            if ($b === null) {
                return self::FIRST_KEY;
            }

            $ib = self::integerPart($b);
            $fb = substr($b, strlen($ib));

            if ($ib === self::SMALLEST_INTEGER) {
                return $ib.self::midpoint('', $fb);
            }

            if (strcmp($ib, $b) < 0) {
                return $ib;
            }

            return self::decrementInteger($ib) ?? throw new LogicException('cannot decrement any more');
        }

        $ia = self::integerPart($a);
        $fa = substr($a, strlen($ia));

        if ($b === null) {
            return self::incrementInteger($ia) ?? $ia.self::midpoint($fa, null);
        }

        $ib = self::integerPart($b);
        $fb = substr($b, strlen($ib));

        if ($ia === $ib) {
            return $ia.self::midpoint($fa, $fb);
        }

        $i = self::incrementInteger($ia) ?? throw new LogicException('cannot increment any more');

        if (strcmp($i, $b) < 0) {
            return $i;
        }

        return $ia.self::midpoint($fa, null);
    }

    /**
     * @return list<string>
     */
    public static function keysBetween(string|null $a, string|null $b, int $n): array
    {
        if ($n < 0) {
            throw new InvalidArgumentException('n must not be negative: '.$n);
        }

        if ($n === 0) {
            return [];
        }

        if ($n === 1) {
            return [self::keyBetween($a, $b)];
        }

        if ($b === null) {
            $c = self::keyBetween($a, $b);
            $result = [$c];

            for ($i = 0; $i < $n - 1; $i++) {
                $c = self::keyBetween($c, $b);
                $result[] = $c;
            }

            return $result;
        }

        if ($a === null) {
            $c = self::keyBetween($a, $b);
            $result = [$c];

            for ($i = 0; $i < $n - 1; $i++) {
                $c = self::keyBetween($a, $c);
                $result[] = $c;
            }

            return array_reverse($result);
        }

        $mid = intdiv($n, 2);
        $c = self::keyBetween($a, $b);

        return [
            ...self::keysBetween($a, $c, $mid),
            $c,
            ...self::keysBetween($c, $b, $n - $mid - 1),
        ];
    }

    public static function validate(string $key): void
    {
        if ($key === self::SMALLEST_INTEGER) {
            throw new InvalidKey('invalid order key: '.$key);
        }

        $integer = self::integerPart($key);

        if (strspn($key, self::DIGITS) !== strlen($key) || str_ends_with(substr($key, strlen($integer)), self::DIGITS[0])) {
            throw new InvalidKey('invalid order key: '.$key);
        }
    }

    private static function midpoint(string $a, string|null $b): string
    {
        $zero = self::DIGITS[0];

        if ($b !== null && strcmp($a, $b) >= 0) {
            throw new InvalidKey($a.' >= '.$b);
        }

        if (str_ends_with($a, $zero) || ($b !== null && str_ends_with($b, $zero))) {
            throw new InvalidKey('trailing zero');
        }

        if ($b !== null && $b !== '') {
            $n = 0;

            while (($a[$n] ?? $zero) === ($b[$n] ?? null)) {
                $n++;
            }

            if ($n > 0) {
                return substr($b, 0, $n).self::midpoint(substr($a, $n), substr($b, $n));
            }
        }

        $digitA = $a === '' ? 0 : self::digit($a[0]);
        $digitB = $b === null ? strlen(self::DIGITS) : self::digit($b[0]);

        if ($digitB - $digitA > 1) {
            return self::DIGITS[intdiv($digitA + $digitB + 1, 2)];
        }

        if ($b !== null && strlen($b) > 1) {
            return substr($b, 0, 1);
        }

        return self::DIGITS[$digitA].self::midpoint(substr($a, 1), null);
    }

    private static function validateInteger(string $integer): void
    {
        if (strlen($integer) !== self::integerLength(substr($integer, 0, 1))) {
            throw new InvalidKey('invalid integer part of order key: '.$integer);
        }
    }

    private static function integerLength(string $head): int
    {
        $index = self::headIndex($head);
        $half = intdiv(strlen(self::HEADS), 2);

        return $index < $half ? $half - $index + 1 : $index - $half + 2;
    }

    private static function integerPart(string $key): string
    {
        $length = self::integerLength(substr($key, 0, 1));

        if ($length > strlen($key)) {
            throw new InvalidKey('invalid order key: '.$key);
        }

        return substr($key, 0, $length);
    }

    private static function incrementInteger(string $integer): string|null
    {
        self::validateInteger($integer);

        $head = $integer[0];
        $trailing = '';

        for ($i = strlen($integer) - 1; $i >= 1; $i--) {
            $digit = self::digit($integer[$i]) + 1;

            if ($digit !== strlen(self::DIGITS)) {
                return $head.substr($integer, 1, $i - 1).self::DIGITS[$digit].$trailing;
            }

            $trailing = self::DIGITS[0].$trailing;
        }

        $headIndex = self::headIndex($head);

        if ($headIndex === strlen(self::HEADS) - 1) {
            return null;
        }

        $next = self::HEADS[$headIndex + 1];

        return $next.self::resize($trailing, self::integerLength($next) - self::integerLength($head), self::DIGITS[0]);
    }

    private static function decrementInteger(string $integer): string|null
    {
        self::validateInteger($integer);

        $head = $integer[0];
        $last = self::DIGITS[strlen(self::DIGITS) - 1];
        $trailing = '';

        for ($i = strlen($integer) - 1; $i >= 1; $i--) {
            $digit = self::digit($integer[$i]) - 1;

            if ($digit !== -1) {
                return $head.substr($integer, 1, $i - 1).self::DIGITS[$digit].$trailing;
            }

            $trailing = $last.$trailing;
        }

        $headIndex = self::headIndex($head);

        if ($headIndex === 0) {
            return null;
        }

        $previous = self::HEADS[$headIndex - 1];

        return $previous.self::resize($trailing, self::integerLength($previous) - self::integerLength($head), $last);
    }

    private static function resize(string $trailing, int $lengthDelta, string $pad): string
    {
        return match (true) {
            $lengthDelta > 0 => $trailing.$pad,
            $lengthDelta < 0 => substr($trailing, 1),
            default => $trailing,
        };
    }

    private static function headIndex(string $head): int
    {
        $index = $head === '' ? false : strpos(self::HEADS, $head);

        if ($index === false) {
            throw new InvalidKey('invalid order key head: '.$head);
        }

        return $index;
    }

    private static function digit(string $char): int
    {
        $index = strpos(self::DIGITS, $char);

        return $index === false ? 0 : $index;
    }
}
