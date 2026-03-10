<?php

namespace app\classes\voip;

use app\classes\Singleton;
use app\models\voip\StateServiceVoip;

class StateVoipUpdater extends Singleton
{
    private array $sql = [];

    protected ?int $accountTariffId = null;

    protected ?string $table = null;

    public function update(?int $accountTariffId = null)
    {
        $this->sql = [];
        echo PHP_EOL . date('r');
        $this->table = StateServiceVoip::tableName();

        $this->createTable();
        $this->makeActual($accountTariffId);

        $this->addMissing();
        $this->deleteMissing($accountTariffId);
        $this->makeChanges($accountTariffId);
        $this->_dropTable();

        try {
            foreach ($this->sql as $sql) {
//                echo PHP_EOL . $sql;
                preg_match('/^\s*(\w+)\b/', $sql, $m);
                echo PHP_EOL . $m[1] . ': ';
                echo var_export(\Yii::$app->db->createCommand($sql)->execute(), true);
            }
        } catch (\Exception $e) {
            $this->_dropTable(true);
            throw $e;
        }
        echo PHP_EOL;
    }

    private function createTable()
    {
        $this->_dropTable();
        $this->sql[] = "CREATE TEMPORARY TABLE {$this->table}_tmp LIKE {$this->table}";
    }

    private function _dropTable($isExecuteNow = false)
    {
        $sql = "DROP TEMPORARY TABLE IF EXISTS {$this->table}_tmp";
        if ($isExecuteNow) {
            \Yii::$app->db->createCommand($sql)->execute();
        } else {
            $this->sql[] = $sql;
        }
    }

    private function makeActual($accountTariffId)
    {
        if ($accountTariffId) {
            $this->makeActualSingle($accountTariffId);
        } else {
            $this->makeActualFull();
        }
    }

    private function makeActualSingle(int $accountTariffId)
    {
        $this->sql[] = <<<SQL

INSERT INTO {$this->table}_tmp
SELECT usage_id,
       client_id,
       e164,
       region                                                 as region,
       actual_from,
       actual_to,
       activation_dt,
       IF(expire_dt > '3000-01-01 00:00:00', NULL, expire_dt) AS expire_dt,
       lines_amount,
       trim(device_address)                                   AS device_address,
       is_verified,
       iccid,
       imsi
FROM (
         SELECT u.id                                          AS usage_id,
                c.id                                          AS client_id,
                u.e164,
                v.region,
                actual_from                                   AS actual_from,
                IF(actual_to > '3000-01-01', NULL, actual_to) AS actual_to,
                activation_dt,
                expire_dt,
                no_of_lines                                   as lines_amount,
                u.address                                     AS device_address,
                null                                          AS is_verified,
                null                                          AS iccid,
                null                                          AS imsi
         FROM usage_voip u,
              voip_numbers v,
              clients c
         WHERE true
           AND c.client = u.client
           AND u.e164 = v.number
           AND u.id = {$accountTariffId}

         UNION

         SELECT usage_id,
                client_id,
                e164,
                region,
                cast(activation_dt as date) as actual_from,
                cast(expire_dt as date)     as actual_to,
                activation_dt,
                expire_dt,
                lines_amount,
                device_address,
                is_verified,
                iccid,
                imsi
         FROM (
                  SELECT u.id                                                     AS usage_id,
                         client_account_id                                        AS client_id,
                         voip_number                                              AS e164,
                         v.region,
                         (SELECT actual_from_utc
                          FROM uu_account_tariff_log
                          WHERE account_tariff_id = u.id
                            AND tariff_period_id IS NOT NULL
                          ORDER BY actual_from_utc
                          LIMIT 1)                                                   activation_dt,
                         (SELECT actual_from_utc
                          FROM uu_account_tariff_log
                          WHERE account_tariff_id = u.id
                            AND tariff_period_id IS NULL
                          ORDER BY actual_from_utc DESC
                          LIMIT 1)                                                   expire_dt,
                         (SELECT max(amount)
                          FROM uu_account_tariff_resource_log l
                          WHERE l.account_tariff_id = u.id AND l.resource_id = 7) as lines_amount,
                         u.device_address,
                         u.is_verified,
                         CAST(JSON_UNQUOTE(JSON_EXTRACT(u.calltracking_params, '$.iccid')) AS UNSIGNED) as iccid,
                         CAST(JSON_UNQUOTE(JSON_EXTRACT(u.calltracking_params, '$.imsi')) AS UNSIGNED) as imsi
                  FROM uu_account_tariff u,
                       voip_numbers v,
                       clients c
                  WHERE true
                    AND u.voip_number = v.number
                    AND c.id = u.client_account_id
                    AND service_type_id = 2
                    AND u.id = {$accountTariffId}
              ) a
     ) a;
SQL;
    }

    private function makeActualFull()
    {
        $this->sql[] = <<<SQL

INSERT INTO {$this->table}_tmp
SELECT usage_id,
       client_id,
       e164,
       region                                                 as region,
       actual_from,
       actual_to,
       activation_dt,
       IF(expire_dt > '3000-01-01 00:00:00', NULL, expire_dt) AS expire_dt,
       lines_amount,
       trim(device_address)                                   AS device_address,
       is_verified,
       iccid,
       imsi
FROM (
         SELECT u.id                                          AS usage_id,
                c.id                                          AS client_id,
                u.e164,
                v.region,
                actual_from                                   AS actual_from,
                IF(actual_to > '3000-01-01', NULL, actual_to) AS actual_to,
                activation_dt,
                expire_dt,
                no_of_lines                                   as lines_amount,
                u.address                                     AS device_address,
                null                                          AS is_verified,
                null                                          AS iccid,
                null                                          AS imsi
         FROM usage_voip u,
              voip_numbers v,
              clients c
         WHERE true
           AND c.client = u.client
           AND u.e164 = v.number

         UNION

         SELECT u.id                                                      AS usage_id,
                client_account_id                                         AS client_id,
                voip_number                                               AS e164,
                v.region,
                cast(atl_act.activation_dt as date)                       as actual_from,
                cast(atl_exp.expire_dt as date)                           as actual_to,
                atl_act.activation_dt,
                atl_exp.expire_dt,
                atrl.lines_amount,
                u.device_address,
                u.is_verified,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(u.calltracking_params, '$.iccid')) AS UNSIGNED) as iccid,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(u.calltracking_params, '$.imsi')) AS UNSIGNED) as imsi
         FROM uu_account_tariff u
         JOIN voip_numbers v ON u.voip_number = v.number
         JOIN clients c ON c.id = u.client_account_id
         LEFT JOIN (
             SELECT account_tariff_id, MIN(actual_from_utc) as activation_dt
             FROM uu_account_tariff_log
             WHERE tariff_period_id IS NOT NULL
             GROUP BY account_tariff_id
         ) atl_act ON atl_act.account_tariff_id = u.id
         LEFT JOIN (
             SELECT account_tariff_id, MAX(actual_from_utc) as expire_dt
             FROM uu_account_tariff_log
             WHERE tariff_period_id IS NULL
             GROUP BY account_tariff_id
         ) atl_exp ON atl_exp.account_tariff_id = u.id
         LEFT JOIN (
             SELECT account_tariff_id, MAX(amount) as lines_amount
             FROM uu_account_tariff_resource_log
             WHERE resource_id = 7
             GROUP BY account_tariff_id
         ) atrl ON atrl.account_tariff_id = u.id
         WHERE service_type_id = 2
     ) a;
SQL;
    }

    private function addMissing()
    {
        $this->sql[] = <<<SQL
insert into {$this->table}
select a.*
from {$this->table}_tmp a
         left join {$this->table} b using (usage_id)
where b.usage_id is null;
SQL;
    }

    private function deleteMissing($accountTariffId)
    {
        $where = $accountTariffId ? 'AND a.usage_id = '.$accountTariffId : '';

        $this->sql[] = <<<SQL
DELETE z
FROM {$this->table} z,
     (
         SELECT a.usage_id
         FROM {$this->table} a
                  LEFT JOIN {$this->table}_tmp b USING (usage_id)
         WHERE b.usage_id is null
               {$where}
     ) a
WHERE a.usage_id = z.usage_id
SQL;
    }

    private function makeChanges($accountTariffId)
    {
        $where = $accountTariffId ? 'AND a.usage_id = '.$accountTariffId : '';

        $this->sql[] = <<<SQL
update
    {$this->table} s,
    (select b.*
     from {$this->table} a
              join {$this->table}_tmp b using (usage_id)
     where true
       and (
                 a.lines_amount != b.lines_amount
             or a.actual_from != b.actual_from
             or coalesce(a.actual_to, '') != coalesce(b.actual_to, '')
             or a.activation_dt != b.activation_dt
             or coalesce(a.expire_dt, '') != coalesce(b.expire_dt, '')
             or coalesce(a.device_address, '') != coalesce(b.device_address, '')
             or coalesce(a.is_verified, '') != coalesce(b.is_verified, '')
             or coalesce(a.region, '') != coalesce(b.region, '')
             or coalesce(a.imsi, 0) != coalesce(b.imsi, 0)
             or coalesce(a.iccid, 0) != coalesce(b.iccid, 0)
         )
         {$where}
    ) b
set s.lines_amount = b.lines_amount,
    s.actual_from = b.actual_from,
    s.actual_to = b.actual_to,
    s.expire_dt = b.expire_dt,
    s.activation_dt = b.activation_dt,
    s.device_address = b.device_address,
    s.region = b.region,
    s.is_verified = b.is_verified,
    s.imsi = b.imsi,
    s.iccid = b.iccid
where s.usage_id = b.usage_id
SQL;
    }

}