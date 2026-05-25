<?php

namespace App\Controller;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Repository\FilesRepository;
use App\Repository\ProductRepository;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Product export feeds for marketplaces.
 *
 * /feed/prom.xml — YML (yml_catalog) format, imported by Prom.ua, Rozetka,
 * Hotline and other Ukrainian platforms. Point the marketplace's "import by
 * URL" at https://api.artbeton.market/feed/prom.xml.
 */
class FeedController extends AbstractController
{
    private const SITE_URL = 'https://artbeton.market';
    private const SHOP_NAME = 'Art Beton Market';

    #[Route('/feed/prom.xml', name: 'feed_prom', methods: ['GET'])]
    public function promFeed(
        ProductRepository $productRepository,
        FilesRepository $filesRepository,
        FilesystemOperator $defaultStorage,
    ): Response {
        $lang = UserLanguageEnum::UA;
        $products = $productRepository->findAll();

        // Distinct categories used by the products.
        $categories = [];
        foreach ($products as $product) {
            foreach ($product->getProductCategory() as $pc) {
                $cat = $pc->getCategory();
                $categories[$cat->getId()] = trim((string) $cat->getCategoryName($lang));
            }
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', date('Y-m-d H:i'));
        $xml->startElement('shop');
        $xml->writeElement('name', self::SHOP_NAME);
        $xml->writeElement('company', self::SHOP_NAME);
        $xml->writeElement('url', self::SITE_URL);

        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', 'UAH');
        $xml->writeAttribute('rate', '1');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('categories');
        foreach ($categories as $id => $name) {
            $xml->startElement('category');
            $xml->writeAttribute('id', (string) $id);
            $xml->text($name !== '' ? $name : ('Категорія ' . $id));
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('offers');
        foreach ($products as $product) {
            $price = (int) $product->getPrice($lang);
            if ($price <= 0) {
                // No UAH price — nothing to sell on the marketplace.
                continue;
            }

            $categoryId = null;
            foreach ($product->getProductCategory() as $pc) {
                $categoryId = $pc->getCategory()->getId();
                break;
            }

            $xml->startElement('offer');
            $xml->writeAttribute('id', (string) $product->getId());
            $xml->writeAttribute('available', 'true');

            $xml->writeElement('name', trim((string) $product->getProductName($lang)));
            $xml->writeElement('url', self::SITE_URL . '/uk/product/view/' . $product->getId());
            $xml->writeElement('price', (string) $price);
            $xml->writeElement('currencyId', 'UAH');
            if ($categoryId !== null) {
                $xml->writeElement('categoryId', (string) $categoryId);
            }

            foreach ($filesRepository->getFileByProductId($product->getId()) as $file) {
                $xml->writeElement('picture', $this->encodeUrl($defaultStorage->publicUrl($file->getPath())));
            }

            $description = trim(strip_tags((string) $product->getDescription($lang)));
            if ($description !== '') {
                $xml->writeElement('description', $description);
            }

            $xml->endElement(); // offer
        }
        $xml->endElement(); // offers

        $xml->endElement(); // shop
        $xml->endElement(); // yml_catalog
        $xml->endDocument();

        return new Response(
            $xml->outputMemory(),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml; charset=utf-8'],
        );
    }

    /**
     * Percent-encode each path segment of a URL. Flysystem's publicUrl() leaves
     * spaces, parentheses, and other URL-unsafe characters in filenames as-is,
     * which strict downloaders (e.g. Prom.ua's image fetcher) reject.
     */
    private function encodeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'], $parsed['path'])) {
            return $url;
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $parsed['path'])));

        return $parsed['scheme'] . '://' . $parsed['host'] . $encodedPath;
    }
}
