# AirNow bundle and desktop plan

## Bundle first

- [x] Inspect showcase and monorepo bundle conventions; use kit/fetch published packages.
- [x] Verify replacement `/aq/forecast/current/` with a live ZIP request; typed Forecast DTO.
- [x] Configure API key, default ZIPs, cache TTL and timeout under `survos_airnow`.
- [x] Test conversion, malformed/empty data, HTTP failures, cache/force, ZIPs and kernel wiring.
- [x] Verify developer-supplied `/aq/observation/current/ziplatlong/` with a live ZIP request.
  Documentation URL requires login; DTO follows the verified response.
- [x] Add typed Observation DTO, ZIP method, normalization and cache/error tests.
  Retain monitor identity and lookup semantics; do not reuse the old reporting-area payload.
- [ ] Expand forecast location selectors and historical/site/map methods only against verified specs.
- [ ] User publishes to Packagist after review; no push/tag/release performed by this task.

The public catalog marks older ZIP/lat-long APIs for retirement in fall 2026. The original
breadthe/aqi-desktop service uses `/aq/observation/zipCode/current/` with distance=25,
one observation per pollutant, and upserts by ZIP/date/hour/pollutant. Its UI and native
provider will be inspected when implementing the application milestone.

## Application after publication

1. `composer require survos/airnow-bundle` in an ordinary PHP 8.5 / Symfony 8.1 app.
2. Prove “Hello from Symfony Desktop”: bundled FrankenPHP managed by a tiny Tauri shell;
   WebView, tray click, compact window, hide-on-close, explicit quit with child cleanup.
3. Document and verify macOS development/build commands; commit the runnable milestone.
4. Implement AqiMonitor + Doctrine/SQLite history, settings, Twig, Stimulus/AssetMapper.
5. No Laravel, NativePHP, React or Vue. Do not extract a desktop bundle before the proof works.
