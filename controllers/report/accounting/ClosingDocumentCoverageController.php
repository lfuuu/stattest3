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
        $month = $request->post('month');
        $typeOfBill = $request->post('type_of_bill', ClosingDocumentCoverageFilter::TYPE_OF_BILL_ALL);

        if (!$expandRowKey || !$month) {
            return 'Не удалось загрузить строки счета';
        }

        $filterModel = new ClosingDocumentCoverageFilter();
        $filterModel->load([
            $filterModel->formName() => [
                'month' => $month,
                'type_of_bill' => $typeOfBill,
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
