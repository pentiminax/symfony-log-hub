<?php

declare(strict_types=1);

namespace App\Log\Application;

enum FilterOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case GREATER_THAN = 'greater_than';
    case GREATER_THAN_OR_EQUAL = 'greater_than_or_equal';
    case LESS_THAN = 'less_than';
    case LESS_THAN_OR_EQUAL = 'less_than_or_equal';
    case EXISTS = 'exists';
    case NOT_EXISTS = 'not_exists';
    case BEFORE = 'before';
    case AFTER = 'after';
    case BETWEEN = 'between';
}
