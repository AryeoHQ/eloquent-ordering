<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class WriteHalted extends RuntimeException
{
    public function __construct(Model $model)
    {
        parent::__construct(sprintf(
            'A listener of [%s:%s] halted an order write, so the write rolled back.',
            $model::class,
            $model->getKey(),
        ));
    }
}
