<?php

namespace App\Controller\API;

use App\Service\NovaPoshtaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: 'api/v1/novaposhta')]
class NovaPoshtaController extends AbstractController
{
    public function __construct(
        private NovaPoshtaService $novaPoshtaService
    ) {}

    #[Route(path: '/cities', name: 'novaposhta_search_cities', methods: [Request::METHOD_GET])]
    public function searchCities(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $limit = $request->query->getInt('limit', 20);

        if (strlen($query) < 2) {
            return $this->json([], Response::HTTP_OK);
        }

        $cities = $this->novaPoshtaService->searchCities($query, $limit);

        $result = array_map(fn(array $city) => [
            'ref' => $city['Ref'] ?? '',
            'description' => $city['Description'] ?? '',
            'area' => $city['AreaDescription'] ?? '',
            'region' => $city['RegionsDescription'] ?? '',
        ], $cities);

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route(path: '/warehouses', name: 'novaposhta_get_warehouses', methods: [Request::METHOD_GET])]
    public function getWarehouses(Request $request): JsonResponse
    {
        $cityRef = $request->query->get('cityRef', '');
        $limit = $request->query->getInt('limit', 50);
        $page = $request->query->getInt('page', 1);

        if (empty($cityRef)) {
            return $this->json([], Response::HTTP_OK);
        }

        $warehouses = $this->novaPoshtaService->getWarehouses($cityRef, $limit, $page);

        $result = array_map(fn(array $warehouse) => [
            'ref' => $warehouse['Ref'] ?? '',
            'description' => $warehouse['Description'] ?? '',
            'number' => $warehouse['Number'] ?? '',
            'shortAddress' => $warehouse['ShortAddress'] ?? '',
        ], $warehouses);

        return $this->json($result, Response::HTTP_OK);
    }
}
