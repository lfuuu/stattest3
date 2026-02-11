<?php
/**
 * Универсальные услуги
 */

namespace app\modules\uu\controllers;

use app\classes\Assert;
use app\classes\BaseController;
use app\classes\Html;
use app\classes\traits\AddClientAccountFilterTraits;
use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\Task;
use app\models\Trouble;
use app\models\TroubleRoistat;
use app\models\UsageTrunkSettings;
use app\modules\nnp\models\NdcType;
use app\modules\uu\filter\AccountTariffFilter;
use app\modules\uu\forms\AccountTariffAddForm;
use app\modules\uu\forms\AccountTariffEditForm;
use app\modules\uu\forms\DisableForm;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\AccountTariffLog;
use app\modules\uu\models\AccountTariffLogAdd;
use app\modules\uu\models\AccountTariffResourceLog;
use app\modules\uu\models\ResourceModel;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\TariffPeriod;
use Exception;
use InvalidArgumentException;
use LogicException;
use Yii;
use yii\base\InvalidParamException;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\web\Response;

class AccountTariffController extends BaseController
{
    // Установить юзерские фильтры + добавить фильтр по клиенту, если он есть
    use AddClientAccountFilterTraits;

    /**
     * Права доступа
     *
     * @return array
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['index'],
                        'roles' => ['services_voip.r'],
                    ],
                    [
                        'allow' => true,
                        'actions' => ['new', 'edit', 'edit-voip', 'save-voip', 'cancel', 'resource-cancel', 'disable', 'select-numbers', 'close-numbers', 'disable-all'],
                        'roles' => ['services_voip.edit'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Дефолтный обработчик
     * Список
     *
     * @param int $serviceTypeId
     * @return string
     * @throws ModelValidationException
     * @throws \yii\db\Exception
     */
    public function actionIndex($serviceTypeId = null)
    {
        ini_set('memory_limit', '4G');

        $filterModel = new AccountTariffFilter($serviceTypeId);
        $this->_addClientAccountFilter($filterModel);

        if ($taskId = $this->_groupAction($filterModel)) {
            return $this->redirect(Yii::$app->request->url . '&task_id=' . $taskId);
        }

        return $this->render('index', [
            'isPersonalForm' => $serviceTypeId != ServiceType::ID_INFRASTRUCTURE && $this->_getCurrentClientAccountId() && !isset(ServiceType::$packages[$serviceTypeId]),
            'filterModel' => $filterModel,
        ]);
    }

    /**
     * Создать
     *
     * @param int $serviceTypeId
     * @return string|Response
     * @throws Exception
     */
    public function actionNew($serviceTypeId)
    {
        $clientAccount = $this->_getCurrentClientAccount();
        $cityId = $clientAccount ? $clientAccount->city->id : null;
        $channel = isset(Yii::$app->request->post()['channel']) ? Yii::$app->request->post()['channel'] : null;

        $formModel = new AccountTariffAddForm([
            'serviceTypeId' => $serviceTypeId,
            'clientAccountId' => $clientAccount ? $clientAccount->id : null,
            'cityId' => in_array($serviceTypeId, [ServiceType::ID_VOIP, ServiceType::ID_VOIP_PACKAGE_CALLS]) ? $cityId : null,
            'ndcTypeId' => $cityId ? NdcType::ID_GEOGRAPHIC : NdcType::ID_FREEPHONE,
        ]);

        if (!$formModel->isSaved) {
            return $this->render('edit', ['formModel' => $formModel]);
        }

        Yii::$app->session->setFlash('success', Yii::t('common', 'The object was created successfully'));
        if ($channel && array_key_exists($channel, TroubleRoistat::CHANNELS)) {
            Trouble::dao()->createTrouble(
                $clientAccount->id,
                Trouble::TYPE_CONNECT,
                Trouble::SUBTYPE_CONNECT,
                'Заявка на доп услугу',
                null,
                null,
                ['roistat_visit' => TroubleRoistat::CHANNELS[$channel]]
            );
        }

        if ($formModel->id) {
            // добавили одного - на его карточку
            return $this->redirect(['edit', 'id' => $formModel->id]);
        }

        // добавили мульти - на их список
        return $this->redirect(
            [
                'index',
                'serviceTypeId' => $serviceTypeId,
                'AccountTariffFilter[client_account_id]' => $formModel->clientAccountId,
            ]
        );
    }

    /**
     * Редактировать
     *
     * @param int $id
     * @return string|Response
     * @throws \yii\base\InvalidParamException
     */
    public function actionEdit($id)
    {
        $accountTariff = AccountTariff::findOne(['id' => $id]);

        $post = \Yii::$app->request->post();

        try {
            $formModel = new AccountTariffEditForm(['id' => $id, 'postData' => $post]);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());
            return $this->render('//layouts/empty', ['content' => '']);
        }

        // сообщение об ошибке
        if ($formModel->validateErrors) {
            Yii::$app->session->setFlash('error', $formModel->validateErrors);
        }

        if ($formModel->isSaved) {
            Yii::$app->session->setFlash('success', Yii::t('common', 'The object was saved successfully'));
            return $this->redirect(['edit', 'id' => $formModel->id]);
        }

        return $this->render('edit', ['formModel' => $formModel]);
    }

    /**
     * Отобразить аяксом форму смены тарифа телефонии
     *
     * @param int $id
     * @param int $cityId
     * @param int $ndcTypeId
     * @param int $serviceTypeId
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function actionEditVoip($id = null, $cityId = null, $ndcTypeId = null, $serviceTypeId = null)
    {
        $this->layout = '@app/views/layouts/minimal';

        try {
            $formModel = $id ?

                // редактировать телефонию или пакет телефонии
                new AccountTariffEditForm(['id' => $id]) :

                // добавить пакет телефонии
                new AccountTariffAddForm([
                    'serviceTypeId' => $serviceTypeId,
                    'clientAccountId' => $this->_getCurrentClientAccountId(),
                    'cityId' => $cityId ?: null,
                    'ndcTypeId' => $ndcTypeId ?: null,
                ]);

        } catch (\InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', $e->getMessage());

            return $this->render('//layouts/empty', ['content' => '']);
        }

        return $this->render('editVoip', ['formModel' => $formModel]);
    }

    /**
     * Сменить тариф или количество ресурса
     *
     * @return string|Response
     */
    public function actionSaveVoip()
    {
        $post = Yii::$app->request->post();

        if (isset(
            $post['AccountTariff'],
            $post['AccountTariff']['ids'],
            $post['AccountTariff']['tariff_period_id'],
            $post['AccountTariffLog'],
            $post['AccountTariffLog']['tariff_period_id'],
            $post['AccountTariffLog']['actual_from'],
            $post['accountTariffId'],
            $post['serviceTypeId'])
        ) {
            // Сменить тариф
            return $this->_saveVoipTariff();
        }

        if (isset(
            $post['AccountTariff'],
            $post['AccountTariff']['ids'],
            $post['AccountTariffResourceLog'])
        ) {
            // Сменить количество ресурса
            return $this->_saveVoipResource();
        }

        throw new InvalidArgumentException('Неправильные параметры');
    }

    /**
     * Сменить количество ресурса
     *
     * @return string|Response
     * @throws \yii\db\Exception
     */
    private function _saveVoipResource()
    {
        $transaction = \Yii::$app->db->beginTransaction();
        $serviceTypeId = ServiceType::ID_VOIP;
        try {

            $post = Yii::$app->request->post();

            $actualFrom = isset($post['AccountTariffResourceLog']['actual_from']) ? $post['AccountTariffResourceLog']['actual_from'] : null;

            // по всем услугам
            $accountTariffIds = (array)$post['AccountTariff']['ids'];
            foreach ($accountTariffIds as $accountTariffId) {

                $accountTariff = AccountTariff::findOne($accountTariffId);
                if (!$accountTariff) {
                    throw new InvalidArgumentException(Yii::t('common', 'Wrong ID ' . $accountTariffId));
                }

                $serviceTypeId = $accountTariff->service_type_id;

                // по всем ресурсам
                foreach ($post['AccountTariffResourceLog'] as $resourceId => $resourceValues) {

                    if (!is_numeric($resourceId)) {
                        continue;
                    }

                    $newResourceValue = $resourceValues['amount'];
                    $currentResourceValue = $accountTariff->getResourceValue($resourceId);

                    if ($newResourceValue == $currentResourceValue) {
                        // ресурс не изменился
                        continue;
                    }

                    $accountTariffResourceLog = new AccountTariffResourceLog();
                    $accountTariffResourceLog->account_tariff_id = $accountTariff->id;
                    $accountTariffResourceLog->amount = $newResourceValue;
                    $accountTariffResourceLog->resource_id = $resourceId;
                    $accountTariffResourceLog->actual_from = $actualFrom;
                    if (!$accountTariffResourceLog->save()) {
                        throw new ModelValidationException($accountTariffResourceLog);
                    }
                }
            }

            $transaction->commit();
            Yii::$app->session->setFlash('success', Yii::t('common', 'The object was saved successfully'));

        } catch (InvalidArgumentException $e) {
            $transaction->rollBack();
            Yii::$app->session->setFlash('error', $e->getMessage());

        } catch (LogicException $e) {
            $transaction->rollBack();
            Yii::$app->session->setFlash('error', $e->getMessage());

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);
            Yii::$app->session->setFlash('error', YII_DEBUG ? $e->getMessage() : Yii::t('common', 'Internal error'));
        }

        return $this->redirect(
            [
                'index',
                'serviceTypeId' => $serviceTypeId,
            ]
        );

    }

    /**
     * Сменить тариф
     *
     * @return string|Response
     * @throws \yii\db\Exception
     */
    private function _saveVoipTariff()
    {
        // загрузить параметры от юзера
        // здесь сильно разнородные данные, поэтому проще хардкорно валидировать, чем писать штатный обработчик
        $transaction = \Yii::$app->db->beginTransaction();
        $serviceTypeId = ServiceType::ID_VOIP;
        try {
            $post = Yii::$app->request->post();

            if (!($actualFromTimestamp = strtotime($post['AccountTariffLog']['actual_from']))) {
                throw new InvalidArgumentException('Неправильные параметры');
            }

            $serviceTypeId = (int)$post['serviceTypeId'];

            // id первой услуги (тарифа или пакета) или 0 (добавление пакета)
            $accountTariffFirstId = (int)$post['accountTariffId'];
            if ($accountTariffFirstId) {
                $accountTariffFirst = AccountTariff::findOne(['id' => $accountTariffFirstId]);
                if (!$accountTariffFirst) {
                    throw new InvalidArgumentException(Yii::t('common', 'Wrong first ID ' . $accountTariffFirstId));
                }

                $accountTariffFirstHash = $accountTariffFirst->getHash();
            } else {
                $accountTariffFirstHash = null;
            }

            $tariffPeriodIdNew = (isset($post['closeTariff']) ? null : (int)$post['AccountTariffLog']['tariff_period_id']);

            $accountTariffIds = (array)$post['AccountTariff']['ids'];
            foreach ($accountTariffIds as $accountTariffId) {

                // найти базовую услугу или пакета
                $packageServiceTypeId = $tariffPeriodIdNew ? TariffPeriod::findOne(['id' => $tariffPeriodIdNew])->tariff->service_type_id : null;
                $accountTariff = $this->findAccountTariff($accountTariffId, $accountTariffFirstHash, $packageServiceTypeId);

                // создать новую услугу, но не менять существующую
                // если тариф меняется с текущего момента, она пересчитается триггером; если с будущего - по крону
                if ($accountTariff->isNewRecord) {
                    $accountTariff->tariff_period_id = $tariffPeriodIdNew;
                    $accountTariff->tariff_period_utc = DateTimeZoneHelper::getUtcDateTime()
                        ->format(DateTimeZoneHelper::DATETIME_FORMAT);
                    if (!$accountTariff->save()) {
                        throw new ModelValidationException($accountTariff);
                    }
                }

                // записать в лог тарифа
                $accountTariffLog = new AccountTariffLog;
                $accountTariffLog->account_tariff_id = $accountTariff->id;
                $accountTariffLog->tariff_period_id = $tariffPeriodIdNew;
                $accountTariffLog->actual_from = date(DateTimeZoneHelper::DATE_FORMAT, $actualFromTimestamp);
                if (!$accountTariffLog->save()) {
                    throw new ModelValidationException($accountTariffLog);
                }


                if ($accountTariff->tariff_period_id && in_array($accountTariff->service_type_id, [ServiceType::ID_TRUNK_PACKAGE_ORIG, ServiceType::ID_TRUNK_PACKAGE_TERM])) {
                    // дополнительно добавить этот пакет транка в маршрутизацию "логического транка"
                    //
                    $type = ($accountTariff->service_type_id == ServiceType::ID_TRUNK_PACKAGE_ORIG) ?
                        UsageTrunkSettings::TYPE_ORIGINATION :
                        UsageTrunkSettings::TYPE_TERMINATION;

                    $usageTrunkSettings = UsageTrunkSettings::findOne([
                        'usage_id' => $accountTariff->prev_account_tariff_id,
                        'package_id' => $accountTariff->tariffPeriod->tariff_id,
                        'account_package_id' => $accountTariff->id,
                        'type' => $type,
                    ]);

                    if ($tariffPeriodIdNew) {
                        if (!$usageTrunkSettings) {
                            $maxOrder = UsageTrunkSettings::find()
                                ->andWhere([
                                    'usage_id' => $accountTariff->prev_account_tariff_id,
                                    'type' => $type,
                                ])
                                ->max('`order`');

                            $usageTrunkSettings = new UsageTrunkSettings;
                            $usageTrunkSettings->account_package_id = $accountTariff->id;
                            $usageTrunkSettings->usage_id = $accountTariff->prev_account_tariff_id;
                            $usageTrunkSettings->package_id = $accountTariff->tariffPeriod->tariff_id;
                            $usageTrunkSettings->activation_dt = $accountTariffLog->actual_from_utc;
                            $usageTrunkSettings->type = $type;
                            $usageTrunkSettings->order = $maxOrder + 1;
                            if (!$usageTrunkSettings->save()) {
                                throw new ModelValidationException($usageTrunkSettings);
                            }
                        }
                    } else {
                        if ($usageTrunkSettings) {
                            $usageTrunkSettings->expire_dt = $accountTariffLog->actual_from_utc;
                            if (!$usageTrunkSettings->save()) {
                                throw new ModelValidationException($usageTrunkSettings);
                            }
                        }
                    }
                }
            }

            $transaction->commit();
            Yii::$app->session->setFlash('success', Yii::t('common', 'The object was saved successfully'));

        } catch (InvalidArgumentException $e) {
            $transaction->rollBack();
            Yii::$app->session->setFlash('error', $e->getMessage());

        } catch (LogicException $e) {
            $transaction->rollBack();
            Yii::$app->session->setFlash('error', $e->getMessage());

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);
            Yii::$app->session->setFlash('error', YII_DEBUG ? $e->getMessage() : Yii::t('common', 'Internal error'));
        }

        return $this->redirect(
            [
                'index',
                'serviceTypeId' => $serviceTypeId,
            ]
        );
    }

    /**
     * Отменить последнюю смену тарифа
     *
     * @param string $accountTariffHash хэш услуги
     * @return string|Response
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    public function actionCancel($accountTariffHash = null)
    {
        $id = 0;
        $serviceTypeId = null;
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            if (!$accountTariffHash) {
                throw new InvalidArgumentException('Неправильные параметры (accountTariffHash)');
            }

            $ids = Yii::$app->request->get('ids');
            $id = (int)Yii::$app->request->get('id');
            if (!$ids || !is_array($ids) || !count($ids)) {
                if (!$id) {
                    throw new InvalidArgumentException('Неправильные параметры');
                }

                $ids = [$id];
            }

            foreach ($ids as $accountTariffId) {

                // найти базовую услугу или пакета
                $accountTariff = $this->findAccountTariff($accountTariffId, $accountTariffHash, null);
                $serviceTypeId = $accountTariff->service_type_id;

                // лог тарифов
                $accountTariffLogs = $accountTariff->accountTariffLogs;

                // отменяемый тариф
                /** @var AccountTariffLog $accountTariffLogCancelled */
                $accountTariffLogCancelled = array_shift($accountTariffLogs);
                if (!$accountTariff->isLogCancelable()) {
                    throw new LogicException('Нельзя отменить уже примененный тариф');
                }

                // отменить (удалить) последний тариф
                if (!$accountTariffLogCancelled->delete()) {
                    throw new ModelValidationException($accountTariffLogCancelled);
                }

                if (!count($accountTariffLogs)) {

                    // услуга еще даже не начинала действовать, текущего тарифа нет - удалить услугу полностью
                    if (!$accountTariff->delete()) {
                        throw new ModelValidationException($accountTariff);
                    }

                    // редиректить на список, а не карточку
                    $id = null;

                } else {

                    // предпоследний тариф становится текущим
                    /** @var AccountTariffLog $accountTariffLogActual */
                    $accountTariffLogActual = array_shift($accountTariffLogs);

                    // у услуги сменить кэш тарифа
                    $accountTariff->tariff_period_id = $accountTariffLogActual->tariff_period_id;
                    $accountTariff->tariff_period_utc = DateTimeZoneHelper::getUtcDateTime()
                        ->format(DateTimeZoneHelper::DATETIME_FORMAT);
                    if (!$accountTariff->save()) {
                        throw new ModelValidationException($accountTariff);
                    }
                }
            }

            $transaction->commit();
            Yii::$app->session->setFlash('success', 'Смена тарифа успешно отменена');

        } catch (InvalidArgumentException $e) {
            $transaction->rollBack();
            Yii::$app->session->setFlash('error', $e->getMessage());

        } catch (LogicException $e) {
            $transaction->rollBack();
            Yii::$app->session->setFlash('error', $e->getMessage());

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);
            Yii::$app->session->setFlash('error', YII_DEBUG ? $e->getMessage() : Yii::t('common', 'Internal error'));
        }

        if ($id) {
            // редактировали одну услугу - на ее карточку
            return $this->redirect(
                [
                    'edit',
                    'id' => $id,
                ]
            );
        }

        // редактировали много услуг одновременно - на их список
        if (array_key_exists($serviceTypeId, ServiceType::$packages)) {
            $serviceTypeId = ServiceType::$packages[$serviceTypeId];
        }

        return $this->redirect(
            [
                'index',
                'serviceTypeId' => $serviceTypeId,
            ]
        );
    }

    /**
     * Отменить последнюю смену количества ресурса
     *
     * @param int[] $ids
     * @param int $resourceId
     * @return string|Response
     * @throws \Throwable
     * @throws \yii\db\Exception
     */
    public function actionResourceCancel(array $ids, $resourceId)
    {
        $serviceTypeId = null;

        /** @var AccountTariff[] $accountTariffs */
        $accountTariffs = AccountTariff::findAll(['id' => $ids]);
        if (!$accountTariffs) {
            throw new InvalidParamException('Услуга не найдена');
        }

        /** @var ResourceModel $resource */
        $resource = ResourceModel::findOne(['id' => $resourceId]);
        if (!$resource) {
            throw new InvalidParamException('Ресурс не найден');
        }

        $transaction = \Yii::$app->db->beginTransaction();
        try {

            foreach ($accountTariffs as $accountTariff) {

                $serviceTypeId = $accountTariff->service_type_id;

                if (!$accountTariff->isResourceCancelable($resource)) {
                    throw new InvalidParamException('Ресурс невозможно отменить');
                }

                $accountTariff->cancelResource($resource);
            }

            $transaction->commit();
            Yii::$app->session->setFlash('success', 'Смена количества ресурса успешно отменена');

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error($e);
            Yii::$app->session->setFlash('error', YII_DEBUG ? $e->getMessage() : Yii::t('common', 'Internal error'));
        }

        return $this->redirect(
            [
                'index',
                'serviceTypeId' => $serviceTypeId,
            ]
        );
    }

    /**
     * Ввести номера для отключения
     * @return string
     * @throws Exception
     */
    public function actionDisable()
    {
        if (!($clientAccountId = $this->_getCurrentClientAccountId())) {
            throw new Exception('Выберите клиента');
        }

        $numbers = \Yii::$app->request->post('numbers', []);

        $numbersArr = $numbers
            ? array_filter(array_map(function ($item) {
                $number = trim($item);
                $len = strlen($number);
                if (!is_numeric($number) || $len < 7 || $len > 15) {
                    return null;
                }
                return $number;
            }, preg_split("/(,|\s)/", $numbers)))
            : null;

        if (!$numbersArr) {
            return $this->render('disableNumbers');
        }


        $numbers = $numbersArr;

        if (!$numbers) {
            return $this->redirect(['disable']);
        }
        $utc = (new Query)->select(new Expression('UTC_TIMESTAMP()'))->scalar();
        $info = [];
        foreach ($numbers as $number) {
            /** @var AccountTariff $accountTariff */
            $accountTariff = AccountTariff::find()
                ->where(['voip_number' => $number])
                ->andWhere(['client_account_id' => $clientAccountId])
                ->andWhere(['NOT', ['tariff_period_id' => null]])
                ->with('accountTariffLogs')
                ->one();
            if (!$accountTariff) {
                $info[$number] = 'Не найден';
                continue;
            }
            if (!$accountTariff->isEditable()) {
                $info[$number] = 'Услуга нередактируемая';
                continue;
            }
            /** @var AccountTariffLog $lastLog */
            $lastLog = array_shift($accountTariff->accountTariffLogs);
            if (($lastLog && !$lastLog->tariff_period_id)) {
                $info[$number] = 'Уже закрыт';
                continue;
            }

            /** @var AccountTariffLog $penultimateLog */
            $penultimateLog = array_shift($accountTariff->accountTariffLogs);
            if ($lastLog
                && $penultimateLog
                && $lastLog->actual_from_utc > $utc
                && $penultimateLog->tariff_period_id
                && $lastLog->tariff_period_id != $penultimateLog->tariff_period_id) {
                $info[$number] = 'Установлен на смену тарифа';
            } else {
                $info[$number] = 'OK';
            }
        }
        return $this->render('selectNumbers', ['info' => $info]);
    }

    /**
     * Отключить номера
     */
    public function actionCloseNumbers()
    {
        $clientAccount = $this->_getCurrentClientAccount();
        $clientAccountId = $clientAccount ? $clientAccount->id : null;
        if (!$clientAccountId) {
            throw new Exception('Клиент не найден');
        }
        $post = Yii::$app->request->post();
        $numbers = isset($post['numbers']) ? $post['numbers'] : null;
        $date = isset($post['date'])
            ? DateTimeZoneHelper::setDateTime($post['date'], DateTimeZoneHelper::DATETIME_FORMAT)
            : null;
        if (!$numbers || !$date) {
            throw new InvalidArgumentException('Отсутствует список номеров или не выбрана дата');
        }

        $accountTariffQuery = AccountTariff::find()
            ->where([
                'voip_number' => $numbers,
                'client_account_id' => $clientAccountId
            ])
            ->andWhere(['NOT', ['tariff_period_id' => null]]);

        $transaction = Yii::$app->db->beginTransaction();
        $message = '';
        foreach ($accountTariffQuery->each() as $accountTariff) {
            /** @var AccountTariff $accountTariff */
            if (!$accountTariff->isEditable()) {
                $message .= $accountTariff->voip_number . ': ' . 'услуга нередактируемая' . '<br>';
                continue;
            }
            try {
                $accountTariff->setClosed($date);
            } catch (Exception $e) {
                $message .= $accountTariff->voip_number . ': ' . $e->getMessage() . '<br>';
            }
        }
        if ($message) {
            Yii::$app->session->setFlash('error', $message);
            $transaction->rollBack();
        } else {
            $transaction->commit();
            Yii::$app->session->setFlash('success', 'Выбранные номера успешно отключены');
        }
        return $this->redirect('disable');
    }

    /**
     * Найти базовую услугу или пакета
     *
     * @param int $accountTariffId
     * @param string $accountTariffFirstHash хэш первой услуги (тарифа или пакета) или null (добавление пакета)
     * @param int $packageServiceTypeId
     * @return AccountTariff
     */
    protected function findAccountTariff($accountTariffId, $accountTariffFirstHash, $packageServiceTypeId)
    {
        $accountTariffId = (int)$accountTariffId;
        if (!$accountTariffId) {
            throw new InvalidArgumentException(Yii::t('common', 'Wrong ID'));
        }

        $accountTariff = AccountTariff::findOne($accountTariffId);
        if (!$accountTariff) {
            throw new InvalidArgumentException(Yii::t('common', 'Wrong ID ' . $accountTariffId));
        }

        if (!$accountTariffFirstHash) {
            // создание нового пакета
            $accountTariffPackage = new AccountTariff;
            $accountTariffPackage->service_type_id = $packageServiceTypeId;
            $accountTariffPackage->prev_account_tariff_id = $accountTariff->id;
            $accountTariffPackage->client_account_id = $accountTariff->client_account_id;
            $accountTariffPackage->region_id = $accountTariff->region_id;
            $accountTariffPackage->city_id = $accountTariff->city_id;
            return $accountTariffPackage;
        }

        if ($accountTariff->getHash() == $accountTariffFirstHash) {
            // базовый тариф телефонии или транка
            return $accountTariff;
        }

        foreach ($accountTariff->nextAccountTariffs as $accountTariffPackage) {
            if ($accountTariffPackage->getHash() == $accountTariffFirstHash) {
                // базовый тариф телефонии или транка
                return $accountTariffPackage;
            }
        }

        unset($accountTariffPackage);

        throw new InvalidArgumentException(sprintf('Услуга %d с хэшем %s не найдена', $accountTariffId, $accountTariffFirstHash));
    }

    /**
     * Групповые действия для фильтрованной выборки модели AccountTariff
     *
     * @param AccountTariffFilter $filterModel
     * @return boolean
     * @throws ModelValidationException
     * @throws \yii\db\Exception
     */
    private function _groupAction($filterModel)
    {
        $post = Yii::$app->request->post();

        if ($filterModel->tariff_period_id <= 0 || !$post) {
            return false;
        }

        if (
            !isset($post['AccountTariffLog']['actual_from'])
            && !isset($post['AccountTariffLogAdd']['tariff_period_id'])
            && !isset($post['AccountTariffLogAdd']['actual_from'])
        ) {
            return false;
        }

        $get = Yii::$app->request->get();
        $get['AccountTariffFilter']['service_type_id'] = $get['serviceTypeId'];

        $task = new Task();
        $task->filter_class = get_class($filterModel);
        $task->filter_data_json = json_encode($get);
        unset($post['_csrf']);
        $task->params_json = json_encode($post);
        $task->user_id = \Yii::$app->user->getId();
        if (!$task->save()) {
            throw new ModelValidationException($task);
        }

        return $task->id;
    }

    public function actionDisableAll()
    {
        $this->layout = '@app/views/layouts/minimal';
        $clientAccount = $this->_getCurrentClientAccount();

        $form = new DisableForm();

        if ($form->load(Yii::$app->request->post())) {
            if ($form->validate() && $form->go()) {
                Yii::$app->session->addFlash('success', 'Поставленно на отключние услуг: ' . $form->serviceCount);
            }

            return $this->redirect(Yii::$app->request->referrer ?: $clientAccount->getUrl());
        }

        $form->clientAccountId = $clientAccount->id;
        $code = $form->generateCode();

        return $this->render('disableAll', ['model' => $form, 'clientAccount' => $clientAccount, 'code' => $code]);
    }
}