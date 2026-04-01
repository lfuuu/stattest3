<?php

namespace app\controllers\report\accounting;

use app\classes\BaseController;
use app\models\filter\ClosingDocumentCoverageFilter;

class ClosingDocumentCoverageController extends BaseController
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['access']['rules'] = [
            [
                'allow' => true,
                'actions' => ['index'],
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
}
