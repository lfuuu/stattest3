<?php

namespace tests\codeception\unit\payments;

use app\dao\BillDao;
use app\helpers\DateTimeZoneHelper;
use app\models\Bill;
use app\models\BillLine;
use app\models\ClientAccount;
use app\models\Country;
use app\models\Invoice;
use app\models\Organization;
use app\modules\uu\models\Bill as UuBill;
use tests\codeception\unit\_TestCase;


/**
 * Тесты генерации УПД для prepaid2 (is_postpaid=2)
 *
 * - TYPE_2 собирает проводки из текущего и предыдущего автоматического счёта
 * - TYPE_GOOD исключается из генерации
 * - Дедупликация по uu_account_entry_id (приоритет текущему счёту)
 */
class Prepaid2InvoiceTest extends _TestCase
{
    private $_transaction = null;

    /** @var ClientAccount */
    private $_account = null;

    function setUp()
    {
        parent::setUp();
        $this->_transaction = \Yii::$app->db->beginTransaction();
        Invoice::deleteAll();

        $this->_account = $this->createTestAccount(ClientAccount::PAYMENT_TYPE_PREPAID_2);
    }

    function tearDown()
    {
        parent::tearDown();
        $this->_transaction->rollBack();
    }

    /**
     * Создание тестового ЛС напрямую через SQL (без форм и behaviors)
     * @param int $isPostpaid
     * @return ClientAccount
     */
    private function createTestAccount($isPostpaid = ClientAccount::PAYMENT_TYPE_POSTPAID)
    {
        $db = \Yii::$app->db;
        $rand = mt_rand(100000, 999999);

        $db->createCommand()->insert('client_super', [
            'name' => 'test_super_' . $rand,
        ])->execute();
        $superId = $db->getLastInsertID();

        $db->createCommand()->insert('client_contragent', [
            'super_id' => $superId,
            'name' => 'test_contragent_' . $rand,
            'name_full' => 'test_contragent_' . $rand,
            'legal_type' => 'legal',
            'country_id' => Country::RUSSIA,
            'lang_code' => 'ru',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
        $contragentId = $db->getLastInsertID();

        $db->createCommand()->insert('client_contract', [
            'super_id' => $superId,
            'contragent_id' => $contragentId,
            'organization_id' => Organization::MCN_TELECOM,
            'number' => 'TEST-' . $rand,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
        $contractId = $db->getLastInsertID();

        $db->createCommand()->insert('clients', [
            'super_id' => $superId,
            'contract_id' => $contractId,
            'client' => 'test' . $rand,
            'country_id' => Country::RUSSIA,
            'currency' => 'RUB',
            'status' => 'income',
            'is_postpaid' => $isPostpaid,
            'price_include_vat' => 1,
            'nal' => 0,
            'account_version' => ClientAccount::VERSION_BILLER_UNIVERSAL,
        ])->execute();
        $accountId = $db->getLastInsertID();

        return ClientAccount::findOne(['id' => $accountId]);
    }

    /**
     * @param string $dateModifier strtotime-совместимая строка
     * @param bool $isAuto установить uu_bill_id (автоматический счёт)
     * @return Bill
     */
    private function makeBill($dateModifier, $isAuto = false)
    {
        $firstDay = (new \DateTimeImmutable($dateModifier))->modify('first day of this month');
        $lastDay = $firstDay->modify('last day of this month');

        $bill = Bill::dao()->createBill($this->_account);
        $bill->bill_date = $firstDay->format(DateTimeZoneHelper::DATE_FORMAT);

        if ($isAuto) {
            $uuBill = new UuBill();
            $uuBill->client_account_id = $this->_account->id;
            $uuBill->date = $firstDay->format(DateTimeZoneHelper::DATE_FORMAT);
            $uuBill->price = 0;
            $uuBill->save(false);
            $bill->uu_bill_id = $uuBill->id;
        }

        $bill->save(false);

        // Базовая проводка за текущий месяц счёта (для TYPE_1)
        $bill->addLine('base item', 1, 100, BillLine::LINE_TYPE_SERVICE, $firstDay, $lastDay);
        Bill::dao()->recalcBill($bill);

        return $bill;
    }

    /**
     * @param Bill $bill
     * @param string $item наименование проводки
     * @param float $price
     * @param string $dateFrom Y-m-d
     * @param int|null $entryId uu_account_entry_id
     * @param string $type
     * @return BillLine
     */
    private function addLine(Bill $bill, $item, $price, $dateFrom, $entryId = null, $type = BillLine::LINE_TYPE_SERVICE)
    {
        $dateFromObj = new \DateTimeImmutable($dateFrom);
        $dateToObj = $dateFromObj->modify('last day of this month');

        $line = $bill->addLine($item, 1, $price, $type, $dateFromObj, $dateToObj);

        if ($entryId) {
            $db = \Yii::$app->db;
            $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();
            $db->createCommand(
                "UPDATE `newbill_lines` SET `uu_account_entry_id`=:eid WHERE `pk`=:pk",
                [':eid' => $entryId, ':pk' => $line->pk]
            )->execute();
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
            $line->refresh();
        }

        Bill::dao()->recalcBill($bill);

        return $line;
    }

    /**
     * Создание автоматического счёта без базовой проводки
     * @param string $dateModifier
     * @param ClientAccount|null $account если null, используется $_account
     * @return Bill
     */
    private function createAutoBill($dateModifier, ClientAccount $account = null)
    {
        $savedAccount = $this->_account;
        if ($account) {
            $this->_account = $account;
        }

        $firstDay = (new \DateTimeImmutable($dateModifier))->modify('first day of this month');

        $bill = Bill::dao()->createBill($this->_account);
        $bill->bill_date = $firstDay->format(DateTimeZoneHelper::DATE_FORMAT);

        $uuBill = new UuBill();
        $uuBill->client_account_id = $this->_account->id;
        $uuBill->date = $firstDay->format(DateTimeZoneHelper::DATE_FORMAT);
        $uuBill->price = 0;
        $uuBill->save(false);
        $bill->uu_bill_id = $uuBill->id;

        $bill->save(false);

        $this->_account = $savedAccount;

        return $bill;
    }

    /**
     * @param BillLine[]|array[] $lines
     * @return int[]
     */
    private function getEntryIds($lines)
    {
        $entryIds = [];
        foreach ($lines as $line) {
            $eid = is_array($line) ? ($line['uu_account_entry_id'] ?? null) : $line->uu_account_entry_id;
            if ($eid) {
                $entryIds[] = (int)$eid;
            }
        }
        return $entryIds;
    }

    /**
     * TYPE_2 включает проводки из предыдущего автоматического счёта
     */
    public function testType2IncludesPrevBillLines()
    {
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        // Счёт за прошлый месяц с проводкой за prev month (от currentBill)
        $prevBill = $this->makeBill('-1 month', true);
        $this->addLine($prevBill, 'Ресурсы VoIP', 500, $oneMonthAgo, 101);

        // Счёт за текущий месяц с проводкой за прошлый
        $currentBill = $this->makeBill('now', true);
        $this->addLine($currentBill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 102);

        $lines = BillDao::getLinesByTypeId($currentBill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertContains(101, $entryIds, 'Проводка из предыдущего счёта (entry 101)');
        $this->assertContains(102, $entryIds, 'Проводка из текущего счёта (entry 102)');
    }

    /**
     * Дедупликация: одинаковый uu_account_entry_id в обоих счетах — берётся из текущего
     */
    public function testType2DeduplicatesByEntryId()
    {
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        // Предыдущий счёт: entry 201 с ценой 500
        $prevBill = $this->makeBill('-1 month', true);
        $this->addLine($prevBill, 'Ресурсы VoIP', 500, $oneMonthAgo, 201);

        // Текущий счёт: entry 201 с ценой 700 (дубль) + entry 202
        $currentBill = $this->makeBill('now', true);
        $this->addLine($currentBill, 'Ресурсы VoIP', 700, $oneMonthAgo, 201);
        $this->addLine($currentBill, 'Ресурсы SIP', 300, $oneMonthAgo, 202);

        $lines = BillDao::getLinesByTypeId($currentBill, Invoice::TYPE_2);

        $entryPrices = [];
        foreach ($lines as $line) {
            $eid = is_array($line) ? ($line['uu_account_entry_id'] ?? null) : $line->uu_account_entry_id;
            $price = is_array($line) ? $line['price'] : $line->price;
            if ($eid) {
                $entryPrices[(int)$eid] = (float)$price;
            }
        }

        $this->assertArrayHasKey(201, $entryPrices);
        $this->assertArrayHasKey(202, $entryPrices);
        $this->assertEquals(700, $entryPrices[201], 'Entry 201 из текущего счёта (price=700)');
    }

    /**
     * TYPE_GOOD исключается для prepaid2 в generateInvoices
     */
    public function testTypeGoodExcludedForPrepaid2()
    {
        $twoMonthsAgo = (new \DateTimeImmutable('-2 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('-1 month', true);

        // Товарная проводка
        $bill->addLine('test good', 1, 500, BillLine::LINE_TYPE_GOOD);

        // Сервисная проводка за позапрошлый месяц (для TYPE_2)
        $this->addLine($bill, 'Ресурсы VoIP', 1000, $twoMonthsAgo);

        Bill::dao()->recalcBill($bill);

        $bill->generateInvoices();

        $goodInvoice = Invoice::find()
            ->where(['bill_no' => $bill->bill_no, 'type_id' => Invoice::TYPE_GOOD])
            ->one();
        $this->assertNull($goodInvoice, 'TYPE_GOOD не должен создаваться для prepaid2');

        $type2Invoice = Invoice::find()
            ->where(['bill_no' => $bill->bill_no, 'type_id' => Invoice::TYPE_2])
            ->one();
        $this->assertNotNull($type2Invoice, 'TYPE_2 должен создаваться');
    }

    /**
     * Ручной счёт (uu_bill_id=null) — слияние не происходит
     */
    public function testManualBillDoesNotMergePrevLines()
    {
        $twoMonthsAgo = (new \DateTimeImmutable('-2 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $prevBill = $this->makeBill('-1 month', true);
        $this->addLine($prevBill, 'Ресурсы VoIP', 500, $twoMonthsAgo, 301);

        // Ручной счёт (без uu_bill_id)
        $currentBill = $this->makeBill('now', false);
        $this->addLine($currentBill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 302);

        $lines = BillDao::getLinesByTypeId($currentBill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertNotContains(301, $entryIds, 'Проводка из предыдущего счёта НЕ включена');
        $this->assertContains(302, $entryIds, 'Проводка из текущего счёта включена');
    }

    /**
     * Для постоплаты (is_postpaid=1) слияние не происходит
     */
    public function testPostpaidDoesNotMergePrevLines()
    {
        // Создаём отдельный ЛС с постоплатой
        $postpaidAccount = $this->createTestAccount(ClientAccount::PAYMENT_TYPE_POSTPAID);
        $savedAccount = $this->_account;
        $this->_account = $postpaidAccount;

        $twoMonthsAgo = (new \DateTimeImmutable('-2 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $prevBill = $this->makeBill('-1 month', true);
        $this->addLine($prevBill, 'Ресурсы VoIP', 500, $twoMonthsAgo, 401);

        $currentBill = $this->makeBill('now', true);
        $this->addLine($currentBill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 402);

        $lines = BillDao::getLinesByTypeId($currentBill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertNotContains(401, $entryIds, 'Для постоплаты слияние не происходит');
        $this->assertContains(402, $entryIds);

        $this->_account = $savedAccount;
    }

    /**
     * TYPE_1 возвращает пустой массив для prepaid2
     */
    public function testType1ReturnsEmptyForPrepaid2()
    {
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('now', true);
        $this->addLine($bill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 501);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_1);

        $this->assertEmpty($lines, 'TYPE_1 для prepaid2 возвращает пустой массив');
    }

    /**
     * TYPE_GOOD возвращает пустой массив для prepaid2 (прямой вызов getLinesByTypeId)
     */
    public function testTypeGoodReturnsEmptyForPrepaid2()
    {
        $bill = $this->makeBill('now', true);
        $bill->addLine('test good', 1, 500, BillLine::LINE_TYPE_GOOD);
        Bill::dao()->recalcBill($bill);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_GOOD);

        $this->assertEmpty($lines, 'TYPE_GOOD для prepaid2 возвращает пустой массив');
    }

    /**
     * TYPE_2: проводки старше prev month отсекаются
     */
    public function testType2ExcludesOldLines()
    {
        $threeMonthsAgo = (new \DateTimeImmutable('-3 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('now', true);
        $this->addLine($bill, 'Ресурсы старые', 500, $threeMonthsAgo, 601);
        $this->addLine($bill, 'Ресурсы текущие', 1000, $oneMonthAgo, 602);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertNotContains(601, $entryIds, 'Проводка за 3 месяца назад отсечена');
        $this->assertContains(602, $entryIds, 'Проводка за prev month включена');
    }

    /**
     * TYPE_2: только проводки за prev month, более старые отсекаются
     */
    public function testType2IncludesOnlyPrevMonthLines()
    {
        $twoMonthsAgo = (new \DateTimeImmutable('-2 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('now', true);
        $this->addLine($bill, 'Ресурсы старые', 500, $twoMonthsAgo, 701);
        $this->addLine($bill, 'Ресурсы текущие', 1000, $oneMonthAgo, 702);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertNotContains(701, $entryIds, 'Проводка за 2 месяца назад отсечена');
        $this->assertContains(702, $entryIds, 'Проводка за prev month включена');
    }

    /**
     * Ручной счёт prepaid2: фильтрация по месяцу не применяется, все прошлые проводки включаются
     */
    public function testType2ManualBillIncludesAllPastLines()
    {
        $threeMonthsAgo = (new \DateTimeImmutable('-3 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
        $twoMonthsAgo = (new \DateTimeImmutable('-2 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        // Ручной счёт (uu_bill_id=null)
        $bill = $this->makeBill('now', false);
        $this->addLine($bill, 'Ресурсы -3м', 300, $threeMonthsAgo, 801);
        $this->addLine($bill, 'Ресурсы -2м', 500, $twoMonthsAgo, 802);
        $this->addLine($bill, 'Ресурсы -1м', 1000, $oneMonthAgo, 803);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertContains(801, $entryIds, 'Проводка за 3 месяца назад включена (ручной счёт)');
        $this->assertContains(802, $entryIds, 'Проводка за 2 месяца назад включена (ручной счёт)');
        $this->assertContains(803, $entryIds, 'Проводка за prev month включена (ручной счёт)');
    }

    /**
     * Авто-счёт без предыдущего счёта — метод работает корректно
     */
    public function testType2NoPrevBillStillWorks()
    {
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('now', true);
        $this->addLine($bill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 901);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertContains(901, $entryIds, 'Проводка из текущего счёта при отсутствии предыдущего');
    }

    /**
     * Проводка с DATE_DEFAULT не попадает в TYPE_2
     */
    public function testType2DateDefaultLineExcluded()
    {
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('now', true);
        $this->addLine($bill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 1001);

        // Проводка с DATE_DEFAULT
        $dateDefaultLine = $bill->addLine('Ручная проводка', 1, 500, BillLine::LINE_TYPE_SERVICE);
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();
        $db->createCommand(
            "UPDATE `newbill_lines` SET `date_from`=:df, `date_to`=:dt, `uu_account_entry_id`=:eid WHERE `pk`=:pk",
            [':df' => BillLine::DATE_DEFAULT, ':dt' => BillLine::DATE_DEFAULT, ':eid' => 1002, ':pk' => $dateDefaultLine->pk]
        )->execute();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        $dateDefaultLine->refresh();
        Bill::dao()->recalcBill($bill);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_2);

        $entryIds = $this->getEntryIds($lines);

        $this->assertNotContains(1002, $entryIds, 'Проводка с DATE_DEFAULT не попадает в TYPE_2');
        $this->assertContains(1001, $entryIds, 'Обычная проводка за prev month включена');
    }

    // ========================================================================
    // Реалистичные сценарии: 3 счёта (янв/фев/мар), абонентка + ресурсы
    // Один ЛС, смена типа через history_version
    // ========================================================================

    /**
     * Сохранение версии ЛС на дату через встроенный механизм HistoryActiveRecord
     * @param ClientAccount $account
     * @param string $date Y-m-d
     * @param int $isPostpaid
     */
    private function setAccountVersion(ClientAccount $account, $date, $isPostpaid)
    {
        $account->setHistoryVersionStoredDate($date);
        $account->is_postpaid = $isPostpaid;
        $account->save(false);
        ClientAccount::clearHistoryVersionCache();
    }

    /**
     * Тест 1: Prepaid с января. Каждый счёт генерирует 2 с/ф:
     * TYPE_1 (абонентка за месяц счёта) и TYPE_2 (ресурсы за предыдущий месяц)
     */
    public function testPrepaidThreeMonthsBilling()
    {
        $account = $this->createTestAccount(ClientAccount::PAYMENT_TYPE_PREPAID);

        $dec = '2025-12-01';
        $jan = '2026-01-01';
        $feb = '2026-02-01';
        $mar = '2026-03-01';

        $this->setAccountVersion($account, $jan, ClientAccount::PAYMENT_TYPE_PREPAID);

        // Январский счёт: абонентка за январь + ресурсы за декабрь
        $janBill = $this->createAutoBill('2026-01-01', $account);
        $this->_account = $account;
        $this->addLine($janBill, 'Абонентская плата', 100, $jan, 5001);
        $this->addLine($janBill, 'Ресурсы VoIP', 200, $dec, 5002);

        // Февральский счёт: абонентка за февраль + ресурсы за январь
        $febBill = $this->createAutoBill('2026-02-01', $account);
        $this->addLine($febBill, 'Абонентская плата', 100, $feb, 5003);
        $this->addLine($febBill, 'Ресурсы VoIP', 200, $jan, 5004);

        // Мартовский счёт: абонентка за март + ресурсы за февраль
        $marBill = $this->createAutoBill('2026-03-01', $account);
        $this->addLine($marBill, 'Абонентская плата', 100, $mar, 5005);
        $this->addLine($marBill, 'Ресурсы VoIP', 200, $feb, 5006);

        // Январь: TYPE_1 = абонентка, TYPE_2 = ресурсы
        $janType1 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_1);
        $janType2 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_2);
        $this->assertContains(5001, $this->getEntryIds($janType1), 'Янв TYPE_1: абонентка');
        $this->assertContains(5002, $this->getEntryIds($janType2), 'Янв TYPE_2: ресурсы');

        // Февраль
        $febType1 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_1);
        $febType2 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_2);
        $this->assertContains(5003, $this->getEntryIds($febType1), 'Фев TYPE_1: абонентка');
        $this->assertContains(5004, $this->getEntryIds($febType2), 'Фев TYPE_2: ресурсы');

        // Март
        $marType1 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_1);
        $marType2 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_2);
        $this->assertContains(5005, $this->getEntryIds($marType1), 'Мар TYPE_1: абонентка');
        $this->assertContains(5006, $this->getEntryIds($marType2), 'Мар TYPE_2: ресурсы');
    }

    /**
     * Тест 2: Один ЛС. Prepaid с января, Prepaid2 с февраля (через history_version).
     * Янв — 2 с/ф (абонентка + ресурсы).
     * Фев — с/ф TYPE_2 с ресурсами из февральского счёта.
     * Мар — с/ф TYPE_2 с ресурсами из мартовского + абоненткой из февральского (merge).
     */
    public function testPrepaidToPrepaid2Transition()
    {
        $account = $this->createTestAccount(ClientAccount::PAYMENT_TYPE_PREPAID);

        $dec = '2025-12-01';
        $jan = '2026-01-01';
        $feb = '2026-02-01';
        $mar = '2026-03-01';

        // С января — prepaid, с февраля — prepaid2
        $this->setAccountVersion($account, $jan, ClientAccount::PAYMENT_TYPE_PREPAID);
        $this->setAccountVersion($account, $feb, ClientAccount::PAYMENT_TYPE_PREPAID_2);

        $this->_account = $account;

        // Январский счёт: абонентка + ресурсы
        $janBill = $this->createAutoBill('2026-01-01', $account);
        $this->addLine($janBill, 'Абонентская плата', 100, $jan, 6001);
        $this->addLine($janBill, 'Ресурсы VoIP', 200, $dec, 6002);

        // Февральский счёт: абонентка + ресурсы
        $febBill = $this->createAutoBill('2026-02-01', $account);
        $this->addLine($febBill, 'Абонентская плата', 100, $feb, 6003);
        $this->addLine($febBill, 'Ресурсы VoIP', 200, $jan, 6004);

        // Мартовский счёт: абонентка + ресурсы
        $marBill = $this->createAutoBill('2026-03-01', $account);
        $this->addLine($marBill, 'Абонентская плата', 100, $mar, 6005);
        $this->addLine($marBill, 'Ресурсы VoIP', 200, $feb, 6006);

        // Январь (prepaid): TYPE_1 = абонентка, TYPE_2 = ресурсы
        $janType1 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_1);
        $janType2 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_2);
        $this->assertContains(6001, $this->getEntryIds($janType1), 'Янв TYPE_1: абонентка');
        $this->assertContains(6002, $this->getEntryIds($janType2), 'Янв TYPE_2: ресурсы');

        // Февраль (prepaid2): TYPE_1 пустой, TYPE_2 = ресурсы за январь
        $febType1 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_1);
        $febType2 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_2);
        $this->assertEmpty($febType1, 'Фев TYPE_1: пустой для prepaid2');
        $this->assertContains(6004, $this->getEntryIds($febType2), 'Фев TYPE_2: ресурсы за январь');

        // Март (prepaid2): TYPE_2 = ресурсы из мартовского + абонентка из февральского (merge)
        $marType1 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_1);
        $marType2 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_2);
        $this->assertEmpty($marType1, 'Мар TYPE_1: пустой для prepaid2');

        $marType2Entries = $this->getEntryIds($marType2);
        $this->assertContains(6006, $marType2Entries, 'Мар TYPE_2: ресурсы за февраль из мартовского счёта');
        $this->assertContains(6003, $marType2Entries, 'Мар TYPE_2: абонентка из февральского счёта (merge)');
        $this->assertNotContains(6004, $marType2Entries, 'Мар TYPE_2: ресурсы за январь из фев. счёта отсечены');
    }

    /**
     * Тест 3: Один ЛС. Prepaid2 с января, Prepaid с февраля (через history_version).
     * Янв — с/ф TYPE_2 только ресурсы (абонентка не попадает: dateFrom=billDate).
     * Фев — TYPE_2 (абонентка янв + ресурсы янв), TYPE_1 (абонентка фев).
     * Мар — TYPE_2 (ресурсы фев), TYPE_1 (абонентка мар).
     */
    public function testPrepaid2ToPrepaidTransition()
    {
        $account = $this->createTestAccount(ClientAccount::PAYMENT_TYPE_PREPAID_2);

        $dec = '2025-12-01';
        $jan = '2026-01-01';
        $feb = '2026-02-01';
        $mar = '2026-03-01';

        // С января — prepaid2, с февраля — prepaid
        $this->setAccountVersion($account, $jan, ClientAccount::PAYMENT_TYPE_PREPAID_2);
        $this->setAccountVersion($account, $feb, ClientAccount::PAYMENT_TYPE_PREPAID);

        $this->_account = $account;

        // Январский счёт: абонентка + ресурсы
        $janBill = $this->createAutoBill('2026-01-01', $account);
        $this->addLine($janBill, 'Абонентская плата', 100, $jan, 7001);
        $this->addLine($janBill, 'Ресурсы VoIP', 200, $dec, 7002);

        // Февральский счёт: абонентка янв (перенос) + абонентка фев + ресурсы янв
        $febBill = $this->createAutoBill('2026-02-01', $account);
        $this->addLine($febBill, 'Абонентская плата', 100, $feb, 7003);
        $this->addLine($febBill, 'Ресурсы VoIP', 200, $jan, 7004);

        // Мартовский счёт: абонентка + ресурсы
        $marBill = $this->createAutoBill('2026-03-01', $account);
        $this->addLine($marBill, 'Абонентская плата', 100, $mar, 7005);
        $this->addLine($marBill, 'Ресурсы VoIP', 200, $feb, 7006);

        // Январь (prepaid2): TYPE_1 пустой, TYPE_2 = только ресурсы
        $janType1 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_1);
        $janType2 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_2);
        $this->assertEmpty($janType1, 'Янв TYPE_1: пустой для prepaid2');
        $janType2Entries = $this->getEntryIds($janType2);
        $this->assertNotContains(7001, $janType2Entries, 'Янв TYPE_2: абонентка не попадает (dateFrom=billDate)');
        $this->assertContains(7002, $janType2Entries, 'Янв TYPE_2: ресурсы за декабрь');

        // Февраль (prepaid): TYPE_1 = абонентка фев, TYPE_2 = абонентка янв + ресурсы янв
        $febType1 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_1);
        $febType2 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_2);

        $febType1Entries = $this->getEntryIds($febType1);
        $this->assertContains(7003, $febType1Entries, 'Фев TYPE_1: абонентка за февраль');
        $this->assertNotContains(7001, $febType1Entries, 'Янв TYPE_2: абонентка за январь');
        $this->assertNotContains(7004, $febType1Entries, 'Янв TYPE_2: абонентка за январь');

        $febType2Entries = $this->getEntryIds($febType2);
        $this->assertNotContains(7003, $febType2Entries, 'Фев TYPE_2: абонентка за январь');
        $this->assertContains(7004, $febType2Entries, 'Фев TYPE_2: ресурсы за январь');
        $this->assertContains(7001, $febType2Entries, 'Янв TYPE_1: абонентка перенос');

        // Март (prepaid): TYPE_1 = абонентка мар, TYPE_2 = ресурсы фев
        $marType1 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_1);
        $marType2 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_2);

        $this->assertContains(7005, $this->getEntryIds($marType1), 'Мар TYPE_1: абонентка за март');
        $this->assertContains(7006, $this->getEntryIds($marType2), 'Мар TYPE_2: ресурсы за февраль');
    }

    /**
     * Тест 4: Один ЛС. Prepaid дек/янв, Prepaid2 фев, Prepaid мар.
     * 4 автоматических счёта (дек/янв/фев/мар), каждый с абоненткой + ресурсами.
     * Фев (prepaid2): абонентка не попадает в TYPE_1, подтягивается через merge.
     * Мар (prepaid, prev=prepaid2): абонентка фев подтягивается через merge.
     */
    public function testPrepaidToPrepaid2AndBackTransition()
    {
        $account = $this->createTestAccount(ClientAccount::PAYMENT_TYPE_PREPAID);

        $nov = '2025-11-01';
        $dec = '2025-12-01';
        $jan = '2026-01-01';
        $feb = '2026-02-01';
        $mar = '2026-03-01';

        $this->setAccountVersion($account, $dec, ClientAccount::PAYMENT_TYPE_PREPAID);
        $this->setAccountVersion($account, $feb, ClientAccount::PAYMENT_TYPE_PREPAID_2);
        $this->setAccountVersion($account, $mar, ClientAccount::PAYMENT_TYPE_PREPAID);

        $this->_account = $account;

        // Декабрьский счёт: абонентка дек + ресурсы ноя
        $decBill = $this->createAutoBill('2025-12-01', $account);
        $this->addLine($decBill, 'Абонентская плата', 100, $dec, 8001);
        $this->addLine($decBill, 'Ресурсы VoIP', 200, $nov, 8002);

        // Январский счёт: абонентка янв + ресурсы дек
        $janBill = $this->createAutoBill('2026-01-01', $account);
        $this->addLine($janBill, 'Абонентская плата', 100, $jan, 8003);
        $this->addLine($janBill, 'Ресурсы VoIP', 200, $dec, 8004);

        // Февральский счёт: абонентка фев + ресурсы янв
        $febBill = $this->createAutoBill('2026-02-01', $account);
        $this->addLine($febBill, 'Абонентская плата', 100, $feb, 8005);
        $this->addLine($febBill, 'Ресурсы VoIP', 200, $jan, 8006);

        // Мартовский счёт: абонентка мар + ресурсы фев
        $marBill = $this->createAutoBill('2026-03-01', $account);
        $this->addLine($marBill, 'Абонентская плата', 100, $mar, 8007);
        $this->addLine($marBill, 'Ресурсы VoIP', 200, $feb, 8008);

        // Декабрь (prepaid): TYPE_1 = абонентка, TYPE_2 = ресурсы
        $decType1 = BillDao::getLinesByTypeId($decBill, Invoice::TYPE_1);
        $decType2 = BillDao::getLinesByTypeId($decBill, Invoice::TYPE_2);
        $this->assertContains(8001, $this->getEntryIds($decType1), 'Дек TYPE_1: абонентка');
        $this->assertContains(8002, $this->getEntryIds($decType2), 'Дек TYPE_2: ресурсы');

        // Январь (prepaid): TYPE_1 = абонентка, TYPE_2 = ресурсы
        $janType1 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_1);
        $janType2 = BillDao::getLinesByTypeId($janBill, Invoice::TYPE_2);
        $this->assertContains(8003, $this->getEntryIds($janType1), 'Янв TYPE_1: абонентка');
        $this->assertContains(8004, $this->getEntryIds($janType2), 'Янв TYPE_2: ресурсы');

        // Февраль (prepaid2): TYPE_1 пустой, TYPE_2 = ресурсы янв + абонентка янв (merge из янв счёта)
        $febType1 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_1);
        $febType2 = BillDao::getLinesByTypeId($febBill, Invoice::TYPE_2);
        $this->assertEmpty($febType1, 'Фев TYPE_1: пустой для prepaid2');
        $febType2Entries = $this->getEntryIds($febType2);
        $this->assertContains(8006, $febType2Entries, 'Фев TYPE_2: ресурсы за январь');
        $this->assertContains(8003, $febType2Entries, 'Фев TYPE_2: абонентка за январь (merge из янв счёта)');
        $this->assertNotContains(8005, $febType2Entries, 'Фев TYPE_2: абонентка за февраль не попадает');
        $this->assertNotContains(8004, $febType2Entries, 'Фев TYPE_2: ресурсы за декабрь отсечены');

        // Март (prepaid, prev=prepaid2): TYPE_1 = абонентка, TYPE_2 = ресурсы + абонентка фев (merge)
        $marType1 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_1);
        $marType2 = BillDao::getLinesByTypeId($marBill, Invoice::TYPE_2);
        $this->assertContains(8007, $this->getEntryIds($marType1), 'Мар TYPE_1: абонентка за март');
        $marType2Entries = $this->getEntryIds($marType2);
        $this->assertContains(8008, $marType2Entries, 'Мар TYPE_2: ресурсы за февраль');
        $this->assertContains(8005, $marType2Entries, 'Мар TYPE_2: абонентка за февраль (merge из фев счёта)');
    }

    /**
     * TYPE_PREPAID возвращает все строки для prepaid2 (ранний возврат до проверки prepaid2)
     */
    public function testTypePrepaidReturnsAllLinesForPrepaid2()
    {
        $oneMonthAgo = (new \DateTimeImmutable('-1 month'))->modify('first day of this month')
            ->format(DateTimeZoneHelper::DATE_FORMAT);

        $bill = $this->makeBill('now', true);
        $this->addLine($bill, 'Ресурсы VoIP', 1000, $oneMonthAgo, 1101);
        $bill->addLine('test good', 1, 500, BillLine::LINE_TYPE_GOOD);
        Bill::dao()->recalcBill($bill);

        $lines = BillDao::getLinesByTypeId($bill, Invoice::TYPE_PREPAID);

        // TYPE_PREPAID возвращает все billLines без фильтрации
        $this->assertNotEmpty($lines, 'TYPE_PREPAID возвращает непустой результат для prepaid2');

        $totalLines = count($bill->lines);
        $this->assertEquals($totalLines, count($lines), 'TYPE_PREPAID возвращает все строки счёта');
    }
}
