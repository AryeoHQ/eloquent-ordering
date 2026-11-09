<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ContendedOrder extends RuntimeException
{
    public function __construct(Model $model, int $attempts)
    {
        parent::__construct(sprintf(
            'A membership changed during each of %d attempts to lock the order of [%s:%s].',
            $attempts,
            $model::class,
            $model->getKey(),
        ));
    }
}
