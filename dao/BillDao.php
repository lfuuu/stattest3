<?php

namespace app\dao;

use app\classes\HandlerLogger;
use app\classes\helpers\DependecyHelper;
use app\classes\Language;
use app\classes\model\ActiveRecord;
use app\classes\Singleton;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\BillCorrection;
use app\models\BillDocument;
use app\models\billing\CallsRaw;
use app\models\billing\Trunk;
use app\models\BillLine;
use app\models\BillLineUu;
use app\models\BillOwner;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use app\models\ClientContractAdditionalAgreement;
use app\models\ClientDocument;
use app\models\Currency;
use app\models\EventQueue;
use app\models\Invoice;
use app\models\LogBill;
use app\models\OperationType;
use app\models\Organization;
use app\models\Payment;
use app\models\Transaction;
use app\models\UsageTrunk;
use app\modules\uu\models\AccountEntry;
use app\modules\uu\models\Bill as uuBill;
use Yii;
use yii\caching\ChainedDependency;
use yii\caching\DbDependency;
use yii\caching\TagDependency;
use yii\db\Expression;
use yii\db\Query;


/**
 * @method static BillDao me($args = null)
 */
class BillDao extends Singleton
{
    const PRICE_PRECISION = 2;
    const ADMISSIBLE_COMPUTATION_ERROR_AMOUNT = 0.0001;
    const ADMISSIBLE_COMPUTATION_ERROR_SUM = 0.01;

    const UU_SERVICE = 'uu_account_tariff';

    /**
     * Получение следующего номера счета
     *
     * @param \DateTime|string $billDate
     * @param int $organizationId
     * @return string
     * @throws \Exception
     */
    public function spawnBillNumber($billDate, $organizationId = Organization::MCN_TELECOM)
    {
        if (is_numeric($billDate)) {
            $timestamp = $billDate;
            $billDate = new \DateTime(null, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW));
            $billDate->setTimestamp($timestamp);
        }

        if (!$billDate && !($billDate instanceof \DateTime)) {
            $billDate = new \DateTime($billDate, new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW));
        }

        if (!$billDate) {
            $billDate = new \DateTime('now', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW));
        }

        $billDateStr = $billDate->format('Ym');
        $billNoPrefix = $billDateStr . '-';

//        $organizationPrefix = '';
//        if ($billDate >= $this->getDateAfterBillNoWithOrganization()) {
//            $organizationPrefix = sprintf('%02d', $organizationId);
//        }

        $cache = \Yii::$app->cacheDb;

        do {
            $organizationPrefix = sprintf('%02d', $organizationId);
            $key = 'bn' . $billDateStr . $organizationId;

            $lastBillNumber = Bill::find()
                ->select('bill_no')
                ->where(['LIKE', 'bill_no', $billNoPrefix . $organizationPrefix . '____', $isEscape = false])
                ->orderBy(['bill_no' => SORT_DESC])
                ->limit(1)
                ->scalar();

            $suffix = $lastBillNumber ? 1 + intval(substr($lastBillNumber, strlen($billNoPrefix) + strlen($organizationPrefix))) : 1;

            $isFind = true;
            if ($suffix % 10000 == 0) {
                $organizationId++;
                $isFind = false;
            }

            if ($isFind) {
                if ($saveSuffix = $cache->get($key)) {
                    if ($saveSuffix >= $suffix) {
                        $suffix = $saveSuffix + 1;

                        if ($suffix % 10000 == 0) {
                            $organizationId++;
                            $isFind = false;
                        }
                    }
                }
            }
        } while (!$isFind);

        $billNo = $billNoPrefix . $organizationPrefix . sprintf("%04d", $suffix);

        $cache->set($key, $suffix, DependecyHelper::TIMELIFE_NEXT_BILL_NO);

        return $billNo;
    }

    /**
     * Дата, после которой номер счета с организацией
     *
     * @return \DateTime
     * @throws \Exception
     */
    public function getDateAfterBillNoWithOrganization()
    {
        return new \DateTime('2017-06-01', new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_MOSCOW));
    }

    /**
     * Пересчёт счёта
     *
     * @param Bill $bill
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    public function recalcBill(Bill $bill)
    {
        $dbTransaction = Bill::getDb()->beginTransaction();
        try {
            $lines = $bill->getLines()->all();

            if ($bill->clientAccount->type_of_bill == ClientAccount::TYPE_OF_BILL_SIMPLE) {
                $lines = BillLine::compactLines($lines, $bill->clientAccount->contragent->lang_code, $bill->price_include_vat);
            }

            $this->_calculateBillSum($bill, $lines);

            if (
                $bill->clientAccount->type_of_bill != ClientAccount::TYPE_OF_BILL_SIMPLE
                && (
                    $bill->biller_version === null
                    || $bill->biller_version == ClientAccount::VERSION_BILLER_USAGE
                )
            ) {
                $this->_updateTransactions($bill, $lines);
            }

            $bill->save();

            $dbTransaction->commit();
        } catch (\Exception $e) {
            $dbTransaction->rollBack();
            throw $e;
        }
    }

    /**
     * Пересчет суммы счета
     *
     * @param Bill $bill
     * @param array $lines
     */
    private function _calculateBillSum(Bill $bill, array $lines)
    {
        $bill->sum_with_unapproved = 0;

        /** @var BillLine[] $lines */
        foreach ($lines as $line) {
            if ($line['type'] == BillLine::LINE_TYPE_ZADATOK) {
                continue;
            }

            $bill->sum_with_unapproved += $line['sum'];
        }

        if ($bill->is_rollback && $bill->sum_with_unapproved > 0) {
            $bill->sum_with_unapproved = -$bill->sum_with_unapproved;
        }

        $bill->sum = $bill->is_approved ? $bill->sum_with_unapproved : 0;
    }

    /**
     * Обновить транзакции счета
     *
     * @param Bill $bill
     * @param BillLine[] $lines
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    private function _updateTransactions(Bill $bill, array $lines)
    {
        /** @var Transaction[] $transactions */
        $transactions = Transaction::find()
            ->andWhere([
                'bill_id' => $bill->id
            ])
            ->indexBy('bill_line_id')
            ->all();

        if ($bill->is_approved) {
            /** @var BillLine[] $lines */
            foreach ($lines as $line) {
                if ($line->type == BillLine::LINE_TYPE_ZADATOK) {
                    continue;
                }

                if (isset($transactions[$line->pk])) {
                    Transaction::dao()->updateByBillLine($bill, $line, $transactions[$line->pk]);
                    unset($transactions[$line->pk]);
                } else {
                    Transaction::dao()->insertByBillLine($bill, $line);
                }
            }
        }

        foreach ($transactions as $transaction) {
            if ($transaction->source == Transaction::SOURCE_STAT) {
                Transaction::dao()->markDeleted($transaction);
            } else {
                $transaction->delete();
            }
        }
    }

    /**
     * Получение типа документа по номеру
     *
     * @param string $bill_no
     * @return array
     */
    public function getDocumentType($bill_no)
    {
        if (preg_match("/\d{2}-\d{8}/", $bill_no)) {
            return ['type' => Bill::DOC_TYPE_INCOMEGOOD];
        }

        if (preg_match("/20\d{4}\/(\d{2})?\d{4}/", $bill_no)) {
            return ['type' => Bill::DOC_TYPE_BILL, 'bill_type' => Bill::TYPE_1C];
        }

        if (preg_match("/20\d{4}-(\d{2})?\d{4}/", $bill_no) || preg_match("/[4567]\d{5}/", $bill_no)) { // mcn telekom || all4net
            return ['type' => Bill::DOC_TYPE_BILL, 'bill_type' => Bill::TYPE_STAT];
        }

        return ['type' => Bill::DOC_TYPE_UNKNOWN];
    }

    /**
     * Закрыт ли счёт
     *
     * @param Bill $bill
     * @return bool
     * @throws \yii\db\Exception
     */
    public function isClosed(Bill $bill)
    {
        $stateId = Yii::$app->db->createCommand('
                    SELECT state_id
                    FROM tt_troubles t, tt_stages s
                    WHERE bill_no = :billNo AND  t.cur_stage_id = s.stage_id
                ', [':billNo' => $bill->bill_no]
        )->queryScalar();

        return $stateId == 20;
    }

    /**
     * Получить менеджера счёта
     *
     * @param string $billNo
     * @return bool|string
     */
    public function getManager($billNo)
    {
        return BillOwner::find()
            ->select('owner_id')
            ->andWhere(['bill_no' => $billNo])
            ->scalar();
    }

    /**
     * Установить менеджер счёта
     *
     * @param string $billNo
     * @param int $userId
     */
    public function setManager($billNo, $userId)
    {
        $owner = BillOwner::findOne($billNo);
        if ($userId) {
            if ($owner == null) {
                $owner = new BillOwner();
            }

            $owner->bill_no = $billNo;
            $owner->owner_id = $userId;
            $owner->save();
        } elseif ($owner) {
            // $owner->delete();
        }
    }

    /**
     * Функция переноса универсального счёта в "старый"
     *
     * @param uuBill $uuBill
     * @return bool
     * @throws \Exception
     * @throws \Throwable
     */
    public function transferUniversalBillsToBills(uuBill $uuBill)
    {
        /** @var Bill $bill */
        $bill = Bill::find()
            ->where(['uu_bill_id' => $uuBill->id])
            ->one();
        if (\Yii::$app->isEu() && $bill) {
            $bill->is_show_in_lk = 0;
        }
        $roundPrice = round($uuBill->price, 2);
        // нулевые счета не нужны
        $isSkip = !$roundPrice;

        if ($isSkip) {
            if ($bill && !$bill->delete()) {
                throw new ModelValidationException($bill);
            }

            return false;
        }

        if (!$bill) {
            $clientAccount = $uuBill->clientAccount;
            $uuBillDateTime = new \DateTimeImmutable($uuBill->date);

            $bill = new Bill();
            $bill->operation_type_id = $uuBill->operation_type_id;
            $bill->client_id = $clientAccount->id;
            $bill->currency = $clientAccount->currency;
            $bill->nal = $clientAccount->nal;
            $bill->is_show_in_lk = 1;
            $bill->is_user_prepay = 0;
            $bill->is_approved = 1;
            $bill->bill_date = $uuBillDateTime->format(DateTimeZoneHelper::DATE_FORMAT);
            $bill->sum_with_unapproved = $uuBill->price;
            $bill->price_include_vat = $clientAccount->price_include_vat;
            $bill->sum = $uuBill->price;
            $bill->biller_version = ClientAccount::VERSION_BILLER_UNIVERSAL;
            $bill->uu_bill_id = $uuBill->id;
            $bill->bill_no = $this->spawnBillNumber($uuBillDateTime, $clientAccount->contract->organization_id);
            if (!$bill->save()) {
                throw new ModelValidationException($bill);
            }
        }

        return $this->transferAccountEntries($uuBill, $bill);
    }

    /**
     * Функция переноса проводок универсального биллера в записи "старых" счетов
     *
     * @param uuBill $uuBill
     * @param Bill $bill
     * @return bool
     * @throws ModelValidationException
     * @throws \Throwable
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     * @throws \yii\db\StaleObjectException
     */
    protected function transferAccountEntries(uuBill $uuBill, Bill $bill)
    {
        $clientAccount = $uuBill->clientAccount;
        $uuBillDateTime = new \DateTimeImmutable($uuBill->date);

        $billRenameDate = false;
        if ($clientAccount->isBillRename1()) {
            $startBillRename1Start = new \DateTimeImmutable(ClientAccount::UNIVERSAL_BILL_RENAME1_DATE);
            if ($uuBillDateTime >= $startBillRename1Start) {
                $billRenameDate = $uuBillDateTime;
            }
        }

        $toRecalculateBillSum = false;
        $billLinePosition = 0;

        // новые проводки
        /** @var AccountEntry[] $accountEntries */
        $accountEntries = $uuBill
            ->getAccountEntries()
            ->andWhere(['<>', new Expression('ROUND(price, 2)'), 0])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        if ($clientAccount->is_postpaid != ClientAccount::PAYMENT_TYPE_POSTPAID) {
            /*
                // для предоплаты не надо включать в счет посуточную абонентку
                // или все-таки надо? иначе эти строчки никогда не попадут в счет и бухгалтерский баланс будет сильно отличаться от реалтайма
                $accountEntries = array_filter($accountEntries, function (AccountEntry $accountEntry) {
                    return
                        $accountEntry->type_id != AccountEntry::TYPE_ID_PERIOD ||
                        $accountEntry->tariffPeriod->charge_period_id != Period::ID_DAY;
                });
            */

            // если не осталось строчек счета, то и сам счет не нужен
            if (!count($accountEntries) && !$bill->isNewRecord && !$bill->delete()) {
                throw new ModelValidationException($bill);
            }

            // могла измениться сумма счета - обновить ее
            $billPrice = array_reduce(
                $accountEntries,
                function ($carry, AccountEntry $accountEntry) {
                    $carry += $accountEntry->price_with_vat;
                    return $carry;
                },
                0
            );
            $billPrice = round($billPrice, self::PRICE_PRECISION);
            if ($billPrice != $bill->sum) {
                $bill->sum = $bill->sum_with_unapproved = $billPrice;
                if (!$bill->save()) {
                    throw new ModelValidationException($bill);
                }
            }
        }

        // оригинальные Uu проводки
        $uuBillLines = BillLineUu::find()
            ->where(['bill_no' => $bill->bill_no])
            ->indexBy(['uu_account_entry_id'])
            ->all();

        // старые проводки
        $_lines = $bill->getLines()
            ->all();

        $lines = [];
        /** @var BillLine $line */
        foreach ($_lines as $line) {

            // отредактированно
            if (isset($uuBillLines[$line->uu_account_entry_id])) {
                $lines[] = $uuBillLines[$line->uu_account_entry_id];
                unset($uuBillLines[$line->uu_account_entry_id]);
                continue;
            }

            $lines[] = $line;
        }

        // удаленные проводки
        foreach ($uuBillLines as $line) {
            $lines[] = $line;
        }

        /** @var BillLine $line */
        foreach ($lines as $line) {
            if (empty($line->id_service)) { // не трогаем ручные проводки
                continue;
            }

            $accountEntryId = $line->uu_account_entry_id;
            if (!isset($accountEntries[$accountEntryId])) {
                // была, но сейчас нет. Удалить
                if (!$line->delete()) {
                    throw new ModelValidationException($line);
                }

                continue;
            }

            // была и осталась
            $accountEntry = $accountEntries[$accountEntryId];
            $sum = round($accountEntry->price_with_vat, self::PRICE_PRECISION);
            $sumWithoutTax = round($accountEntry->price_without_vat, self::PRICE_PRECISION);

            $accountEntryName = $this->getAccountEntryName($clientAccount, $accountEntry, $billRenameDate);

            if (
                abs((float)$line->sum_without_tax - (float)$accountEntry->price_without_vat) > self::ADMISSIBLE_COMPUTATION_ERROR_SUM
                || abs((float)$line->amount - (float)$accountEntry->getAmount()) > self::ADMISSIBLE_COMPUTATION_ERROR_AMOUNT
                || $line->item != $accountEntryName
                || $line->tax_rate != $accountEntry->vat_rate
            ) {
                // ... но изменилась. Обновить
                $line->sum = $sum;
                $line->sum_without_tax = $sumWithoutTax;
                $line->sum_tax = round($accountEntry->vat, self::PRICE_PRECISION);
                $line->tax_rate = $accountEntry->vat_rate;

                $line->amount = $accountEntry->getAmount();
                if ($line->amount > 0 && $line->amount != 1) {
                    $line->price = ($bill->price_include_vat ? $accountEntry->price_with_vat : $accountEntry->price_without_vat) / $line->amount; // цена за "1 шт."
                    $line->price = round($line->price, self::PRICE_PRECISION);
                    if ($line->price) {
                        $line->amount = ($bill->price_include_vat ? $line->sum : $line->sum_without_tax) / $line->price; // после округления price и sum надо подкорректировать коэффициент
                    }
                } else {
                    $line->amount = 1;
                    $line->price = $bill->price_include_vat ? $sum : $sumWithoutTax;
                }

                BillLine::_calculateSum($bill->price_include_vat, $line);

                $line->item = $accountEntryName;
                $line->cost_price = $accountEntry->cost_price;

                if (!$line->save()) {
                    throw new ModelValidationException($line);
                }

                $toRecalculateBillSum = true;
            }

            unset($accountEntries[$accountEntryId]);
            $billLinePosition = max($billLinePosition, $line->sort);
        }

        unset($lines, $line);

        // не было, но стало. Добавить
        foreach ($accountEntries as $accountEntry) {

            $billLinePosition++;
            $sum = round($accountEntry->price_with_vat, self::PRICE_PRECISION);
            $sumWithoutTax = round($accountEntry->price_without_vat, self::PRICE_PRECISION);

            $line = new BillLine();
            $line->sort = $billLinePosition;
            $line->bill_no = $bill->bill_no;
            $line->setParentId($bill->id);

            $line->item = $this->getAccountEntryName($clientAccount, $accountEntry, $billRenameDate);
            $line->date_from = $accountEntry->date_from;
            $line->date_to = $accountEntry->date_to;
            $line->type = BillLine::LINE_TYPE_SERVICE;
            $line->amount = $accountEntry->getAmount();
            $line->tax_rate = $accountEntry->vat_rate;
            $line->sum = $sum;
            $line->sum_without_tax = $sumWithoutTax;
            $line->sum_tax = $accountEntry->vat;
            $line->uu_account_entry_id = $accountEntry->id;
            $line->cost_price = $accountEntry->cost_price;
            $line->service = self::UU_SERVICE;
            $line->id_service = $accountEntry->account_tariff_id;
            $line->item_id = null;
            if ($line->amount > 0 && $line->amount != 1) {
                $line->price = ($bill->price_include_vat ? $accountEntry->price_with_vat : $accountEntry->price_without_vat) / $line->amount; // цена за "1 шт."
                $line->price = round($line->price, self::PRICE_PRECISION);
                if ($line->price) {
                    $line->amount = ($bill->price_include_vat ? $line->sum : $line->sum_without_tax) / $line->price; // после округления price надо подкорректировать коэффициент
                }
            } else {
                $line->amount = 1;
                $line->price = $bill->price_include_vat ? $sum : $sumWithoutTax;
            }

            BillLine::_calculateSum($bill->price_include_vat, $line);

            if (!$line->save()) {
                throw new ModelValidationException($line);
            }

            $toRecalculateBillSum = true;
        }

        if ($toRecalculateBillSum) {
            Bill::dao()->recalcBill($bill);
        }

        return true;
    }

    /**
     * Получение названия транзакции
     *
     * @param ClientAccount $clientAccount
     * @param AccountEntry $accountEntry
     * @param $billRenameDate
     * @return string
     * @throws \Exception
     */
    protected function getAccountEntryName(ClientAccount $clientAccount, AccountEntry $accountEntry, $billRenameDate)
    {
        static $cacheDocument = [];
        static $cacheLang = [];

        $name = $accountEntry->fullName;

        if (!$billRenameDate) {
            return $name;
        }

        $dateStr = $billRenameDate->format(DateTimeZoneHelper::DATE_FORMAT);

        if (!isset($cacheDocument[$dateStr][$clientAccount->contract_id])) {
            $_billRenameDate = new \DateTime($dateStr);
            $cacheDocument[$dateStr][$clientAccount->contract_id] = $clientAccount->contract->getContractInfo($_billRenameDate);
        }

        if (!isset($cacheLang[$clientAccount->contract_id])) {
            $cacheLang[$clientAccount->contract_id] = $clientAccount->contragent->lang_code;
        }

        /** @var ClientDocument $contractDocument */
        $contractDocument = $cacheDocument[$dateStr][$clientAccount->contract_id];

        $lang = $cacheLang[$clientAccount->contract_id];

        if ($contractDocument) {
            $name = Yii::t('uu', 'Services provided under the contract', [
                    'contract_no' => $contractDocument->contract_no,
                    'contract_date' => (new \DateTime($contractDocument->contract_date,
                        new \DateTimeZone(DateTimeZoneHelper::TIMEZONE_DEFAULT)))->getTimestamp()
                ], $lang) . $name;
        }

        return $name;
    }

    /**
     * Этот счет выписывается на новую компанию?
     *
     * Переход с МСН Телкома на МСН Телеком Ритейл
     *
     * @param Bill $bill
     * @return bool
     * @throws \yii\db\Exception
     */
    public function isBillNewCompany(Bill $bill, $oldCompanyId, $newCompanyId)
    {
        return ClientContractAdditionalAgreement::find()->where([
            'account_id' => $bill->client_id,
            'from_organization_id' => $oldCompanyId,
            'to_organization_id' => $newCompanyId,
            'transfer_date' => $bill->bill_date,
        ])->exists();

        $sql = <<<ESQL
            SELECT
                model,
                model_id,
                date,
                replace(replace(SUBSTRING(data_json, instr(data_json, 'organization_id') + 15,
                                        instr(SUBSTRING(data_json, instr(data_json, 'organization_id') + 15), ',') - 1), '\"', ''),
                      ':', '')              new_org_id,
                replace(replace(SUBSTRING(h2_json, instr(h2_json, 'organization_id') + 15,
                                        instr(SUBSTRING(h2_json, instr(h2_json, 'organization_id') + 15), ',') - 1), '\"',
                              ''), ':', '') old_org_id,
                client_id
            FROM (
                   SELECT
                     h1.*,
                     c.id AS   client_id,
                     (SELECT data_json
                      FROM history_version h2
                      WHERE h2.model = 'app\\\\models\\\\ClientContract' AND h2.date < :billDate AND h1.model_id = h2.model_id
                      ORDER BY date DESC
                      LIMIT 1) h2_json
                   FROM history_version h1, clients c
                   WHERE h1.model = 'app\\\\models\\\\ClientContract' AND h1.date = :billDate AND c.id = :accountId AND h1.model_id = c.contract_id
                 ) a
            HAVING new_org_id = :newCompanyId AND old_org_id = :oldCompanyId
ESQL;

        return (bool)\Yii::$app->db->createCommand($sql, [
            ':billDate' => $bill->bill_date,
            ':accountId' => $bill->client_id,
            ':oldCompanyId' => $oldCompanyId,
            ':newCompanyId' => $newCompanyId,
        ])->queryOne();
    }

    /**
     * Дата перехода с МСН Телкома на МСН Телеком Ритейл
     *
     * @param integer $accountId
     * @return false|string|null
     * @throws \yii\db\Exception
     */
    public function getNewCompanyDate($accountId)
    {
        $sql = <<<SQL
            SELECT 
                date 
            FROM (
                SELECT
                    model,
                    model_id,
                    date,
                    replace(replace(SUBSTRING(data_json, instr(data_json, 'organization_id') + 15,
                                instr(SUBSTRING(data_json, instr(data_json, 'organization_id') + 15), ',') - 1), '\"',
                            ''), ':', '') new_org_id,
                    replace(replace(SUBSTRING(h2_json, instr(h2_json, 'organization_id') + 15,
                                instr(SUBSTRING(h2_json, instr(h2_json, 'organization_id') + 15), ',') - 1), '\"',
                            ''), ':', '') old_org_id,
                     client_id
                FROM (
                    SELECT
                    h1.*,c.id AS client_id,
                    (SELECT data_json
                     FROM history_version h2
                     WHERE h2.model = 'app\\\\models\\\\ClientContract' AND h2.date < h1.date AND h1.model_id = h2.model_id
                     ORDER BY date DESC
                     LIMIT 1) h2_json
                    FROM history_version h1, clients c
                    WHERE h1.model = 'app\\\\models\\\\ClientContract' AND c.id = :accountId AND h1.model_id = c.contract_id
                    ) a
                                            
                HAVING new_org_id = 11 AND old_org_id = 1
                ORDER BY date DESC
                LIMIT 1
            ) a
SQL;

        return \Yii::$app->db->createCommand($sql, [':accountId' => $accountId])->queryScalar();
    }

    /**
     * Проверяет, у всех ли счетов, выпущенных в заданном периоде, проставлена организация
     * Эта функция необходима, до полного перехода на yii'шную модель
     *
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     */
    public function checkSetBillsOrganization(\DateTime $dateFrom, \DateTime $dateTo)
    {
        $query = Bill::find()
            ->where([
                'between',
                'bill_date',
                $dateFrom->format(DateTimeZoneHelper::DATE_FORMAT),
                $dateTo->format(DateTimeZoneHelper::DATE_FORMAT)
            ])
            ->andWhere(['organization_id' => 0]);

        /** @var Bill $bill */
        foreach ($query->each() as $bill) {
            $bill->organization_id = $bill->clientAccount->contract->organization_id;
            $bill->save();
        }
    }

    /**
     * Получение счета на предоплату для ЛК
     *
     * @param int $accountId
     * @param float $sum
     * @param string $currency
     * @param bool $isForceCreate
     * @return Bill|null
     * @throws \Exception
     */
    public function getPrepayedBillOnSum($accountId, $sum, $currency = Currency::RUB, $isForceCreate = false)
    {
        if (!$isForceCreate) {
            $billNo = $this->getPrepayedBillNoOnSumFromDB($accountId, $sum, $currency);

            if ($billNo) {
                return Bill::findOne(['bill_no' => $billNo]);
            }
        }

        return $this->createBillOnSum($accountId, $sum, $currency);
    }

    /**
     * Получение счета на предоплату для Лк из базы
     *
     * @param int $accountId
     * @param float $sum
     * @param string $currency
     * @return Bill|null
     * @throws \yii\db\Exception
     */
    public function getPrepayedBillNoOnSumFromDB($accountId, $sum, $currency = Currency::RUB)
    {
        return \Yii::$app->db->createCommand(
            "SELECT
                bill_no
             FROM (
                SELECT
                    b.bill_no,
                    p.payment_no
                FROM (
                        SELECT
                            b.bill_no,
                            b.client_id,
                            bill_date,
                            COUNT(1) AS count_lines,
                            SUM(l.sum) AS l_sum
                        FROM
                            newbills b, newbill_lines l
                        WHERE
                                b.client_id = :accountId
                            AND l.bill_no = b.bill_no
                            AND b.currency = :currency
                            AND is_user_prepay
                        GROUP BY
                            bill_no
                        HAVING
                                count_lines = 1
                            AND l_sum = :sum
                ) b
                LEFT JOIN newpayments p ON (p.client_id = b.client_id AND (b.bill_no = p.bill_no OR b.bill_no = p.bill_vis_no))
                HAVING
                    p.payment_no IS NULL
                ORDER BY
                    bill_date DESC
                LIMIT 1
             )a", [
            ':accountId' => $accountId,
            ':sum' => $sum,
            ':currency' => $currency,
        ])->queryScalar();

    }

    /**
     * Создает счет на основе суммы платежа
     *
     * @param int $accountId
     * @param float $sum
     * @return Bill
     * @throws \Exception
     * @internal param bool|false $createAutoLkLog
     */
    public function createBillOnSum($accountId, $sum, $currency)
    {
        $clientAccount = ClientAccount::findOne(['id' => $accountId]);

        $transaction = Yii::$app->db->beginTransaction();
        try {

            $bill = self::me()->createBill($clientAccount, $currency, $isForcePriceIncludeVat = true);
            $bill->is_show_in_lk = 0;
            $bill->is_user_prepay = 1;
            if (!$bill->save()) {
                throw new ModelValidationException($bill);
            }

            $bill->addLine(
                Yii::t('biller', 'incomming_payment', [], Language::normalizeLang($clientAccount->contragent->lang_code)),
                1,
                $sum,
                BillLine::LINE_TYPE_ZADATOK
            );

            LogBill::dao()->log($bill->bill_no, 'Создание счета');

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $bill;
    }

    /**
     * Создание пустого счета
     *
     * @param ClientAccount $clientAccount
     * @param string $currency
     * @param int $isForcePriceIncludeVat
     * @return Bill
     * @throws \Exception
     * @internal param \DateTime|null $date
     */
    public function createBill(ClientAccount $clientAccount, $currency = null, $isForcePriceIncludeVat = null)
    {
        $date = new \DateTime('now', new \DateTimeZone($clientAccount->timezone_name));

        if (!$currency) {
            $currency = $clientAccount->currency;
        }

        $bill = new Bill();
        $bill->client_id = $clientAccount->id;
        $bill->currency = $currency;
        $bill->bill_no = self::me()->spawnBillNumber($date, $clientAccount->contract->organization_id);
        $bill->bill_date = $date->format(DateTimeZoneHelper::DATE_FORMAT);
        $bill->nal = $clientAccount->nal;
        $bill->is_approved = 1;
        $bill->price_include_vat = $isForcePriceIncludeVat === null ? $clientAccount->price_include_vat : (int)(bool)$isForcePriceIncludeVat;
        $bill->biller_version = $clientAccount->account_version;
        $bill->comment = '';
        $bill->save();

        $bill->refresh();

        return $bill;
    }

    /**
     * Существует ли у счета платеж типа credit note
     *
     * @param Bill $bill
     * @return bool
     */
    public function isBillWithCreditNote(Bill $bill)
    {
        return $this
            ->_getBillCreditNoteQuery($bill)
            ->exists();
    }

    /**
     * Получение credit note по счету
     *
     * @param Bill $bill
     * @return Payment
     */
    public function getCreditNote(Bill $bill)
    {
        return $this
            ->_getBillCreditNoteQuery($bill)
            ->one();
    }

    /**
     * Получение Query-объекта на получение платежа типа credit note по счету
     *
     * @param Bill $bill
     * @return Query
     */
    public function _getBillCreditNoteQuery(Bill $bill)
    {
        return Payment::find()
            ->where([
                'type' => Payment::TYPE_CREDITNOTE,
                'bill_no' => $bill->bill_no,
            ])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * Выставление авансовых счетов операторам
     *
     * @param Query $query
     * @param \DateTimeImmutable $periodStart
     * @param \DateTimeImmutable $periodEnd
     * @throws \Exception
     */
    public function advanceAccounts(Query $query, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd)
    {
        foreach ($query->each() as $account) {
            $this->_advanceAccount($account, $periodStart, $periodEnd);
        }
    }

    /**
     * Реализация выставления авансовых счетов операторам
     *
     * @param ClientAccount $account
     * @param \DateTimeImmutable $periodStart
     * @param \DateTimeImmutable $periodEnd
     * @throws \Exception
     */
    private function _advanceAccount(ClientAccount $account, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd)
    {
        $physicalTrunkIds = UsageTrunk::find()
            ->select('trunk_id')
            ->where(['client_account_id' => $account->id])
            ->actual()
            ->column();

        if (!$physicalTrunkIds) {
            return;
        }

        $trunkNamesStr = '';
        $trunks = Trunk::find()
            ->select('name')
            ->where(['id' => $physicalTrunkIds])
            ->column();

        if ($trunks) {
            $trunkNamesStr = implode(", ", $trunks);
        }

        if ($offset = (new \DateTime('now', (new \DateTimeZone($account->timezone_name))))->getOffset()) {
            $periodStart = $periodStart->sub(new \DateInterval('PT' . $offset . 'S'));
            $periodEnd = $periodEnd->sub(new \DateInterval('PT' . $offset . 'S'));
        }

        CallsRaw::setPgTimeout(ActiveRecord::PG_CALCULATE_RESOURCE_TIMEOUT);

        $result = CallsRaw::find()
            ->select([
                'sale_sum' => new Expression('SUM(cost)'),
                'session_time_sum' => new Expression('SUM(billed_time)')
            ])
            ->where(['between', 'connect_time', $periodStart->format(DateTimeZoneHelper::DATETIME_FORMAT), $periodEnd->format(DateTimeZoneHelper::DATETIME_FORMAT)])
            ->andWhere([
                'trunk_id' => $physicalTrunkIds,
                'orig' => true,
            ])
            ->asArray()
            ->one();

        if (!$result) {
            return;
        }

        /*
                $report = new CallsRawFilter();

                if (!$report->load(
                    [
                        'CallsRawFilter' => [
                            'connect_time_from' => $periodStart->format(DateTimeZoneHelper::DATETIME_FORMAT),
                            'connect_time_to' => $periodEnd->format(DateTimeZoneHelper::DATETIME_FORMAT),
                            'src_physical_trunks_ids' => $physicalTrunkIds,
                            'currency' => $account->currency,
                            'aggr' => ['sale_sum', 'session_time_sum']
                        ]
                    ]
                )) {
                    throw new \LogicException('CallsRawFilter not load parameters');
                }

                $result = $report->getReport(false);

                if (!$result) {
                    return;
                }

                $result = reset($result);
        */

        $sum = abs($result['sale_sum']);
        $billedTime = $result['session_time_sum'] / 60;

        $lineItem = Yii::t(
            'biller-voip',
            'voip_operator_trunk_orig',
            ['service' => $trunkNamesStr, 'date_range' => '', 'minutes' => $billedTime],
            Language::normalizeLang($account->contract->contragent->lang_code)
        );
        $transaction = \Yii::$app->db->beginTransaction();

        try {
            $bill = $this->createBill($account);

            HandlerLogger::me()->add(date('r') . ': accountId: ' . $account->id . ': ' .
                $bill->bill_no . ' ' . $lineItem . ' ' .
                str_replace(["\n", "\r"], '', print_r($result, true))
            );

            $bill->addLine(
                $lineItem,
                1,
                $sum,
                BillLine::LINE_TYPE_ZADATOK,
                $periodStart,
                $periodEnd->modify('-1 day')
            );

            $bill->comment = 'Авансовый автоматический счет на ' . round($sum, 2) . ' ' . $account->currency;

            if (!$bill->save()) {
                throw new ModelValidationException($bill);
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);

            throw $e;
        }
    }

    /**
     * @param Bill $bill
     * @param int $typeId
     * @param bool $isInsert
     * @return array|bool|\yii\db\ActiveRecord[]
     * @throws \yii\base\InvalidConfigException
     * @throws \Exception
     */
    public static function getLinesByTypeId($bill, $typeId, $isInsert = false)
    {
        $clientAccount = $bill->clientAccount;

        $sql = 'SELECT (SELECT coalesce(sum(pk + sum_without_tax + sum_tax + price + sum + date_from + date_to + coalesce(id_service, 0) + amount), 0) + count(*) AS cnt
        FROM newbill_lines
        WHERE bill_no = :billNo) +
       (SELECT coalesce(sum(pk + sum + date_from + date_to + bill_correction_id + amount) + count(*), 0) AS cnt
        FROM newbill_lines_correction
        WHERE bill_no = :billNo) as check_sum';


        $tagsDep = new TagDependency(['tags' => [DependecyHelper::TAG_BILL]]);
        $dbDep = new DbDependency(['sql' => $sql, 'params' => [':billNo' => $bill->bill_no]]);

        $dependency = new ChainedDependency(['dependencies' => [$dbDep, $tagsDep]]);

        $key = 'getLineByTypeId' . str_replace(['-', '/'], ['i', 'g'], $bill->bill_no) . 't' . $typeId . 't' . $clientAccount->type_of_bill;
//
//        if (($value = \Yii::$app->cache->get($key)) !== false) {
//            return $value;
//        }

        $lines = [];


        $billLines = $bill->lines;

        if ($typeId == Invoice::TYPE_PREPAID) {
            return $billLines;
        }

        if ($clientAccount->type_of_bill == ClientAccount::TYPE_OF_BILL_SIMPLE) {
            $billLines = BillLine::compactLines(
                $bill->lines,
                $bill->clientAccount->contragent->lang_code,
                $bill->price_include_vat
            );
        }

        // скорректированные с/ф только если они есть и не в книге продаж.
        $correctionInfo = null;
        if (!$isInsert/*$bill->sum_correction*/) {

            $billCorrection = BillCorrection::findOne([
                'bill_no' => $bill->bill_no,
                'type_id' => $typeId
            ]);

            $billCorrection && $billLines = $billCorrection->getLines()->asArray()->all();
        }


        /** @var BillLine $line */
        foreach ($billLines as $line) {

            $dateFrom = is_array($line) ? $line['date_from'] : $line->date_from;
            $dateFrom == BillLine::DATE_DEFAULT && $dateFrom = $bill->bill_date; // ручная проводка без даты

            $dateFrom = (new \DateTimeImmutable($dateFrom))->modify('first day of this month');
            $billDate = (new \DateTimeImmutable($bill->bill_date))->modify('first day of this month');

            $type = is_array($line) ? $line['type'] : $line->type;

            if (in_array($typeId, [Invoice::TYPE_1, Invoice::TYPE_2]) && $type != BillLine::LINE_TYPE_SERVICE) {
                continue;
            }

            if ($typeId == Invoice::TYPE_GOOD && $type != BillLine::LINE_TYPE_GOOD) {
                continue;
            }

            $isAllow = false;
            // в первой с/ф только проводки с датой по умолчанию - они заведены в ручную,
            // и абонентка за текущий месяц.
            // Всё остальное - с/ф 2
            if ($typeId == Invoice::TYPE_1) {
                if (
                    $dateFrom == BillLine::DATE_DEFAULT
                    || $dateFrom >= $billDate) {
                    $isAllow = true;
                }
            } elseif ($typeId == Invoice::TYPE_2) {
                if ($dateFrom != BillLine::DATE_DEFAULT
                    && $dateFrom < $billDate)
                    $isAllow = true;
            } elseif ($typeId == Invoice::TYPE_GOOD) {
                $isAllow = $type == BillLine::LINE_TYPE_GOOD;
            }

            if (!$isAllow) {
                continue;
            }

            $lines[] = $line;
        }

        if ($typeId == Invoice::TYPE_PREPAID) {
            $lines = BillLine::refactLinesWithFourOrderFacture($bill, $lines);
        }


        \Yii::$app->cache->set($key, $lines, DependecyHelper::DEFAULT_TIMELIFE, $dependency);

        return $lines;

    }

    /**
     * @param Bill $bill
     * @param bool $is4Invoice
     * @param bool $isAsInsert
     * @throws \Throwable
     */
    public static function generateInvoices(Bill $bill, $is4Invoice = false, $isAsInsert = false, $isForceUpdate = false)
    {
        if (!$bill->isEditable() && !$isForceUpdate) {
            return;
        }

        if (
            $bill->bill_date < Invoice::DATE_ACCOUNTING
            || !$bill->operationType->is_convertible
        ) {
            // 1 авг 2018 новый формат с/ф
            // или не конвертируемый
            return;
        }

        $clientAccount = $bill->clientAccount;

        // только рублевые ЛС и ЛС с выгрузкой, или не рублевые, но после 1 янв 2019
        if (
            ($clientAccount->currency != Currency::RUB && $bill->bill_date < Invoice::DATE_NO_RUSSIAN_ACCOUNTING)
            || !$clientAccount->getOptionValue(ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK)
        ) {

            // выключаем ошибочно включеные
//            foreach ($bill->invoices as $invoice) {
//                $invoice->setReversal();
//            }

            return;
        }


        try {
            $types = Invoice::$types;

            if ($is4Invoice) {
                $types = [Invoice::TYPE_PREPAID];
            }

            foreach ($types as $typeId) {
                $invoiceDate = Invoice::getDate($bill, $typeId);

                // если нет даты документа, то и с/ф регистрировать не надо
                if (!$invoiceDate) {
                    continue;
                }

                /** @var Invoice $invoice */
                $invoice = Invoice::find()
                    ->where(['bill_no' => $bill->bill_no, 'type_id' => $typeId])
                    ->orderBy(['id' => SORT_DESC])
                    ->one();

                // Если последний документ - зарегистрирован, то ничего делать не надо
                if ($invoice && $invoice->number && !$isForceUpdate) {
                    continue;
                }

                if ($isForceUpdate && $invoice->lines) {
                    array_walk($invoice->lines, fn($line) => $line->delete());
                }

                $lines = $invoice && $invoice->lines ?
                    $invoice->lines :
                    $bill->getLinesByTypeId($typeId);

                if (!$lines) {
                    $invoice && $invoice->delete();
                    continue;
                }

                if ($typeId == Invoice::TYPE_PREPAID) {
                    $lines = BillLine::refactLinesWithFourOrderFacture($bill, $lines);
                }

                $sumData = BillLine::getSumsLines($lines);

                $sum = $sumData['sum'];

                // не вносим отрицательные суммы, нулевые можно вносить
                if ($sum < 0) {
                    $invoice && $invoice->delete();
                    continue;
                }

                if (!$invoice) {
                    $invoice = new Invoice();
                    $invoice->bill_no = $bill->bill_no;
                    $invoice->type_id = $typeId;
                    $invoice->sum = $invoice->sum_tax = $invoice->sum_without_tax = 0;
                    $invoice->date = $invoiceDate->format(DateTimeZoneHelper::DATE_FORMAT);
                    $invoice->is_reversal = 0;
                }

                // если номера нет - его надо установить
                if (!$invoice->number) {
                    $invoice->isSetDraft = false;
                }

                $isAsInsert && $invoice->isAsInsert = true;

                // сумму меняем только для новых, или принудительно
                if (
                    abs((float)$invoice->sum - $sum) > 0.001
                    || abs((float)$invoice->sum_tax - $sumData['sum_tax']) > 0.001
                    || $invoice->isAsInsert
                ) {
                    $invoice->sum = $sum;
                    $invoice->sum_tax = $sumData['sum_tax'];
                    $invoice->sum_without_tax = $sumData['sum_without_tax'];
                }


                if (!$invoice->save()) {
                    throw new ModelValidationException($invoice);
                }

                if ($isForceUpdate && $invoice->number) {
                    if ($invoice->is_act) {
                        $filePath = $invoice->getFilePath(BillDocument::TYPE_ACT);
                        if (file_exists($filePath)) {
                            rename($filePath, $filePath.'.unlinked');
//                            unlink($filePath);
                        }
                        EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_ACT]);
                    }

                    if ($invoice->is_invoice) {
                        $filePath = $invoice->getFilePath(BillDocument::TYPE_INVOICE);
                        if (file_exists($filePath)) {
                            rename($filePath, $filePath.'.unlinked');
//                            unlink($filePath);
                        }
                        EventQueue::go(EventQueue::INVOICE_GENERATE_PDF, ['id' => $invoice->id, 'document' => BillDocument::TYPE_INVOICE]);
                    }
                }

            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Сторнирование с/ф счета
     *
     * @param Bill $bill
     * @param bool $is4Invoice
     * @throws ModelValidationException
     */
    public function invoiceReversal(Bill $bill, $is4Invoice = false)
    {
        $invoices = Invoice::find()->where(['bill_no' => $bill->bill_no, 'type_id' => $is4Invoice ? Invoice::TYPE_PREPAID : Invoice::$types]);

        /** @var Invoice $invoice */
        foreach ($invoices->each() as $invoice) {
            $invoice->setReversal();
        }
    }

    /**
     * В счете есть партнерские вознаграждения
     *
     * @param Bill $bill
     * @return bool
     */
    public function isHavePartnerRewards(Bill $bill)
    {
        return
            $bill->clientAccount->contract->isPartner()
            && BillLine::isAgentCommisionInLines($bill->lines);
    }

    /**
     * Публикуем все счета
     *
     * @return int
     * @throws \Exception
     */
    public function publishAllBills()
    {
        $count = 0;
        $query = Bill::find()->where([
            'is_show_in_lk' => 0
        ]);

        $transaction = Bill::getDb()->beginTransaction();
        try {
            foreach ($query->each() as $bill) {
                $bill->is_show_in_lk = 1;
                $bill->save();
                $count++;
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $count;

    }

    public function getInvoicePayments($billNo)
    {
        $billTable = \app\models\Bill::tableName();
        $paymentTable = \app\models\Payment::tableName();
        global $db;
        $query = "SELECT
  *,
  UNIX_TIMESTAMP(payment_date) AS payment_date_ts
FROM
  {$paymentTable} as pays
WHERE
  payment_no <> ''
  AND `sum` >= 0
  AND (
    bill_no = '" . $billNo . "'
    OR
    bill_vis_no = '" . $billNo . "'
  )
  AND
  1 IN (
    SELECT pays.payment_date
    BETWEEN
    ADDDATE(
        DATE_FORMAT(bills.bill_date, '%Y-%m-01'),
        INTERVAL -1 MONTH
    )
    AND
    ADDDATE(
        ADDDATE(
            DATE_FORMAT(bills.bill_date, '%Y-%m-01'),
            INTERVAL 1 MONTH
        ),
        INTERVAL -1 DAY
    )
    FROM
      {$billTable} as bills
    WHERE
      bills.bill_no = IFNULL(
          (
            SELECT np1.bill_no
            FROM {$paymentTable} np1
            WHERE np1.bill_no = '" . $billNo . "'
            GROUP BY np1.bill_no
          ),
          (
            SELECT np2.bill_vis_no
            FROM {$paymentTable} np2
            WHERE np2.bill_vis_no = '" . $billNo . "'
            GROUP BY np2.bill_vis_no
          )
      )
  ) /*or bill_no = '201109/0574'*/
            ";

        return Bill::getDb()->createCommand($query)->queryAll();
    }

    /**
     * Можно ли редактировать строки счета
     *
     * @param Bill $bill
     * @return bool
     */
    public function isEditable(Bill $bill): bool
    {
        if (!in_array($bill->operation_type_id, [OperationType::ID_PRICE, OperationType::ID_COST])) {
            return false;
        }

        $info = array_filter(Invoice::getInfo($bill->bill_no), function ($v) {
            return $v['status'] == 'invoice';
        });

        return !$info; // имеется хоть одна зарегистрированная с/ф
    }

}
