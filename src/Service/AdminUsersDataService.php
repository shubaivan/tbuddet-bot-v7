<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Returns a unified Telegram + Web user dataset for the /admin/users
 * DataTable. Both kinds live in different tables (telegram_user,
 * client_user) but each row has the same normalized shape:
 *
 *   source              'tg' | 'web'
 *   origin_id           int — id within its source table
 *   row_key             string — composite key "tg-3" / "web-5"
 *   display_name        string
 *   handle              string|null — @username (tg) or email (web)
 *   phone               string|null
 *   created_at          datetime ISO
 *   last_visit          datetime ISO
 *   orders_total_count  int
 *   orders_paid_count   int
 *   orders_paid_amount  float
 */
class AdminUsersDataService
{
    public function __construct(private Connection $db) {}

    public function fetch(array $params): array
    {
        $search    = trim((string) ($params['search']['value'] ?? ''));
        $start     = (int) ($params['start']  ?? 0);
        $length    = (int) ($params['length'] ?? 10);
        $orderCol  = $params['order'][0]['column'] ?? 0;
        $orderDir  = strtolower($params['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $filterOrders = (string) ($params['filter_orders'] ?? '');
        $filterFrom   = (string) ($params['filter_reg_from'] ?? '');
        $filterTo     = (string) ($params['filter_reg_to'] ?? '');
        $filterSource = (string) ($params['filter_source'] ?? '');

        // Map DataTable column index to UNION column name. Keep in lockstep
        // with TelegramUser::$dataTableFields.
        $sortMap = [
            0 => 'origin_id',
            1 => 'display_name',
            2 => 'phone',
            3 => 'orders_paid_count',
            4 => 'created_at',
            5 => 'last_visit',
        ];
        $sortBy = $sortMap[$orderCol] ?? 'created_at';

        $totalSql = '
            SELECT
                \'tg\'::text AS source,
                tu.id AS origin_id,
                NULLIF(TRIM(BOTH FROM COALESCE(tu.first_name, \'\') || \' \' || COALESCE(tu.last_name, \'\')), \'\') AS display_name,
                tu.username AS handle,
                tu.phone_number AS phone,
                tu.created_at,
                tu.updated_at AS last_visit,
                COUNT(o.id) AS orders_total_count,
                COUNT(o.id) FILTER (WHERE o.liq_pay_status = \'success\') AS orders_paid_count,
                COALESCE(SUM(o.total_amount) FILTER (WHERE o.liq_pay_status = \'success\'), 0) AS orders_paid_amount
            FROM telegram_user tu
            LEFT JOIN user_order o ON o.telegram_user_id = tu.id
            GROUP BY tu.id

            UNION ALL

            SELECT
                \'web\'::text AS source,
                cu.id AS origin_id,
                NULLIF(TRIM(BOTH FROM COALESCE(cu.first_name, \'\') || \' \' || COALESCE(cu.last_name, \'\')), \'\') AS display_name,
                cu.email AS handle,
                cu.phone AS phone,
                cu.created_at,
                cu.updated_at AS last_visit,
                COUNT(o.id) AS orders_total_count,
                COUNT(o.id) FILTER (WHERE o.liq_pay_status = \'success\') AS orders_paid_count,
                COALESCE(SUM(o.total_amount) FILTER (WHERE o.liq_pay_status = \'success\'), 0) AS orders_paid_amount
            FROM client_user cu
            LEFT JOIN user_order o ON o.client_user_id = cu.id
            GROUP BY cu.id
        ';

        $wheres = [];
        $binds  = [];
        if ($search !== '') {
            $wheres[] = '(LOWER(display_name) LIKE :q OR LOWER(handle) LIKE :q OR LOWER(phone) LIKE :q)';
            $binds[':q'] = '%' . mb_strtolower($search) . '%';
        }
        if ($filterFrom !== '') {
            $wheres[] = 'created_at >= :reg_from';
            $binds[':reg_from'] = $filterFrom . ' 00:00:00';
        }
        if ($filterTo !== '') {
            $wheres[] = 'created_at <= :reg_to';
            $binds[':reg_to'] = $filterTo . ' 23:59:59';
        }
        if ($filterSource === 'tg' || $filterSource === 'web') {
            $wheres[] = 'source = :source';
            $binds[':source'] = $filterSource;
        }
        if ($filterOrders === 'with_orders') {
            $wheres[] = 'orders_total_count > 0';
        } elseif ($filterOrders === 'without_orders') {
            $wheres[] = 'orders_total_count = 0';
        }

        $whereSql = $wheres ? ' WHERE ' . implode(' AND ', $wheres) : '';

        $recordsTotalRaw = $this->db->fetchOne('SELECT COUNT(*) FROM (' . $totalSql . ') AS unfiltered');
        $recordsTotal = (int) $recordsTotalRaw;

        $filteredCountSql = 'SELECT COUNT(*) FROM (' . $totalSql . ') AS u' . $whereSql;
        $recordsFiltered = (int) $this->db->fetchOne($filteredCountSql, $binds);

        $dataSql = 'SELECT * FROM (' . $totalSql . ') AS u' . $whereSql
            . ' ORDER BY ' . $sortBy . ' ' . $orderDir . ', source ASC'
            . ' LIMIT :lim OFFSET :off';
        $binds[':lim'] = $length;
        $binds[':off'] = $start;

        $rows = $this->db->fetchAllAssociative($dataSql, $binds);

        $data = array_map(function (array $r) {
            $createdAt = $this->fmt($r['created_at'] ?? null);
            $lastVisit = $this->fmt($r['last_visit'] ?? null);
            return [
                'row_key'             => $r['source'] . '-' . $r['origin_id'],
                'source'              => $r['source'],
                'origin_id'           => (int) $r['origin_id'],
                'display_name'        => $r['display_name'],
                'handle'              => $r['handle'],
                'phone'               => $r['phone'],
                'created_at'          => $createdAt,
                'last_visit'          => $lastVisit,
                'orders_total_count'  => (int) $r['orders_total_count'],
                'orders_paid_count'   => (int) $r['orders_paid_count'],
                'orders_paid_amount'  => (float) $r['orders_paid_amount'],
            ];
        }, $rows);

        return [
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ];
    }

    private function fmt($v): ?string
    {
        if (!$v) return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        try { return (new \DateTimeImmutable((string) $v))->format('Y-m-d H:i:s'); }
        catch (\Throwable) { return (string) $v; }
    }
}
