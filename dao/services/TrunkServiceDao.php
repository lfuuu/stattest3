<?php

namespace app\dao\services;

use app\dao\UsageDao;
use app\models\UsageTrunk;
use app\models\ClientAccount;

/**
 * @method static TrunkServiceDao me($args = null)
 */

class TrunkServiceDao extends UsageDao
{
    public $usageClass = null;

    /**
     * Инициализация
     */
    public function init()
    {
        $this->usageClass = UsageTrunk::class;
        parent::init();
    }
    /**
     * @param ClientAccount|int $client
     * @return bool
     */
    public function hasService($client)
    {
        $clientId = $client instanceof ClientAccount ? $client->id : (int)$client;
        if (!$clientId) {
            return false;
        }

        return UsageTrunk::find()
            ->where([
                'client_account_id' => $clientId
            ])
            ->actual()
            ->count() > 0;
    }
}
