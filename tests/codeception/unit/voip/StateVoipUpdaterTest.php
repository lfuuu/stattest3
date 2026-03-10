<?php

namespace tests\codeception\unit\voip;

use app\classes\voip\StateVoipUpdater;
use app\models\voip\StateServiceVoip;
use tests\codeception\unit\_TestCase;
use Yii;

/**
 * Тесты StateVoipUpdater: полное и точечное обновление state_service_voip
 */
class StateVoipUpdaterTest extends _TestCase
{
    /** @var \yii\db\Transaction */
    private $_transaction;

    private $table;

    /** id вставленных тестовых записей для cleanup */
    private $insertedClientIds = [];
    private $insertedAtIds = [];
    private $insertedUsageVoipIds = [];

    protected function setUp()
    {
        parent::setUp();
        $this->_transaction = Yii::$app->db->beginTransaction();
        $this->table = StateServiceVoip::tableName();

        Yii::$app->db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();
    }

    protected function tearDown()
    {
        Yii::$app->db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        $this->_transaction->rollBack();
        parent::tearDown();
    }

    /**
     * Вставить тестового клиента
     */
    private function insertClient($id, $client)
    {
        Yii::$app->db->createCommand()->insert('clients', [
            'id' => $id,
            'client' => $client,
        ])->execute();
        $this->insertedClientIds[] = $id;
    }

    /**
     * Вставить тестовый voip_number
     */
    private function insertVoipNumber($number, $region = 77)
    {
        Yii::$app->db->createCommand()->insert('voip_numbers', [
            'number' => $number,
            'region' => $region,
        ])->execute();
    }

    /**
     * Вставить запись uu_account_tariff (VoIP)
     */
    private function insertAccountTariff($id, $clientAccountId, $voipNumber, $deviceAddress = '', $isVerified = null)
    {
        Yii::$app->db->createCommand()->insert('uu_account_tariff', [
            'id' => $id,
            'client_account_id' => $clientAccountId,
            'service_type_id' => 2,
            'voip_number' => $voipNumber,
            'device_address' => $deviceAddress,
            'is_verified' => $isVerified,
        ])->execute();
        $this->insertedAtIds[] = $id;
    }

    /**
     * Вставить запись uu_account_tariff_log
     */
    private function insertAccountTariffLog($accountTariffId, $actualFromUtc, $tariffPeriodId = null)
    {
        Yii::$app->db->createCommand()->insert('uu_account_tariff_log', [
            'account_tariff_id' => $accountTariffId,
            'actual_from_utc' => $actualFromUtc,
            'tariff_period_id' => $tariffPeriodId,
        ])->execute();
    }

    /**
     * Вставить запись uu_account_tariff_resource_log
     */
    private function insertResourceLog($accountTariffId, $amount)
    {
        Yii::$app->db->createCommand()->insert('uu_account_tariff_resource_log', [
            'account_tariff_id' => $accountTariffId,
            'resource_id' => 7,
            'amount' => $amount,
        ])->execute();
    }

    /**
     * Полное обновление: данные из uu_account_tariff попадают в state_service_voip
     */
    public function testFullUpdateFromAccountTariff()
    {
        $clientId = 999990;
        $atId = 999990;
        $number = '74950000001';

        $this->insertClient($clientId, 'test_svu_client_1');
        $this->insertVoipNumber($number, 77);
        $this->insertAccountTariff($atId, $clientId, $number, 'test address');

        // activation_dt: MIN(actual_from_utc) WHERE tariff_period_id IS NOT NULL
        $this->insertAccountTariffLog($atId, '2025-01-10 10:00:00', 1);
        $this->insertAccountTariffLog($atId, '2025-01-15 12:00:00', 2);

        // expire_dt: MAX(actual_from_utc) WHERE tariff_period_id IS NULL
        $this->insertAccountTariffLog($atId, '2025-06-01 00:00:00', null);

        // lines_amount: MAX(amount) WHERE resource_id=7
        $this->insertResourceLog($atId, 5);
        $this->insertResourceLog($atId, 10);

        ob_start();
        StateVoipUpdater::me()->update();
        ob_end_clean();

        $row = StateServiceVoip::findOne(['usage_id' => $atId]);

        $this->assertNotNull($row, 'Запись должна появиться в state_service_voip');
        $this->assertEquals($clientId, $row->client_id);
        $this->assertEquals($number, $row->e164);
        $this->assertEquals(77, $row->region);
        $this->assertEquals('2025-01-10 10:00:00', $row->activation_dt);
        $this->assertEquals(10, $row->lines_amount);
        $this->assertEquals('test address', $row->device_address);
    }

    /**
     * Точечное обновление: обновляется только указанный account_tariff_id
     */
    public function testSingleUpdate()
    {
        $clientId = 999991;
        $atId1 = 999991;
        $atId2 = 999992;
        $number1 = '74950000002';
        $number2 = '74950000003';

        $this->insertClient($clientId, 'test_svu_client_2');
        $this->insertVoipNumber($number1, 50);
        $this->insertVoipNumber($number2, 77);
        $this->insertAccountTariff($atId1, $clientId, $number1);
        $this->insertAccountTariff($atId2, $clientId, $number2);
        $this->insertAccountTariffLog($atId1, '2025-03-01 00:00:00', 1);
        $this->insertAccountTariffLog($atId2, '2025-04-01 00:00:00', 1);

        // точечное обновление только atId1
        ob_start();
        StateVoipUpdater::me()->update($atId1);
        ob_end_clean();

        $row1 = StateServiceVoip::findOne(['usage_id' => $atId1]);
        $row2 = StateServiceVoip::findOne(['usage_id' => $atId2]);

        $this->assertNotNull($row1, 'Запись atId1 должна появиться');
        $this->assertNull($row2, 'Запись atId2 не должна появиться при точечном обновлении');
    }

    /**
     * Повторный вызов update() не дублирует SQL (сброс $sql)
     */
    public function testRepeatedUpdateNoDuplicateSql()
    {
        $clientId = 999993;
        $atId = 999993;
        $number = '74950000004';

        $this->insertClient($clientId, 'test_svu_client_3');
        $this->insertVoipNumber($number, 77);
        $this->insertAccountTariff($atId, $clientId, $number);
        $this->insertAccountTariffLog($atId, '2025-05-01 00:00:00', 1);

        ob_start();
        StateVoipUpdater::me()->update($atId);
        StateVoipUpdater::me()->update($atId);
        ob_end_clean();

        $count = (int) Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {$this->table} WHERE usage_id = :id",
            [':id' => $atId]
        )->queryScalar();

        $this->assertEquals(1, $count, 'Повторный вызов не должен дублировать записи');
    }

    /**
     * deleteMissing: удаление записей, отсутствующих в источнике
     */
    public function testDeleteMissing()
    {
        $fakeUsageId = 999994;

        // вставить "осиротевшую" запись в state_service_voip
        Yii::$app->db->createCommand()->insert($this->table, [
            'usage_id' => $fakeUsageId,
            'client_id' => 0,
            'e164' => '70000000000',
            'region' => 0,
            'actual_from' => '2025-01-01',
            'lines_amount' => 0,
        ])->execute();

        $this->assertNotNull(StateServiceVoip::findOne(['usage_id' => $fakeUsageId]));

        ob_start();
        StateVoipUpdater::me()->update();
        ob_end_clean();

        $this->assertNull(
            StateServiceVoip::findOne(['usage_id' => $fakeUsageId]),
            'Осиротевшая запись должна быть удалена при полном обновлении'
        );
    }

    /**
     * makeChanges: обновление изменённых данных
     */
    public function testMakeChangesUpdatesModifiedFields()
    {
        $clientId = 999995;
        $atId = 999995;
        $number = '74950000005';

        $this->insertClient($clientId, 'test_svu_client_5');
        $this->insertVoipNumber($number, 50);
        $this->insertAccountTariff($atId, $clientId, $number, 'address v1');
        $this->insertAccountTariffLog($atId, '2025-02-01 00:00:00', 1);
        $this->insertResourceLog($atId, 3);

        // первичное заполнение
        ob_start();
        StateVoipUpdater::me()->update($atId);
        ob_end_clean();

        $row = StateServiceVoip::findOne(['usage_id' => $atId]);
        $this->assertEquals(3, $row->lines_amount);
        $this->assertEquals('address v1', $row->device_address);

        // изменить данные в источнике
        Yii::$app->db->createCommand()->update('uu_account_tariff', [
            'device_address' => 'address v2',
        ], ['id' => $atId])->execute();
        $this->insertResourceLog($atId, 20);

        // повторное обновление
        ob_start();
        StateVoipUpdater::me()->update($atId);
        ob_end_clean();

        $row = StateServiceVoip::findOne(['usage_id' => $atId]);
        $this->assertEquals(20, $row->lines_amount, 'lines_amount должен обновиться');
        $this->assertEquals('address v2', $row->device_address, 'device_address должен обновиться');
    }

    /**
     * expire_dt > 3000-01-01 обнуляется в NULL
     */
    public function testExpireDateFarFutureBecomesNull()
    {
        $clientId = 999996;
        $atId = 999996;
        $number = '74950000006';

        $this->insertClient($clientId, 'test_svu_client_6');
        $this->insertVoipNumber($number, 77);
        $this->insertAccountTariff($atId, $clientId, $number);
        $this->insertAccountTariffLog($atId, '2025-01-01 00:00:00', 1);
        // expire_dt далеко в будущем
        $this->insertAccountTariffLog($atId, '3001-01-01 00:00:00', null);

        ob_start();
        StateVoipUpdater::me()->update($atId);
        ob_end_clean();

        $row = StateServiceVoip::findOne(['usage_id' => $atId]);
        $this->assertNotNull($row);
        $this->assertNull($row->expire_dt, 'expire_dt > 3000-01-01 должен стать NULL');
    }

    /**
     * Точечное deleteMissing: при удалении услуги запись удаляется из state_service_voip
     */
    public function testSingleDeleteMissing()
    {
        $clientId = 999997;
        $atId = 999997;
        $number = '74950000007';

        $this->insertClient($clientId, 'test_svu_client_7');
        $this->insertVoipNumber($number, 77);
        $this->insertAccountTariff($atId, $clientId, $number);
        $this->insertAccountTariffLog($atId, '2025-01-01 00:00:00', 1);

        // заполнить
        ob_start();
        StateVoipUpdater::me()->update($atId);
        ob_end_clean();

        $this->assertNotNull(StateServiceVoip::findOne(['usage_id' => $atId]));

        // удалить услугу из источника
        Yii::$app->db->createCommand()->delete('uu_account_tariff', ['id' => $atId])->execute();

        // точечное обновление должно удалить запись
        ob_start();
        StateVoipUpdater::me()->update($atId);
        ob_end_clean();

        $this->assertNull(
            StateServiceVoip::findOne(['usage_id' => $atId]),
            'Удалённая услуга должна быть удалена из state_service_voip при точечном обновлении'
        );
    }
}
