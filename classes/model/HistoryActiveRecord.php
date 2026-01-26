<?php

namespace app\classes\model;

use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\models\HistoryVersion;
use app\models\User;
use Yii;
use yii\base\InvalidParamException;

/**
 * Class HistoryActiveRecord
 *
 * @property int $id
 */
class HistoryActiveRecord extends ActiveRecord
{
    public $isHistoryVersioning = true;

    private
        $_historyVersionStoredDate = null,
        $_historyVersionRequestedDate = null;


    public
        $attributesProtectedForVersioning = [], // Свойства модели которые не должны обновляться при загрузки версионной модели
        $attributesAllowedForVersioning = [];   // Свойства модели, которые должны обновляться при загрузки версионной модели


    private static $_cache = [];
    private static $_cacheHolder = [];
    private static $_historyVersionCache = [];

    /**
     * @return null|string Дата сохранения версии
     */
    public function getHistoryVersionStoredDate()
    {
        return $this->_historyVersionStoredDate;
    }

    /**
     * @param string $date Формат: Y-m-d
     */
    public function setHistoryVersionStoredDate($date)
    {
        $this->_historyVersionStoredDate = $date;
    }

    /**
     * @return null|string Дата запроса версии
     */
    public function getHistoryVersionRequestedDate()
    {
        return $this->_historyVersionRequestedDate;
    }

    /**
     * @param string $date Формат: Y-m-d
     */
    public function setHistoryVersionRequestedDate($date)
    {
        $this->_historyVersionRequestedDate = $date;
    }

    /**
     * @param bool $runValidation
     * @param array $attributeNames
     * @return bool
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        $result = $this->_isNeedHistoryVersionSaveModel() ?
            parent::save($runValidation, $attributeNames) :
            true;

        if ($this->isHistoryVersioning && $result) {
            $this->_createHistoryVersion();
        }

        return $result;
    }

    /**
     * @return string[]
     */
    public function getDateList()
    {
        $months = [
            'января',
            'февраля',
            'марта',
            'апреля',
            'мая',
            'июня',
            'июля',
            'августа',
            'сенября',
            'октября',
            'ноября',
            'декабря'
        ];

        $prevMonth = strtotime('first day of previous month');
        $nextMonth = strtotime('first day of next month');

        return ($this->_historyVersionStoredDate ? [$this->_historyVersionStoredDate => $this->_historyVersionStoredDate] : []) +
            [
                date(DateTimeZoneHelper::DATE_FORMAT) => 'Текущую дату',
                date('Y-m-01', $prevMonth) => 'С 1го ' . $months[date('m', $prevMonth) - 1],
                date('Y-m-01') => 'С 1го ' . $months[date('m') - 1],
                date('Y-m-01', $nextMonth) => 'С 1го ' . $months[date('m', $nextMonth) - 1],
                '' => 'выбраную дату'
            ];
    }

    /**
     * @return bool
     */
    private function _isNeedHistoryVersionSaveModel()
    {
        if ($this->isNewRecord || !$this->getHistoryVersionStoredDate()) {
            return true;
        } else {
            $date = $this->getHistoryVersionStoredDate();
            if (strtotime($date) < time() && !HistoryVersion::find()
                    ->andWhere([
                        'model' => $this->getClassName(),
                        'model_id' => $this->id
                    ])
                    ->andWhere(['<=', 'date', date(DateTimeZoneHelper::DATE_FORMAT)])
                    ->andWhere(['>', 'date', $date])
                    ->count()
            ) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * @inheritdoc
     * @throws \app\exceptions\ModelValidationException
     */
    private function _createHistoryVersion()
    {
        if ($this->isNewRecord || !$this->getHistoryVersionStoredDate()) {
            $date = date(DateTimeZoneHelper::DATE_FORMAT);
        } else {
            $date = $this->getHistoryVersionStoredDate();
        }

        $model = HistoryVersion::findOne([
            'model' => $this->getClassName(),
            'model_id' => $this->primaryKey,
            'date' => $date,
        ]);

        $userId = Yii::$app->user->getId();
        if (!$model) {
            $queryData = [
                'model' => $this->getClassName(),
                'model_id' => $this->primaryKey,
                'date' => $date,
                'data_json' => json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                'user_id' => $userId ?: User::SYSTEM_USER_ID,
            ];

            $model = new HistoryVersion($queryData);
        }elseif ($userId && $model->user_id != $userId && $userId != User::SYSTEM_USER_ID) {
            $model->user_id = $userId;
        }

        $model->data_json = json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
        if (!$model->save()) {
            throw new ModelValidationException($model);
        }
    }


    /**
     * Загружает в модель данные на заданную дату
     *
     * @param null|string $date
     * @return HistoryActiveRecord
     * @internal param HistoryActiveRecord $model
     */
    public function loadVersionOnDate($date = null)
    {
        if (!$date) {
            return $this;
        }

        $modelName = $this->getClassName();
        $cacheKey = $modelName . ':' . $this->primaryKey . ':' . $date;

        // Проверяем кэш истории версий
        if (isset(self::$_historyVersionCache[$cacheKey])) {
            $cached = self::$_historyVersionCache[$cacheKey];
            if ($cached['historyModel']) {
                $this->fillHistoryDataInModel($cached['decodedData']);
                $this->setHistoryVersionStoredDate($cached['storedDate']);
            }
            $this->setHistoryVersionRequestedDate($date);
            return $this;
        }

        $historyModel = HistoryVersion::find()
            ->andWhere(['model' => $modelName])
            ->andWhere(['model_id' => $this->primaryKey])
            ->andWhere(['<=', 'date', $date])
            ->orderBy(['date' => SORT_DESC])
            ->limit(1)
            ->one();

        if ($historyModel) {
            $decodedData = $this->_historyModelJsonDecode($historyModel);
            $this->fillHistoryDataInModel($decodedData);
            $this->setHistoryVersionStoredDate($historyModel['date']);
            self::$_historyVersionCache[$cacheKey] = [
                'historyModel' => true,
                'decodedData' => $decodedData,
                'storedDate' => $historyModel['date'],
            ];
        } else {
            // если нет истории на вызыванную дату, то берем первое сохранение версии
            $historyModel = HistoryVersion::find()
                ->andWhere(['model' => $modelName])
                ->andWhere(['model_id' => $this->primaryKey])
                ->andWhere(['>', 'date', $date])
                ->orderBy(['date' => SORT_ASC])
                ->limit(1)
                ->one();

            if ($historyModel) {
                $decodedData = $this->_historyModelJsonDecode($historyModel);
                $this->fillHistoryDataInModel($decodedData);
                $this->setHistoryVersionStoredDate($date);
                self::$_historyVersionCache[$cacheKey] = [
                    'historyModel' => true,
                    'decodedData' => $decodedData,
                    'storedDate' => $date,
                ];
            } else {
                self::$_historyVersionCache[$cacheKey] = ['historyModel' => false];
            }
        }

        $this->setHistoryVersionRequestedDate($date);

        return $this;
    }

    /**
     * Декодирует Json и выкидывает ошибку с данными о неверной модели
     *
     * @param HistoryVersion $historyModel
     * @return mixed
     */
    private function _historyModelJsonDecode(HistoryVersion $historyModel)
    {
        $json = json_decode($historyModel->data_json, true);

        if (!$json) {
            throw new InvalidParamException(sprintf('Error decode json (%s:%s, %s)',
                $historyModel->model,
                $historyModel->model_id,
                $historyModel->date
            ));
        }

        return $json;
    }

    /**
     * Заполняет текущую модель данными из истории
     *
     * @param array $versionData
     * @internal param HistoryActiveRecord $model
     */
    public function fillHistoryDataInModel(array $versionData)
    {
        if ($this instanceof HistoryActiveRecord) {

            // модели со списком атрибутов доступных для версионирования
            if ($this->attributesAllowedForVersioning) {
                $newVersionData = [];

                foreach ($this->attributesAllowedForVersioning as $key) {
                    if (isset($versionData[$key])) {
                        $newVersionData[$key] = $versionData[$key];
                    }
                }

                $versionData = $newVersionData;

                // модели с атрибутами, которые надо исключить из версионирования
            } elseif ($this->attributesProtectedForVersioning) {
                $protectedAttributes = array_flip($this->attributesProtectedForVersioning);

                foreach ($this as $key => $value) {
                    if (isset($protectedAttributes[$key])) {
                        unset($versionData[$key]);
                    }
                }
            }
        }

        $this->setAttributes($versionData, false);
    }

    /**
     * Получение кешированной исторической модели
     *
     * @param string $className
     * @param int $id
     * @param string $date
     * @return HistoryActiveRecord
     */
    protected function getCachedHistoryModel($className, $id, $date = null, $relatedHolderObject = null)
    {
        $models = $this->getCachedHistoryModels($className, 'id', $id, $date, $relatedHolderObject);

        if (!$models) {
            return null;
        }

        return reset($models);
    }

    /**
     * Получение кешированных исторических моделей
     *
     * @param string $className
     * @param string $field
     * @param int $id
     * @param string $date
     * @return HistoryActiveRecord[]
     */
    protected function getCachedHistoryModels($className, $field, $id, $date = null, $relatedHolderObject = null)
    {
        $date = (string)($date ?: ($this->getHistoryVersionRequestedDate() ?: null));

        if (!isset(self::$_cache[$className][$date][$field][$id])) {
            $models = $className::findAll([$field => $id]);
            /** @var self $model */
            foreach ($models as $model) {

                if ($model && $date) {
                    $model->loadVersionOnDate($date);
                }
            }

            self::$_cache[$className][$date][$field][$id] = $models;

            if ($relatedHolderObject) {
                self::$_cacheHolder[get_class($relatedHolderObject)][$relatedHolderObject->id][$className][$date][$field] = $id;
            }
        }

        return self::$_cache[$className][$date][$field][$id];
    }

    /**
     * Обновление данных модели
     *
     * @return bool
     */
    public function refresh()
    {
        if (!$this->_historyVersionStoredDate) {
            $this->_resetCachedModel(self::class, $this->id, $this->_historyVersionRequestedDate);
            return parent::refresh();
        }

        return false; // не обновлять, если модель взята из истории
    }

    /**
     * Сброс модели, хранящейся в кеше
     *
     * @param string $className
     * @param int $id
     * @param string $date
     * @param string $field
     */
    private function _resetCachedModel($className, $id, $date, $field = 'id')
    {
        $date = (string)$date;

        unset(self::$_cache[$className][$date][$field][$id]);

        if (!isset(self::$_cacheHolder[$className][$id])) {
            return;
        }

        foreach (self::$_cacheHolder[$className][$id] as $relatedClassName => $dates) {
            foreach ($dates as $relatedDate => $fields) {
                foreach ($fields as $relatedField => $relatedId) {
                    unset(self::$_cache[$relatedClassName][$relatedDate][$relatedField][$relatedId]);
                }
            }
        }

        unset(self::$_cacheHolder[$className][$id]);
    }

    /**
     * Очистка кэша истории версий
     * Используется для освобождения памяти при batch-операциях
     */
    public static function clearHistoryVersionCache()
    {
        self::$_historyVersionCache = [];
        self::$_cache = [];
        self::$_cacheHolder = [];
    }
}