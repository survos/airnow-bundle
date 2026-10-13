<?php

declare(strict_types=1);

namespace Survos\AirNowBundle\Service;

use Survos\AirNowBundle\Dto\Forecast;
use Survos\AirNowBundle\Dto\Observation;
use Survos\AirNowBundle\Exception\AirNowException;
use Survos\FetchBundle\Contract\PersistentFetcherInterface;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

final readonly class AirNowClient
{
    /** @param list<string> $defaultZipCodes */
    public function __construct(
        private PersistentFetcherInterface $fetcher,
        #[\SensitiveParameter] private string $apiKey = '',
        public array $defaultZipCodes = [],
        private int $cacheTtl = 3600,
        private float $timeout = 10.0,
    ) {
        foreach ($defaultZipCodes as $zipCode) {
            $this->validateZip($zipCode);
        }
        if ($cacheTtl < 1 || $timeout <= 0) {
            throw new \InvalidArgumentException('AirNow cache TTL and timeout must be positive.');
        }
    }

    /** @return list<Forecast> All available forecast dates and pollutants; [] means no data. */
    public function currentForecastsByZip(string $zipCode, bool $force = false): array
    {
        $this->validateZip($zipCode);

        return $this->request('/aq/forecast/current/', ['zipCode' => $zipCode], Forecast::class, $force);
    }

    /** @return list<Observation> Every returned pollutant/monitor; [] means no data. */
    public function currentObservationsByZip(string $zipCode, bool $force = false): array
    {
        $this->validateZip($zipCode);

        return $this->request('/aq/observation/current/ziplatlong/', ['zipcode' => $zipCode], Observation::class, $force);
    }

    /**
     * @template T of object
     *
     * @param array<string, string|int|float> $query
     * @param class-string<T>                 $type
     *
     * @return list<T>
     */
    private function request(string $path, array $query, string $type, bool $force): array
    {
        if ('' === trim($this->apiKey)) {
            throw new AirNowException('Configure survos_airnow.api_key before requesting AirNow data.');
        }
        $url = 'https://www.airnowapi.org'.$path.'?'.http_build_query([
            'format' => 'application/json', ...$query, 'API_KEY' => $this->apiKey,
        ], '', '&', PHP_QUERY_RFC3986);
        try {
            $result = $this->fetcher->fetch($url, [
                'ttl' => $this->cacheTtl, 'force_fetch' => $force,
                'timeout' => $this->timeout, 'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            throw new AirNowException('AirNow transport failed.');
        }
        if (!$result->isOkay()) {
            $this->fetcher->forget($url);
            throw new AirNowException(sprintf('AirNow request failed (HTTP status %d).', $result->statusCode));
        }
        try {
            $rows = json_decode($result->contents ?? '', false, flags: JSON_THROW_ON_ERROR);
            if (!is_array($rows)) {
                throw new \UnexpectedValueException();
            }
            $serializer = new Serializer([new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor())]);

            return array_map(static function (mixed $row) use ($serializer, $type): object {
                if (!$row instanceof \stdClass) {
                    throw new \UnexpectedValueException();
                }
                $data = (array) $row;
                if (Observation::class === $type) {
                    // Normalize the replacement API's names to our camelCase DTO contract.
                    foreach (['siteID' => 'siteId', 'nowcastAQI' => 'aqi', 'aqiCategoryName' => 'categoryName'] as $source => $target) {
                        unset($data[$target]);
                        if (array_key_exists($source, $data)) {
                            $data[$target] = $data[$source];
                        }
                        unset($data[$source]);
                    }
                }

                return $serializer->denormalize($data, $type);
            }, $rows);
        } catch (\JsonException|\UnexpectedValueException|\Symfony\Component\Serializer\Exception\ExceptionInterface|\TypeError) {
            $this->fetcher->forget($url);
            throw new AirNowException('AirNow returned an invalid response.');
        }
    }

    private function validateZip(string $zipCode): void
    {
        $violations = Validation::createValidator()->validate($zipCode, [
            new Assert\NotBlank(), new Assert\Regex('/^[0-9]{5}$/D'),
        ]);
        if (0 !== count($violations)) {
            throw new \InvalidArgumentException('ZIP code must be a five-digit string, including leading zeros.');
        }
    }
}
