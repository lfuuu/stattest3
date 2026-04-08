<?php

namespace app\controllers\report\accounting;

use app\classes\BaseController;
use app\models\filter\accounting\ClosingDocumentCoverageFilter;

class ClosingDocumentCoverageController extends BaseController
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['access']['rules'] = [
            [
                'allow' => true,
                'actions' => ['index', 'detail'],
                'roles' => ['clients.read'],
            ],
        ];

        return $behaviors;
    }

    public function actionIndex()
    {
        $filterModel = (new ClosingDocumentCoverageFilter())->load();

        return $this->render('index', [
            'filterModel' => $filterModel,
            'summary' => $filterModel->validate() ? $filterModel->getSummary() : null,
            'dataProvider' => $filterModel->validate() ? $filterModel->getDataProvider() : null,
        ]);
    }

    public function actionDetail()
    {
        $request = \Yii::$app->request;
        $expandRowKey = $request->post('expandRowKey');
        $billDateFrom = $request->post('bill_date_from');
        $billDateTo = $request->post('bill_date_to');
        $serviceDateFrom = $request->post('service_date_from');
        $serviceDateTo = $request->post('service_date_to');

        if (!$expandRowKey || !$billDateFrom || !$billDateTo || !$serviceDateFrom || !$serviceDateTo) {
            return 'Не удалось загрузить строки счета';
        }

        $filterModel = new ClosingDocumentCoverageFilter();
        $filterModel->load([
            $filterModel->formName() => [
                'bill_date_from' => $billDateFrom,
                'bill_date_to' => $billDateTo,
                'service_date_from' => $serviceDateFrom,
                'service_date_to' => $serviceDateTo,
            ],
        ]);

        if (!$filterModel->validate()) {
            return 'Некорректные параметры';
        }

        return $this->renderPartial('detail', [
            'dataProvider' => $filterModel->getBillDetails((string)$expandRowKey),
        ]);
    }
}
