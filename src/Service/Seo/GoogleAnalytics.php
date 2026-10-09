<?php

declare(strict_types=1);

namespace App\Service\Seo;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GA4 Data API, лише читання, для місячного звіту.
 *
 * Сервісний акаунт має бути «Переглядачем» ресурсу GA4 (Адміністратор →
 * Керування доступом до ресурсу), а в його проекті Google Cloud увімкнено
 * Analytics Data API. Порожній GA4_PROPERTY_ID — блок Analytics у звіті пропускається.
 */
final class GoogleAnalytics
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const API = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly GoogleServiceAccount $account,
        private readonly string $ga4PropertyId,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->account->isConfigured() && ctype_digit($this->ga4PropertyId);
    }

    /**
     * runReport: рядки як [вимір…, метрика…] у порядку запиту.
     *
     * @param list<string> $metrics
     * @param list<string> $dimensions
     * @param array<string, mixed>|null $filter dimensionFilter у форматі API
     *
     * @return list<list<string>>
     */
    public function report(\DateTimeInterface $from, \DateTimeInterface $to, array $metrics, array $dimensions = [], int $limit = 10, ?array $filter = null): array
    {
        $body = [
            'dateRanges' => [['startDate' => $from->format('Y-m-d'), 'endDate' => $to->format('Y-m-d')]],
            'metrics' => array_map(static fn (string $m): array => ['name' => $m], $metrics),
            'limit' => $limit,
        ];
        if ([] !== $dimensions) {
            $body['dimensions'] = array_map(static fn (string $d): array => ['name' => $d], $dimensions);
            $body['orderBys'] = [['metric' => ['metricName' => $metrics[0]], 'desc' => true]];
        }
        if (null !== $filter) {
            $body['dimensionFilter'] = $filter;
        }

        $response = $this->http->request('POST', self::API.$this->ga4PropertyId.':runReport', [
            'headers' => ['Authorization' => 'Bearer '.$this->account->token(self::SCOPE)],
            'json' => $body,
            'timeout' => 30,
        ]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('GA4 runReport → HTTP %d: %s', $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        $rows = [];
        foreach ($response->toArray(false)['rows'] ?? [] as $row) {
            $rows[] = [
                ...array_map(static fn (array $v): string => (string) $v['value'], $row['dimensionValues'] ?? []),
                ...array_map(static fn (array $v): string => (string) $v['value'], $row['metricValues'] ?? []),
            ];
        }

        return $rows;
    }
}
