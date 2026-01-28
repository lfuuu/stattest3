<?php

namespace app\models;

use app\classes\model\ActiveRecord;

/**
 * @property int $pk
 * @property string $e164
 * @property string $action
 * @property int $client
 * @property int $user
 * @property string $time
 * @property string $addition
 *
 * Class NumberLog
 * @package app\models
 */
class NumberLog extends ActiveRecord
{
    const ACTION_CREATE = 'create';

    const ACTION_FIX = 'fix';
    const ACTION_UNFIX = 'unfix';

    const ACTION_HOLD = 'hold';
    const ACTION_UNHOLD = 'unhold';

    const ACTION_ACTIVE = 'active';
    const ACTION_ADDITION_TESTED = 'tested';
    const ACTION_ADDITION_COMMERCIAL = 'commercial';
    const ACTION_NOT_VERFIED = 'not_verfied';
    const ACTION_MSTEAMS = 'msteams';
    const ACTION_BLOCKED_BY_SUBSCRIBER = 'blocked_by_subscriber';
    const ACTION_BLOCKED_BY_OPERATOR = 'blocked_by_operator';

    const ACTION_CONNECTED = 'connected';

    const ACTION_INVERTRESERVED = 'invertReserved';
    //const ACTION_INVERTOUR = 'invertOur';
    //const ACTION_INVERTSPECIAL = 'invertSpecial';

    const ACTION_SALE = 'sale';
    const ACTION_NOTSALE = 'notsale';
    const ACTION_UNRELEASE = 'unrelease';
    const ACTION_MOVE_TO_RELEASED = 'moveToReleased';

    const ACTION_WITH_DISCOUNT = 'with_discount';
    const ACTION_NO_DISCOUNT = 'no_discount';

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'e164_stat';
    }

}