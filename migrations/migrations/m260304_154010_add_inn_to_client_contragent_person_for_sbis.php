<?php

use app\classes\Migration;
use app\models\ClientContragentPerson;

/**
 * Добавляет ИНН ФЛ в расширяющую таблицу контрагента для СБИС XML.
 */
class m260304_154010_add_inn_to_client_contragent_person_for_sbis extends Migration
{
    public function safeUp()
    {
        $this->addColumn(
            ClientContragentPerson::tableName(),
            'inn',
            $this->string(12)->notNull()->defaultValue('')
        );
    }

    public function safeDown()
    {
        $this->dropColumn(ClientContragentPerson::tableName(), 'inn');
    }
}
