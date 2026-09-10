<?php

declare(strict_types=1);

namespace Survos\AirNowBundle\Dto;

/** Dates are local calendar dates; forecasts are not current observations. */
final readonly class Forecast
{
    public function __construct(
        public string $dateIssue,
        public string $dateValid,
        public string $reportingArea,
        public string $reportingAreaCode,
        public string $stateCode,
        public string $parameterName,
        public int $aqi,
        public string $forecastAgency,
        public int $categoryNumber,
        public string $categoryName,
        public bool $actionDay,
        public string $discussion,
    ) {
    }
}
