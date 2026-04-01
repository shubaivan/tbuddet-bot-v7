<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NovaPoshtaService
{
    private const API_URL = 'https://api.novaposhta.ua/v2.0/json/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $novaPoshtaApiKey
    ) {}

    public function searchCities(string $query, int $limit = 20): array
    {
        return $this->callApi('AddressGeneral', 'getSettlements', [
            'FindByString' => $query,
            'Limit' => (string)$limit,
            'Warehouse' => '1',
        ]);
    }

    /**
     * Get tracking status for a document number (TTN).
     * Returns StatusCode: 1=created, 2=deleted, 3=not found, 4=in transit,
     * 5=at destination city, 6=at department, 7=picked up, 9=delivered, 10=returned, 11=returning
     */
    public function getTrackingStatus(string $trackingNumber): array
    {
        $result = $this->callApi('TrackingDocument', 'getStatusDocuments', [
            'Documents' => [
                ['DocumentNumber' => $trackingNumber],
            ],
        ]);

        return $result[0] ?? [];
    }

    public function getWarehouses(string $cityRef, int $limit = 50, int $page = 1): array
    {
        return $this->callApi('AddressGeneral', 'getWarehouses', [
            'SettlementRef' => $cityRef,
            'Limit' => (string)$limit,
            'Page' => (string)$page,
            'Language' => 'UA',
        ]);
    }

    private function callApi(string $modelName, string $calledMethod, array $methodProperties): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'json' => [
                    'apiKey' => $this->novaPoshtaApiKey,
                    'modelName' => $modelName,
                    'calledMethod' => $calledMethod,
                    'methodProperties' => $methodProperties,
                ],
            ]);

            $data = $response->toArray();

            if (!($data['success'] ?? false)) {
                $this->logger->error('Nova Poshta API error', [
                    'errors' => $data['errors'] ?? [],
                    'method' => $calledMethod,
                ]);

                return [];
            }

            return $data['data'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Nova Poshta API request failed', [
                'error' => $e->getMessage(),
                'method' => $calledMethod,
            ]);

            return [];
        }
    }
}
