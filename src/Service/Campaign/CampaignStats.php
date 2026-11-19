<?php

declare(strict_types=1);

namespace App\Service\Campaign;

use Doctrine\DBAL\Connection;

/**
 * Read-side numbers for /admin/campaigns. Humans only (is_bot = false) unless
 * a column says otherwise; "paid" comes from the orders themselves.
 */
class CampaignStats
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Funnel per campaign, keyed by campaign id.
     *
     * @param list<int> $campaignIds
     *
     * @return array<int, array<string, int|float>>
     */
    public function funnel(array $campaignIds): array
    {
        $out = [];
        foreach ($campaignIds as $id) {
            $out[$id] = [
                'scans' => 0, 'scans_bot' => 0, 'visitors' => 0, 'repeat_scans' => 0,
                'landed' => 0, 'registered' => 0, 'logged_in' => 0, 'signed_in' => 0, 'carted' => 0,
                'orders' => 0, 'orders_qr' => 0, 'orders_promocode' => 0, 'paid' => 0, 'paid_sum' => 0,
                'code_uses' => 0, 'code_discount' => 0,
            ];
        }
        if ([] === $campaignIds) {
            return $out;
        }
        $p = ['ids' => $campaignIds];
        $t = ['ids' => Connection::PARAM_INT_ARRAY];

        foreach ($this->db->fetchAllAssociative(
            'SELECT campaign_id,
                    count(*) FILTER (WHERE NOT is_bot) AS scans,
                    count(*) FILTER (WHERE is_bot) AS scans_bot,
                    count(*) FILTER (WHERE NOT is_bot AND is_repeat) AS repeat_scans
               FROM qr_scan WHERE campaign_id IN (:ids) GROUP BY campaign_id', $p, $t) as $r) {
            $out[(int) $r['campaign_id']] = array_merge($out[(int) $r['campaign_id']], [
                'scans' => (int) $r['scans'], 'scans_bot' => (int) $r['scans_bot'], 'repeat_scans' => (int) $r['repeat_scans'],
            ]);
        }

        foreach ($this->db->fetchAllAssociative(
            'SELECT campaign_id, count(*) AS n FROM campaign_visitor WHERE campaign_id IN (:ids) GROUP BY campaign_id', $p, $t) as $r) {
            $out[(int) $r['campaign_id']]['visitors'] = (int) $r['n'];
        }

        foreach ($this->db->fetchAllAssociative(
            "SELECT campaign_id,
                    count(DISTINCT visitor_id) FILTER (WHERE type = 'landing') AS landed,
                    count(DISTINCT coalesce(visitor_id, 'u'||user_id)) FILTER (WHERE type = 'register') AS registered,
                    count(DISTINCT coalesce(visitor_id, 'u'||user_id)) FILTER (WHERE type = 'login') AS logged_in,
                    count(DISTINCT coalesce(visitor_id, 'u'||user_id)) FILTER (WHERE type IN ('register', 'login')) AS signed_in,
                    count(DISTINCT coalesce(visitor_id, 'u'||user_id)) FILTER (WHERE type = 'add_to_cart') AS carted
               FROM campaign_event WHERE campaign_id IN (:ids) GROUP BY campaign_id", $p, $t) as $r) {
            foreach (['landed', 'registered', 'logged_in', 'signed_in', 'carted'] as $k) {
                $out[(int) $r['campaign_id']][$k] = (int) $r[$k];
            }
        }

        foreach ($this->db->fetchAllAssociative(
            "SELECT campaign_id,
                    count(*) AS orders,
                    count(*) FILTER (WHERE campaign_source = 'qr') AS orders_qr,
                    count(*) FILTER (WHERE campaign_source = 'promocode') AS orders_promocode,
                    count(*) FILTER (WHERE liq_pay_status = 'success' OR paid_at IS NOT NULL) AS paid,
                    coalesce(sum(CASE WHEN total_amount ~ '^[0-9]+(\.[0-9]+)?$' THEN total_amount::numeric ELSE 0 END) FILTER (WHERE liq_pay_status = 'success' OR paid_at IS NOT NULL), 0) AS paid_sum
               FROM user_order WHERE campaign_id IN (:ids) GROUP BY campaign_id", $p, $t) as $r) {
            $out[(int) $r['campaign_id']] = array_merge($out[(int) $r['campaign_id']], [
                'orders' => (int) $r['orders'], 'orders_qr' => (int) $r['orders_qr'], 'orders_promocode' => (int) $r['orders_promocode'],
                'paid' => (int) $r['paid'], 'paid_sum' => (float) $r['paid_sum'],
            ]);
        }

        foreach ($this->db->fetchAllAssociative(
            'SELECT c.id AS campaign_id, count(r.id) AS uses, coalesce(sum(r.discount_applied), 0) AS discount
               FROM campaign c JOIN promocode_redemption r ON r.promocode_id = c.promocode_id
              WHERE c.id IN (:ids) AND r.canceled_at IS NULL GROUP BY c.id', $p, $t) as $r) {
            $out[(int) $r['campaign_id']]['code_uses'] = (int) $r['uses'];
            $out[(int) $r['campaign_id']]['code_discount'] = (float) $r['discount'];
        }

        return $out;
    }

    /**
     * Human scans / new visitors / landings per Kyiv calendar day.
     *
     * @return list<array{day: string, scans: int, visitors: int, landed: int}>
     */
    public function daily(int $campaignId): array
    {
        return array_map(static fn (array $r): array => [
            'day' => (string) $r['day'], 'scans' => (int) $r['scans'], 'visitors' => (int) $r['visitors'], 'landed' => (int) $r['landed'],
        ], $this->db->fetchAllAssociative(
            "WITH s AS (
                SELECT (scanned_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Kyiv')::date AS day, count(*) AS scans
                  FROM qr_scan WHERE campaign_id = :c AND NOT is_bot GROUP BY 1
             ), v AS (
                SELECT (first_seen_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Kyiv')::date AS day, count(*) AS visitors
                  FROM campaign_visitor WHERE campaign_id = :c GROUP BY 1
             ), l AS (
                SELECT (created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Kyiv')::date AS day, count(*) AS landed
                  FROM campaign_event WHERE campaign_id = :c AND type = 'landing' GROUP BY 1
             )
             SELECT to_char(d.day, 'DD.MM.YYYY') AS day, coalesce(s.scans, 0) AS scans, coalesce(v.visitors, 0) AS visitors, coalesce(l.landed, 0) AS landed
               FROM (SELECT day FROM s UNION SELECT day FROM v UNION SELECT day FROM l) d
               LEFT JOIN s ON s.day = d.day LEFT JOIN v ON v.day = d.day LEFT JOIN l ON l.day = d.day
              ORDER BY d.day DESC",
            ['c' => $campaignId],
        ));
    }

    /**
     * Latest scans with what that visitor did afterwards.
     *
     * @return list<array<string, mixed>>
     */
    public function scans(int $campaignId, bool $includeBots, int $limit = 300): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT s.id, s.scanned_at, s.ip, s.ip_anonymized, s.user_agent, s.referer, s.accept_language,
                    s.is_bot, s.bot_reason, s.is_repeat, s.visitor_id,
                    v.user_id, u.first_name, u.last_name, u.email, u.phone,
                    (SELECT string_agg(DISTINCT e.type, ',') FROM campaign_event e WHERE e.visitor_id = s.visitor_id) AS steps,
                    (SELECT count(*) FROM user_order o WHERE o.campaign_visitor_id = s.visitor_id) AS orders,
                    (SELECT count(*) FROM user_order o WHERE o.campaign_visitor_id = s.visitor_id AND (o.liq_pay_status = 'success' OR o.paid_at IS NOT NULL)) AS paid
               FROM qr_scan s
               LEFT JOIN campaign_visitor v ON v.visitor_id = s.visitor_id
               LEFT JOIN client_user u ON u.id = v.user_id
              WHERE s.campaign_id = :c ".($includeBots ? '' : 'AND NOT s.is_bot ').'
              ORDER BY s.id DESC
              LIMIT '.max(1, min(2000, $limit)),
            ['c' => $campaignId],
        );
    }

    /**
     * Orders attributed to the campaign.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(int $campaignId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT o.id, o.created_at, o.total_amount, o.discount_amount, o.promocode_code_used, o.campaign_source,
                    (o.liq_pay_status = 'success' OR o.paid_at IS NOT NULL) AS is_paid, o.payment_method,
                    o.client_user_id, u.first_name, u.last_name, u.phone, o.phone AS guest_phone
               FROM user_order o LEFT JOIN client_user u ON u.id = o.client_user_id
              WHERE o.campaign_id = :c
              ORDER BY o.id DESC",
            ['c' => $campaignId],
        );
    }
}
