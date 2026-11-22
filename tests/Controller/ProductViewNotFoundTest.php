<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\API\ProductController;
use App\Repository\ProductRepository;
use App\Service\LocalizationService;
use App\Service\Payment\PaymentResultUrl;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Serializer\SerializerInterface;

/** An unknown product is a normal answer (200 + not_found), not a 422 in the browser console. */
final class ProductViewNotFoundTest extends TestCase
{
    public function testUnknownProductIs200WithAFlag(): void
    {
        $controller = new ProductController(
            $this->createMock(LocalizationService::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $this->createMock(SerializerInterface::class),
            'pub',
            'priv',
            'https://api.example.test/liq/pay',
            new PaymentResultUrl('https://shop.example.test', 'secret'),
        );
        $controller->setContainer(new Container());

        $products = $this->createMock(ProductRepository::class);
        $products->method('find')->with(999999)->willReturn(null);

        $response = $controller->viewId('999999', $products, $this->createMock(FilesystemOperator::class));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['not_found' => true], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // An id beyond the integer column never reaches the database.
        $untouched = $this->createMock(ProductRepository::class);
        $untouched->expects(self::never())->method('find');
        $huge = $controller->viewId('99999999999', $untouched, $this->createMock(FilesystemOperator::class));
        self::assertSame(['not_found' => true], json_decode((string) $huge->getContent(), true));
    }
}
