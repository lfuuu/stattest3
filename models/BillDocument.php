<?php

namespace app\models;

use app\classes\model\ActiveRecord;
use app\dao\BillDocumentDao;

/**
 * @property string $bill_no
 * @property string $ts
 */
class BillDocument extends ActiveRecord
{
    const TYPE_BILL = 'bill';
    const TYPE_AKT = 'akt'; // не использовать !!!!!
    const TYPE_ACT = 'act';
    const TYPE_INVOICE = 'invoice';
    const TYPE_LADING = 'lading';
    const TYPE_GDS = 'gds';
    const TYPE_UPD = 'upd';
    const TYPE_UPD2 = 'upd2';

    const ID_PERIOD = 1; // абонентская плата
    const ID_RESOURCE = 2; // потребленные ресурсы
    const ID_GOODS = 3; // Товары
    const ID_FOUR = 4; // 4ая счет-фактура.

    const SUBID_GOODS_UPDT = 1;
    const SUBID_GOODS_LADING = 2;

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'newbills_documents';
    }

    /**
     * @return BillDocumentDao
     */
    public static function dao()
    {
        return BillDocumentDao::me();
    }
}