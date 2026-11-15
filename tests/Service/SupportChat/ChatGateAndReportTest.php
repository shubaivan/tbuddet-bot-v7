<?php

declare(strict_types=1);

namespace App\Tests\Service\SupportChat;

use App\Service\SupportChat\CatalogContext;
use App\Service\SupportChat\ChatAssistantService;
use App\Service\SupportChat\ChatDialogReport;
use App\Service\SupportChat\ChatDialogStore;
use App\Service\SupportChat\ChatPhone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class ChatGateAndReportTest extends TestCase
{
    /** @dataProvider phones */
    public function testPhoneNormalize(string $raw, ?string $expected): void
    {
        self::assertSame($expected, ChatPhone::normalize($raw));
    }

    public static function phones(): iterable
    {
        yield 'local' => ['097 123 45 67', '+380971234567'];
        yield 'intl' => ['+38 (097) 123-45-67', '+380971234567'];
        yield '380 without plus' => ['380971234567', '+380971234567'];
        yield 'foreign' => ['+48 512 345 678', '+48512345678'];
        yield 'too short' => ['097 123', null];
        yield 'letters' => ['097abc4567', null];
        yield 'fake repeated digits' => ['0111111111', null];
        yield 'empty' => ['', null];
    }

    public function testTokenSignAndVerify(): void
    {
        $t = ChatDialogStore::sign('0123456789abcdef01234567', 's3cret');
        self::assertSame('0123456789abcdef01234567', ChatDialogStore::verify($t, 's3cret'));
        self::assertNull(ChatDialogStore::verify($t, 'other-secret'));
        self::assertNull(ChatDialogStore::verify('0123456789abcdef01234567.forged', 's3cret'));
        self::assertNull(ChatDialogStore::verify(null, 's3cret'));
        self::assertNull(ChatDialogStore::verify('garbage', 's3cret'));
    }

    public function testDialogEndsExactlyOnceAndIdleSweep(): void
    {
        $store = new ChatDialogStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), 'secret');
        $d = $store->create('Олена', '+380971234567', 'https://artbeton.market/uk');
        self::assertSame($d['id'], $store->idFromToken($d['token']));

        self::assertSame([], $store->idle(1200, time() + 60));
        self::assertSame([$d['id']], $store->idle(1200, time() + 1300));

        self::assertNotNull($store->markEnded($d['id']), 'first finisher wins');
        self::assertNull($store->markEnded($d['id']), 'beacon + cron → one report');
        self::assertSame([], $store->idle(1200, time() + 5000), 'ended dialog leaves the open index');
    }

    public function testCatalogRendersRealUrlsAndPrices(): void
    {
        $text = CatalogContext::render([
            ['id' => 119, 'name' => 'Обмежувач руху "Півсфера" Ø250 мм', 'price' => '165', 'size' => '', 'cat_ids' => '51', 'cats' => 'Обмежувачі руху'],
            ['id' => 115, 'name' => 'Обмежувач руху "Тауер"', 'price' => '1650', 'size' => '250*250*750h мм', 'cat_ids' => '51', 'cats' => 'Обмежувачі руху'],
            ['id' => 144, 'name' => 'Бруквей', 'price' => '520', 'size' => null, 'cat_ids' => '60', 'cats' => 'Плитка'],
        ], 'https://artbeton.market');

        self::assertStringContainsString('## Обмежувачі руху — уся категорія: https://artbeton.market/uk/product/category/traffic-barriers', $text);
        self::assertStringContainsString('- Обмежувач руху "Півсфера" Ø250 мм | 165 грн | https://artbeton.market/uk/product/view/119', $text);
        self::assertStringContainsString('- Обмежувач руху "Тауер" (розмір 250*250*750h мм) | 1650 грн | https://artbeton.market/uk/product/view/115', $text);
        self::assertStringContainsString('## Плитка — уся категорія: https://artbeton.market/uk/product?full_text_search=%D0%BF%D0%BB%D0%B8%D1%82%D0%BA%D0%B0', $text);
    }

    public function testCompetitorLinesAreStripped(): void
    {
        $reply = "Бетон М300 — 3 100 грн/м³.\nКонтакти заводу «Буддеталь»: +38 097 444 55 01\nСайт: zavodbuddetal.com.ua\nЗамовити — у менеджера: 📞 068 517 00 77";
        $clean = ChatAssistantService::stripCompetitors($reply);
        self::assertDoesNotMatchRegularExpression('/буддетал|zavodbuddetal/iu', $clean);
        self::assertStringContainsString('068 517 00 77', $clean);
    }

    public function testKnowledgeFedToModelHasNoOtherCompany(): void
    {
        foreach (glob(__DIR__.'/../../../config/support_chat/*.md') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression('/буддетал|buddetal|zavodbuddetal|instagram\.com\/zavod/iu', (string) file_get_contents($file), basename($file));
        }
        foreach (['faq.uk.md', 'faq.buddetal.uk.md'] as $f) {
            $kb = (string) file_get_contents(__DIR__.'/../../../config/support_chat/'.$f);
            foreach (['068 517 00 77', '068 372 70 07', '063 302 26 66'] as $phone) {
                self::assertStringContainsString($phone, $kb, $f);
            }
        }
        self::assertSame('📞 Лена 068 517 00 77 · Олег 068 372 70 07 · Іван 063 302 26 66', ChatAssistantService::MANAGERS_CTA);
        foreach (glob(__DIR__.'/../../../config/support_chat/*.md') ?: [] as $file) {
            self::assertStringNotContainsString('444 55 01', (string) file_get_contents($file));
            self::assertStringNotContainsString('160 км', (string) file_get_contents($file));
        }
    }

    public function testExtractLinks(): void
    {
        self::assertSame(
            ['https://artbeton.market/uk/product/view/119', 'https://t.me/prod_beton_decor_bot'],
            ChatAssistantService::extractLinks("• Півсфера — https://artbeton.market/uk/product/view/119.\nTelegram: https://t.me/prod_beton_decor_bot"),
        );
    }

    public function testDialogReport(): void
    {
        $text = ChatDialogReport::render([
            'name' => 'Олена <b>', 'phone' => '+380971234567', 'page' => 'https://artbeton.market/uk',
            'startedAt' => 1763208000, 'lastAt' => 1763208600, 'questions' => 3, 'replies' => 3,
            'operator' => true, 'links' => ['https://artbeton.market/uk/product/view/119'],
        ], 'Цікавиться півсферами Ø250.', 'closed', 0.4211, 0.0102);

        self::assertStringContainsString('💬 <b>Діалог завершено</b>', $text);
        self::assertStringContainsString('Олена &lt;b&gt;', $text);
        self::assertStringContainsString('+380971234567', $text);
        self::assertStringContainsString('(10 хв) · закрив чат', $text);
        self::assertStringContainsString('Питань: <b>3</b> · відповідей: 3', $text);
        self::assertStringContainsString('https://artbeton.market/uk/product/view/119', $text);
        self::assertStringContainsString('так, просив', $text);
        self::assertStringContainsString('0,42 грн ($0.010)', $text);
    }

    public function testCapsProductLinesAtEight(): void
    {
        $items = [];
        for ($i = 1; $i <= 11; ++$i) {
            $items[] = '• Товар '.$i.' — 100 грн — https://artbeton.market/uk/product/view/'.$i;
        }
        $reply = "Ось що є:\n\n".implode("\n", \array_slice($items, 0, 8))."\n\nТакож:\n".implode("\n", \array_slice($items, 8))
            ."\n\nУсі моделі: https://artbeton.market/uk/product/category/traffic-barriers\n\nЗамовити: 📞 068 517 00 77";
        $capped = ChatAssistantService::capProductLines($reply);
        self::assertSame(8, substr_count($capped, '/uk/product/view/'));
        self::assertStringContainsString('Усі моделі: https://artbeton.market/uk/product/category/traffic-barriers', $capped);
        self::assertStringEndsWith('📞 068 517 00 77', $capped);

        $noAll = implode("\n", $items)."\n\nЗамовити: 📞 068 517 00 77";
        $capped = ChatAssistantService::capProductLines($noAll);
        self::assertSame(8, substr_count($capped, '/uk/product/view/'));
        self::assertStringContainsString("Усі моделі: https://artbeton.market/uk/product\n\nЗамовити", $capped);

        $short = "• A — https://artbeton.market/uk/product/view/1\nЗамовити";
        self::assertSame($short, ChatAssistantService::capProductLines($short));
    }

    public function testCapDropsOrphanedSubheader(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; ++$i) {
            $items[] = '• Товар '.$i.' — https://artbeton.market/uk/product/view/'.$i;
        }
        $reply = implode("\n", $items)."\n\nЄ також сфери «Верона»:\n• Сфера — https://artbeton.market/uk/product/view/99\n\nУсі моделі: https://artbeton.market/uk/product/category/traffic-barriers\n\nЗамовити: 📞 068 517 00 77";
        $capped = ChatAssistantService::capProductLines($reply);
        self::assertStringNotContainsString('Верона', $capped);
        self::assertStringContainsString('Усі моделі:', $capped);
    }

    public function testCapKeepsTrailingCtaBeforeContactsCard(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; ++$i) {
            $items[] = '• Товар '.$i.' — https://artbeton.market/uk/product/view/'.$i;
        }
        $reply = implode("\n", $items)."\n\nУсі моделі: https://artbeton.market/uk/product/category/traffic-barriers\n\nЗамовити можна на сайті за посиланням вище або в менеджера:\n";
        $capped = ChatAssistantService::capProductLines(trim($reply));
        self::assertStringEndsWith('або в менеджера:', $capped);
        self::assertSame(8, substr_count($capped, '/uk/product/view/'));
    }
}
