<?php

declare(strict_types=1);

namespace Support\Ordering\Keys;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Support\Ordering\Keys\Exceptions\InvalidKey;
use Tests\TestCase;

#[CoversClass(FractionalIndex::class)]
class FractionalIndexTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function upstreamKeys(): iterable
    {
        foreach (self::vectors()['keyBetween'] as $vector) {
            yield sprintf('%s..%s', $vector['a'] ?? 'null', $vector['b'] ?? 'null') => [$vector['a'], $vector['b'], $vector['key']];
        }
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function upstreamInvalidKeys(): iterable
    {
        foreach (self::vectors()['invalid'] as $vector) {
            yield sprintf('%s..%s', $vector['a'] ?? 'null', $vector['b'] ?? 'null') => [$vector['a'], $vector['b'], $vector['message']];
        }
    }

    /**
     * @return iterable<string, array{?string, ?string, int, list<string>}>
     */
    public static function keyRuns(): iterable
    {
        yield 'between two keys' => ['a0', 'a1', 5, ['a08', 'a0G', 'a0V', 'a0d', 'a0l']];
        yield 'from nothing' => [null, null, 3, ['a0', 'a1', 'a2']];
        yield 'before a key' => [null, 'a0', 3, ['Zx', 'Zy', 'Zz']];
        yield 'after a key' => ['a0', null, 3, ['a1', 'a2', 'a3']];
        yield 'one key' => ['a0', 'a1', 1, ['a0V']];
        yield 'no keys' => ['a0', 'a1', 0, []];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysOutsideTheAlphabet(): iterable
    {
        yield 'in the integer part' => ['a!'];
        yield 'in the fraction' => ['a0!'];
    }

    #[DataProvider('upstreamKeys')]
    #[Test]
    public function it_reproduces_the_upstream_key(string|null $a, string|null $b, string $key): void
    {
        $this->assertSame($key, FractionalIndex::keyBetween($a, $b));
    }

    #[DataProvider('upstreamInvalidKeys')]
    #[Test]
    public function it_rejects_the_upstream_invalid_key(string|null $a, string|null $b, string $message): void
    {
        $this->assertInvalidKey($message, fn () => FractionalIndex::keyBetween($a, $b));
    }

    /**
     * @param  list<string>  $keys
     */
    #[DataProvider('keyRuns')]
    #[Test]
    public function it_generates_a_run_of_keys(string|null $a, string|null $b, int $n, array $keys): void
    {
        $this->assertSame($keys, FractionalIndex::keysBetween($a, $b, $n));
    }

    #[Test]
    public function it_appends_one_thousand_keys(): void
    {
        $keys = [];
        $last = null;

        for ($i = 0; $i < 1000; $i++) {
            $last = FractionalIndex::keyBetween($last, null);
            $keys[] = $last;
        }

        $this->assertSame('bF7', $last);
        $this->assertSortedByBytes($keys);
    }

    #[Test]
    public function it_keeps_byte_order_under_random_inserts(): void
    {
        $randomizer = new Randomizer(new Mt19937(1));
        /** @var list<string> $keys */
        $keys = [];

        for ($i = 0; $i < 1000; $i++) {
            $position = $randomizer->getInt(0, count($keys));
            $key = FractionalIndex::keyBetween($keys[$position - 1] ?? null, $keys[$position] ?? null);
            array_splice($keys, $position, 0, [$key]);
        }

        $this->assertSortedByBytes($keys);
    }

    #[Test]
    public function it_rejects_a_negative_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FractionalIndex::keysBetween('a0', 'a1', -1);
    }

    #[Test]
    public function it_validates_a_key(): void
    {
        FractionalIndex::validate('a0V');

        $this->assertInvalidKey('invalid order key: a0V0', fn () => FractionalIndex::validate('a0V0'));
    }

    #[DataProvider('keysOutsideTheAlphabet')]
    #[Test]
    public function it_rejects_a_character_outside_base_62(string $key): void
    {
        $this->assertInvalidKey('invalid order key: '.$key, fn () => FractionalIndex::validate($key));
    }

    private function assertInvalidKey(string $message, callable $call): void
    {
        try {
            $call();
        } catch (InvalidKey $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected '.InvalidKey::class.': '.$message);
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertSortedByBytes(array $keys): void
    {
        $sorted = $keys;
        usort($sorted, strcmp(...));

        $this->assertSame($sorted, $keys);
        $this->assertSame(array_values(array_unique($keys)), $keys);
    }

    /**
     * @return array{keyBetween: list<array{a: ?string, b: ?string, key: string}>, invalid: list<array{a: ?string, b: ?string, message: string}>}
     */
    private static function vectors(): array
    {
        /** @var array{keyBetween: list<array{a: ?string, b: ?string, key: string}>, invalid: list<array{a: ?string, b: ?string, message: string}>} */
        return json_decode((string) file_get_contents(dirname(__DIR__, 4).'/tests/Fixtures/rocicorp-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
