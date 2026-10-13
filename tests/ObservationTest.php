<?php

declare(strict_types=1);

namespace Survos\AirNowBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\AirNowBundle\Dto\Observation;
use Survos\AirNowBundle\Exception\AirNowException;
use Survos\AirNowBundle\Service\AirNowClient;
use Survos\FetchBundle\Retry\ExponentialBackoffRetry;
use Survos\FetchBundle\Service\PersistentFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObservationTest extends TestCase
{
    public function testReplacementEndpointAndNormalizedObservations(): void
    {
        $http = new MockHttpClient(function ($method, $url) {
            self::assertSame('GET', $method);
            self::assertSame('/aq/observation/current/ziplatlong/', parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame(['format' => 'application/json', 'zipcode' => '00501', 'API_KEY' => 'test'], $query);

            return new MockResponse(file_get_contents(__DIR__.'/fixtures/observations.json'));
        });
        $client = $this->client($http);
        $rows = $client->currentObservationsByZip('00501');
        self::assertCount(3, $rows);
        self::assertInstanceOf(Observation::class, $rows[0]);
        self::assertSame('110010041', $rows[0]->siteId);
        self::assertSame('07:00', $rows[0]->hourObserved);
        self::assertSame('EDT', $rows[0]->localTimeZone);
        self::assertSame('2026-09-10', $rows[0]->dateObserved);
        self::assertSame(34, $rows[0]->aqi);
        self::assertSame('Good', $rows[0]->categoryName);
        self::assertSame('50 Miles', $rows[0]->lookupBoundary);
        self::assertSame('PM10', $rows[2]->parameterName);
        self::assertSame('510590030', $rows[2]->siteId);
        $client->currentObservationsByZip('00501');
        self::assertSame(1, $http->getRequestsCount());
        $client->currentObservationsByZip('00501', force: true);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testEmptyObservationsAreNotZeroAqi(): void
    {
        self::assertSame([], $this->client(new MockHttpClient(new MockResponse('[]')))->currentObservationsByZip('20002'));
    }

    #[DataProvider('malformedResponses')]
    public function testMalformedObservationIsEvicted(string $body): void
    {
        $http = new MockHttpClient([new MockResponse($body), new MockResponse('[]')]);
        $client = $this->client($http);
        try {
            $client->currentObservationsByZip('20002');
            self::fail('Malformed observation accepted');
        } catch (AirNowException $e) {
            self::assertSame('AirNow returned an invalid response.', $e->getMessage());
        }
        self::assertSame([], $client->currentObservationsByZip('20002'));
        self::assertSame(2, $http->getRequestsCount());
    }

    public static function malformedResponses(): iterable
    {
        yield ['{}'];
        yield ['[{"DateObserved":"2026-09-10","AQI":34}]'];
        $rows = json_decode(file_get_contents(__DIR__.'/fixtures/observations.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['siteID', 'nowcastAQI', 'aqiCategoryName', 'hourObserved'] as $field) {
            $bad = $rows;
            unset($bad[0][$field]);
            yield [json_encode($bad, JSON_THROW_ON_ERROR)];
        }
        $rows[0]['nowcastAQI'] = 'not a number';
        yield [json_encode($rows, JSON_THROW_ON_ERROR)];
    }

    public function testForecastAndObservationCachesStaySeparate(): void
    {
        $http = new MockHttpClient([
            new MockResponse(file_get_contents(__DIR__.'/fixtures/forecast.json')),
            new MockResponse(file_get_contents(__DIR__.'/fixtures/observations.json')),
        ]);
        $client = $this->client($http);
        self::assertCount(6, $client->currentForecastsByZip('20002'));
        self::assertCount(3, $client->currentObservationsByZip('20002'));
        self::assertCount(6, $client->currentForecastsByZip('20002'));
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testInvalidZipIsRejectedBeforeRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new MockHttpClient())->currentObservationsByZip('20002&API_KEY=other');
    }

    private function client(MockHttpClient $http): AirNowClient
    {
        return new AirNowClient(new PersistentFetcher($http, new ArrayAdapter(), new ExponentialBackoffRetry()), 'test');
    }
}
