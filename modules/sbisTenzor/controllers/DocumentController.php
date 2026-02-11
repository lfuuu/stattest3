<?php

namespace app\modules\sbisTenzor\controllers;

use app\classes\BaseController;
use app\models\ClientAccount;
use app\modules\sbisTenzor\classes\SBISDocumentStatus;
use app\modules\sbisTenzor\forms\document\AddFilesForm;
use app\modules\sbisTenzor\forms\document\EditForm;
use app\modules\sbisTenzor\forms\document\IndexForm;
use app\modules\sbisTenzor\forms\document\ViewForm;
use app\modules\sbisTenzor\helpers\SBISUtils;
use app\modules\sbisTenzor\models\SBISAttachment;
use app\modules\sbisTenzor\models\SBISDocument;
use yii\base\InvalidArgumentException;
use yii\filters\AccessControl;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * DocumentController controller for the `sbisTenzor` module
 */
class DocumentController extends BaseController
{
    /**
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
                        'actions' => ['index', 'view', 'download-attachment', 'statuses'],
                        'roles' => ['newaccounts_bills.read'],
                    ],
                    [
                        'allow' => true,
                        'actions' => ['add', 'add-files', 'cancel', 'cancel-auto', 'restore', 'restore-auto', 'start', 'restart', 'send-auto', 'recreate'],
                        'roles' => ['newaccounts_bills.edit'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Получить выбранного клиента
     *
     * @param int|null $clientId
     * @param bool $strict
     * @return ClientAccount|null
     * @throws \yii\base\InvalidConfigException
     */
    protected function getClient($clientId = null, $strict = true)
    {
        $client = null;
        if ($clientId) {
            $client = ClientAccount::findOne(['id' => $clientId]);
            if (!$client) {
                throw new InvalidArgumentException('Клиент ' . $clientId . ' не найден!');
            }

            if (!$this->getFixClient()) {
                $_SESSION["clients_client"] = $clientId;

                $url = \Yii::$app->getRequest()->getUrl();
                $url = SBISUtils::removeVariableFromURL($url, 'clientId');

                $this->redirect($url);
            }
        } else {
            $client = $this->getFixClient();
        }

        if (!$client && $strict) {
            throw new InvalidArgumentException('Клиент не выбран');
        }

        return $client;
    }

    /**
     * Список пакетов документов
     *
     * @param int $clientId
     * @param int $state
     * @return string
     * @throws \yii\base\InvalidConfigException
     */
    public function actionIndex($clientId = 0, $state =  0)
    {
        $client = $this->getClient($clientId, false);
        $indexForm = new IndexForm($state, $client);

        return $this->render('index', [
            'dataProvider' => $indexForm->getDataProvider(),
            'title' => $indexForm->getTitle(),
            'isAuto' => $client->exchange_group_id,
            'sendAutoConfirmText' => $indexForm->getSendAutoConfirmText(),
            'sendAutoCount' => $indexForm->getSendAutoCount(),
            'clientId' => $client->id,
            'state' => $state,
        ]);
    }

    /**
     * Добавление пакета документов
     *
     * @param int $clientId
     * @return string|\yii\web\Response
     */
    public function actionAdd($clientId = 0)
    {
        $document = null;
        try {
            $editForm = new EditForm($this->getClient($clientId));
            $document = $editForm->getDocument();

            if ($editForm->tryToSave()) {
                return $this->redirect($document->getUrl());
            }
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->render('add', [
            'model' => $document,
            'indexUrl' => EditForm::getIndexUrl($clientId),
        ]);
    }

    /**
     * Add attachments to document
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionAddFiles($id)
    {
        try {
            if (!$id) {
                throw new InvalidArgumentException('Пакет документов не выбран');
            }

            $document = SBISDocument::findOne(['id' => $id]);
            if (!$document) {
                throw new InvalidArgumentException('Пакет документов ' . $id . ' не найден!');
            }

            $addFilesForm = new AddFilesForm($document);
            $addFilesForm->tryToSave();
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id='. $id);
    }

    /**
     * Просмотр пакета документов
     *
     * @param int $id
     * @return string|\yii\web\Response
     */
    public function actionView($id = 0)
    {
        $document = null;
        try {
            $form = new ViewForm($id);
            $document = $form->getDocument();
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->render('view', [
            'model' => $document,
            'form' => $form,
            'indexUrl' => '/sbisTenzor/document/',
            'indexClientUrl' => '/sbisTenzor/document/' . ($form ? '?clientId=' . $form->getDocument()->client_account_id : ''),
        ]);
    }

    /**
     * Cancel document
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionCancel($id = 0)
    {
        try {
            $id = ViewForm::cancel($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id=' . $id);
    }

    /**
     * Cancel auto-created document
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionCancelAuto($id = 0)
    {
        try {
            $id = ViewForm::cancelAuto($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id=' . $id);
    }

    /**
     * Restore document
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionRestore($id = 0)
    {
        try {
            $id = ViewForm::restore($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id=' . $id);
    }

    /**
     * Restore auto-created document
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionRestoreAuto($id = 0)
    {
        try {
            $id = ViewForm::restoreAuto($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id=' . $id);
    }

    /**
     * Запуск документа в работу
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionStart($id = 0)
    {
        try {
            $id = ViewForm::start($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id=' . $id);
    }

    /**
     * Запуск документа в работу
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionRestart($id = 0)
    {
        try {
            $id = ViewForm::restart($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/?state=' . SBISDocumentStatus::CREATED);
    }

    /**
     * Send all auto-created
     *
     * @param int $clientId
     * @return \yii\web\Response
     */
    public function actionSendAuto($clientId = 0)
    {
        try {
            $client = $this->getClient($clientId, false);
            $indexForm = new IndexForm(0, $client);

            $indexForm->sendAuto();
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/' . ($clientId ? '?clientId=' . $clientId : ''));
    }


    /**
     * Пересоздать пакет
     *
     * @param int $id
     * @return \yii\web\Response
     */
    public function actionRecreate($id = 0)
    {
        try {
            $id = ViewForm::recreate($id);
        } catch (\Exception $e) {
            Yii::$app->session->addFlash('error', $e->getMessage());
        }

        return $this->redirect('/sbisTenzor/document/view?id=' . $id);
    }


    /**
     * Получить актуальные статусы документов (AJAX)
     *
     * @param string $ids ID документов
     * @return array
     */
    public function actionStatuses($ids = '')
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $ids = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($ids)) {
            return [];
        }

        $documents = SBISDocument::find()
            ->where(['id' => $ids])
            ->all();

        $result = [];
        foreach ($documents as $document) {
            $progressValue = 0;
            $progressStyle = 'info';

            if (
                $document->state >= SBISDocumentStatus::PROCESSING &&
                $document->state < SBISDocumentStatus::SENT
            ) {
                $progressValue = 25;
                $progressStyle = 'danger';

                if ($document->state == SBISDocumentStatus::SAVED) {
                    $progressValue = 50;
                    $progressStyle = 'warning';
                } elseif (in_array($document->state, [SBISDocumentStatus::NOT_SIGNED, SBISDocumentStatus::READY])) {
                    $progressValue = 75;
                    $progressStyle = 'info';
                }
            }

            $result[$document->id] = [
                'state' => $document->state,
                'stateName' => $document->stateName,
                'externalStateName' => $document->external_state_name ?: '',
                'progressValue' => $progressValue,
                'progressStyle' => $progressStyle,
                'isFinal' => in_array($document->state, [
                    SBISDocumentStatus::CANCELLED,
                    SBISDocumentStatus::CANCELLED_AUTO,
                    SBISDocumentStatus::ACCEPTED,
                    SBISDocumentStatus::ERROR,
                ]),
            ];
        }

        return $result;
    }

    /**
     * Download attachment
     *
     * @param int $id
     * @throws \yii\base\ExitException
     * @throws \yii\web\RangeNotSatisfiableHttpException
     */
    public function actionDownloadAttachment($id = 0)
    {
        if ($attachment = SBISAttachment::findOne(['id' => $id])) {
            $fileName = $attachment->getActualStoredPath();

            if (!file_exists($fileName)) {
                throw new NotFoundHttpException('Файл документа не найден');
            }

            Yii::$app
                ->response
                ->sendContentAsFile(
                    file_get_contents($fileName),
                    basename($fileName)
                );
        }

        Yii::$app->end(200);
    }
}
