<?php

namespace app\commands\convert;

use app\helpers\DateTimeZoneHelper;
use app\models\billing\A2pSms;
use app\models\ClientAccount;
use app\models\Number;
use app\modules\nnp\models\Operator;
use DateTimeImmutable;
use yii\console\Controller;

class SmsController extends Controller
{
    /**
     * A2P. Билайн. Пересчет 40+0+0+0+0+8
     */
    public function actionA2pBeeline40($isThisMonth = 0, $isApply = 0, $isTest = 0)
    {
        if ($isThisMonth) {
            $periodFrom = (new DateTimeImmutable('first day of this month'))->setTime(0, 0, 0, 0);
            $periodTo = new DateTimeImmutable('now');
        } else {
            $periodTo = (new DateTimeImmutable('first day of this month'))->setTime(0, 0, 0, 0);
            $periodFrom = $periodTo->modify('first day of previous month');
        }

        $convertStart = (new DateTimeImmutable('2025-10-20 00:00:00'));

        if ($periodFrom < $convertStart) {
            $periodFrom = $convertStart;
        }

        echo PHP_EOL . 'From: ' . $periodFrom->format('r');
        echo PHP_EOL . 'To: ' . $periodTo->format('r');

        $query = $this->_getQuery($periodFrom->modify('-8 hours'), $periodTo->modify('+8 hours'), $isTest);
        $ls = $query->select('account_id')->distinct()->asArray()->orderBy(null)->column();
        $lsTxs = ClientAccount::find()->where(['id' => $ls])->select(['id', 'timezone_name'])->asArray()->all();
        $tzLs = [];
        foreach ($lsTxs as $l) {
            $tzLs[$l['timezone_name']][] = $l['id'];
        }

        $storage = [];
        $operatorStorage = [];
        $st = [];

        $tzUtc = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC);
        foreach ($tzLs as $timeZone => $lss) {

//            $periodFromWithTzLs = DateTimeImmutable::createFromInterface($periodFrom)->setTimezone(new \DateTimeZone($timeZone)); // DateTimeImmutable::createFromInterface will only appear in PHP 8

            $periodFromWithTzLs = (new DateTimeImmutable($periodFrom->format(DateTimeZoneHelper::DATETIME_FORMAT), new \DateTimeZone($timeZone)))->setTimezone($tzUtc);
            $periodToWithTzLs = (new DateTimeImmutable($periodTo->format(DateTimeZoneHelper::DATETIME_FORMAT), new \DateTimeZone($timeZone)))->setTimezone($tzUtc);


            $query = $this->_getQuery($periodFromWithTzLs, $periodToWithTzLs, $isTest)->andWhere(['account_id' => $lss]);

            /** @var A2pSms $sms */
            foreach ($query->each() as $sms) {

                if (!isset($storage[$sms->src_number])) {
                    $storage[$sms->src_number] = [];
                }

                $operator = null;
                if (!array_key_exists($sms->dst_number, $storage[$sms->src_number])) {
                    $nnpInfo = Number::getNnpInfo($sms->dst_number);

                    if (!array_key_exists($nnpInfo['nnp_operator_id'], $operatorStorage)) {
                        $operatorStorage[$nnpInfo['nnp_operator_id']] = Operator::findOne(['id' => $nnpInfo['nnp_operator_id']]);
                    }

                    $operator = $operatorStorage[$nnpInfo['nnp_operator_id']];

                    if ($operator->id == Operator::ID_BEELINE) {
                        $storage[$sms->src_number][$sms->dst_number] = new CalculateSmsCharges($sms->id);
                    } else {
                        $storage[$sms->src_number][$sms->dst_number] = false;
                    }
                }

                $calc = &$storage[$sms->src_number][$sms->dst_number];

                /** CalculateSmsСharges $calc */
                if (!$calc) {
                    continue;
                }

//                echo PHP_EOL . $this->s($sms);
//
//                echo $calc->getId();
//                echo ' - ';
                $total = $calc->writeOff((int)round($sms->count));
//                echo ' => ' . $total;

                $setRate = 8;

                if ($sms->rate != $setRate || $sms->cost != -$total) {
                    $st[] = ['id' => $sms->id, 'orig_rate' => $sms->rate, 'orig_cost' => $sms->cost, 'set_rate' => $setRate, 'set_cost' => -$total];
                }
            }
        }

        list($collectSet, $collectOrig) = $this->_transformChanges($st);

        $updSet = $this->collectToUpdate($collectSet);
        $updOrig = $this->collectToUpdate($collectOrig);

        ob_start();
        echo PHP_EOL . '=================================' .
            PHP_EOL . 'SET UPDATES: ' . PHP_EOL . PHP_EOL;

        echo implode(PHP_EOL, $updSet);

        echo PHP_EOL .PHP_EOL .PHP_EOL . '=================================' .
            PHP_EOL . 'TO ORIG UPDATES: ' . PHP_EOL . PHP_EOL;

        echo implode(PHP_EOL, $updOrig);
        echo PHP_EOL;
        echo PHP_EOL;


        $content = ob_get_clean();
        file_put_contents('/tmp/save_sqls', $content);

        echo $content;

        $isRun = false;
        if ($isApply === null) {
            $isRun = $this->confirm('Запускаем?');
        }

        if ($isApply) {
            $isRun = true;
        }

        if ($isRun) {
            $this->_applyUpdates($updSet);
        }
    }

    private function _getQuery(DateTimeImmutable $periodFrom, DateTimeImmutable $periodTo, $isTest = false)
    {
        $query = A2pSms::find()
            ->where(['orig' => true])
            ->andWhere(['not', ['rate' => 0]]);

        $query->andWhere(['>=', 'charge_time', $periodFrom->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
        $query->andWhere(['<', 'charge_time', $periodTo->format(DateTimeZoneHelper::DATETIME_FORMAT)]);
        $query->orderBy(['charge_time' => SORT_ASC]);

        if ($isTest) {
            $query->andWhere(['account_id' => 47197]);
        }

        return $query;
    }

    public function s($sms)
    {
        ob_start();
        print_r($sms->getAttributes(null, ['sms_call_id', 'server_id', 'orig', 'account_tariff_light_id', 'pricelist_location_id', 'cdr_id', 'location_id', 'src_route', 'dst_route', 'mcc', 'mnc']));
        $content = ob_get_clean();
        return preg_replace('/\s+/', ' ', $content);

    }

    private function _transformChanges($st)
    {
        $collectSet = [];
        $collectOrig = [];
        foreach ($st as $s) {
            if ($s['orig_rate'] == $s['set_rate'] && $s['orig_cost'] == $s['set_cost']) {
                continue;
            }

            $set = $orig = [];
            $setRate = $setCost = '';
            $origRate = $origCost = '';
            if ($s['orig_rate'] != $s['set_rate']) {
                $setRate = $s['set_rate'];
                $set['rate'] = $s['set_rate'];

                $origRate = $s['orig_rate'];
                $orig['rate'] = $s['orig_rate'];
            }

            if ($s['orig_cost'] != $s['set_cost']) {
                $setCost = $s['set_cost'];
                $set['cost'] = $s['set_cost'];

                $origCost = $s['orig_cost'];
                $orig['cost'] = $s['orig_cost'];
            }

            $key = $setRate . '__' . $setCost;
            $keyOrig = $origRate . '__' . $origCost;
            if (!isset($collectSet[$key])) {
                $collectSet[$key] = ['ids' => [], 'set' => $set];
            }
            if (!isset($collectOrig[$keyOrig])) {
                $collectOrig[$keyOrig] = ['ids' => [], 'set' => $orig];
            }
            $collectSet[$key]['ids'][] = $s['id'];
            $collectOrig[$keyOrig]['ids'][] = $s['id'];
        }

        return [$collectSet, $collectOrig];
    }

    private function collectToUpdate($collect)
    {
        return array_map(fn($updateData) => A2pSms::getDb()->createCommand()->update(A2pSms::tableName(), $updateData['set'], ['id' => $updateData['ids']])->rawSql . ';', $collect);
    }

    private function _applyUpdates($updSet)
    {
        $db = \Yii::$app->dbPg;
        $transaction = $db->beginTransaction();
        try {
            foreach ($updSet as $update) {
                echo ' . ';
                $db->createCommand($update)->execute();
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

    }
}

class CalculateSmsCharges
{
    private int $id = 0;
    private int $alreadyWrittenOffUnits = 0;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function writeOff(int $amountToWriteOffUnits)
    {
        $total = 0;
        $currentPosition = $this->alreadyWrittenOffUnits + 1;

        for ($i = 0; $i < $amountToWriteOffUnits; $i++) {
            $position = $currentPosition + $i;


            if ($position == 1) {
                $total += 40;  // Первое списание
            } elseif ($position >= 2 && $position <= 5) {
                $total += 0;   // Со 2 по 5 - 0 ед
            } else {
                $total += 8;   // Свыше 6 - по 8 ед
            }
//            echo ' p:' . $position . '.t:' . $total . ' ';
        }

        $this->alreadyWrittenOffUnits += $amountToWriteOffUnits;

        return $total;
    }

}