<?php

use app\classes\BaseView;
use app\dao\OrganizationDao;
use app\models\Business;
use app\models\BusinessProcess;
use app\models\BusinessProcessStatus;
use app\models\ClientContract;
use app\models\Country;
use app\models\Currency;
use app\models\dictionary\PublicSite;
use app\models\EntryPoint;
use app\models\LkWizardState;
use app\models\PriceLevel;
use app\models\Region;
use app\classes\Html;
use app\models\Timezone;
use kartik\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;
use app\models\ClientAccount;

/** @var EntryPoint $model */
/** @var ActiveForm $form */
/** @var BaseView $this */

$form = ActiveForm::begin([
    'type' => ActiveForm::TYPE_VERTICAL,
]);

$regions = Region::find()
    ->select(['id', 'name', 'country_id'])
    ->orderBy(['id' => SORT_DESC])
    ->asArray()
    ->all();

echo Html::formLabel('Редактирование точки входа');
echo Breadcrumbs::widget([
    'links' => [
        'Словари',
        'Словари',
        ['label' => $this->title = 'Точки входа', 'url' => $cancelUrl = '/dictionary/entry-point'],
        ($model->id ? 'Редактирование' : 'Добавление'),
    ],
]);

$this->registerJsVariables([
    'statuses' => BusinessProcessStatus::getTree(),
    'regions' => $regions,
]);

echo $form->field($model, 'id')->hiddenInput()->label('');
?>

<div class="container well col-sm-12">
    <fieldset class="col-sm-12">

        <div class="row">
            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'code')
                    ->textInput($model->id ? ['readonly' => true] : [])
                ?>
            </div>
            <div class="col-sm-6">
                <?= $form
                    ->field($model, 'name')
                ?>
            </div>
            <div class="col-sm-3">
                <?= $form
                    ->field($model, 'connect_trouble_user_id')
                ->dropDownList(\app\models\User::getList(true, false, 'id'))
                ?>
            </div>
            <div class="col-sm-1">
                <?= $form
                    ->field($model, 'is_postpaid')
                    ->dropDownList(ClientAccount::$paymentTypes)
                ?>
            </div>

        </div>

        <div class="row">
            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'name_prefix')
                ?>
            </div>

            <div class="col-sm-4">
                <?= $form
                    ->field($model, 'organization_id')
                    ->dropDownList(OrganizationDao::me()->getList())
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'partner_id')
                    ->dropDownList(ClientContract::dao()->getPartnerList($isWithEmpty = true))
                ?>
            </div>
            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'country_id')
                    ->dropDownList(Country::getList())
                ?>
            </div>
            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'site_id')
                    ->dropDownList(PublicSite::getList())
                ?>
            </div>

        </div>

        <div class="row">
            <div class="col-sm-4">
                <?= $form
                    ->field($model, 'client_contract_business_id')
                    ->dropDownList(Business::getList())
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'credit')
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'account_version')->dropDownList(
                            ClientAccount::$versions,
                            ['disabled' => 'disabled', 'options' => [ClientAccount::DEFAULT_ACCOUNT_VERSION => ['selected' => true]],]
                    )
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'region_id')
                    ->dropDownList(Region::getList($isWithEmpty = false))
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'legal_type')
                    ->dropDownList(['' => '-- Не заданно --'] + \app\models\ClientContragent::$names)
                ?>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-4">
                <?= $form
                    ->field($model, 'client_contract_business_process_id')
                    ->dropDownList(
                        BusinessProcess::find()
                            ->where(['business_id' => $model->client_contract_business_id])
                            ->andWhere(['show_as_status' => '1'])
                            ->indexBy('id')
                            ->all()
                    )
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'voip_credit_limit_day')
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'price_level')
                    ->dropDownList(PriceLevel::getList())
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'currency_id')
                    ->dropDownList(Currency::getList())
                ?>
            </div>
            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'lk_shopfront_id')
                ?>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'client_contract_business_process_status_id')
                    ->dropDownList(
                        BusinessProcessStatus::find()
                            ->where(['business_process_id' => $model->client_contract_business_process_id])
                            ->indexBy('id')
                            ->all()
                    )
                ?>
            </div>


            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'wizard_type')
                    ->dropDownList(LkWizardState::$name)
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'voip_limit_mn_day')
                ?>
            </div>

            <div class="col-sm-2">
                <?= $form
                    ->field($model, 'is_default')
                    ->checkbox()
                ?>
            </div>

            <div class="col-sm-4">
                <?= $form
                    ->field($model, 'timezone_name')
                    ->dropDownList(Timezone::getList())
                ?>
            </div>
        </div>

    </fieldset>

    <div class="form-group text-right">
        <?= $this->render('//layouts/_buttonCancel', ['url' => $cancelUrl]) ?>
        <?= $this->render('//layouts/_submitButton' . ($model->id ? 'Save' : 'Create')) ?>
    </div>

    <?php ActiveForm::end() ?>
</div>
