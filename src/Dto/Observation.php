<?php

declare(strict_types=1);

namespace Survos\AirNowBundle\Dto;

/** Current NowCast values; local date/time and timezone labels are preserved as reported. */
final readonly class Observation
{
    public function __construct(
        public string $dateObserved,
        public string $hourObserved,
        public string $localTimeZone,
        public string $reportingAreaName,
        public string $siteId,
        public string $siteName,
        public string $parameterName,
        public int $aqi,
        public string $categoryName,
        public string $reportingAgency,
        public string $lookupBehavior,
        public string $consideredMonitors,
        public string $lookupBoundary,
    ) {
    }
}
