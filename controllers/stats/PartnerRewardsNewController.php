<?php

namespace app\controllers\stats;

use app\classes\BaseController;
use app\classes\excel\PartnerRewardsNewDocumentExcel;
use app\models\filter\PartnerRewardsNewFilter;
use Yii;
use yii\base\InvalidParamException;
use yii\web\Response;

class PartnerRewardsNewController extends BaseController
{

    /**
     * @return array
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['access']['rules'] = [
            [
                'allow' => true,
                'actions' => ['index', 'document'],
                'roles' => ['clients.read'],
            ],
        ];
        return $behaviors;
    }

    /**
     * @param bool $isExtends
     * @return string
     * @throws InvalidParamException
     */
    public function actionIndex($isExtends = false)
    {
        return $this->render('index', [
            'filterModel' => (new PartnerRewardsNewFilter($isExtends))->load(),
        ]);
    }

    /**
     * Формальный документный экспорт для менеджерского сценария.
     *
     * @param string $format
     * @param bool $isExtends
     * @return Response|string
     * @throws InvalidParamException
     */
    public function actionDocument($format = 'xlsx', $isExtends = false)
    {
        $filterModel = (new PartnerRewardsNewFilter($isExtends))->load();
        if ($issue = $filterModel->getDocumentExportIssue()) {
            $params = Yii::$app->request->get();
            unset($params['format']);
            Yii::$app->session->setFlash('danger', $issue);
            return $this->redirect(array_merge(['index'], $params));
        }

        $documentData = $filterModel->getDocumentData();
        $fileName = $filterModel->getDocumentFileName();

        switch (strtolower($format)) {
            case 'pdf':
                $content = $this->renderAsPDF('template/partner_rewards_report', [
                    'filterModel' => $filterModel,
                    'documentData' => $documentData,
                ]);

                return Yii::$app->response->sendContentAsFile(
                    $content,
                    $fileName . '.pdf',
                    ['mimeType' => 'application/pdf']
                );

            case 'xlsx':
                (new PartnerRewardsNewDocumentExcel([
                    'documentData' => $documentData,
                ]))->download($fileName);
                return '';

            default:
                throw new InvalidParamException('Неподдерживаемый формат документа');
        }
    }

}
