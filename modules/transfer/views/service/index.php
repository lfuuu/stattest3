<?php

use app\classes\Html;
use app\modules\transfer\forms\services\BaseForm;
use app\modules\transfer\forms\services\decorators\ServiceDecoratorInterface;
use kartik\widgets\ActiveForm;
use kartik\widgets\DatePicker;
use kartik\widgets\Select2;
use yii\helpers\Url;
use yii\widgets\Breadcrumbs;

/** @var BaseForm $form */
/** @var \app\classes\BaseView $this */

echo Html::formLabel('Перенос услуг');
echo Breadcrumbs::widget([
    'links' => [
        'Лицевой счет',
        [
            'label' => $form->clientAccount->contract->contragent->name,
            'url' => $cancelUrl = Url::toRoute(['/client/view', 'id' => $form->clientAccount->id]),
        ],
        'Перенос услуг',
    ],
]);

$htmlForm = ActiveForm::begin([
    'action' => '/transfer/service/process'
]);

/**
 * @param string $fieldName
 * @return string
 */
$datePicker = function ($fieldName) use ($form) {
    /** @var ActiveForm $htmlForm */
    return DatePicker::widget([
        'type' => DatePicker::TYPE_INPUT,
        'name' => $fieldName,
        'language' => 'ru',
        'value' => $form->getNearestMonthDate(),
        'pluginOptions' => [
            'autoclose' => true,
            'format' => 'yyyy-mm-dd',
            'orientation' => 'bottom left',
            'startDate' => $form->getNearestPossibleDate(),
        ],
        'options' => [
            'class' => 'process-datepicker',
        ],
    ]);
};

echo Html::input('hidden', 'data[clientAccountId]', $form->clientAccount->id, [
    'data-account-version' => $form->clientAccount->account_version,
]);

echo Html::input('hidden', 'data[targetClientAccountId]');

$actionButton = $this->render('//layouts/_submitButton', [
    'glyphicon' => 'glyphicon-retweet',
    'text' => 'Перенести',
    'params' => [
        'disabled' => true,
        'class' => 'btn btn-primary pull-right process-btn',
    ],
]);
?>

<?= $actionButton ?>
<div class="clearfix"></div>
<br/>

<table class="kv-grid-table table table-hover table-bordered table-striped">
    <colgroup>
        <col width="50"/>
        <col width="*"/>
        <col width="300"/>
        <col width="120"/>
        <col width="300"/>
    </colgroup>
    <thead>
    <tr class="info">
        <th class="text-center">
            <input type="checkbox" id="transfer-select-all"/>
        </th>
        <th>Услуга</th>
        <th>Лицевой счет</th>
        <th>Дата переноса</th>
        <th>Тариф</th>
    </tr>
    <tr>
        <th></th>
        <th></th>
        <th>
            <div class="input-group">
                <?= Html::input('text', 'targetClientAccount', '', [
                    'class' => 'form-control account-search',
                    'placeholder' => 'Укажите лицевой счет',
                ])
                ?>
                <span class="input-group-btn" title="Submit">
                        <button type="button" class="btn btn-default"><i
                                    class="glyphicon glyphicon-search"></i></button>
                    </span>
            </div>
        </th>
        <td>
            <?= $datePicker('data[processedFromDate]') ?>
        </td>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php
    $idx = 0;
    foreach ($form->servicesPossibleToTransfer as $serviceKey => $servicesData): ?>
        <tr class="active">
            <td colspan="5" class="text-center">
                <b><?= $servicesData['title'] ?></b>
            </td>
        </tr>
        <?php
        /** @var ServiceDecoratorInterface $service */
        foreach ($servicesData['services'] as $service): ?>
            <tr>
                <td class="text-center">
                    <?= Html::checkbox('data[services][' . $serviceKey . '][' . $idx . ']', $checked = false, [
                        'class' => 'service-checkbox',
                        'value' => $service->id,
                    ])
                    ?>
                </td>
                <td><?= $service->description ?></td>
                <td class="target-account">
                    <div></div>
                    <div class="label label-info"></div>
                </td>
                <td class="processed-from-date">
                    <?= $datePicker('data[fromDate][' . $serviceKey . '][' . $idx . ']') ?>
                </td>
                <td class="processed-tariff">
                    <div class="collapse" data-tariff-choose="true">
                        <?= Select2::widget([
                            'name' => 'data[tariffIds][' . $serviceKey . '][' . $idx . ']',
                            'data' => [],
                            'options' => [
                                'class' => 'choose-tariff',
                                'data-service-type' => $serviceKey,
                                'data-service-value' => $service->value,
                                'data-service-extends-data' => $service->extendsData,
                                'data-current-tariff' => $service->currentTariffLabel,
                            ],
                        ])
                        ?>
                        <div class="tariff-info">
                            <span class="glyphicon glyphicon-info-sign" title="Информация о тарифном плане"></span>
                            <a href="#"></a>
                        </div>
                    </div>
                </td>
            </tr>
            <?php $idx++; endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $actionButton ?>

<?php ActiveForm::end() ?>
<div class="card-body">
    <div class="mb-3">
        <label for="numberList" class="form-label">Список телефонных номеров (каждый номер с новой строки):</label>
        <textarea class="form-control" id="numberList" rows="6"
                  placeholder="Введите номера телефонов, каждый с новой строки"></textarea>
    </div>

    <button type="button" id="processNumbers" class="btn btn-success">
        <i class="bi bi-play-fill"></i> Отметить номера
    </button>

</div>
<script>
    $(document).ready(function () {
        $('#processNumbers').click(function () {
            let numbersText = $('#numberList').val();
            let numbers = numbersText.split('\n');
            let updatedNumbers = [];

            numbers.forEach(function (numberLine) {
                let number = numberLine.trim();

                if (!number) {
                    updatedNumbers.push('');
                    return;
                }

                if (number.endsWith('+')) {
                    updatedNumbers.push(number);
                    return;
                }

                let isFound = false;

                // Более точный поиск по номеру в ссылке
                $('a[href*="/uu/account-tariff/edit"]').each(function () {
                    let linkText = $(this).text();

                    // Ищем точное совпадение номера в тексте ссылки
                    if (linkText.includes(number)) {
                        // Находим checkbox в родительской tr и включаем его
                        $(this).closest('tr').find('.service-checkbox').prop('checked', true);
                        isFound = true;
                        return false; // Прерываем цикл после нахождения
                    }
                });

                updatedNumbers.push(isFound ? number + '+' : number);

            });

            $('#numberList').val(updatedNumbers.join('\n'));
        });
    });
</script>
