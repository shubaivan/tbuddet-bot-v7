<?php

declare(strict_types=1);

namespace App\Service\Campaign;

use Doctrine\DBAL\Connection;

/**
 * Raw DBAL writes for campaign tracking. Kept off the ORM on purpose: these run
 * inside requests that do other work (checkout, login) and a failed insert must
 * never close the EntityManager (see the promocode redeem() lesson).
 */
class CampaignStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{campaign_id: ?int, slug: string, visitor_id: ?string, ip: ?string, user_agent: string,
     *              referer: ?string, accept_language: ?string, is_bot: bool, bot_reason: ?string, is_repeat: bool} $scan
     */
    public function insertScan(array $scan, \DateTimeImmutable $at): void
    {
        $this->db->executeStatement(
            'INSERT INTO qr_scan (id, campaign_id, slug, visitor_id, scanned_at, ip, user_agent, referer, accept_language, is_bot, bot_reason, is_repeat)
             VALUES (nextval(\'qr_scan_id_seq\'), :campaign_id, :slug, :visitor_id, :at, :ip, :ua, :referer, :lang, :is_bot, :bot_reason, :is_repeat)',
            [
                'campaign_id' => $scan['campaign_id'],
                'slug' => mb_substr($scan['slug'], 0, 64),
                'visitor_id' => $scan['visitor_id'],
                'at' => $at->format('Y-m-d H:i:s'),
                'ip' => null !== $scan['ip'] ? mb_substr($scan['ip'], 0, 45) : null,
                'ua' => mb_substr($scan['user_agent'], 0, 512),
                'referer' => null !== $scan['referer'] ? mb_substr($scan['referer'], 0, 512) : null,
                'lang' => null !== $scan['accept_language'] ? mb_substr($scan['accept_language'], 0, 64) : null,
                'is_bot' => $scan['is_bot'],
                'bot_reason' => $scan['bot_reason'],
                'is_repeat' => $scan['is_repeat'],
            ],
            ['is_bot' => 'boolean', 'is_repeat' => 'boolean'],
        );
    }

    /**
     * @return array{visitor_id: string, campaign_id: int, first_seen_at: string, user_id: ?int}|null
     */
    public function findVisitor(string $visitorId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT visitor_id, campaign_id, first_seen_at, user_id FROM campaign_visitor WHERE visitor_id = :v',
            ['v' => $visitorId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'visitor_id' => (string) $row['visitor_id'],
            'campaign_id' => (int) $row['campaign_id'],
            'first_seen_at' => (string) $row['first_seen_at'],
            'user_id' => null !== $row['user_id'] ? (int) $row['user_id'] : null,
        ];
    }

    /** New visitor (first touch) — a no-op if the id already exists. */
    public function createVisitor(string $visitorId, int $campaignId, \DateTimeImmutable $at): void
    {
        $this->db->executeStatement(
            'INSERT INTO campaign_visitor (visitor_id, campaign_id, first_seen_at, last_seen_at)
             VALUES (:v, :c, :at, :at) ON CONFLICT (visitor_id) DO NOTHING',
            ['v' => $visitorId, 'c' => $campaignId, 'at' => $at->format('Y-m-d H:i:s')],
        );
    }

    public function touchVisitor(string $visitorId, \DateTimeImmutable $at): void
    {
        $this->db->executeStatement(
            'UPDATE campaign_visitor SET last_seen_at = :at WHERE visitor_id = :v',
            ['v' => $visitorId, 'at' => $at->format('Y-m-d H:i:s')],
        );
    }

    /**
     * The visitor's campaign if the first touch is still inside the campaign's
     * attribution window.
     *
     * @return array{visitor_id: string, campaign_id: int, user_id: ?int}|null
     */
    public function findAttributableVisitor(string $visitorId, \DateTimeImmutable $now): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT v.visitor_id, v.campaign_id, v.user_id
               FROM campaign_visitor v JOIN campaign c ON c.id = v.campaign_id
              WHERE v.visitor_id = :v
                AND v.first_seen_at >= CAST(:now AS timestamp) - make_interval(days => c.attribution_days)",
            ['v' => $visitorId, 'now' => $now->format('Y-m-d H:i:s')],
        );

        return false === $row ? null : self::visitorRow($row);
    }

    /**
     * Latest in-window visitor already linked to this account — attributes the
     * user's later actions from another device / after the header was lost.
     *
     * @return array{visitor_id: string, campaign_id: int, user_id: ?int}|null
     */
    public function findAttributableVisitorForUser(int $userId, \DateTimeImmutable $now): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT v.visitor_id, v.campaign_id, v.user_id
               FROM campaign_visitor v JOIN campaign c ON c.id = v.campaign_id
              WHERE v.user_id = :u
                AND v.first_seen_at >= CAST(:now AS timestamp) - make_interval(days => c.attribution_days)
              ORDER BY v.first_seen_at ASC
              LIMIT 1",
            ['u' => $userId, 'now' => $now->format('Y-m-d H:i:s')],
        );

        return false === $row ? null : self::visitorRow($row);
    }

    /** First account wins: a shared phone never re-links a visitor to someone else. */
    public function linkVisitorToUser(string $visitorId, int $userId, \DateTimeImmutable $at): void
    {
        $this->db->executeStatement(
            'UPDATE campaign_visitor SET user_id = :u, linked_at = :at, last_seen_at = :at WHERE visitor_id = :v AND user_id IS NULL',
            ['u' => $userId, 'v' => $visitorId, 'at' => $at->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @return bool false when the row was a duplicate (only "landing" is unique per visitor)
     */
    public function insertEvent(int $campaignId, ?string $visitorId, string $type, ?int $userId, ?int $orderId, \DateTimeImmutable $at): bool
    {
        return $this->db->executeStatement(
            "INSERT INTO campaign_event (id, campaign_id, visitor_id, type, user_id, user_order_id, created_at)
             VALUES (nextval('campaign_event_id_seq'), :c, :v, :t, :u, :o, :at)
             ON CONFLICT DO NOTHING",
            ['c' => $campaignId, 'v' => $visitorId, 't' => $type, 'u' => $userId, 'o' => $orderId, 'at' => $at->format('Y-m-d H:i:s')],
        ) > 0;
    }

    /** Snapshot on the order — only if it has none yet (first attribution wins). */
    public function setOrderCampaign(int $orderId, int $campaignId, ?string $visitorId, string $source): bool
    {
        return $this->db->executeStatement(
            'UPDATE user_order SET campaign_id = :c, campaign_visitor_id = :v, campaign_source = :s WHERE id = :id AND campaign_id IS NULL',
            ['c' => $campaignId, 'v' => $visitorId, 's' => $source, 'id' => $orderId],
        ) > 0;
    }

    /** Campaign whose promocode is this code (case-insensitive), if any. */
    public function findCampaignIdByPromocodeCode(string $code): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT c.id FROM campaign c JOIN promocode p ON p.id = c.promocode_id WHERE p.code = :code ORDER BY c.id LIMIT 1',
            ['code' => strtoupper(trim($code))],
        );

        return false === $id || null === $id ? null : (int) $id;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{visitor_id: string, campaign_id: int, user_id: ?int}
     */
    private static function visitorRow(array $row): array
    {
        return [
            'visitor_id' => (string) $row['visitor_id'],
            'campaign_id' => (int) $row['campaign_id'],
            'user_id' => null !== $row['user_id'] ? (int) $row['user_id'] : null,
        ];
    }

    /**
     * IP retention: zero the host part of IPs older than $before.
     *
     * @return int rows changed (or that would change, in dry-run)
     */
    public function anonymizeIpsBefore(\DateTimeImmutable $before, bool $dryRun, int $batch = 1000): int
    {
        $total = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, ip FROM qr_scan WHERE ip_anonymized = FALSE AND scanned_at < :before ORDER BY id LIMIT '.(int) $batch.' OFFSET '.($dryRun ? $total : 0),
                ['before' => $before->format('Y-m-d H:i:s')],
            );
            foreach ($rows as $row) {
                if (!$dryRun) {
                    $this->db->executeStatement(
                        'UPDATE qr_scan SET ip = :ip, ip_anonymized = TRUE WHERE id = :id',
                        ['ip' => IpAnonymizer::anonymize($row['ip']), 'id' => $row['id']],
                    );
                }
                ++$total;
            }
        } while (\count($rows) === $batch);

        return $total;
    }
}
