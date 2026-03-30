<?php

namespace tests\codeception\unit\models;

use app\exceptions\ModelValidationException;
use app\forms\client\ClientCreateExternalForm;
use app\models\ClientAccount;
use app\models\Country;
use app\models\UsageVoip;
use app\modules\nnp\models\NdcType;


class _ClientAccount extends \app\models\ClientAccount
{

    public static $usageId = 0;

    public function getVoipNumbers()
    {
        $numbers = UsageVoip::find()
            ->where([
                'client' => $this->client,
                'ndc_type_id' => NdcType::ID_GEOGRAPHIC
            ])
            ->all();

        $result = [];

        foreach ($numbers as $number) {
            $result[$number->E164] = [
                'type' => 'vpbx',
                'stat_product_id' => self::$usageId,
            ];
        }

        return $result;
    }

    /**
     * Создает одного клиента для тестирования
     * @params int|null $entryPointCode
     * @return \app\models\ClientAccount
     * @throws \Exception
     */
    public static function createOne($entryPointCode = null)
    {
        $clientForm = new ClientCreateExternalForm;
        $clientForm->company = 'test account ' . mt_rand(0, 1000);
        $clientForm->country_id = Country::RUSSIA;

        if ($entryPointCode !== null) {
            $clientForm->entry_point_id = $entryPointCode;
        }

        if (!$clientForm->create()) {
            throw new \Exception('Client not created (ClientCreateExternalForm)');
        }

        return self::findOne(['id' => $clientForm->account_id]);
    }

}
