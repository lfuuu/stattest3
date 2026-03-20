<?php

namespace app\modules\transfer\forms\services\decorators;

use app\classes\Html;
use app\models\ClientAccount;
use app\modules\uu\models\AccountTariff;
use yii\base\Model;
use yii\helpers\Json;

class UniversalServiceDecorator extends Model implements ServiceDecoratorInterface
{

    /** @var AccountTariff */
    public $service;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->service->id;
    }

    /**
     * @return string
     */
    public function getValue()
    {
        return (string)($this->service->voip_number ?? '');
    }

    /**
     * @return string
     */
    public function getClientAccountUIDField()
    {
        return 'client_account_id';
    }

    /**
     * @param ClientAccount $clientAccount
     * @return int
     */
    public function getClientAccountUID(ClientAccount $clientAccount)
    {
        return $clientAccount->id;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return Html::a($this->service->name, $this->service->url, ['target' => '_blank']);
    }

    /**
     * @return string
     */
    public function getExtendsData()
    {
        $number = $this->service->number;
        if (!$number) {
            return '';
        }

        return Json::encode(['didGroupId' => $number->did_group_id]);
    }

    /**
     * Название текущего тарифа для метки "Оставить как есть"
     *
     * @return string
     */
    public function getCurrentTariffLabel()
    {
        if (!$this->service->tariff_period_id || !$this->service->tariffPeriod) {
            return '';
        }

        return $this->service->tariffPeriod->getName();
    }

}
