<?php

declare(strict_types=1);

namespace App\Log\Application;

enum SortDirection: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
