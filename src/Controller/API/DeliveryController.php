<?php

namespace App\Controller\API;

use App\Entity\Enum\DeliveryCarrierEnum;
use App\Service\Delivery\DeliveryCarrierRegistry;
use App\Service\Delivery\Exception\CarrierNotConfiguredException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Carrier-agnostic delivery endpoints. The {carrier} path segment is matched
 * to a backed-enum value (nova_poshta, ukrposhta, meest, justin, pickup).
 *
 * The legacy /api/v1/novaposhta/* routes in NovaPoshtaController stay alive
 * for backwards-compat with the existing frontend until the multi-carrier
 * picker ships.
 */
#[Route(path: 'api/v1/delivery')]
class DeliveryController extends AbstractController
{
    public function __construct(
        private DeliveryCarrierRegistry $carriers,
    ) {}

    #[Route(path: '/carriers', name: 'delivery_list_carriers', methods: [Request::METHOD_GET])]
    public function listCarriers(): JsonResponse
    {
        $result = array_map(fn($carrier) => [
            'code' => $carrier->code()->value,
            'label' => $carrier->code()->label(),
            'configured' => $carrier->isConfigured(),
        ], $this->carriers->all());

        return $this->json($result, Response::HTTP_OK);
    }

    #[Route(path: '/{carrier}/cities', name: 'delivery_search_cities', methods: [Request::METHOD_GET])]
    public function searchCities(DeliveryCarrierEnum $carrier, Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $limit = $request->query->getInt('limit', 20);

        if (strlen($query) < 2) {
            return $this->json([], Response::HTTP_OK);
        }

        try {
            $cities = $this->carriers->get($carrier)->searchCities($query, $limit);
        } catch (CarrierNotConfiguredException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(array_map(fn($c) => $c->toArray(), $cities), Response::HTTP_OK);
    }

    #[Route(path: '/{carrier}/departments', name: 'delivery_get_departments', methods: [Request::METHOD_GET])]
    public function getDepartments(DeliveryCarrierEnum $carrier, Request $request): JsonResponse
    {
        $cityRef = $request->query->get('cityRef', '');
        $limit = $request->query->getInt('limit', 50);
        $page = $request->query->getInt('page', 1);

        if ($cityRef === '') {
            return $this->json([], Response::HTTP_OK);
        }

        try {
            $departments = $this->carriers->get($carrier)->getDepartments($cityRef, $limit, $page);
        } catch (CarrierNotConfiguredException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(array_map(fn($d) => $d->toArray(), $departments), Response::HTTP_OK);
    }
}
