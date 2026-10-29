<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ContendedOrder extends RuntimeException
{
    public function __construct(Model $item, int $attempts)
    {
        parent::__construct(sprintf(
            '[%s:%s] changed parent during each of %d attempts to lock its order.',
            $item::class,
            $item->getKey(),
            $attempts,
        ));
    }
}
