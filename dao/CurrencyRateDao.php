<?php
namespace app\dao;

use app\classes\Assert;
use app\classes\Singleton;
use app\helpers\DateTimeZoneHelper;
use app\models\Currency;
use app\models\CurrencyRate;

/**
 * @method static CurrencyRateDao me($args = null)
 */
class CurrencyRateDao extends Singleton
{

    protected static $fetchedRates = [];

    /**
     * @param string $currency
     * @param string $date
     * @param bool $isCheckRate логировать ли нулевой курс
     * @return float
     * @throws \yii\base\Exception
     */
    public static function getRate($currency, $date = '', $isCheckRate = false)
    {
        if ($currency === Currency::RUB) {
            return 1.0;
        }

        if ($date instanceof \DateTime) {
            $date = $date->format(DateTimeZoneHelper::DATE_FORMAT);
        }

        $fetchedKey = $currency . $date;
        if (!array_key_exists($fetchedKey, self::$fetchedRates)) {
            $currencyQuery = CurrencyRate::find()
                ->currency($currency)
                ->onDate($date);

            self::$fetchedRates[$fetchedKey] = $currencyQuery->one();
        }

        $currencyRate = self::$fetchedRates[$fetchedKey];
        Assert::isObject(
            $currencyRate,
            sprintf('Missing rate for "%s" at date "%s"', $currency, $date)
        );

        $rate = $currencyRate->rate;
        if ($isCheckRate && !$rate) {
            \Yii::error(
                sprintf('Unknown currency rate for "%s" at date "%s"', $currency, $date)
            );
        }

        return $rate;
    }

    /**
     * Получение кросс курса валюты через
     *
     * @param string $currencyFromId
     * @param string $currencyToId
     * @param string|\DateTime $date
     * @return float|null
     * @throws \yii\base\Exception
     */
    public static function crossRate($currencyFromId, $currencyToId, $date = '')
    {
        if ($currencyFromId == $currencyToId) {
            return 1.0;
        }

        if (
            ($rateFrom = self::getRate($currencyFromId, $date, true))
            && ($rateTo = self::getRate($currencyToId, $date, true))
        ) {
            return $rateFrom / $rateTo;
        }

        return null;
    }

}