<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Documents;

enum DocumentKind: string
{
    case Notes = 'notes';
    case Drafts = 'drafts';
}
