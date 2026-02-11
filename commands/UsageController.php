<?php

namespace app\commands;

use app\classes\adapters\Tele2Adapter;
use app\classes\behaviors\SetTaxVoip;
use app\classes\Utils;
use app\classes\voip\StateVoipUpdater;
use app\health\MonitorVoipDelayOnPackages;
use app\models\EventQueue;
use app\models\Trouble;
use app\modules\transfer\forms\services\BaseForm;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\billing\CallsAggr;
use app\models\BusinessProcessStatus;
use app\models\ClientContract;
use app\models\ClientFlag;
use app\models\usages\UsageInterface;
use app\models\UsageVoip;
use app\models\LogTarif;
use app\models\TariffVoip;
use app\modules\uu\Module as uuModule;
use kartik\base\Config;
use Yii;
use DateTime;
use app\models\ClientAccount;
use yii\console\Controller;
use app\forms\usage\UsageVoipEditForm;
use yii\console\ExitCode;
use yii\db\Expression;
use yii\db\Query;


class UsageController extends Controller
{

    const ACTION_SET_BLOCK = 1;
    const ACTION_SET_OFF = 2;
    const ACTION_CLEAN_TRASH = 3;

    public function actionClientUpdateIsActive()
    {
        $partSize = 500;
        try {
            $count = $partSize;
            $offset = 0;
            while ($count >= $partSize) {
                $clientAccounts =
                    ClientAccount::find()
                        ->limit($partSize)->offset($offset)
                        ->orderBy('id')
                        ->all();

                foreach ($clientAccounts as $clientAccount) {
                    $offset++;
                    ClientAccount::dao()->updateIsActive($clientAccount);
                }

                $count = count($clientAccounts);
            }

            Trouble::dao()->setTroublesClosed();

        } catch (\Exception $e) {
            Yii::error($e);
            throw $e;
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Очистка услуг телефонии.
     * через 3 дня   - высвободить номер, если статус бизнес процесса - "Заказ услуг"
     * через 10 дней - заблокировать ЛС, если есть услуга в тесте
     * через 40 дней - высвободить номер, если есть услуга в тесте
     *
     * @return int
     */
    public function actionVoipTestClean()
    {
        $info = [];

        $now = new DateTime("now");

        echo "\nstart " . $now->format(DateTimeZoneHelper::DATETIME_FORMAT) . "\n";

        $cleanOrderOfServiceDate = (new DateTime("now"))->modify("-3 day");
        $offDate = (new DateTime("now"))->modify("-10 day");

        echo $now->format(DateTimeZoneHelper::DATE_FORMAT) . ": off:   " . $offDate->format(DateTimeZoneHelper::DATE_FORMAT) . "\n";
        echo $now->format(DateTimeZoneHelper::DATE_FORMAT) . ": clean: " . $cleanOrderOfServiceDate->format(DateTimeZoneHelper::DATE_FORMAT) . "\n";

        $infoOff = $this->disableTestVoipUsages($offDate);
        $infoClean = $this->cleanUsages($cleanOrderOfServiceDate, self::ACTION_CLEAN_TRASH);

        if ($infoOff) {
            $info = array_merge($info, $infoOff);
        }

        if ($infoClean) {
            $info = array_merge($info, $infoClean);
        }

        if ($info) {
            if (defined("ADMIN_EMAIL") && ADMIN_EMAIL) {
                mail(ADMIN_EMAIL, "voip clean processor", implode("\n", $info));
            }

            echo implode("\n", $info);
        }

        return ExitCode::OK;
    }

    private function cleanUsages(\DateTime $date, $action)
    {
        $now = new DateTime("now");
        $yesterday = clone $now;
        $yesterday->modify('-1 day');

        $usages = UsageVoip::find()->actual()->andWhere(["actual_from" => $date->format(DateTimeZoneHelper::DATE_FORMAT)])->all();

        $info = [];

        foreach ($usages as $usage) {
            $account = $usage->clientAccount;

            if ($action == self::ACTION_CLEAN_TRASH) {
                if ($account->contract->business_process_status_id != BusinessProcessStatus::TELEKOM_MAINTENANCE_TRASH) {
                    continue;
                }

                $info[] = $now->format(DateTimeZoneHelper::DATE_FORMAT) . ": " . $usage->E164 . ", from: " . $usage->actual_from . ": clean trash";

                $model = new UsageVoipEditForm();
                $model->initModel($account, $usage);
                $model->disconnecting_date = $yesterday->format(DateTimeZoneHelper::DATE_FORMAT);
                $model->status = UsageInterface::STATUS_WORKING;
                $model->edit();
            }
        }

        return $info;
    }

    /**
     * Отключаем услуги телефонии на тестовом тарифе
     *
     * @param DateTime $date
     * @return array
     */
    private function disableTestVoipUsages(\DateTime $date)
    {
        $now = new DateTime("now");
        $yesterday = clone $now;
        $yesterday->modify('-1 day');

        $info = [];

        $usageVoipTable = UsageVoip::tableName();
        $logTariffTable = LogTarif::tableName();
        $tariffVoipTable = TariffVoip::tableName();

        $query = \Yii::$app->getDb()->createCommand("
            SELECT
              u.id as usage_id
            FROM
              (
                SELECT
                  id AS     usage_id,
                  (SELECT id
                   FROM log_tarif
                   WHERE id_service = u.id
                         AND date_activation <= CAST(NOW() AS DATE)
                         AND service = '{$usageVoipTable}'
                   ORDER BY date_activation DESC, id DESC
                   LIMIT 1) log_tariff_id
            
                FROM {$usageVoipTable} u
                WHERE
                  CAST(NOW() AS DATE) BETWEEN actual_from AND actual_to
              ) a, {$usageVoipTable} u, {$logTariffTable} lt, {$tariffVoipTable} tv
            WHERE u.id = usage_id
                  AND lt.id = log_tariff_id
                  AND tv.id = lt.id_tarif
                  AND tv.status = :statusTest
                  AND lt.date_activation <= :date
            ", [
            ':statusTest' => TariffVoip::STATUS_TEST,
            ':date' => $date->format(DateTimeZoneHelper::DATE_FORMAT)
        ]);

        foreach ($query->queryAll() as $row) {
            $usage = UsageVoip::findOne(['id' => $row['usage_id']]);
            if (!$usage) {
                continue;
            }

            $info[] = $now->format(DateTimeZoneHelper::DATE_FORMAT) . ": " . $usage->E164 . ", from: " . $usage->actual_from . ": set off";

            $model = new UsageVoipEditForm();
            $model->initModel($usage->clientAccount, $usage);
            $model->disconnecting_date = $yesterday->format(DateTimeZoneHelper::DATE_FORMAT);
            $model->status = UsageInterface::STATUS_WORKING;
            $model->edit();
        }

        return $info;
    }

    /**
     * @inheritdoc
     * @return int
     */
    public function actionCheckVoipDayDisable()
    {
        $now = new DateTime('now');
        echo PHP_EOL . 'start ' . $now->format(DateTimeZoneHelper::DATETIME_FORMAT);

        $isDayBlockExp = new Expression('voip_limit_day != 0 AND amount_day_sum < -voip_limit_day');
        $isMNBlockExp = new Expression('voip_limit_mn_day != 0 AND amount_mn_day_sum < -voip_limit_mn_day');

        $lockQuery = (new Query())
            ->select(['cc.client_id', 'voip_limit_day', 'amount_day_sum', 'voip_limit_mn_day', 'amount_mn_day_sum', 'is_overran', 'is_mn_overran'])
            ->addSelect([
                'is_block_day' => $isDayBlockExp,
                'is_block_mn' => $isMNBlockExp
            ])
            ->from(['c' => 'billing.clients'])
            ->innerJoin(['cc' => 'billing.cached_counters'], 'c.id=cc.client_id')
            ->innerJoin(['cl' => 'billing.locks'], 'c.id=cl.client_id')
            ->where([
                'AND',
                ['OR', ['cl.is_overran' => true], ['cl.is_mn_overran' => true]], // стоит флаг превышения лимита (is_overran - дневной общий, is_mn_overran - дневной МН)
                ['OR', $isDayBlockExp, $isMNBlockExp], // или вычисляем сами блокировку под дневному и/или МН
                ['c.voip_disabled' => false] // телефония не выключена
            ]);

        foreach ($lockQuery->each(100, Yii::$app->dbPgSlave) as $lock) {
            $client = ClientAccount::findOne($lock['client_id']);

            if (!$client->voip_disabled) {
                echo PHP_EOL . '...';
                $info = 'ЛС: ' . $lock['client_id'] . '; ';
                if ($lock['is_overran']) {
                    $info .= 'flag day limit block: limit:' . $lock['voip_limit_day'] . ' / value: ' . abs($lock['amount_day_sum']);
                } elseif ($lock['is_mn_overran']) {
                    $info .= 'flag MN limit block: limit:' . $lock['voip_limit_mn_day'] . ' / value: ' . abs($lock['amount_mn_day_sum']);
                } else {
                    $info .= 'no flag found (voip_limit_day: ' . $lock['voip_limit_day'] .
                        ' / amount_day_sum: ' . $lock['amount_day_sum'] .
                        ' / voip_limit_mn_day: ' . $lock['voip_limit_mn_day'] .
                        ' / amount_mn_day_sum: ' . $lock['amount_mn_day_sum'] .
                        ' / is_overran: ' . $lock['is_overran'] .
                        ' / is_mn_overran: ' . $lock['is_mn_overran'];
                }

                echo $info;
                Yii::info('[usage/check-voip-day-disable] ' . $info);
                $client->voip_disabled = 1;
                $client->save();
            }
        }

        return ExitCode::OK;
    }

    /**
     * Заполнение поля с эффективной ставкой НДС.
     */
    public function actionResetEffectiveVatRate()
    {
        ClientContract::dao()->resetAllEffectiveVATRate();
    }

    /**
     * Устанавливает начальные значения. Тарифы телефонии с НДС или без НДС
     */
    public function actionResetContractsVoipTax()
    {
        $contractsQuery = ClientContract::find();

        $count = 0;
        /** @var ClientContract $contract */
        $transaction = Yii::$app->db->beginTransaction();
        try {
            foreach ($contractsQuery->each() as $contract) {

                if (++$count % 100 == 0) {
                    $transaction->commit();
                    echo PHP_EOL;
                    $transaction = Yii::$app->db->beginTransaction();
                }

                // нужен только расчет нужного поля
                $contract->detachBehaviors();
                $contract->attachBehavior('SetTaxVoip', SetTaxVoip::class);
                $contract->isHistoryVersioning = false;

                $contract->trigger(ClientContract::TRIGGER_RESET_TAX_VOIP);

                if ($contract->isSetVoipWithTax === null) {
                    echo ".";
                    continue;
                }

                if (!$contract->save()) {
                    throw new ModelValidationException($contract);
                }

                echo $contract->isSetVoipWithTax ? '+' : '-';
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Устанавливает блокировку по неоплате счета
     */
    public function actionCheckBillOverdue()
    {
        echo PHP_EOL . date('r') . ": start";

        $dateTo = new DateTime();
        $dateTo->modify('-1 day');

        $dateFrom = new DateTime();
        $dateFrom->modify('-3 day');

        $billQuery = Bill::find()
            ->where([
                'between',
                'pay_bill_until',
                $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
                $dateTo->format(DateTimeZoneHelper::DATE_FORMAT)
            ]);

        $count = 0;

        /** @var Bill $bill */
        foreach ($billQuery->each() as $bill) {
            $count++;
            try {
                $bill->trigger(Bill::TRIGGER_CHECK_OVERDUE);
                if ($bill->isSetPayOverdue !== null) {
                    if (!$bill->save()) {
                        throw new ModelValidationException($bill);
                    }

                    echo PHP_EOL . date('r') . ": " . $bill->bill_no . " " . ($bill->isSetPayOverdue ? "(+)" : "(-)");
                }
            } catch (\Exception $e) {
                Yii::error($e);
                echo PHP_EOL . $bill->bill_no . " " . $e->getMessage();
            }
        }

        echo PHP_EOL . date('r') . ": end. Count: " . $count;
    }

    /**
     * Проверяем правильность установки блокировки по не уплате счета
     */
    public function actionRecheckClientOverdue()
    {
        $query = Yii::$app->db->createCommand("SELECT bill_no
FROM
  (SELECT
     (SELECT bill_no
      FROM newbills b
      WHERE b.client_id = a.client_id
      ORDER BY bill_date
      LIMIT 1) AS bill_no,
     a.*,
     c.is_bill_pay_overdue
   FROM (SELECT
           client_id,
           max(is_pay_overdue) AS max_v,
           min(is_pay_overdue) AS min_v
         FROM newbills
         GROUP BY client_id) a, clients c
   WHERE a.client_id = c.id
   HAVING max_v != is_bill_pay_overdue
  ) a")->query();

        $count = 0;
        while ($billNo = $query->readColumn(0)) {
            $bill = Bill::findOne(['bill_no' => $billNo]);
            echo PHP_EOL . $count++ . ': ' . $bill->client_id;
            $bill->trigger(Bill::TRIGGER_CHECK_OVERDUE);
        }
    }

    /**
     * Генерация событий о блокировке через 7/3/1 день по трафику телефонии
     */
    public function actionCheckVoipBlockByTrafficAlert()
    {
        echo PHP_EOL . date("r");

        $reportDays = 30;

        $tz = new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT);
        $periodTo = new \DateTime('now', $tz);
        $periodTo->setTime(0, 0, 0);

        $periodFrom = clone $periodTo;
        $periodFrom->modify('-' . $reportDays . ' days');


        $callsByAccountId = CallsAggr::dao()->getCallCostByPeriod($periodFrom, $periodTo);

        $activeUuUsagesQuery = AccountTariff::find()
            ->select('client_account_id')
            ->distinct()
            ->where([
                'AND',
                ['service_type_id' => ServiceType::ID_VOIP],
                ['IS NOT', 'voip_number', null],
                ['IS NOT', 'tariff_period_id', null],
            ]);

        $activeUsages = UsageVoip::find()
            ->select('c.id')
            ->distinct()
            ->innerJoin(['c' => ClientAccount::tableName()], 'c.client = ' . UsageVoip::tableName() . '.client')
            ->actual()
            ->union($activeUuUsagesQuery);

        $clientIdsQuery = (new Query())
            ->select('id')
            ->from(['a' => $activeUsages]);

        $accountsQuery = ClientAccount::find()
            ->with('flag')
            ->where(['id' => $clientIdsQuery]);


        /** @var ClientAccount $account */
        foreach ($accountsQuery->each() as $account) {
            if (!isset($callsByAccountId[$account->id]) || $callsByAccountId[$account->id]) {
                continue;
            }

            $callsCost = $callsByAccountId[$account->id];

            if ($callsCost > -100) {
                continue;
            }

            $perDay = $callsCost / $reportDays;

            $balance = $account->billingCountersFastMass->amount_sum;
            $flag = $account->flag;

            if (!$flag) {
                $flag = new ClientFlag;
                $flag->account_id = $account->id;
                $flag->is_notified_7day = 0;
                $flag->is_notified_3day = 0;
                $flag->is_notified_1day = 0;
            }

            $sum7Day = $balance + ($perDay * 7);
            if (-$sum7Day > $account->credit) {
                $flag->is_notified_7day = 1;
            } else {
                $flag->is_notified_7day = 0;
            }

            $sum3Day = $balance + ($perDay * 3);
            if (-$sum3Day > $account->credit) {
                $flag->is_notified_3day = 1;
            } else {
                $flag->is_notified_3day = 0;
            }

            $sum1Day = $balance + ($perDay * 1);
            if (-$sum1Day > $account->credit) {
                $flag->is_notified_1day = 1;
            } else {
                $flag->is_notified_1day = 0;
            }

            if (!$flag->save()) {
                throw new ModelValidationException($flag);
            }

            if ($flag->isSetFlag) {
                if ($flag->is_notified_7day || $flag->is_notified_3day || $flag->is_notified_1day) {
                    echo PHP_EOL . "(+) ";
                } else {
                    echo PHP_EOL . "(-) ";
                }

                echo $account->id . ' ';
                echo (int)$flag->is_notified_7day . '/' . (int)$flag->is_notified_3day . '/' . (int)$flag->is_notified_1day;
                echo ' callsCost: ' . $callsCost . ', perDay: ' . $perDay . ', credit: ' . $account->credit . ', balance: ' . $balance;
            }
        }
    }

    /**
     * Проверяем, есть ли невключенные пакеты, и включаем их
     *
     * @return int
     * @throws ModelValidationException
     */
    public function actionCheckVoipDelay()
    {
        $monitor = new MonitorVoipDelayOnPackages();

        $fromDataStr = (new DateTime('now'))
            ->modify('-3 hours')
            ->format(DateTimeZoneHelper::DATETIME_FORMAT);

        if (!($value = $monitor->getValue(600, ['>', 'insert_time', $fromDataStr]))) {
            return ExitCode::OK;
        }

        /** @var AccountTariff $accountTariff */
        foreach ($monitor->data as $accountTariff) {
            $data = [
                'account_tariff_id' => $accountTariff['id'],
                'client_account_id' => $accountTariff['client_account_id'],
            ];

            echo PHP_EOL . str_replace([' ', '  ', '   ', "\r", "\n"], '', print_r($data, true));

            $event = EventQueue::go(uuModule::EVENT_RECALC_ACCOUNT, $data);

            if (!$event->log_error) {
                $event->log_error = 'CheckVoipDelay';

                if (!$event->save()) {
                    throw new ModelValidationException($event);
                }
            }
        }
    }

    public function actionRunTele2Daemon()
    {
        Tele2Adapter::me()->runReceiverDaemon();

        echo PHP_EOL;
        return ExitCode::OK;
    }

    /**
     * Консольная реализация переноса услуг
     * 
     * @throws ModelValidationException
     * @throws \yii\db\Exception
     */
    public function actionTransfer()
    {
        $fromAccountId = 55140;
        $toAccountId = 64680;

        $d = new \Datetime();
        $d->modify('first day of next month');
        $date = $d->format('Y-m-d');

        $numbers = $this->_getNumbersForTransfer();

        $data = [
            "clientAccountId" => $fromAccountId,
            "targetClientAccountId" => $toAccountId,
            "processedFromDate" => $date,
        ];

        $data['services']['usage_voip'] = array_map(function($number) use ($fromAccountId, $toAccountId) {

            if (AccountTariff::find()
                ->where([
                    'client_account_id' => $toAccountId,
                    'voip_number' => $number
                ])
                ->andWhere(['NOT', ['tariff_period_id' => null]])->exists()) {
                return false;
            }


            return AccountTariff::find()
                ->where([
                    'client_account_id' => $fromAccountId,
                    'voip_number' => $number
                ])
                ->andWhere(['NOT', ['tariff_period_id' => null]])
                ->select('id')
                ->scalar();
        }, $numbers);

        $data['services']['usage_voip'] = array_filter($data['services']['usage_voip']);

        $data['fromDate']['usage_voip'] = array_combine(array_keys($data['services']['usage_voip']), array_fill(0, count($data['services']['usage_voip']), $date));

        print_r($data);

        $post = $data;

        $clientAccount = ClientAccount::findOne(['id' => $fromAccountId]);
        /** @var \app\modules\transfer\Module $module */
        $module = Config::getModule('transfer');
        /** @var BaseForm $form */
        $form = $module
            ->getServiceProcessor($clientAccount->account_version)
            ->getForm($clientAccount);

        $form->process($post);

        foreach ($form->processLog as $record) {
            /** @var PreProcessor $object */
            $object = $record['object'];

            if ($record['type'] === 'error') {
                echo PHP_EOL . "Error: " . $record['message'];
            } else {
                $source = $object->sourceServiceHandler->getServiceDecorator($object->sourceServiceHandler->getService());
                $target = $object->targetServiceHandler->getServiceDecorator($object->targetServiceHandler->getService());

                echo PHP_EOL . 'OK: ' . strip_tags($source->description . ' / ' . $target->description . ' / ' . $object->activationDate);
            }
        }
    }

    public function actionStateVoipUpdate($accountTariffId = null)
    {
        StateVoipUpdater::me()->update($accountTariffId);
    }

    private function _getNumbersForTransfer()
    {
        $a = <<<AAA
...
AAA;

        $n = array_filter(explode("\n", $a));
        sort($n);
        return array_slice($n, 0, 500);
    }

    public function actionPresetAssets()
    {
        // grep -R 'use .*\\Asset' .
        $A = <<<AAA
./widgets/multiselect/MultiSelectAsset.php:class MultiSelectAsset extends AssetBundle
./widgets/GridViewExport/GridViewExportAsset.php:class GridViewExportAsset extends AssetBundle
./widgets/TagsSelect2/TagsSelect2Asset.php:class TagsSelect2Asset extends AssetBundle
./widgets/GridViewSequence/GridViewSequenceAsset.php:class GridViewSequenceAsset extends AssetBundle
./widgets/MonthPickerAsset.php:class MonthPickerAsset extends \yii\web\AssetBundle
./widgets/JQTree/JQTreeAsset.php:class JQTreeAsset extends AssetBundle
./assets/TinymceAsset.php:class TinymceAsset extends AssetBundle
./assets/SwaggerUiAsset.php:class SwaggerUiAsset extends AssetBundle
./assets/LayoutMinimalAsset.php:class LayoutMinimalAsset extends AssetBundle
./assets/BootstrapTableAsset.php:class BootstrapTableAsset extends AssetBundle
./assets/AppAsset.php:class AppAsset extends AssetBundle
./assets/LayoutMainAsset.php:class LayoutMainAsset extends AssetBundle
./vendor/unclead/yii2-multiple-input/src/assets/MultipleInputSortableAsset.php:class MultipleInputSortableAsset extends AssetBundle
./vendor/unclead/yii2-multiple-input/src/assets/MultipleInputAsset.php:class MultipleInputAsset extends AssetBundle
./vendor/yiisoft/yii2/web/JqueryAsset.php:class JqueryAsset extends AssetBundle
./vendor/yiisoft/yii2/web/YiiAsset.php:class YiiAsset extends AssetBundle
./vendor/yiisoft/yii2/grid/GridViewAsset.php:class GridViewAsset extends AssetBundle
./vendor/yiisoft/yii2/widgets/MaskedInputAsset.php:class MaskedInputAsset extends AssetBundle
./vendor/yiisoft/yii2/widgets/ActiveFormAsset.php:class ActiveFormAsset extends AssetBundle
./vendor/yiisoft/yii2/widgets/PjaxAsset.php:class PjaxAsset extends AssetBundle
./vendor/yiisoft/yii2/captcha/CaptchaAsset.php:class CaptchaAsset extends AssetBundle
./vendor/yiisoft/yii2/validators/PunycodeAsset.php:class PunycodeAsset extends AssetBundle
./vendor/yiisoft/yii2/validators/ValidationAsset.php:class ValidationAsset extends AssetBundle
./vendor/yiisoft/yii2-bootstrap/TetherAsset.php:class TetherAsset extends AssetBundle
./vendor/yiisoft/yii2-bootstrap/BootstrapPluginAsset.php:class BootstrapPluginAsset extends AssetBundle
./vendor/yiisoft/yii2-bootstrap/BootstrapAsset.php:class BootstrapAsset extends AssetBundle
./vendor/yiisoft/yii2-bootstrap/BootstrapThemeAsset.php:class BootstrapThemeAsset extends AssetBundle
./vendor/yiisoft/yii2-jui/JuiAsset.php:class JuiAsset extends AssetBundle
./vendor/yiisoft/yii2-jui/DatePickerLanguageAsset.php:class DatePickerLanguageAsset extends AssetBundle
./vendor/yiisoft/yii2-gii/TypeAheadAsset.php:class TypeAheadAsset extends AssetBundle
./vendor/yiisoft/yii2-gii/GiiAsset.php:class GiiAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-timepicker/TimePickerAsset.php:class TimePickerAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-rating/StarRatingAsset.php:class StarRatingAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-rating/StarRatingThemeAsset.php:class StarRatingThemeAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-select2/ThemeClassicAsset.php:class ThemeClassicAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-select2/ThemeDefaultAsset.php:class ThemeDefaultAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-select2/Select2Asset.php:class Select2Asset extends AssetBundle
./vendor/kartik-v/yii2-widget-select2/ThemeBootstrapAsset.php:class ThemeBootstrapAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-select2/ThemeKrajeeAsset.php:class ThemeKrajeeAsset extends AssetBundle
./vendor/kartik-v/yii2-tabs-x/TabsXAsset.php:class TabsXAsset extends AssetBundle
./vendor/kartik-v/yii2-tabs-x/StickyTabsAsset.php:class StickyTabsAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-typeahead/TypeaheadHBAsset.php:class TypeaheadHBAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widget-typeahead/TypeaheadAsset.php:class TypeaheadAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widget-typeahead/TypeaheadBasicAsset.php:class TypeaheadBasicAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-builder/TabularFormAsset.php:class TabularFormAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-builder/FormAsset.php:class FormAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-popover-x/PopoverXAsset.php:class PopoverXAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-activeform/ActiveFormAsset.php:class ActiveFormAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-datetimepicker/DateTimePickerAsset.php:class DateTimePickerAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widget-growl/GrowlAsset.php:class GrowlAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widget-alert/AlertAsset.php:class AlertAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-datecontrol/DateControlAsset.php:class DateControlAsset extends AssetBundle
./vendor/kartik-v/yii2-datecontrol/DateFormatterAsset.php:class DateFormatterAsset extends AssetBundle
./vendor/kartik-v/yii2-date-range/MomentAsset.php:class MomentAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-date-range/LanguageAsset.php:class LanguageAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-date-range/DateRangePickerAsset.php:class DateRangePickerAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-editable/EditableAsset.php:class EditableAsset extends AssetBundle
./vendor/kartik-v/yii2-editable/EditablePjaxAsset.php:class EditablePjaxAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-datepicker/DatePickerAsset.php:class DatePickerAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-spinner/SpinnerAsset.php:class SpinnerAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widget-switchinput/SwitchInputAsset.php:class SwitchInputAsset extends AssetBundle
./vendor/kartik-v/yii2-krajee-base/AnimateAsset.php:class AnimateAsset extends AssetBundle
./vendor/kartik-v/yii2-krajee-base/Html5InputAsset.php:class Html5InputAsset extends AssetBundle
./vendor/kartik-v/yii2-krajee-base/PluginAssetBundle.php:class PluginAssetBundle extends AssetBundle
./vendor/kartik-v/yii2-krajee-base/WidgetAsset.php:class WidgetAsset extends AssetBundle
./vendor/kartik-v/yii2-krajee-base/AssetBundle.php:class AssetBundle extends \yii\web\AssetBundle
./vendor/kartik-v/yii2-widget-colorinput/ColorInputAsset.php:class ColorInputAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widgets/AssetBundle.php:class AssetBundle extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-widget-fileinput/SortableAsset.php:class SortableAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-fileinput/FileInputAsset.php:class FileInputAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-fileinput/CanvasBlobAsset.php:class CanvasBlobAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-fileinput/DomPurifyAsset.php:class DomPurifyAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-touchspin/TouchSpinAsset.php:class TouchSpinAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-affix/AffixAsset.php:class AffixAsset extends \kartik\base\AssetBundle
./vendor/kartik-v/yii2-grid/GridResizeColumnsAsset.php:class GridResizeColumnsAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/ExpandRowColumnAsset.php:class ExpandRowColumnAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/GridPerfectScrollbarAsset.php:class GridPerfectScrollbarAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/GridGroupAsset.php:class GridGroupAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/CheckboxColumnAsset.php:class CheckboxColumnAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/EditableColumnAsset.php:class EditableColumnAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/GridExportAsset.php:class GridExportAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/GridResizeStoreAsset.php:class GridResizeStoreAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/RadioColumnAsset.php:class RadioColumnAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/GridFloatHeadAsset.php:class GridFloatHeadAsset extends AssetBundle
./vendor/kartik-v/yii2-grid/GridViewAsset.php:class GridViewAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-depdrop/DepDropExtAsset.php:class DepDropExtAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-depdrop/DepDropAsset.php:class DepDropAsset extends AssetBundle
./vendor/kartik-v/yii2-widget-sidenav/SideNavAsset.php:class SideNavAsset extends \kartik\base\AssetBundle
./modules/sbisTenzor/assets/DocumentStatusAsset.php:class DocumentStatusAsset extends AssetBundle

AAA;

        foreach (explode(PHP_EOL, $A) as $l) {
            if (!$l) {
                continue;
            }

//            echo PHP_EOL . '+' . $l;

            list($file, $classData) = explode(":", $l);

            $c = file_get_contents($file, false, null, 0, 1024);

            if (
                preg_match("/namespace ([^;]+);/", $c, $m)
                && preg_match("/class +([^ ]+) *extends/", $classData, $m2)
            ) {

                echo PHP_EOL;
                $s = "\\" . $m[1] . "\\" . $m2[1] . '::register(Yii::$app->getView());';
                echo $s;
                eval($s);
            }
        }
        echo PHP_EOL;
    }

}
