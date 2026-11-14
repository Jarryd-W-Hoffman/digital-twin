<?php

declare(strict_types=1);

namespace App\Enums;

enum SurveyComparisonStatus: string
{
    case Measuring = 'measuring';
    case Ready = 'ready';
    case Failed = 'failed';
}
