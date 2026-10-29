<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class WriteHalted extends RuntimeException
{
    public function __construct(Model $model, string $event)
    {
        parent::__construct(sprintf(
            'A [%s] listener of [%s:%s] halted an order write, so the write rolled back.',
            $event,
            $model::class,
            $model->getKey(),
        ));
    }
}
