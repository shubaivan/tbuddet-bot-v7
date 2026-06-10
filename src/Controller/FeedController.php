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

    /**
     * Override our internal category names with the canonical Prom.ua catalog
     * names so the marketplace's matcher routes products to the right tree.
     * Without this, Prom guesses from product photos and mislabels (e.g. it
     * filed 41 concrete planters under "Живі рослини" / live plants).
     * Several internal categories collapse to the same Prom node — that's
     * intentional and supported by the YML format.
     */
    private const PROM_CATEGORY_NAMES = [
        44 => 'Вазони, кашпо, горщики садові',  // Вазон
        48 => 'Вазони, кашпо, горщики садові',  // Циліндр
        47 => 'Вазони, кашпо, горщики садові',  // Куб
        49 => 'Вазони, кашпо, горщики садові',  // Лонг
        58 => 'Вазони, кашпо, горщики садові',  // Бел
        46 => 'Вазони, кашпо, горщики садові',  // Хілс
        52 => 'Тротуарна плитка',                // Садова плитка
        60 => 'Тротуарна плитка',                // Плитка
        51 => 'Паркувальні стовпчики',           // Обмежувачі руху
        43 => 'Лави садові',                     // Лавки
        50 => 'Столи садові',                    // Столи
        40 => 'Урни для сміття',                 // Урни
        41 => 'Раковини для ванної кімнати',     // Раковини
        42 => 'Садовий декор',                   // Елементи декору
    ];

    /**
     * Internal category IDs to exclude from the Prom feed only (they stay
     * visible on the site). Requested by the Prom manager: drop the
     * "Тротуарна плитка" group — internal cats 52 (Садова плитка) and 60 (Плитка).
     */
    private const PROM_EXCLUDED_CATEGORIES = [52, 60];

    #[Route('/feed/prom.xml', name: 'feed_prom', methods: ['GET'])]
    public function promFeed(
        ProductRepository $productRepository,
        FilesRepository $filesRepository,
        FilesystemOperator $defaultStorage,
    ): Response {
        $lang = UserLanguageEnum::UA;
        $products = $productRepository->findAll();

        // Distinct categories used by the products. Names are remapped to
        // Prom-canonical labels (see PROM_CATEGORY_NAMES) so Prom's auto-matcher
        // routes products correctly instead of guessing from photos.
        $categories = [];
        foreach ($products as $product) {
            foreach ($product->getProductCategory() as $pc) {
                $cat = $pc->getCategory();
                $id = $cat->getId();
                if (in_array($id, self::PROM_EXCLUDED_CATEGORIES, true)) {
                    continue;
                }
                $categories[$id] = self::PROM_CATEGORY_NAMES[$id]
                    ?? trim((string) $cat->getCategoryName($lang));
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

            // Skip products in excluded groups (e.g. "Тротуарна плитка") — Prom
            // only; they remain on the site.
            $excluded = false;
            foreach ($product->getProductCategory() as $pc) {
                if (in_array($pc->getCategory()->getId(), self::PROM_EXCLUDED_CATEGORIES, true)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
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
