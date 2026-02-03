<?php

use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;

class TarifVirtpbx extends ActiveRecord\Model
{
    static $table_name = "tarifs_virtpbx";
    static $private_key = 'id';
}
