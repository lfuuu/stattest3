<?php

namespace app\exceptions;

use yii\base\Model;
use yii\db\ActiveRecord;
use yii\web\HttpException;

class ModelValidationException extends HttpException
{
    const STATUS_CODE = 400;

    private $_errors = [];

    private $_model = null;

    /**
     * @param Model $model
     * @param int $errorCode код ошибки для API
     * @param int $statusCode http-код для браузера
     */
    public function __construct(Model $model, $errorCode = 0, $statusCode = ModelValidationException::STATUS_CODE)
    {
        $this->_model = $model;
        $this->_errors = $model->getErrors();
        $pk = $model instanceof ActiveRecord ? print_r($model->getPrimaryKey(), true) : '';
        parent::__construct($statusCode, 'Error. ' . get_class($model) . ' ' . $pk . ': ' . implode(' ', $model->getFirstErrors()), $errorCode);
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Получение модели
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->_model;
    }
}