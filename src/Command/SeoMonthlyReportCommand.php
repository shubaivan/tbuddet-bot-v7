<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Analytics\TelegramNotifier;
use App\Service\Seo\GoogleAnalytics;
use App\Service\Seo\SearchConsole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Місячний SEO-звіт artbeton.market у тему «Аналітика» групи менеджерів.
 *
 * Що в ньому: кліки, покази, CTR і середня позиція з Search Console проти
 * попереднього місяця; топ запитів і сторінок; коли Google востаннє забирав
 * sitemap (у doshka він тихо застарів на сім тижнів — без цього рядка не
 * видно); скільки адрес із sitemap справді в індексі. Якщо підключено GA4 —
 * ще відвідуваність: сеанси, користувачі, частка органіки й сторінки входу з пошуку.
 *
 * Дані Search Console запізнюються на 2–3 дні, тому крон — 3-го числа:
 * 0 6 3 * *  → php bin/console app:seo:monthly-report --env=prod
 */
#[AsCommand(name: 'app:seo:monthly-report', description: 'Monthly Search Console report to the managers group (topic «Аналітика»)')]
final class SeoMonthlyReportCommand extends Command
{
    private const MONTHS = [1 => 'січень', 'лютий', 'березень', 'квітень', 'травень', 'червень', 'липень', 'серпень', 'вересень', 'жовтень', 'листопад', 'грудень'];

    /** Канали GA4 людською мовою. */
    private const CHANNELS = [
        'Organic Search' => 'пошук',
        'Direct' => 'прямі',
        'Referral' => 'посилання',
        'Organic Social' => 'соцмережі',
        'Paid Search' => 'реклама в пошуку',
        'Paid Social' => 'реклама в соцмережах',
        'Unassigned' => 'невизначено',
        'Email' => 'пошта',
    ];

    /** Скільки днів без завантаження sitemap — уже тривога. */
    private const SITEMAP_STALE_DAYS = 14;

    public function __construct(
        private readonly SearchConsole $gsc,
        private readonly GoogleAnalytics $ga,
        private readonly TelegramNotifier $notifier,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Місяць YYYY-MM (за замовчуванням — попередній)')
            ->addOption('no-send', null, InputOption::VALUE_NONE, 'Лише надрукувати, у групу не слати')
            ->addOption('skip-inspect', null, InputOption::VALUE_NONE, 'Не перевіряти індексацію кожної адреси з sitemap');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->gsc->isConfigured()) {
            $output->writeln('<error>Search Console не налаштовано (GSC_SERVICE_ACCOUNT_FILE / GSC_SITE_URL).</error>');

            return Command::FAILURE;
        }

        $month = $input->getOption('month');
        $from = null !== $month
            ? \DateTimeImmutable::createFromFormat('!Y-m', (string) $month)
            : new \DateTimeImmutable('first day of last month midnight');
        if (false === $from) {
            $output->writeln('<error>--month має бути у форматі YYYY-MM</error>');

            return Command::INVALID;
        }
        $to = $from->modify('last day of this month');
        $prevFrom = $from->modify('first day of last month');
        $prevTo = $prevFrom->modify('last day of this month');

        $text = $this->report($from, $to, $prevFrom, $prevTo, !$input->getOption('skip-inspect'));
        $output->writeln(strip_tags($text));

        if (!$input->getOption('no-send')) {
            $output->writeln($this->notifier->send($text, TelegramNotifier::TOPIC_ANALYTICS) ? 'sent' : '<error>send failed</error>');
        }

        return Command::SUCCESS;
    }

    private function report(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $prevFrom, \DateTimeImmutable $prevTo, bool $inspect): string
    {
        $now = $this->gsc->performance($from, $to)[0] ?? null;
        $prev = $this->gsc->performance($prevFrom, $prevTo)[0] ?? null;

        $lines = [\sprintf('📈 <b>SEO-звіт за %s %s</b>', self::MONTHS[(int) $from->format('n')], $from->format('Y')), 'artbeton.market · Google Пошук', ''];

        $clicks = (int) ($now['clicks'] ?? 0);
        $impressions = (int) ($now['impressions'] ?? 0);
        $lines[] = '👆 Кліки: <b>'.$this->num($clicks).'</b>'.$this->delta($clicks, null === $prev ? null : (int) $prev['clicks']);
        $lines[] = '👀 Покази: <b>'.$this->num($impressions).'</b>'.$this->delta($impressions, null === $prev ? null : (int) $prev['impressions']);
        if ($impressions > 0) {
            $lines[] = '🎯 CTR: <b>'.number_format(100 * (float) $now['ctr'], 1, ',', '').'%</b>';
            $position = (float) $now['position'];
            $line = '📍 Середня позиція: <b>'.number_format($position, 1, ',', '').'</b>';
            if (null !== $prev && (int) $prev['impressions'] > 0) {
                // Позиція: менше — краще.
                $line .= ' (було '.number_format((float) $prev['position'], 1, ',', '').($position < (float) $prev['position'] ? ' ⬆️' : ($position > (float) $prev['position'] ? ' ⬇️' : '')).')';
            }
            $lines[] = $line;
        }

        $queries = $this->gsc->performance($from, $to, 'query', 10);
        if ([] !== $queries) {
            $lines[] = '';
            $lines[] = '🔎 <b>Топ запитів</b>';
            foreach ($queries as $i => $row) {
                $lines[] = \sprintf('%d. %s — %s кл. · %s пок. · поз. %s', $i + 1, TelegramNotifier::esc($row['keys'][0] ?? ''), $this->num((int) $row['clicks']), $this->num((int) $row['impressions']), number_format((float) $row['position'], 1, ',', ''));
            }
        }

        $pages = $this->gsc->performance($from, $to, 'page', 5);
        if ([] !== $pages) {
            $lines[] = '';
            $lines[] = '📄 <b>Топ сторінок</b>';
            foreach ($pages as $i => $row) {
                $lines[] = \sprintf('%d. %s — %s кл. · %s пок.', $i + 1, TelegramNotifier::esc($this->path($row['keys'][0] ?? '')), $this->num((int) $row['clicks']), $this->num((int) $row['impressions']));
            }
        }

        if ($this->ga->isConfigured()) {
            try {
                $lines = [...$lines, '', ...$this->analyticsLines($from, $to, $prevFrom, $prevTo)];
            } catch (\Throwable $e) {
                // Звіт без Analytics кращий, ніж жодного: Search Console вже зібрано.
                $lines[] = '';
                $lines[] = '⚠️ Google Analytics недоступна: '.TelegramNotifier::esc(mb_substr($e->getMessage(), 0, 150));
            }
        }

        $lines[] = '';
        $lines = [...$lines, ...$this->sitemapLines()];

        if ($inspect) {
            $lines = [...$lines, ...$this->coverageLines()];
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function analyticsLines(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $prevFrom, \DateTimeImmutable $prevTo): array
    {
        $metrics = ['sessions', 'totalUsers', 'newUsers'];
        $now = $this->ga->report($from, $to, $metrics)[0] ?? ['0', '0', '0'];
        $prev = $this->ga->report($prevFrom, $prevTo, $metrics)[0] ?? ['0', '0', '0'];

        $lines = [
            '📊 <b>Відвідуваність (Google Analytics)</b>',
            'Сеанси: <b>'.$this->num((int) $now[0]).'</b>'.$this->delta((int) $now[0], (int) $prev[0]),
            'Користувачі: <b>'.$this->num((int) $now[1]).'</b>'.$this->delta((int) $now[1], (int) $prev[1]).' · нових: '.$this->num((int) $now[2]),
        ];

        $sessions = max(1, (int) $now[0]);
        $channels = $this->ga->report($from, $to, ['sessions'], ['sessionDefaultChannelGroup'], 6);
        if ([] !== $channels) {
            $lines[] = 'Звідки: '.implode(' · ', array_map(
                fn (array $r): string => \sprintf('%s %d%%', TelegramNotifier::esc(self::CHANNELS[$r[0]] ?? $r[0]), (int) round(100 * (int) $r[1] / $sessions)),
                $channels,
            ));
        }

        $landing = $this->ga->report($from, $to, ['sessions'], ['landingPage'], 5, [
            'filter' => ['fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => ['value' => 'Organic Search']],
        ]);
        if ([] !== $landing) {
            $lines[] = 'Входи з пошуку: '.implode(', ', array_map(
                fn (array $r): string => TelegramNotifier::esc(urldecode($r[0])).' — '.$this->num((int) $r[1]),
                $landing,
            ));
        }

        return $lines;
    }

    /** @return list<string> */
    private function sitemapLines(): array
    {
        $out = [];
        foreach ($this->gsc->sitemaps() as $sitemap) {
            $downloaded = isset($sitemap['lastDownloaded']) ? new \DateTimeImmutable($sitemap['lastDownloaded']) : null;
            $stale = null === $downloaded || $downloaded < new \DateTimeImmutable('-'.self::SITEMAP_STALE_DAYS.' days');
            $out[] = \sprintf(
                '%s Sitemap %s: Google забирав %s%s',
                $stale ? '⚠️' : '🗺',
                TelegramNotifier::esc($this->path($sitemap['path'])),
                $downloaded?->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y') ?? 'ніколи',
                (int) ($sitemap['errors'] ?? 0) > 0 ? ' · помилок: '.(int) $sitemap['errors'] : '',
            );
        }

        return $out ?: ['⚠️ Sitemap у Search Console не додано'];
    }

    /** @return list<string> */
    private function coverageLines(): array
    {
        $xml = $this->http->request('GET', rtrim($this->gsc->siteUrl(), '/').'/sitemap.xml', ['timeout' => 20])->getContent(false);
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $xml, $m);
        $urls = array_values(array_unique($m[1]));
        if ([] === $urls) {
            return [];
        }

        $indexed = 0;
        $missing = [];
        $failed = 0;
        foreach ($urls as $url) {
            $verdict = $this->gsc->verdict($url);
            match ($verdict) {
                'PASS' => ++$indexed,
                null => ++$failed,
                default => $missing[] = $url,
            };
        }

        $out = [\sprintf('✅ У індексі Google: <b>%d з %d</b> адрес sitemap%s', $indexed, \count($urls), $failed > 0 ? ' (не перевірено: '.$failed.')' : '')];
        if ([] !== $missing) {
            $out[] = 'Ще не в індексі, напр.: '.implode(', ', array_map(fn (string $u): string => TelegramNotifier::esc($this->path($u)), \array_slice($missing, 0, 5)));
        }

        return $out;
    }

    private function delta(int $now, ?int $prev): string
    {
        if (null === $prev || 0 === $prev) {
            return null === $prev ? '' : ' (минулого місяця 0)';
        }
        $pct = (int) round(100 * ($now - $prev) / $prev);

        return \sprintf(' (%s%d%% до мин. місяця)', $pct > 0 ? '+' : '', $pct);
    }

    private function num(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private function path(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return \is_string($path) && '' !== $path ? urldecode($path) : $url;
    }
}
