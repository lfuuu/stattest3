<?php
/**
 * Список универсальных услуг с пакетами
 *
 * @link http://rd.welltime.ru/confluence/pages/viewpage.action?pageId=10715249
 */

use app\classes\helpers\DependecyHelper;
use app\classes\Html;
use app\modules\uu\filter\AccountTariffFilter;
use app\modules\uu\models\AccountTariff;
use app\modules\uu\models\ServiceType;
use yii\caching\TagDependency;
use yii\db\ActiveQuery;
use yii\widgets\ActiveForm;

/**
 * @var \app\classes\BaseView $this
 * @var AccountTariffFilter $filterModel
 */

$serviceType = $filterModel->getServiceType();

/** @var ActiveQuery $query */
$query = $filterModel->search()->query;
if (!$serviceType) {
    $query->andWhere(['NOT', ['service_type_id' => array_keys(ServiceType::$packages)]]);
}

// сгруппировать одинаковые город-тариф-пакеты по строчкам
$rows = AccountTariff::getGroupedObjects($query);
?>

<p>
    <?= ($serviceType ? $this->render('//layouts/_buttonCreate', ['url' => AccountTariff::getUrlNew($serviceType->id)]) : '') ?>
    <?php if ($serviceType && $serviceType->id == ServiceType::ID_VOIP): ?>
        <span class="pull-right">
            <?=
                Html::a(
                    '<i class="glyphicon glyphicon-trash"></i> Массовое отключение',
                    '/uu/account-tariff/disable',
                    ['class' => 'btn btn-warning']
                )
            ?>
        </span>
    <?php endif ?>
</p>

<?php
foreach ($rows as $hash => $row) {

    /** @var AccountTariff $accountTariffFirst */
    $accountTariffFirst = reset($row);

    $cacheKey = DependecyHelper::me()->getKey(DependecyHelper::TAG_UU_SERVICE_LIST, $hash);
    $value = \Yii::$app->cache->get($cacheKey);

    if ($value !== false) {
//        if (strpos($value, $accountTariffFirst->id . '"') === false) {
//            \Yii::error('CACHEDOUBLE C ' . $accountTariffFirst->id . ': ' . $accountTariffFirst->client_account_id);
//            $value = false;
//        }

        ActiveForm::begin(['action' => '/uu/account-tariff/save-voip']);
        $params = [
            'accountTariffFirst' => $accountTariffFirst,
            'row' => $row,
            'tagName' => 'div',
        ];
        $value = str_replace("{::_indexVoipFormNumber::}", $this->render('_indexVoipFormNumber', $params), $value);
        echo $value;

        ActiveForm::end();
        continue;
    }

    if (array_key_exists($accountTariffFirst->service_type_id, ServiceType::$packages)) {
        // пакеты отдельно не выводим. Только в комплекте с базовой услугой
        continue;
    }

    $serviceType = $accountTariffFirst->serviceType;

    switch ($accountTariffFirst->service_type_id) {
        case ServiceType::ID_VOIP:
            $packageServiceTypeIds = [ServiceType::ID_VOIP_PACKAGE_CALLS, ServiceType::ID_VOIP_PACKAGE_SMS, ServiceType::ID_VOIP_PACKAGE_INTERNET_ROAMABILITY, ServiceType::ID_VOIP_PACKAGE_MAV];
            break;
        case ServiceType::ID_TRUNK:
            $packageServiceTypeIds = [ServiceType::ID_TRUNK_PACKAGE_ORIG, ServiceType::ID_TRUNK_PACKAGE_TERM];
            break;
        case ServiceType::ID_A2P:
            $packageServiceTypeIds = [ServiceType::ID_A2P_PACKAGE];
            break;
        case ServiceType::ID_BILLING_API:
            $packageServiceTypeIds = [ServiceType::ID_BILLING_API_MAIN_PACKAGE];
            break;
        default:
            $packageServiceTypeIds = [];
            break;
    }

    // сгруппировать пакеты по типу
    $packagesList = [];
    foreach ($accountTariffFirst->nextAccountTariffs as $accountTariffPackage) {
        $tariff = $accountTariffPackage->getNotNullTariffPeriod()->tariff;
        $isPackageDefault = $tariff->is_default;
        $isPackageBundle = $tariff->is_bundle;
        $packagesList[(($isPackageDefault || $isPackageBundle) ? 0 : $accountTariffPackage->service_type_id)][] = $accountTariffPackage;
    }

    // форма
    $form = ActiveForm::begin(['action' => '/uu/account-tariff/save-voip']);

    $value = $this->render('_indexVoipForm', [
        'accountTariffFirst' => $accountTariffFirst,
        'packageServiceTypeIds' => $packageServiceTypeIds,
        'row' => $row,
        'serviceType' => $serviceType,
        'packagesList' => $packagesList,
        'form' => $form,
    ]);

    \Yii::$app->cache->set(
        $cacheKey,
        $value,
        DependecyHelper::DEFAULT_TIMELIFE,
        (new TagDependency(['tags' => [DependecyHelper::TAG_UU_SERVICE_LIST, DependecyHelper::TAG_USAGE]]))
    );

    $params = [
        'accountTariffFirst' => $accountTariffFirst,
        'row' => $row,
        'tagName' => 'div',
    ];

    $value = str_replace("{::_indexVoipFormNumber::}", $this->render('_indexVoipFormNumber', $params), $value);

    echo $value;

    ActiveForm::end();
}
?>
<span class="pull-right">
<?= $this->render('//layouts/_button', [
    'glyphicon' => 'glyphicon-trash',
    'text' => '',
    'params' => [
        'id' => 'btnDisableAll',
        'class' => 'btn btn-danger',
    ]
]) ?>
</span>
<div id="disableAllForm" style="display: none;" data-is-showed="0"></div>
