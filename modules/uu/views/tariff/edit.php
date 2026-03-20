<?php
/**
 * Создание/редактирование универсального тарифа
 *
 * @var \app\classes\BaseView $this
 * @var \app\modules\uu\forms\TariffForm $formModel
 * @var \app\models\ClientAccount $clientAccount
 */

use app\modules\uu\controllers\TariffController;
use app\modules\uu\models\ServiceType;
use app\modules\uu\models\Tariff;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

$tariff = $formModel->tariff;

if (!$tariff->isNewRecord) {
    $this->title = $tariff->name;
} else {
    $this->title = Yii::t('common', 'Create');
}

$serviceType = $tariff->serviceType;
if (!$serviceType) {
    Yii::$app->session->setFlash('error', \Yii::t('common', 'Wrong ID'));
    return;
}
?>

<?= Breadcrumbs::widget([
    'links' => [
        [
            'label' => Yii::t('tariff', 'Universal tariffs') .
                $this->render('//layouts/_helpConfluence', Tariff::getHelpConfluence()),
            'encode' => false,
        ],

        ['label' => $serviceType->name, 'url' => Url::to(['/uu/tariff', 'serviceTypeId' => $serviceType->id])],
        [
            'label' => $this->render('//layouts/_helpConfluence', $serviceType->getHelpConfluence()),
            'encode' => false,
        ],
        $this->title
    ],
]) ?>

<div class="tariff-edit">
    <?php
    $form = ActiveForm::begin();
    $viewParams = [
        'formModel' => $formModel,
        'form' => $form,
        'clientAccount' => $clientAccount,
    ];

    if ($tariff->isHasAccountTariff()) {
        Yii::$app->session->setFlash('error', 'На этом тарифе есть услуги. Редактировать можно только некоторые свойства.');
        $viewParams['editableType'] = TariffController::EDITABLE_LIGHT;
    } else {
        $viewParams['editableType'] = TariffController::EDITABLE_FULL;
    }

    if (!\Yii::$app->user->can('tarifs.edit')) {
        $viewParams['editableType'] = TariffController::EDITABLE_NONE;
    }

    $viewParams['isCanEditTheVatRate'] = \Yii::$app->user->can('tarifs.editTax');

    // сообщение об ошибке
    if ($formModel->validateErrors) {
        Yii::$app->session->setFlash('error', $formModel->validateErrors);
    }
    ?>

    <?php // кнопка сохранения ?>
    <?= $this->render('_editSubmit', $viewParams) ?>

    <?php // свойства тарифа из основной таблицы ?>
    <?= $this->render('_editMain', $viewParams) ?>

    <?php
    // свойства тарифа конкретного типа услуги (ВАТС, телефония и пр.)
    switch ($serviceType->id) {

        case ServiceType::ID_VOIP:
            echo $this->render('_editMainVoip', $viewParams);
            break;

        case ServiceType::ID_VOIP_PACKAGE_CALLS:
            echo $this->render('_editMainVoipPackage', $viewParams);
            break;

        case ServiceType::ID_VOIP_PACKAGE_MAV:
            echo $this->render('_editMainVoipPackagePricelistV2', $viewParams + ['pricelistServiceTypeId' => ServiceType::getNnpServiceTypeId($serviceType->id)]);
            break;

        case ServiceType::ID_VOIP_PACKAGE_SMS:
            echo $this->render('_editMainVoipPackagePricelistNnpOnly', $viewParams);
            echo $this->render('_editMainVoipSms', $viewParams);
            break;

        case ServiceType::ID_A2P_PACKAGE:
            echo $this->render('_editMainVoipPackagePricelistNnpOnly', $viewParams);
            break;

        case ServiceType::ID_VOIP_PACKAGE_INTERNET:
            echo $this->render('_editMainVoipInternet', $viewParams);
            break;

        case ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY:
            echo $this->render('_editMainVoipPackagePricelistNnpOnly', $viewParams);
            break;

        case ServiceType::ID_TRUNK_PACKAGE_ORIG:
        case ServiceType::ID_TRUNK_PACKAGE_TERM:
            echo $this->render('_editMainTrunkPackage', $viewParams);
            break;

        case ServiceType::ID_VPS:
            echo $this->render('_editMainVps', $viewParams);
            break;

        case ServiceType::ID_BILLING_API_MAIN_PACKAGE:
            echo $this->render('_editMainBillingApiPackage', $viewParams);
            break;

    }
    ?>

    <?php // свойства тарифа (периоды) ?>
    <?= $this->render('_editPeriod', $viewParams) ?>

    <?php // свойства тарифа (ресурсы) ?>
    <?= $this->render('_editResource', $viewParams) ?>

    <?php // Описание тарифа ?>
    <?= $this->render('_editOverview', $viewParams) ?>

    <?php // Комментарий к тарифу ?>
    <?= $this->render('_editComment', $viewParams) ?>

    <?php // кнопка сохранения ?>
    <?= $this->render('_editSubmit', $viewParams) ?>

    <?php ActiveForm::end(); ?>
</div>

<?= $this->render('_checkAccountTariffAdd', $viewParams) ?>
