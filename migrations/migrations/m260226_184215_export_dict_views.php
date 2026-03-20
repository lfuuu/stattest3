<?php

/**
 * View'шки в схеме export_dict.
 * Схема export_dict должна быть создана DBA до запуска миграции:
 *   CREATE DATABASE IF NOT EXISTS `export_dict`;
 */
class m260226_184215_export_dict_views extends \app\classes\Migration
{
    private $schema = 'export_dict';

    private $requiredTables = [
        'nispd' => [
            'newbills',
            'client_contragent',
            'client_contragent_person',
            'z_model_life_log',
            'voip_numbers',
            'newpayments',
            'newpayment_info',
            'bik',
            'newpayment_info_short',
            'sorm_redirect_ranges',
            'state_service_voip',
            'sim_registry',
            'sim_region_settings',
            'voip_registry',
            'city',
        ],
    ];

    public function safeUp()
    {
        $schemaExists = (int)$this->db->createCommand(
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :schema",
            [':schema' => $this->schema]
        )->queryScalar();

        if (!$schemaExists) {
            throw new \yii\db\Exception(
                "Схема `{$this->schema}` не существует. "
                . "DBA должен выполнить: CREATE DATABASE `{$this->schema}`;"
            );
        }

        $missing = $this->checkRequiredTables();
        if ($missing) {
            throw new \yii\db\Exception(
                "Отсутствуют таблицы: " . implode(', ', $missing)
            );
        }

        foreach ($this->getViews() as $name => $sql) {
            $this->execute("CREATE OR REPLACE VIEW `{$this->schema}`.`{$name}` AS {$sql}");
        }
    }

    public function safeDown()
    {
        foreach (array_keys($this->getViews()) as $name) {
            $this->execute("DROP VIEW IF EXISTS `{$this->schema}`.`{$name}`");
        }

        // Схему не удаляем -- она создается DBA вручную
    }

    /**
     * @return string[]
     */
    private function checkRequiredTables(): array
    {
        $missing = [];
        foreach ($this->requiredTables as $schema => $tables) {
            foreach ($tables as $table) {
                $exists = (int)$this->db->createCommand(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table",
                    [':schema' => $schema, ':table' => $table]
                )->queryScalar();

                if (!$exists) {
                    $missing[] = "`{$schema}`.`{$table}`";
                }
            }
        }
        return $missing;
    }

    /**
     * @return array [viewName => selectSQL]
     */
    private function getViews(): array
    {
        return [
            'bill' => "
                SELECT
                    `nb`.`id`                AS `id`,
                    `nb`.`operation_type_id` AS `operation_type_id`,
                    `nb`.`bill_no`           AS `bill_no`,
                    `nb`.`bill_date`         AS `bill_date`,
                    `nb`.`client_id`         AS `account_id`,
                    `nb`.`currency`          AS `currency`,
                    `nb`.`sum`               AS `sum`,
                    `nb`.`is_payed`          AS `is_payed`,
                    `nb`.`organization_id`   AS `organization_id`,
                    `nb`.`pay_bill_until`    AS `pay_bill_until`,
                    `nb`.`is_pay_overdue`    AS `is_pay_overdue`,
                    `nb`.`payment_date`      AS `payment_date`,
                    `nb`.`sum_correction`    AS `sum_correction`
                FROM `nispd`.`newbills` `nb`
                WHERE `nb`.`bill_date` >= '2025-07-01'
                    AND `nb`.`sum` > 0
                ORDER BY `nb`.`id`
            ",

            'contragent' => "
                SELECT
                    `cg`.`id`                                   AS `id`,
                    `cg`.`is_lk_first`                          AS `is_lk_first`,
                    `cg`.`legal_type`                           AS `legal_type`,
                    `cg`.`name`                                 AS `contragent_name`,
                    `cg`.`inn`                                  AS `inn`,
                    `cg`.`ogrn`                                 AS `ogrn`,
                    `cg`.`address_jur`                          AS `address_registration_legal`,
                    IF(`cg`.`legal_type` = 'person', 1, NULL)   AS `contragent_document_type_id`,
                    `cgp`.`first_name`                          AS `family_name`,
                    `cgp`.`last_name`                           AS `given_name`,
                    `cgp`.`middle_name`                         AS `sinitial_name`,
                    `cgp`.`passport_serial`                     AS `passport_serial`,
                    `cgp`.`passport_number`                     AS `passport_number`,
                    `cgp`.`passport_date_issued`                AS `passport_issued_date`,
                    `cgp`.`passport_issued`                     AS `passport_issued`,
                    `cgp`.`birthday`                            AS `birthday`,
                    `cgp`.`registration_address`                AS `address_registration_personal`
                FROM `nispd`.`client_contragent` `cg`
                LEFT JOIN `nispd`.`client_contragent_person` `cgp` ON (`cgp`.`contragent_id` = `cg`.`id`)
                ORDER BY `cg`.`id` DESC
            ",

            'model_event_log' => "
                SELECT
                    `mll`.`id`            AS `id`,
                    `mll`.`created_at`    AS `dt`,
                    `mll`.`model`         AS `table_name`,
                    UPPER(`mll`.`action`) AS `event`,
                    `mll`.`model_id`      AS `param`,
                    `mll`.`id`            AS `version`
                FROM `nispd`.`z_model_life_log` `mll`
            ",

            'number_mobile_instock' => "
                SELECT `vn`.`number` AS `number`
                FROM `nispd`.`voip_numbers` `vn`
                WHERE `vn`.`status` = 'instock'
                    AND `vn`.`country_code` = 643
                    AND `vn`.`ndc_type_id` = 2
            ",

            'payment_bank' => "
                SELECT
                    'bank'                          AS `payment_type`,
                    `i`.`payment_id`                AS `payment_id`,
                    `p`.`client_id`                 AS `account_id`,
                    CAST(`p`.`add_date` AS DATE)    AS `payment_date`,
                    `p`.`sum`                       AS `amount`,
                    `p`.`currency`                  AS `amount_currency`,
                    `i`.`payer_account`             AS `payer_account`,
                    `i`.`payer_bik`                 AS `payer_bank_bik`,
                    `i`.`payer_bank`                AS `payer_bank_name`,
                    CONCAT('г. ', `b`.`bank_city`, ', ', `b`.`bank_address`) AS `payer_bank_address`,
                    `b`.`dadata`                    AS `dadata`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.postal_code')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.postal_code')) END AS `postal_code`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.country')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.country')) END AS `country`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.district_type')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.district_type')) END AS `district_type`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.district')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.district')) END AS `district`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region_type')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region_type')) END AS `region_type`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region')) END AS `region`,
                    COALESCE(
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city_type')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city_type')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement_type')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement_type')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region_type')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region_type')) END
                    ) AS `city_type`,
                    COALESCE(
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.region')) END
                    ) AS `city`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city_district_type')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city_district_type')) END AS `city_district_type`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city_district')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.city_district')) END AS `city_district`,
                    COALESCE(
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.street_type')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.street_type')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement_type')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement_type')) END
                    ) AS `street_type`,
                    COALESCE(
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.street')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.street')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.settlement')) END
                    ) AS `street`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.house')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.house')) END AS `house`,
                    COALESCE(
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.housing')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.housing')) END,
                        CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.block')) = 'NULL' THEN NULL
                             ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.block')) END
                    ) AS `block`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.flat_type')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.flat_type')) END AS `flat_type`,
                    CASE WHEN JSON_TYPE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.flat')) = 'NULL' THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(`b`.`dadata`, '$.data.address.data.flat')) END AS `flat`
                FROM `nispd`.`newpayments` `p`
                LEFT JOIN `nispd`.`newpayment_info` `i` ON (`p`.`id` = `i`.`payment_id`)
                LEFT JOIN `nispd`.`bik` `b` ON (`b`.`bik` = `i`.`payer_bik`)
                WHERE `p`.`sum` > 0
                    AND `p`.`add_date` > (NOW() - INTERVAL 3 YEAR)
                    AND `p`.`organization_id` IN (1, 14)
                HAVING `i`.`payment_id` IS NOT NULL
                ORDER BY `p`.`id`
            ",

            'payment_undefined' => "
                SELECT
                    `p`.`id`             AS `id`,
                    `p`.`client_id`      AS `account_id`,
                    `p`.`payment_no`     AS `payment_no`,
                    `p`.`payment_date`   AS `payment_date`,
                    `p`.`sum`            AS `amount`,
                    `p`.`currency`       AS `amount_currency`,
                    `s`.`type`           AS `type`,
                    `s`.`comment`        AS `type_info`,
                    `p`.`type`           AS `payment_type`,
                    `p`.`ecash_operator` AS `ecash_operator`,
                    NULL                 AS `pay_type_id`
                FROM `nispd`.`newpayment_info_short` `s`
                JOIN `nispd`.`newpayments` `p` ON (`p`.`id` = `s`.`payment_id`)
                WHERE `p`.`organization_id` IN (1, 14)
                ORDER BY `p`.`id`
            ",

            'redirect_ranges' => "
                SELECT
                    `srr`.`usage_id`   AS `usage_id`,
                    `srr`.`did`        AS `did`,
                    `srr`.`type`       AS `type`,
                    `srr`.`numbers`    AS `numbers`,
                    `srr`.`open_time`  AS `open_time`,
                    `srr`.`close_time` AS `close_time`,
                    `srr`.`id`         AS `id`
                FROM `nispd`.`sorm_redirect_ranges` `srr`
            ",

            'service' => "
                SELECT
                    `sv`.`client_id`                                            AS `account_id`,
                    `sv`.`usage_id`                                             AS `service_id`,
                    'VOIP'                                                      AS `service_type`,
                    `sv`.`e164`                                                 AS `service_phone`,
                    `sv`.`region`                                               AS `region_id`,
                    `n`.`ndc_type_id`                                           AS `ndc_type_id`,
                    COALESCE(IF(`n`.`ndc_type_id` = 2, `sv`.`imsi`, ''), '')    AS `service_content_main`,
                    COALESCE(IF(`n`.`ndc_type_id` = 2, `sv`.`iccid`, ''), '')   AS `service_content_second`,
                    `sv`.`activation_dt`                                        AS `activation_dt`,
                    `sv`.`expire_dt`                                            AS `expire_dt`,
                    `sv`.`device_address`                                       AS `device_address`,
                    COALESCE(`sv`.`is_verified`, TRUE)                          AS `is_verified`
                FROM `nispd`.`state_service_voip` `sv`
                JOIN `nispd`.`voip_numbers` `n` ON (`sv`.`e164` = `n`.`number`)
                WHERE `n`.`country_code` = 643
                ORDER BY `sv`.`usage_id` DESC
            ",

            'sorm_redirect_ranges' => "
                SELECT
                    `srr`.`usage_id`   AS `usage_id`,
                    `srr`.`did`        AS `did`,
                    `srr`.`type`       AS `type`,
                    `srr`.`numbers`    AS `numbers`,
                    `srr`.`open_time`  AS `open_time`,
                    `srr`.`close_time` AS `close_time`,
                    `srr`.`id`         AS `id`
                FROM `nispd`.`sorm_redirect_ranges` `srr`
            ",

            'voip_mobile_imsi_plan' => "
                SELECT
                    `r`.`id`                                AS `id`,
                    `s`.`region_id`                         AS `region_id`,
                    `s`.`imsi_prefix`                       AS `imsi_prefix`,
                    `s`.`imsi_region_code`                  AS `imsi_region_code`,
                    `r`.`imsi_from`                         AS `imsi_from`,
                    `r`.`imsi_to`                           AS `imsi_to`,
                    `s`.`imsi_range_length`                 AS `imsi_range_length`,
                    (`r`.`imsi_to` - `r`.`imsi_from` + 1)   AS `count`,
                    `r`.`log`                               AS `log`,
                    `r`.`updated_at`                        AS `updated_at`
                FROM `nispd`.`sim_registry` `r`
                JOIN `nispd`.`sim_region_settings` `s` ON (`s`.`id` = `r`.`region_sim_settings_id`)
                WHERE `r`.`sim_type_id` = 1
                    AND `s`.`imsi_prefix` = '25037'
                    AND `r`.`count` > 0
                    AND `r`.`state` = 50
            ",

            'voip_mobile_phone_plan' => "
                SELECT
                    `r`.`id`                                                                         AS `id`,
                    `r`.`country_id`                                                                 AS `country_id`,
                    `r`.`city_id`                                                                    AS `city_id`,
                    `r`.`source`                                                                     AS `source`,
                    `r`.`number_from`                                                                AS `number_from`,
                    `r`.`number_to`                                                                  AS `number_to`,
                    `r`.`account_id`                                                                 AS `account_id`,
                    `r`.`created_at`                                                                 AS `created_at`,
                    `r`.`comment`                                                                    AS `comment`,
                    `r`.`ndc`                                                                        AS `ndc`,
                    `r`.`number_full_from`                                                           AS `number_full_from`,
                    `r`.`number_full_to`                                                             AS `number_full_to`,
                    `r`.`ndc_type_id`                                                                AS `ndc_type_id`,
                    `r`.`fmc_trunk_id`                                                               AS `fmc_trunk_id`,
                    `r`.`mvno_trunk_id`                                                              AS `mvno_trunk_id`,
                    `r`.`nnp_operator_id`                                                            AS `nnp_operator_id`,
                    `r`.`solution_number`                                                            AS `solution_number`,
                    `r`.`numbers_count`                                                              AS `numbers_count`,
                    CONCAT(COALESCE(STR_TO_DATE(`r`.`solution_date`, '%d.%m.%Y'), '2000-01-01'), ' 00:00:00') AS `solution_date`,
                    `r`.`mvno_partner_id`                                                            AS `mvno_partner_id`,
                    `c`.`connection_point_id`                                                        AS `region_id`
                FROM `nispd`.`voip_registry` `r`
                JOIN `nispd`.`city` `c` ON (`c`.`id` = `r`.`city_id`)
                WHERE `r`.`country_id` = '643'
                    AND `r`.`source` = 'regulator'
                    AND `r`.`ndc_type_id` = '2'
                    AND `r`.`solution_date` <> ''
            ",
        ];
    }
}
