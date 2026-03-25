<?php

namespace app\controllers\stats;

use app\classes\BaseController;
use app\models\filter\MavFilter;
use yii\base\InvalidParamException;

class MavController extends BaseController
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

    /**
     * @return string
     * @throws InvalidParamException
     */
    public function actionIndex()
    {
        $account = $this->getFixClient();

        return $this->render('index', [
            'filterModel' => (new MavFilter())->load($account ? $account->id : null),
        ]);
    }
}
