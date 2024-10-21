<?php

namespace App\Controller\API;

use App\Controller\API\Request\ProductListRequest;
use App\Controller\API\Request\PurchaseProduct;
use App\Entity\Enum\RoleEnum;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Pagination\PaginatedRepresentation;
use App\Pagination\Paginator;
use App\Repository\ProductRepository;
use App\Repository\UserOrderRepository;
use App\Service\ObjectHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

#[Route(path: 'api/v1/product')]
class ProductController extends AbstractController
{

    public function __construct(
        protected readonly SerializerInterface $serializer,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
    ) {}

    #[Route(name: 'public_product_list', methods: [Request::METHOD_GET])]
    public function getProducts(
        #[MapQueryString] ?ProductListRequest $listRequest,
        ProductRepository $repository,
        Paginator $paginator,
    )
    {
        //Todo https://github.com/symfony/symfony/issues/50690
        if (is_null($listRequest)) {
            $listRequest = new ProductListRequest();
        }

        $paginatedRepresentation = $paginator->getPaginatedRepresentation(
            $repository->productQueryBuilder(),
            [
                Paginator::PAGE => $listRequest->getPage(),
                Paginator::LIMIT => $listRequest->getLimit(),
                Paginator::PAGINATION_URL => $this->generateUrl(
                    'public_product_list',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
            ]
        );

        return new JsonResponse(
            $this->serializer->serialize($paginatedRepresentation, JsonEncoder::FORMAT, [
                AbstractNormalizer::GROUPS => [
                    PaginatedRepresentation::PAGINATION_DEFAULT,
                    Product::PUBLIC_PRODUCT_VIEW_GROUP
                ]
            ]),
            Response::HTTP_OK, [], true
        );
    }

    #[Route('/view/{id}', name: 'product_view_by_id', methods: [Request::METHOD_GET])]
    public function viewId(
        string $id,
        ProductRepository $repository,
        ObjectHandler $objectHandler,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        return $this->json($product, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [Product::PUBLIC_PRODUCT_VIEW_GROUP],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('/purchase/{id}', name: 'product_purchase_by_id', methods: [Request::METHOD_POST])]
    public function purchaseProduct(
        string $id,
        ProductRepository $repository,
        ObjectHandler $objectHandler,
        #[MapRequestPayload] PurchaseProduct $purchaseProduct,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, Product::class, 'id');
        $product = $repository->findOneBy(['id' => $id]);

        $userOrder = new UserOrder();
        $userOrder->setProductId($product);
        $userOrder->setQuantityProduct($purchaseProduct->getQuantity());
        $userOrder->setClientUserId($user);
        $userOrder->setTotalAmount($product->getPrice() * $purchaseProduct->getQuantity());
        $description = sprintf('Ваше замовлення: %s: в кількості: %s одиниць',
            $product->getProductName(),
            $purchaseProduct->getQuantity()
        );
        $userOrder->setDescription($description);

        $em->persist($userOrder);
        $em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        if ($userOrder->getTelegramUserid()) {
            $phoneNumber = $userOrder->getTelegramUserid()->getPhoneNumber();
        }

        if ($userOrder->getClientUserId()) {
            $phoneNumber = $userOrder->getClientUserId()->getPhone();
        }

        $params = array(
            'action' => 'invoice_send',
            'version' => '3',
            'phone' => $phoneNumber,
            'amount' => $userOrder->getTotalAmount(),
            'currency' => 'UAH',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $res = $liqpay->api("request", $params);
        $userOrder->setLiqPayresponse(json_encode($res));
        $userOrder->setLiqPayorderid($liqPayOrderID);
        $em->flush();

        $params = array(
            'action' => 'pay',
            'version' => '3',
            'amount' => $userOrder->getTotalAmount(),
            'currency' => 'UAH',
            'order_id' => $liqPayOrderID,
            'server_url' => $this->liqpayServerUrl,
            'description' => $description
        );
        $cnb_form_raw = $liqpay->cnb_form_raw($params);
        $link = sprintf(
            '%s?%s&%s',
            $cnb_form_raw['url'],
            'data=' . $cnb_form_raw['data'],
            'signature=' . $cnb_form_raw['signature'],
        );

        return $this->json([
            'order' => $userOrder,
            'liqpay' => $res,
            'link' => $link
        ], Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('/user-order/view/{id}', name: 'user_order_view_by_id', methods: [Request::METHOD_GET])]
    public function userOrderById(
        string $id,
        UserOrderRepository $repository,
        ObjectHandler $objectHandler,
    ): JsonResponse
    {
        $objectHandler->entityLookup($id, UserOrder::class, 'id');
        $userOrder = $repository->findOneBy(['id' => $id]);

        return $this->json($userOrder, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [UserOrder::PROTECTED_ORDER_VIEW_GROUP],
        ]);
    }
}