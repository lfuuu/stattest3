<?php

namespace app\modules\sbisTenzor\forms\document;

use app\exceptions\ModelValidationException;
use app\helpers\DateTimeZoneHelper;
use app\modules\sbisTenzor\classes\SBISDocumentStatus;
use app\modules\sbisTenzor\models\SBISDocument;
use app\modules\sbisTenzor\models\SBISGeneratedDraft;
use app\modules\sbisTenzor\services\DocumentRecreateService;
use yii\base\InvalidArgumentException;

class ViewForm extends \app\classes\Form
{
    /** @var int */
    protected $clientId;

    /** @var SBISDocument */
    protected $document;

    /**
     * View form constructor.
     * @param int $id
     */
    public function __construct($id = 0)
    {
        parent::__construct();

        if (!( $this->document = SBISDocument::findOne(['id' => $id]) )) {
            throw new InvalidArgumentException('Неверный пакет документов');
        }

        $this->clientId = $this->document->client_account_id;
    }

    /**
     * Получить документ
     *
     * @return SBISDocument
     */
    public function getDocument()
    {
        return $this->document;
    }

    /**
     * Показывать ли кнопку Отмена
     *
     * @return bool
     */
    public function getShowCancelButton()
    {
        return $this->document->state == SBISDocumentStatus::CREATED;
    }

    /**
     * Показывать ли кнопку Отмена для атоматически созданного пакета
     *
     * @return bool
     */
    public function getShowCancelAutoButton()
    {
        return $this->document->state == SBISDocumentStatus::CREATED_AUTO;
    }

    /**
     * Показывать ли кнопку Отправить
     *
     * @return bool
     */
    public function getShowSendButton()
    {
        return $this->document->state == SBISDocumentStatus::CREATED;
    }

    /**
     * Показывать ли кнопку Обновить
     *
     * @return bool
     */
    public function getShowRefreshButton()
    {
        return
            $this->document->state >= SBISDocumentStatus::PROCESSING &&
            $this->document->state != SBISDocumentStatus::ACCEPTED
        ;
    }

    /**
     * Показывать ли кнопку Восстановить
     *
     * @return bool
     */
    public function getShowRestoreButton()
    {
        return $this->document->state == SBISDocumentStatus::CANCELLED;
    }

    /**
     * Показывать ли кнопку Восстановить для атоматически созданного пакета
     *
     * @return bool
     */
    public function getShowRestoreAutoButton()
    {
        return $this->document->state == SBISDocumentStatus::CANCELLED_AUTO;
    }


    /**
     * Показывать ли кнопку Пересоздать
     *
     * @return bool
     */
    public function getShowReCreateButton()
    {
        return self::getShowReCreateButton_st($this->document);
    }

    public static function getShowReCreateButton_st($document)
    {
        // Проверяем, есть ли связанный черновик
        $hasDraft = SBISGeneratedDraft::find()
            ->where(['sbis_document_id' => $document->id])
            ->exists();

        if (!$hasDraft) {
            return false;
        }

        return
<<<<<<< modules/sbisTenzor/forms/document/ViewForm.php
            $document->state == SBISDocumentStatus::CREATED
            || $document->state == SBISDocumentStatus::CREATED_AUTO
            || $document->state == SBISDocumentStatus::CANCELLED
            || $document->state == SBISDocumentStatus::NEGOTIATED
            || $document->state == SBISDocumentStatus::ERROR
            || $document->state == SBISDocumentStatus::SENT
            || $document->state == SBISDocumentStatus::SENT_ERROR
            || $document->state == SBISDocumentStatus::DELIVERED
            ;
    }

    /**
     * @return string
     */
    public function getCancelUrl()
    {
        return '/sbisTenzor/document/cancel?id=' . $this->document->id;
    }

    /**
     * @return string
     */
    public function getCancelAutoUrl()
    {
        return '/sbisTenzor/document/cancel-auto?id=' . $this->document->id;
    }

    /**
     * @return string
     */
    public function getRestoreUrl()
    {
        return '/sbisTenzor/document/restore?id=' . $this->document->id;
    }

    /**
     * @return string
     */
    public function getRestoreAutoUrl()
    {
        return '/sbisTenzor/document/restore-auto?id=' . $this->document->id;
    }

    /**
     * @return string
     */
    public function getRecreateUrl()
    {
        return '/sbisTenzor/document/recreate?id=' . $this->document->id;
    }

    /**
     * @return string
     */
    public function getStartUrl()
    {
        return '/sbisTenzor/document/start?id=' . $this->document->id;
    }

    /**
     * Изменение статуса пакета документов с проверкой
     *
     * @param int $id
     * @param int $stateFrom
     * @param int $stateTo
     * @param string $errorMessage
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    protected static function changeDocumentState($id, $stateFrom, $stateTo, $errorMessage)
    {
        $document = SBISDocument::findOne(['id' => $id]);
        if (!$document) {
            throw new InvalidArgumentException('Неверный пакет документов');
        }

        /** @var SBISDocument $document */
        if ($document->state != $stateFrom) {
            $errorMessage = strtr($errorMessage, ['{state}' => $document->stateName]);

            throw new InvalidArgumentException($errorMessage);
        }

        $document->setState($stateTo);
        if (!$document->save()) {
            throw new ModelValidationException($document);
        }

        return $id;
    }

    /**
     * Cancel document
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function cancel($id)
    {
        return self::changeDocumentState(
            $id,
            SBISDocumentStatus::CREATED,
            SBISDocumentStatus::CANCELLED,
            'Пакет документов в статусе {state} не может быть отменён.'
        );
    }

    /**
     * Cancel document created automatically
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function cancelAuto($id)
    {
        return self::changeDocumentState(
            $id,
            SBISDocumentStatus::CREATED_AUTO,
            SBISDocumentStatus::CANCELLED_AUTO,
            'Пакет документов в статусе {state} не может быть отменён.'
        );
    }

    /**
     * Restore document
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function restore($id)
    {
        return self::changeDocumentState(
            $id,
            SBISDocumentStatus::CANCELLED,
            SBISDocumentStatus::CREATED,
            'Пакет документов в статусе {state} не может быть восстановлен.'
        );
    }

    /**
     * Restore document created automatically
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function restoreAuto($id)
    {
        return self::changeDocumentState(
            $id,
            SBISDocumentStatus::CANCELLED_AUTO,
            SBISDocumentStatus::CREATED_AUTO,
            'Пакет документов в статусе {state} не может быть восстановлен.'
        );
    }

    /**
     * Start document
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function start($id)
    {
        return self::changeDocumentState(
            $id,
            SBISDocumentStatus::CREATED,
            SBISDocumentStatus::PROCESSING,
            'Пакет документов в статусе {state} не может быть запущен в работу.'
        );
    }

    /**
     * ReStart document
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function restart($id)
    {
        return self::changeDocumentState(
            $id,
            SBISDocumentStatus::ERROR,
            SBISDocumentStatus::CREATED,
            'Пакет документов в статусе {state} не может быть перезапущен в работу.'
        );
    }

    /**
     * ReCreate document
     *
     * @param $id
     * @return int
     * @throws ModelValidationException
     * @throws \Exception
     */
    public static function recreate($id)
    {
        return DocumentRecreateService::recreate($id);
    }

    /**
     * Возвращает цепочку пройденных статусов
     *
     * @return array
     * @throws \Exception
     */
    public function getStatusesChain()
    {
        $document = $this->getDocument();

        $chain = [];
        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::CREATED),
            'passed' => true,
            'date' => DateTimeZoneHelper::getDateTime($document->created_at)
                . PHP_EOL . '(' . $document->createdBy->name . ')',
        ];

        if (in_array($document->state, [SBISDocumentStatus::CANCELLED, SBISDocumentStatus::CANCELLED_AUTO])) {
            $chain[] = [
                'name' => $document->getStateName(),
                'passed' => true,
                'btn' => 'btn-warning',
                'date' => DateTimeZoneHelper::getDateTime($document->updated_at)
                    . ($document->updatedBy ? PHP_EOL . '(' . $document->updatedBy->name . ')' : ''),
            ];

            return $chain;
        }

        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::PROCESSING),
            'passed' => !empty($document->started_at),
            'date' => DateTimeZoneHelper::getDateTime($document->started_at),
        ];

        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::SIGNED),
            'passed' => !empty($document->signed_at),
            'date' => DateTimeZoneHelper::getDateTime($document->signed_at),
        ];

        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::SAVED),
            'passed' => !empty($document->saved_at),
            'date' => DateTimeZoneHelper::getDateTime($document->saved_at),
        ];

        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::READY),
            'passed' => !empty($document->prepared_at),
            'date' => DateTimeZoneHelper::getDateTime($document->prepared_at),
        ];

        $passed = !empty($document->sent_at);
        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::SENT),
            'passed' => $passed,
            'date' => DateTimeZoneHelper::getDateTime($document->sent_at),
            'btn' => $passed ? 'btn-info' : '',
            'extra' => ' <i class="glyphicon glyphicon-send"></i>',
        ];

        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::DELIVERED),
            'passed' => !empty($document->read_at),
            'date' => DateTimeZoneHelper::getDateTime($document->read_at),
        ];

        if (
            ($document->state > SBISDocumentStatus::DELIVERED) &&
            ($document->state != SBISDocumentStatus::ACCEPTED) &&
            ($document->state != SBISDocumentStatus::ERROR)
        ) {
            $chain[] = [
                'name' => $document->getStateName(),
                'passed' => true,
            ];
        }

        $passed = !empty($document->completed_at);
        $chain[] = [
            'name' => SBISDocumentStatus::getById(SBISDocumentStatus::ACCEPTED),
            'passed' => !empty($document->completed_at),
            'date' => DateTimeZoneHelper::getDateTime($document->completed_at),
            'btn' => $passed ? 'btn-success' : '',
            'extra' => ' <i class="glyphicon glyphicon-ok-circle"></i>',
        ];

        if ($document->state == SBISDocumentStatus::ERROR) {
            $chain[] = [
                'name' => SBISDocumentStatus::getById(SBISDocumentStatus::ERROR),
                'passed' => true,
                'btn' => 'btn-danger',
            ];
        }

        return $chain;
    }
}
