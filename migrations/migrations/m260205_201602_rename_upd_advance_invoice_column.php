<?php

use app\models\Invoice;

/**
 * Class m260205_201602_rename_upd_advance_invoice_column
 */
class m260205_201602_rename_upd_advance_invoice_column extends \app\classes\Migration
{
    public function safeUp()
    {
        $schema = $this->db->schema->getTableSchema(Invoice::tableName(), true);
        if (!$schema) {
            return;
        }

        $hasOld = isset($schema->columns['upd_advance_invoice_display']);
        $hasNew = isset($schema->columns['upd_advance_invoice']);

        if ($hasOld && !$hasNew) {
            $this->renameColumn(Invoice::tableName(), 'upd_advance_invoice_display', 'upd_advance_invoice');
            return;
        }

        if (!$hasOld && !$hasNew) {
            $this->addColumn(Invoice::tableName(), 'upd_advance_invoice', $this->string(255)->null());
        }
    }

    public function safeDown()
    {
        $schema = $this->db->schema->getTableSchema(Invoice::tableName(), true);
        if (!$schema) {
            return;
        }

        $hasOld = isset($schema->columns['upd_advance_invoice_display']);
        $hasNew = isset($schema->columns['upd_advance_invoice']);

        if ($hasNew && !$hasOld) {
            $this->renameColumn(Invoice::tableName(), 'upd_advance_invoice', 'upd_advance_invoice_display');
            return;
        }

        if ($hasNew && $hasOld) {
            $this->dropColumn(Invoice::tableName(), 'upd_advance_invoice');
        }
    }
}
