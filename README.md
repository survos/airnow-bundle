# survos/airnow-bundle

Small AirNow client for PHP 8.5 and Symfony 8.1. Extends `survos/kit-bundle`;
`survos/fetch-bundle` handles Symfony HttpClient requests, retry/backoff and SQLite caching.

## Status

Initial implementation: **replacement current forecast and current observation endpoints**,
verified with live responses on 2026-09-10. Both return typed DTOs, preserving every
returned pollutant. Forecasts are predictions; observations carry current NowCast AQI.

| Client method | Endpoint | ZIP query parameter |
| --- | --- | --- |
| `currentForecastsByZip()` | `/aq/forecast/current/` | `zipCode` |
| `currentObservationsByZip()` | `/aq/observation/current/ziplatlong/` | `zipcode` |

AirNow's [service catalog](https://docs.airnowapi.org/webservices) also covers current
observations, historical observations/forecasts, monitoring-site data and KML contour maps.
It marks the older ZIP/latitude/longitude services for retirement in fall 2026.
This bundle uses the replacement endpoints supplied by the developer and verified live.
The detailed observation documentation still requires login; other location selectors and
reporting-area queries are not implemented or inferred from endpoint names.

## Install and configure

After publishing this package:

```bash
composer require survos/airnow-bundle
```

Flex registers the bundle classes. Without Flex, enable these in `config/bundles.php`:

```php
Survos\Kit\SurvosKitBundle::class => ['all' => true],
Survos\FetchBundle\SurvosFetchBundle::class => ['all' => true],
Survos\AirNowBundle\SurvosAirNowBundle::class => ['all' => true],
```

Copy `examples/survos_airnow.yaml` into `config/packages/survos_airnow.yaml`.
Set `AIRNOW_API_KEY=your-key` in the application's untracked `.env.local`.
An empty key allows installation/container boot; fetching fails with an actionable exception.
ZIPs must be strings (quote leading zeros). Defaults are preferences exposed on the client;
setting them does not schedule requests or trigger a bulk fetch.

```php
use Survos\AirNowBundle\Service\AirNowClient;

// Inject AirNowClient in an ordinary Symfony service/controller.
$observations = $airNow->currentObservationsByZip('20002');
$forecasts = $airNow->currentForecastsByZip('20008');
$forecasts = $airNow->currentForecastsByZip('20008', force: true);
foreach ($forecasts as $forecast) {
    // $forecast->dateValid, ->parameterName, ->aqi, ->categoryName, ->actionDay
}
```

`Observation` exposes `aqi` (AirNow `nowcastAQI`), `categoryName` (`aqiCategoryName`)
and `siteId` (`siteID`), plus `dateObserved`, `hourObserved`, `localTimeZone`,
`reportingAreaName`, `siteName`, `parameterName`, `reportingAgency`, `lookupBehavior`,
`consideredMonitors` and `lookupBoundary`. Monitor IDs remain strings. Local times remain
`HH:MM` strings with the reported timezone label, without guessing a UTC offset.
The ZIP is the caller's input, not an API response field. The later application should
retain it alongside observations when persisting history.

The new ZIP response may select different monitors for different pollutants. It supplies
lookup details (the verified response reports closest readings within 50 miles), unlike
the old reference's reporting-area payload and explicit 25-mile distance parameter.
No legacy distance option is sent. The API does not provide a numeric category in this
response; the client preserves its category name rather than inventing a number.

Empty lists mean no data. HTTP/transport failures and malformed responses throw
`Survos\AirNowBundle\Exception\AirNowException`. Failed/malformed responses are evicted,
so they do not poison later refreshes. Extra API response fields are ignored; required
fields and their types are checked by Symfony Serializer. Date strings retain AirNow's
calendar-date semantics. No pollutant selection or AQI aggregation occurs in the client.

## Cache and credentials

`cache_ttl` defaults to one hour. `force: true` replaces the cached response. The cache is
Fetch-bundle's disposable SQLite cache, separate from future Doctrine observation history.
Configure its location through `survos_fetch.persistent_cache_path` (default
`%kernel.project_dir%/var/data/fetch_cache.db`). PDO SQLite must be enabled.
The [AirNow FAQ](https://docs.airnowapi.org/faq) recommends caching, with observations
usually updated hourly and forecasts usually issued daily.

AirNow requires the key in the request query. The client emits sanitized exceptions and
uses a fetcher with a NullLogger so retry messages do not print the key. Fetch-bundle
stores the full request URL in its cached result: treat the SQLite cache as private
credential-bearing application data, and clear it when rotating keys. HTTP tracing/profiling
may also record URLs. Never ship development caches, profiler data or `.env.local` in builds.

## Development

```bash
cd ~/sites/mono/bu/airnow-bundle
composer install
composer validate --strict
composer test
```

Tests use MockHttpClient with the real PersistentFetcher and no API key/network access.
A kernel test verifies bundle registration and autowiring with published dependencies.
The recorded fixtures contain public response data only.

No Doctrine entity, settings persistence, scheduler, native shell or UI belongs in this bundle.
The consuming application will own AqiMonitor, observation history and desktop lifecycle.
