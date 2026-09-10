<?php

declare(strict_types=1);

namespace Survos\AirNowBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\AirNowBundle\Exception\AirNowException;
use Survos\AirNowBundle\Service\AirNowClient;
use Survos\FetchBundle\Retry\ExponentialBackoffRetry;
use Survos\FetchBundle\Service\PersistentFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AirNowClientTest extends TestCase
{
    public function testForecastsPreserveDatesPollutantsAndUseCache(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function ($method, $url) use (&$calls) {
            ++$calls;
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('GET', $method);
            self::assertSame('/aq/forecast/current/', parse_url($url, PHP_URL_PATH));
            self::assertSame('00501', $query['zipCode']);
            self::assertSame('test&key', $query['API_KEY']);
            return new MockResponse(file_get_contents(__DIR__.'/fixtures/forecast.json'));
        });
        $client = $this->client($http);
        $rows = $client->currentForecastsByZip('00501');
        self::assertCount(6, $rows);
        self::assertSame('2026-09-10', $rows[0]->dateValid);
        self::assertSame('PM2.5', $rows[0]->parameterName);
        self::assertSame(44, $rows[0]->aqi);
        self::assertFalse($rows[0]->actionDay);
        $client->currentForecastsByZip('00501');
        self::assertSame(1, $calls);
        $client->currentForecastsByZip('00501', force: true);
        self::assertSame(2, $calls);
    }

    public function testEmptyResultIsNotZeroAqi(): void
    {
        self::assertSame([], $this->client(new MockHttpClient(new MockResponse('[]')))->currentForecastsByZip('20008'));
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidResponseIsEvicted(string $body): void
    {
        $http = new MockHttpClient([new MockResponse($body), new MockResponse('[]')]);
        $client = $this->client($http);
        try {
            $client->currentForecastsByZip('20008');
            self::fail('Malformed response accepted');
        } catch (AirNowException $e) {
            self::assertSame('AirNow returned an invalid response.', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame([], $client->currentForecastsByZip('20008'));
        self::assertSame(2, $http->getRequestsCount());
    }

    public static function invalidBodies(): iterable
    {
        yield ['{broken'];
        yield ['{}'];
        yield ['[null]'];
        yield ['[{"aqi":44}]'];
        $rows = json_decode(file_get_contents(__DIR__.'/fixtures/forecast.json'), true, flags: JSON_THROW_ON_ERROR);
        $rows[0]['aqi'] = 'bad';
        yield [json_encode($rows, JSON_THROW_ON_ERROR)];
    }

    public function testHttpFailureIsEvictedAndDoesNotExposeKey(): void
    {
        $http = new MockHttpClient([new MockResponse('secret body', ['http_code' => 403]), new MockResponse('[]')]);
        $client = $this->client($http);
        try {
            $client->currentForecastsByZip('20008');
            self::fail('HTTP failure accepted');
        } catch (AirNowException $e) {
            self::assertSame('AirNow request failed (HTTP status 403).', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame([], $client->currentForecastsByZip('20008'));
    }

    #[DataProvider('invalidZips')]
    public function testInvalidZipDoesNotSendRequest(string $zip): void
    {
        $http = new MockHttpClient();
        try {
            $this->client($http)->currentForecastsByZip($zip);
            self::fail('Invalid ZIP accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    public static function invalidZips(): iterable
    {
        foreach (['', '1234', '123456', '20008-1234', "20008\n", 'abcde'] as $zip) {
            yield [$zip];
        }
    }

    public function testMissingKeyDoesNotSendRequest(): void
    {
        $http = new MockHttpClient();
        $client = new AirNowClient(new PersistentFetcher($http, new ArrayAdapter(), new ExponentialBackoffRetry()));
        $this->expectException(AirNowException::class);
        $client->currentForecastsByZip('20008');
    }

    public function testTransientFailureIsRetriedByFetchBundle(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 503]), new MockResponse('[]')]);
        $client = new AirNowClient(new PersistentFetcher($http, new ArrayAdapter(), new ExponentialBackoffRetry(baseDelayMs: 0)), 'test');
        self::assertSame([], $client->currentForecastsByZip('20008'));
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testZeroTtlCannotCacheForever(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AirNowClient(new PersistentFetcher(new MockHttpClient(), new ArrayAdapter(), new ExponentialBackoffRetry()), cacheTtl: 0);
    }

    private function client(MockHttpClient $http): AirNowClient
    {
        return new AirNowClient(new PersistentFetcher($http, new ArrayAdapter(), new ExponentialBackoffRetry()), 'test&key');
    }
}
