<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * Turns a raw user query ("Вазони бетонні!", "півсф", "planters") into safe
 * PostgreSQL to_tsquery('simple', …) strings plus a plain string for pg_trgm.
 *
 * - Only letters and digits survive, so punctuation can never break
 *   to_tsquery (before this, "вазон!" returned HTTP 500).
 * - Each term becomes a prefix match (term:*), so partial words work: "півсф".
 * - A light Ukrainian suffix strip makes word forms meet: "вазони" -> "вазон:*"
 *   matches "вазон", "вазону", "вазонів". PostgreSQL ships no Ukrainian
 *   snowball config, and the Russian one mangles Ukrainian words.
 */
final class ProductSearchQuery
{
    private const MIN_STEM_LENGTH = 3;
    private const MAX_TERMS = 8;

    /** Ukrainian noun/adjective endings, longest first. */
    private const UA_ENDINGS = [
        'ами', 'ями', 'ові', 'еві', 'єві', 'ого', 'ому', 'ими', 'іми',
        'ах', 'ях', 'ам', 'ям', 'ою', 'ею', 'єю', 'ів', 'їв', 'ей',
        'ий', 'ій', 'ої', 'им', 'их', 'ім', 'іх',
        'а', 'я', 'и', 'і', 'ї', 'у', 'ю', 'о', 'е', 'є', 'ь', 'й',
    ];

    /** @var list<string> */
    private array $terms;

    private string $plain;

    public function __construct(?string $raw)
    {
        $normalized = mb_strtolower(trim((string) $raw));
        // Letters and digits only. The apostrophe separates too ("п'єдестал" -> "п", "єдестал"),
        // the same way the PostgreSQL parser splits it in to_tsvector.
        $normalized = (string) preg_replace("/[^\\p{L}\\p{N}]+/u", ' ', $normalized);
        $words = array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $w): bool => mb_strlen($w) >= 2 || ctype_digit($w)
        ));
        $words = array_slice(array_values(array_unique($words)), 0, self::MAX_TERMS);

        $this->plain = implode(' ', $words);
        $this->terms = array_values(array_unique(array_map(self::stem(...), $words)));
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /** All terms must match: "вазон куб" -> 'вазон:* & куб:*'. */
    public function toTsQueryAll(): string
    {
        return $this->join(' & ');
    }

    /** Any term may match: used as a fallback when the AND query finds nothing. */
    public function toTsQueryAny(): string
    {
        return $this->join(' | ');
    }

    /** Lower-cased, punctuation-free query for pg_trgm word_similarity (typos). */
    public function plain(): string
    {
        return $this->plain;
    }

    /** @return list<string> */
    public function terms(): array
    {
        return $this->terms;
    }

    public static function stem(string $word): string
    {
        if (preg_match('/^\p{N}+$/u', $word)) {
            return $word;
        }

        if (preg_match('/^[a-z]+$/', $word)) {
            // English plurals: planters -> planter, benches -> bench.
            if (mb_strlen($word) > 4 && str_ends_with($word, 'es') && preg_match('/(ch|sh|x|ss)es$/', $word)) {
                return substr($word, 0, -2);
            }
            if (mb_strlen($word) > 4 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
                return substr($word, 0, -1);
            }

            return $word;
        }

        foreach (self::UA_ENDINGS as $ending) {
            if (str_ends_with($word, $ending)
                && mb_strlen($word) - mb_strlen($ending) >= self::MIN_STEM_LENGTH
            ) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($ending));
            }
        }

        return $word;
    }

    private function join(string $operator): string
    {
        return implode($operator, array_map(static fn (string $t): string => $t . ':*', $this->terms));
    }
}
