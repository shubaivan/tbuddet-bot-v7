<?php

declare(strict_types=1);

namespace App\Service\Seo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Search Console, лише читання: ефективність у пошуку, sitemap і
 * перевірка URL.
 *
 * Сервісний акаунт має бути доданий користувачем у ресурс artbeton.market
 * (Search Console → Налаштування → Користувачі й дозволи). Без файлу ключа
 * isConfigured() = false, і звіт просто не будується.
 */
final class SearchConsole
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const API = 'https://www.googleapis.com/webmasters/v3/sites/';
    private const INSPECT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly GoogleServiceAccount $account,
        private readonly string $gscSiteUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->account->isConfigured() && '' !== $this->gscSiteUrl;
    }

    public function siteUrl(): string
    {
        return $this->gscSiteUrl;
    }

    /**
     * Search Analytics за період. Без $dimension — один рядок із підсумками.
     *
     * @return list<array{keys?: list<string>, clicks: float, impressions: float, ctr: float, position: float}>
     */
    public function performance(\DateTimeInterface $from, \DateTimeInterface $to, ?string $dimension = null, int $limit = 10): array
    {
        $body = [
            'startDate' => $from->format('Y-m-d'),
            'endDate' => $to->format('Y-m-d'),
            'type' => 'web',
            'rowLimit' => $limit,
        ];
        if (null !== $dimension) {
            $body['dimensions'] = [$dimension];
        }

        $data = $this->call('POST', self::API.rawurlencode($this->gscSiteUrl).'/searchAnalytics/query', $body);

        return $data['rows'] ?? [];
    }

    /** @return list<array{path: string, lastDownloaded?: string, errors?: string|int, warnings?: string|int}> */
    public function sitemaps(): array
    {
        return $this->call('GET', self::API.rawurlencode($this->gscSiteUrl).'/sitemaps')['sitemap'] ?? [];
    }

    /** Вердикт перевірки URL: PASS — у індексі; null — запит не вдався. */
    public function verdict(string $url): ?string
    {
        try {
            $data = $this->call('POST', self::INSPECT, ['inspectionUrl' => $url, 'siteUrl' => $this->gscSiteUrl]);
        } catch (\Throwable $e) {
            $this->logger->warning('GSC inspect failed for {url}: {e}', ['url' => $url, 'e' => $e->getMessage()]);

            return null;
        }

        return $data['inspectionResult']['indexStatusResult']['verdict'] ?? null;
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $url, ?array $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->account->token(self::SCOPE)], 'timeout' => 30];
        if (null !== $json) {
            $options['json'] = $json;
        }

        $response = $this->http->request($method, $url, $options);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(\sprintf('GSC %s → HTTP %d: %s', $url, $response->getStatusCode(), mb_substr($response->getContent(false), 0, 300)));
        }

        return $response->toArray(false);
    }
}
