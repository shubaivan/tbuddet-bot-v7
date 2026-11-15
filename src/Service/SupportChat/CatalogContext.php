<?php

declare(strict_types=1);

namespace App\Service\SupportChat;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Актуальний каталог artbeton.market для промпту консультанта: кожен товар —
 * назва, розмір, ціна й пряме посилання на картку, згруповано за категоріями.
 *
 * Чому весь каталог, а не пошук під питання: товарів ~100, це ~4 тис. токенів,
 * які кешуються разом із базою знань (читання кешу — 0,1 ціни). Повнотекстовий
 * пошук по розмовній фразі («мене цікавлять обмежувач руху, скажи які є…»)
 * чіпляє службові слова й губить релевантні позиції, а з повним списком модель
 * бачить усе й бере ціни/URL лише звідси — нічого не вигадує.
 *
 * Рядки відсортовані стабільно (категорія, id), щоб промпт був байт-в-байт
 * однаковим, доки не змінились самі товари, — інакше кеш промпту не спрацює.
 */
class CatalogContext
{
    /** Категорії з власною SEO-сторінкою на фронті (src/lib/categoryLandings.ts). */
    public const CATEGORY_LANDINGS = [
        44 => 'planter',
        40 => 'urns',
        43 => 'benches',
        50 => 'tables',
        41 => 'sinks',
        51 => 'traffic-barriers',
    ];

    private const CACHE_KEY = 'consultant_catalog_v1';
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $supportChatCache,
        private readonly LoggerInterface $logger,
        private readonly string $frontendUrl,
    ) {
    }

    public function text(): string
    {
        $item = $this->supportChatCache->getItem(self::CACHE_KEY);
        if ($item->isHit() && \is_string($item->get())) {
            return $item->get();
        }

        try {
            $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT p.id,
                       p.product_name->>'ua' AS name,
                       p.price->>'ua' AS price,
                       (SELECT e->'ua'->>'property_value'
                          FROM jsonb_array_elements(COALESCE(p.product_properties, '[]'::jsonb)) e
                         WHERE lower(e->'ua'->>'property_name') = 'розмір' LIMIT 1) AS size,
                       (SELECT string_agg(c.id::text, ',' ORDER BY c.id)
                          FROM product_category pc JOIN category c ON c.id = pc.category_id
                         WHERE pc.product_id = p.id) AS cat_ids,
                       (SELECT string_agg(c.category_name->>'ua', ' / ' ORDER BY c.id)
                          FROM product_category pc JOIN category c ON c.id = pc.category_id
                         WHERE pc.product_id = p.id) AS cats
                  FROM product p
                SQL);
        } catch (\Throwable $e) {
            $this->logger->error('Support chat: catalog query failed.', ['error' => $e->getMessage()]);

            return '(Каталог тимчасово недоступний — точні ціни дивіться на сайті.)';
        }

        $text = self::render($rows, rtrim($this->frontendUrl, '/'));
        $item->set($text)->expiresAfter(self::CACHE_TTL);
        $this->supportChatCache->save($item);

        return $text;
    }

    public static function productUrl(string $base, int $id): string
    {
        return $base.'/uk/product/view/'.$id;
    }

    public static function categoryUrl(string $base, int $categoryId, string $categoryName): string
    {
        if (isset(self::CATEGORY_LANDINGS[$categoryId])) {
            return $base.'/uk/product/category/'.self::CATEGORY_LANDINGS[$categoryId];
        }

        return $base.'/uk/product?full_text_search='.rawurlencode(mb_strtolower($categoryName));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function render(array $rows, string $base): string
    {
        $groups = [];
        foreach ($rows as $r) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($r['name'] ?? '')) ?? '');
            if ('' === $name) {
                continue;
            }
            $cats = (string) ($r['cats'] ?? '') ?: 'Інше';
            $catIds = array_map('intval', array_filter(explode(',', (string) ($r['cat_ids'] ?? ''))));
            $groups[$cats]['ids'] = $catIds;
            $groups[$cats]['items'][(int) $r['id']] = $r + ['clean_name' => $name];
        }
        ksort($groups);

        $out = [];
        foreach ($groups as $cats => $g) {
            $parts = explode(' / ', $cats);
            $catUrl = self::categoryUrl($base, 0, (string) end($parts));
            foreach ($g['ids'] as $cid) {
                if (isset(self::CATEGORY_LANDINGS[$cid])) {
                    $catUrl = self::categoryUrl($base, $cid, $parts[0]);
                }
            }

            $out[] = '## '.$cats.' — уся категорія: '.$catUrl;
            ksort($g['items']);
            foreach ($g['items'] as $id => $r) {
                $line = '- '.$r['clean_name'];
                $size = trim((string) ($r['size'] ?? ''));
                if ('' !== $size && !preg_match('/\d/u', $r['clean_name'])) {
                    $line .= ' (розмір '.$size.')';
                }
                $price = trim((string) ($r['price'] ?? ''));
                $line .= ' | '.('' !== $price && '0' !== $price ? $price.' грн' : 'ціна за запитом');
                $line .= ' | '.self::productUrl($base, $id);
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
