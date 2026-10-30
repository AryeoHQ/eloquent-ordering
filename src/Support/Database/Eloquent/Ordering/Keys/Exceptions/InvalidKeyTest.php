<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Keys\Exceptions;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Tests\TestCase;

#[CoversClass(InvalidKey::class)]
class InvalidKeyTest extends TestCase
{
    #[Test]
    public function it_is_an_invalid_argument_that_names_the_rejected_key(): void
    {
        try {
            FractionalIndex::validate('a0V0');
            $this->fail('A key with a trailing zero passed validation.');
        } catch (InvalidKey $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertSame('invalid order key: a0V0', $exception->getMessage());
        }
    }
}
