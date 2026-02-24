<?php

namespace app\commands\convert;

use app\classes\enum\VoipRegistrySourceEnum;
use app\classes\helpers\CalculateGrowthRate;
use app\models\Country;
use app\models\Number;
use app\models\voip\Registry;
use app\modules\nnp\models\NdcType;
use app\modules\nnp\models\NumberRange;
use app\widgets\ConsoleProgress;
use yii\console\Controller;
use app\exceptions\ModelValidationException;

class NumberController extends Controller
{
    /**
     * Привязка номеров к реестру
     */
    public function actionCreateRelationWithRegistry()
    {
        $numbersQuery = Number::find()->where(['registry_id' => null]);
        foreach ($numbersQuery->each() as $numberModel) {
            /** @var $numberModel Number */

            $registry = Registry::find()
                ->where(['<=', 'number_full_from', $numberModel->number])
                ->andWhere(['>=', 'number_full_to', $numberModel->number])
                ->one();

            if (!$registry) {
                continue;
            }

            $numberModel->registry_id = $registry->id;
            $numberModel->source = $registry->source;
            try {
                if (!$numberModel->save()) {
                    throw new ModelValidationException($numberModel);
                }
            } catch (ModelValidationException $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
    }

    /**
     * Заполнение поля ННП-оператор в номерах
     *
     * @throws ModelValidationException
     */
    public function actionSetNnpOperator()
    {
        $numberQuery = Number::find()
            ->where([
                'nnp_operator_id' => null,
                'source' => VoipRegistrySourceEnum::PORTABILITY_NOT_FOR_SALE,
            ]);

        $progress = new ConsoleProgress($numberQuery->count(), function ($string) {
            echo $string;
        });

        /** @var Number $number */
        foreach ($numberQuery->each() as $number) {
            $progress->nextStep();

            /** @var NumberRange $numberRange */
            $numberRange = NumberRange::find()
                ->where([
                    'is_active' => true
                ])
                ->andWhere(['<=', 'full_number_from', $number->number])
                ->andWhere(['>=', 'full_number_to', $number->number])
                ->orderBy(['id' => SORT_DESC])
                ->one();

            if ($numberRange && $numberRange->operator_id) {
                $number->nnp_operator_id = $numberRange->operator_id;

                if (!$number->save()) {
                    throw new ModelValidationException($number);
                }
            }
        }
    }

    public function actionFillRegion()
    {
        $numbers = Number::find()->where(['nnp_region_id' => null])
            ->orWhere(['nnp_city_id' => null])
            ->orWhere(['nnp_operator_id' => null])
            ->select(['nnp_region_id', 'nnp_city_id', 'nnp_operator_id', 'number'])
            ->asArray()
            ->all();

        /** @var Number $number */
        foreach ($numbers as $number) {
            echo ' .';
            try {
                $numberInfo = Number::getNnpInfo($number['number']);
            } catch (\Exception $e) {
                echo PHP_EOL . 'ERROR: ' . $e->getMessage();
                continue;
            }

            $update = [];
            if (!$number['nnp_city_id']) {
                $update['nnp_city_id'] = $numberInfo['nnp_city_id'];
            }

            if (!$number['nnp_region_id']) {
                $update['nnp_region_id'] = $numberInfo['nnp_region_id'];
            }

            if (!$number['nnp_operator_id']) {
                $update['nnp_operator_id'] = $numberInfo['nnp_operator_id'];
            }

            if ($update) {
                Number::updateAll($update, ['number' => $number['number']]);
            }
        }
    }


    /**
     * Ежедневная проверка смены ННП оператора у мобильного в России
     *
     * @return void
     * @throws ModelValidationException
     */
    public function actionDailyCheckNnpOperator()
    {
        $query = Number::find()
            ->where([
                'ndc_type_id' => NdcType::ID_MOBILE,
                'country_code' => Country::RUSSIA,
            ]);

        $speedProc = new CalculateGrowthRate();
        $countAll = $query->count();
        $counter = 0;
        /** @var Number $number */
        foreach ($query->each() as $number) {
            $counter++;
            $speed = $speedProc->calculate($counter);

            if (($counter % 100) == 0) {
                echo "\n\r[ " . str_pad($counter . ' / ' . $countAll . ' => ' . round($counter / ($countAll / 100)) . '% ', 30, '.') . '] speed: ' . number_format($speed) . ' per sec';
            }
            try {
                $numberInfo = Number::getNnpInfo($number->number, $isWithPorting = true, $useCache = false); // перезаписываем кеш по номерам
            } catch (\Exception $e) {
                echo PHP_EOL . 'ERROR: ' . $e->getMessage();
                continue;
            }

            $nnpOperatorId = $numberInfo['nnp_operator_id'];
            if ($nnpOperatorId != $number->nnp_operator_id) {
                echo PHP_EOL . date('r') . ': ' . $number->number . ' ' . $number->nnp_operator_id . ' => ' . $nnpOperatorId;
                $number->nnp_operator_id = $nnpOperatorId;
                if (!$number->save()) {
                    throw new ModelValidationException($number);
                }
            }
        }
    }


    public function actionFillOrigOperator()
    {
        $numbers = Number::find()->where(['orig_nnp_operator_id' => null])
            ->select(['number'])
            ->column();

        $speedProc = new CalculateGrowthRate();
        $countAll = count($numbers);
        $counter = 0;
        $collector = [];
        foreach ($numbers as $number) {
            $counter++;
            $speed = $speedProc->calculate($counter);

            if (($counter % 100) == 0) {
                echo "\n\r[ " . str_pad($counter . ' / ' . $countAll . ' => ' . round($counter / ($countAll / 100)) . '% ', 30, '.') . '] speed: ' . number_format($speed) . ' per sec';
            }
            try {
                $numberInfo = Number::getNnpInfo($number, false);
            } catch (\Exception $e) {
                echo PHP_EOL . 'ERROR: ' . $e->getMessage();
                continue;
            }

            $nnpOperatorId = $numberInfo['nnp_operator_id'];
            if (!isset($collector[$nnpOperatorId])) {
                $collector[$nnpOperatorId] = [];
            }
            $collector[$nnpOperatorId][] = $number;

            if (($counter % 1000) == 0) {
                $this->_setNumberValue_flushCollector($collector, 'orig_nnp_operator_id');
            }
        }

        if ($collector) {
            $this->_setNumberValue_flushCollector($collector, 'orig_nnp_operator_id');
        }
    }

    private function _setNumberValue_flushCollector(&$collector, $field)
    {
        if (!$collector) {
            return;
        }

        foreach ($collector as $nnpOperatortId => $numbers) {
            echo PHP_EOL . 'update rows: ' . $nnpOperatortId . ' => ' . Number::updateAll([$field => $nnpOperatortId], ['number' => $numbers]);
        }

        $collector = [];
    }
}
