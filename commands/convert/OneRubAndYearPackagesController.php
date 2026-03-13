<?php

namespace app\commands\convert;

use app\classes\Assert;
use app\classes\HandlerLogger;
use app\models\PriceLevel;
use app\modules\nnp\models\NdcType;
use app\modules\uu\models\AccountLogPeriod;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\Period;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use app\modules\uu\models\TariffPeriod;
use app\modules\uu\models\traits\AccountTariffPackageTrait;
use Yii;
use yii\console\Controller;

class OneRubAndYearPackagesController extends Controller
{
	/**
	 * Добавление пакета в 1 рубль
	 */
	public function actionAdd()
	{

        $tps = [];
        $tpsskip = [];
        $q = AccountTariff::find()
            ->where(['not', ['tariff_period_id' => null]])
            ->andWhere(['service_type_id' => ServiceType::ID_VOIP])

//            ->andWhere(['client_account_id' => 58631])

//            ->offset(20000)
//            ->limit(10000)
//                ->andWhere(['>', 'id', 2516771])
//                ->andWhere(['id' => 172252])

            ->andWhere(['not', ['id' => [2380620, 2380621, 2380622, 2380623, 2380625, 2464526, 2479157, 2510172, 2516770, 2516771, 2516772, 2532699, 2585217, 2590621, 2594685, 2622534, 2622535]]])

            ->innerJoinWith(['tariffPeriod tp'])
            ->andWhere(['tp.charge_period_id' => Period::ID_YEAR])

            ->andWhere(['id' => [111796]])
            ->andWhere(['not', ['id' => [301275, 301276]]]) // (tp:7788) тариф №11882 / Базовый - номер: 79311110418 NDC: Mobile pl=Клиент 3 (New B2C) - pl is B2C. Skip

            ->orderBy(['id' => SORT_ASC])
            ->with('tariffPeriod.tariff')
            ->with('clientAccount')
            ->with('number')
            ->each();

        $priceLevels = PriceLevel::getList();
        $ndcs = NdcType::getList();

        $tariffTn = Tariff::findOne(['id' => 15711]);
        $accountTariffLogSet = new AccountTariffLog();

        /** @var AccountTariff $accountTariff */
        foreach ($q as $accountTariff) {
            $tariff = $accountTariff->tariffPeriod->tariff;
            echo PHP_EOL . 'Л/с: ' . $accountTariff->client_account_id . ', услуга: ' . $accountTariff->id . ' (tp:' . $accountTariff->tariff_period_id . ') тариф №' . $tariff->id . ' / ' . $tariff->name . ' - номер: ' . $accountTariff->voip_number;
            if (!in_array($accountTariff->number->ndc_type_id, [NdcType::ID_MOBILE, NdcType::ID_GEOGRAPHIC])) {
                echo ' NDC: ' . $ndcs[$accountTariff->number->ndc_type_id] . '. Skip.';
                $tpsskip[$accountTariff->tariff_period_id]++;
                continue;
            }

            echo ' NDC: ' . $ndcs[$accountTariff->number->ndc_type_id];

            $plName = $priceLevels[$accountTariff->clientAccount->price_level];
            echo ' pl=' . $priceLevels[$accountTariff->clientAccount->price_level];

            if (strpos($plName, 'ОТТ') !== false) {
                $tpsskip[$accountTariff->tariff_period_id]++;
                echo ' - pl is OTT. Skip';
                continue;
            }

            if (strpos($plName, 'B2C') !== false) {
                $tpsskip[$accountTariff->tariff_period_id]++;
                echo ' - pl is B2C. Skip';
                continue;
            }

            Assert::isObject($accountTariff);
            $tariffPeriod = $accountTariff->getNotNullTariffPeriod();
            Assert::isObject($tariffPeriod);
            HandlerLogger::me()->isEchoOnAdd = true;


            $param = [
                'client_account_id' => $accountTariff->client_account_id,
                'account_tariff_id' => $accountTariff->id,
                'old_tariff_period_id' => null,
                'new_tariff_period_id' => $tariffPeriod->id,
                'account_tariff_log_actual_from_utc' => '2025-08-31 21:00:00',
            ];


            if ($tariffPeriod->tariff->is_bundle) {
                echo ' - is bundle';
//                $transaction = \Yii::$app->db->beginTransaction();
                try {
                    AccountTariff::actualizeBundlePackages($param);
                } catch (\Exception $e) {
                    echo " Error: " . $e->getMessage();
                }
//                $transaction->rollBack();

            } else {
                echo ' - is default';


                foreach ($accountTariff->nextAccountTariffs as $nextAccountTariff) {
                    if (!$nextAccountTariff->isActive()) {
//                        HandlerLogger::me()->add($accountTariff->id .' -> ' . $nextAccountTariff->id . ' is off. Skip');
                        // зачем нам уже отключенные. Включенные в будущем будут здесь
                        continue;
                    }

                    $nextTariffPeriod = $nextAccountTariff->getNotNullTariffPeriod();
                    if ($nextTariffPeriod->id == 11776) {
                        echo ' TN Package - already exists';
                        continue 2;
                    }
                }

                $accountTariffLog = AccountTariffPackageTrait::_getAccountTariffLogByParam($param, $accountTariff);
                HandlerLogger::me()->add($accountTariff->id .'. Add TN package');
                $accountTariff->_addPackage($tariffTn, $accountTariffLog);


                $tps[$accountTariff->tariff_period_id] = 1;
                echo " +";
            }

        }

        echo PHP_EOL;
        var_export($tpsskip);

        echo PHP_EOL;
	}

	/**
	 * Проверка годовых пакетов: отнимание рубля из period price
	 */
	public function actionCheckYearlyPackages($mode = null)
	{
		if (!\Yii::$app->isRus()) {
			echo PHP_EOL . 'Пропуск: не RU-окружение' . PHP_EOL;
			return;
		}

		$isReal = $mode !== null;
		$skipWithInvoice = false; // true = только л/с без invoice
		$firstDayOfMonth = date('Y-m-01');

		echo PHP_EOL . ($isReal ? '*** ИСПРАВЛЕНИЕ ***' : '--- ОТЧЁТ ---');
		echo PHP_EOL . 'First day of month: ' . $firstDayOfMonth;
		echo PHP_EOL . 'Skip with invoice: ' . ($skipWithInvoice ? 'YES' : 'NO') . PHP_EOL;

		$invoiceFilter = '';
		if ($skipWithInvoice) {
			$invoiceFilter = "
              AND NOT EXISTS (
                  SELECT 1 FROM uu_account_entry ae
                  INNER JOIN uu_bill ub ON ub.id = ae.bill_id
                  INNER JOIN newbills b ON b.uu_bill_id = ub.id
                  INNER JOIN invoice i ON i.bill_no = b.bill_no
                  WHERE ae.id = alp.account_entry_id
              )";
		}

		$sql = "
            SELECT
                alp.id as log_period_id,
                alp.account_tariff_id,
                at_main.client_account_id,
                alp.date_from,
                alp.price as log_price,
                alp.period_price,
                tp.price_per_period as tp_price,
                alp.coefficient,
                (tp.price_per_period - alp.period_price) as pp_diff,
                (SELECT COUNT(*) FROM uu_account_tariff at_pkg
                 INNER JOIN uu_tariff_period tp_pkg ON tp_pkg.id = at_pkg.tariff_period_id
                 WHERE at_pkg.prev_account_tariff_id = at_main.id
                   AND tp_pkg.price_per_period = 1
                   AND at_pkg.tariff_period_id IS NOT NULL
                ) as has_1rub_package
            FROM uu_account_log_period alp
            INNER JOIN uu_account_tariff at_main ON at_main.id = alp.account_tariff_id
            INNER JOIN uu_tariff_period tp ON tp.id = alp.tariff_period_id
            INNER JOIN uu_tariff t ON t.id = tp.tariff_id
            WHERE at_main.prev_account_tariff_id IS NULL
              AND t.service_type_id = :serviceType
              AND tp.charge_period_id = :chargePeriod
              AND alp.date_from = :firstDayOfMonth
              {$invoiceFilter}
            ORDER BY alp.price
        ";

		$rows = Yii::$app->db->createCommand($sql, [
			':serviceType' => ServiceType::ID_VOIP,
			':chargePeriod' => Period::ID_YEAR,
			':firstDayOfMonth' => $firstDayOfMonth,
		])->queryAll();

		echo PHP_EOL . sprintf(
			'%-15s %-20s %-20s %-12s %-12s %-14s %-10s %-8s %-8s %-10s',
			'log_period_id', 'account_tariff_id', 'client_account_id',
			'date_from', 'log_price', 'period_price', 'tp_price', 'coeff', 'pp_diff', 'pkg_1rub'
		);
		echo PHP_EOL . str_repeat('-', 140);

		$toFix = [];
		foreach ($rows as $row) {
			echo PHP_EOL . sprintf(
				'%-15s %-20s %-20s %-12s %-12s %-14s %-10s %-8s %-8s %-10s',
				$row['log_period_id'],
				$row['account_tariff_id'],
				$row['client_account_id'],
				$row['date_from'],
				$row['log_price'],
				$row['period_price'],
				$row['tp_price'],
				$row['coefficient'],
				$row['pp_diff'],
				$row['has_1rub_package']
			);

			$tpPrice = (float)$row['tp_price'];
			$coefficient = (float)$row['coefficient'];
			$packageDiscount = $row['has_1rub_package'] > 0 ? 1 : 0;
			$expectedPeriodPrice = $tpPrice - $packageDiscount;
			$expectedPrice = $expectedPeriodPrice * $coefficient;

			if ((float)$row['period_price'] != $expectedPeriodPrice || (float)$row['log_price'] != $expectedPrice) {
				echo ' <-- FIX (expected pp=' . $expectedPeriodPrice . ', price=' . $expectedPrice . ')';
				$toFix[] = [
					'id' => $row['log_period_id'],
					'has_1rub_package' => $row['has_1rub_package'] > 0,
				];
			}
		}

		echo PHP_EOL . PHP_EOL . 'Total: ' . count($rows) . ', to fix: ' . count($toFix);

		if ($isReal && $toFix) {
			echo PHP_EOL . 'Fixing...';
			$fixed = 0;
			foreach ($toFix as $fix) {
				$alp = AccountLogPeriod::findOne($fix['id']);
				$tp = TariffPeriod::findOne($alp->tariff_period_id);
				$discount = $fix['has_1rub_package'] ? 1 : 0;
				$alp->period_price = $tp->price_per_period - $discount;
				$alp->price = $alp->period_price * $alp->coefficient;
				$alp->save(false);
				$fixed++;
				echo PHP_EOL . "  #{$fix['id']}: period_price={$alp->period_price}, price={$alp->price}";
			}
			echo PHP_EOL . 'Updated rows: ' . $fixed;
		}

		echo PHP_EOL;
	}
}
