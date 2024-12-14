<?php

namespace App\Controller\API;

use App\Controller\API\Request\Purchase\CheckoutRequest;
use App\Controller\API\Request\Purchase\ProductProperties;
use App\Controller\API\Request\Purchase\PurchaseProduct;
use App\Controller\API\Request\Purchase\ShoppingCartPurchase;
use App\Entity\Enum\RoleEnum;
use App\Entity\PurchaseProduct as EntityPurchaseProduct;
use App\Entity\ShoppingCart;
use App\Entity\User;
use App\Entity\UserOrder;
use App\Liqpay\LiqPay;
use App\Repository\ProductRepository;
use App\Repository\PurchaseProductRepository;
use App\Service\ObjectHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[Route(path: 'api/v1/cart')]
class ShoppingCartController extends AbstractController
{
    public function __construct(
        protected LoggerInterface $logger,
        private string $liqpayPublicKey,
        private string $liqpayPrivateKey,
        private string $liqpayServerUrl,
    ) {}

    #[isGranted(RoleEnum::USER->value)]
    #[Route(name: 'add-product-to-cart', methods: Request::METHOD_POST)]
    public function addProduct(
        ProductRepository $repository,
        #[MapRequestPayload] PurchaseProduct $inputPurchaseProduct,
        #[CurrentUser] User $user,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $product = $repository->findOneBy(['id' => $inputPurchaseProduct->getProductId()]);
        $product->checkInputProp($inputPurchaseProduct->getProductProperties());

        $entityPurchaseProduct = new EntityPurchaseProduct();
        $entityPurchaseProduct
            ->setQuantity($inputPurchaseProduct->getQuantity())
            ->setProductProperties($inputPurchaseProduct->getProductProperties())
            ->setProduct($product);
        $em->persist($entityPurchaseProduct);

        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
        }

        $shoppingCart->addPurchaseProduct($entityPurchaseProduct);

        $em->flush();

        return $this->json($shoppingCart, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(name: 'show-cart', methods: Request::METHOD_GET)]
    public function show(
        #[CurrentUser] User $user,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
            $em->flush();
        }

        return $this->json($shoppingCart, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(path: '/{purchase_id}', name: 'remove-purchase-product', methods: Request::METHOD_DELETE)]
    public function removePurchaseProduct(
        string $purchase_id,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ObjectHandler $objectHandler,
        PurchaseProductRepository $repository
    ): JsonResponse
    {
        $objectHandler->entityLookup($purchase_id, EntityPurchaseProduct::class, 'id');
        $purchaseProduct = $repository->findOneBy(['id' => $purchase_id]);

        $shoppingCart = $user->getShoppingCart();
        if (!$shoppingCart) {
            $shoppingCart = new ShoppingCart();
            $shoppingCart->setUser($user);
            $em->persist($shoppingCart);
            $em->flush();
        }

        if ($purchaseProduct->getShoppingCart() !== $user->getShoppingCart()) {
            return $this->json(['error' => 'user not owner of purchase product'], Response::HTTP_BAD_REQUEST);
        }

        $em->remove($purchaseProduct);
        $em->flush();

        return $this->json($shoppingCart, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route(path: '/{purchase_id}', name: 'remove-purchase-product', methods: Request::METHOD_PUT)]
    public function updatePurchaseProduct(
        string $purchase_id,
        #[MapRequestPayload] PurchaseProduct $inputPurchaseProduct,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ObjectHandler $objectHandler,
        PurchaseProductRepository $repository
    ): JsonResponse
    {
        $objectHandler->entityLookup($purchase_id, EntityPurchaseProduct::class, 'id');
        $entityPurchaseProduct = $repository->findOneBy(['id' => $purchase_id]);
        $product = $entityPurchaseProduct->getProduct();
        $product->checkInputProp($inputPurchaseProduct->getProductProperties());

        $entityPurchaseProduct
            ->setQuantity($inputPurchaseProduct->getQuantity())
            ->setProductProperties($inputPurchaseProduct->getProductProperties());

        $em->flush();

        return $this->json($user->getShoppingCart(), Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => [ShoppingCart::GROUP_VIEW],
        ]);
    }

    #[isGranted(RoleEnum::USER->value)]
    #[Route('checkout', name: 'checkout-action', methods: [Request::METHOD_POST])]
    public function checkoutAction(
        #[MapRequestPayload] CheckoutRequest $checkoutRequest,
        #[CurrentUser] User $user,
        PurchaseProductRepository $purchaseProductRepository,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $userOrder = new UserOrder();
        $userOrder->setClientUserId($user);
        $em->persist($userOrder);

        $purchaseProductIds = $checkoutRequest->getPurchaseProductIds();
        $total_amount = 0;
        $description = '';
        $propExplainingTemplate = 'Назва: %s, Значення: %s, Плюс до ціни продкта: %s';

        foreach ($purchaseProductIds as $purchaseProductId) {
            $purchaseProduct = $purchaseProductRepository->find($purchaseProductId);
            $purchaseProduct->setUserOrder($userOrder);

            $product = $purchaseProduct->getProduct();

            $productProperties = array_map(function (array $prop) {
                return (new ProductProperties())
                    ->setPropertyPriceImpact($prop['property_price_impact'])
                    ->setPropertyValue($prop['property_value'])
                    ->setPropertyName($prop['property_name']);
            }, $purchaseProduct->getProductProperties());
            $product->checkInputProp($productProperties);

            $price = $product->getPrice();

            $propExplainingSet = [];
            foreach ($productProperties as $productProperty) {
                $price += $productProperty->getPropertyPriceImpact();
                $propExplainingSet[] = sprintf(
                    $propExplainingTemplate,
                    $productProperty->getPropertyName(),
                    $productProperty->getPropertyValue(),
                    $productProperty->getPropertyPriceImpact()
                );
            }

            $total_amount += $price * $purchaseProduct->getQuantity();

            $description .= sprintf('Ваше замовлення: %s: в кількості: %s одиниць' . PHP_EOL,
                $product->getProductName(),
                $purchaseProduct->getQuantity()
            );

            if (count($propExplainingSet)) {
                $description .= PHP_EOL . implode(PHP_EOL, $propExplainingSet) . PHP_EOL;
            }
        }

        $userOrder->setTotalAmount($total_amount);
        $userOrder->setDescription($description);

        $em->flush();

        $liqPayOrderID = sprintf('%s-%s', $userOrder->getId(), time());

        $liqpay = new LiqPay($this->logger, $this->liqpayPublicKey, $this->liqpayPrivateKey);

        if ($userOrder->getClientUserId()) {
            $phoneNumber = $userOrder->getClientUserId()->getPhone();
        } elseif ($userOrder->getPhone()) {
            $phoneNumber = $userOrder->getPhone();
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
}
