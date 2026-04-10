<?php
/**
 * Статистика: Вызовы-API
 *
 * @var \app\classes\BaseView $this
 * @var BillingApiFilter $filterModel
 */

use app\classes\grid\column\universal\DateRangeDoubleColumn;
use app\classes\grid\column\universal\ClientAccountColumn;
use app\classes\grid\column\universal\DropdownColumn;
use app\classes\grid\column\universal\IntegerRangeColumn;
use app\classes\grid\GridView;
use app\classes\Html;
use app\helpers\DateTimeZoneHelper;
use app\models\ClientAccount;
use app\models\billing\api\ApiMethod;
use app\models\billing\api\ApiRaw;
use app\models\filter\BillingApiFilter;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
use yii\widgets\Breadcrumbs;

echo Html::formLabel($this->title = 'Статистика: Вызовы-API');

echo Breadcrumbs::widget([
    'links' => [
        'Статистика',
        ['label' => $this->title, 'url' => $baseUrl = Url::toRoute('stats/billing-api')]
    ],
]);

$utcNow = new DateTimeImmutable('now', new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
$requestParams = Yii::$app->request->queryParams;
unset($requestParams['page'], $requestParams['sort']);

$filterParams = $requestParams[$filterModel->formName()] ?? [];
$buildPeriodUrl = function (string $dateFrom, string $dateTo) use ($baseUrl, $filterModel, $requestParams, $filterParams) {
    $requestParams[$filterModel->formName()] = array_merge($filterParams, [
        'connect_time_from' => $dateFrom,
        'connect_time_to' => $dateTo,
    ]);

    return Url::to(array_merge([$baseUrl], $requestParams));
};

$periodAnchor = new DateTimeImmutable(
    $filterModel->connect_time_from ?: $utcNow->format(DateTimeZoneHelper::DATE_FORMAT),
    new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC)
);
$previousMonth = $periodAnchor->modify('first day of previous month');
$currentPeriodMonth = $periodAnchor->modify('first day of this month');
$today = $utcNow->format(DateTimeZoneHelper::DATE_FORMAT);

$moneyFormat = function ($value) {
    return number_format((float)$value, 4, '.', ' ');
};

$moneyWithCurrencyFormat = function ($value, ?string $currencyId) use ($moneyFormat) {
    $currency = trim((string)$currencyId);
    return $currency === ''
        ? $moneyFormat($value)
        : sprintf('%s %s', $moneyFormat($value), $currency);
};

$resolveCostPriceCurrency = function (ApiRaw $row) use ($filterModel): ?string {
    $currencyId = trim((string)$row->price_currency_id);
    if ($currencyId !== '') {
        return $currencyId;
    }

    if ($filterModel->isGroupByMethod() && !empty($row->api_method_id)) {
        return $filterModel->getMethodPriceCurrency((int)$row->api_method_id);
    }

    $fallbackCurrency = trim((string)$row->cost_currency_id);
    return $fallbackCurrency !== '' ? $fallbackCurrency : 'RUB';
};

$integerFormat = function ($value) {
    return number_format((float)$value, 0, '.', ' ');
};

$moneyColumnOptions = [
    'contentOptions' => ['style' => 'text-align: right; white-space: nowrap;'],
    'headerOptions' => ['style' => 'text-align: center;'],
];

$previousMonthUrl = $buildPeriodUrl(
    $previousMonth->format('Y-m-01'),
    $previousMonth->format('Y-m-t')
);
$currentMonthUrl = $buildPeriodUrl(
    $utcNow->format('Y-m-01'),
    $utcNow->format('Y-m-t')
);
$currentDayUrl = $buildPeriodUrl($today, $today);

if (!$filterModel->isLoad) {
    ?>

    <div class="row">
        <div class="col-sm-6 text-left">
            <div class="well">Итоговое потребление: <?=$moneyFormat($filterModel->getTotal())?></div>
        </div>
    </div>

    <?php
}
$form = ActiveForm::begin(['method' => 'get', 'action' => $baseUrl]);

?>

<div style="margin-bottom: 15px;">
    Создайте отчёт сами:
    (или - посмотрите отчёты за
    <?= Html::a('прошлый месяц', $previousMonthUrl) ?>,
    за <?= Html::a('текущий месяц', $currentMonthUrl) ?>,
    за <?= Html::a('текущий день', $currentDayUrl) ?>)
</div>

<div class="row" style="margin-bottom: 15px;">
    <div class="col-sm-3">
        Выводить по
        <?= Html::activeDropDownList($filterModel, 'group_by', BillingApiFilter::getGroupByList(), [
            'class' => 'form-control input-sm',
        ]) ?>
    </div>
    <div class="col-sm-3">
        Часовой пояс
        <?= Html::activeDropDownList($filterModel, 'timezone', $filterModel->getTimezoneList(), [
            'class' => 'form-control input-sm',
        ]) ?>
    </div>
    <div class="col-sm-3">
        <?= Html::submitButton('Сформировать отчёт', ['class' => 'btn btn-primary btn-sm', 'style' => 'margin-top: 20px']) ?>
    </div>
</div>

<?php

$methodColumn = [
    'attribute' => 'api_method_id',
    'class' => DropdownColumn::class,
    'filter' => ApiMethod::getList(true),
    'value' => function (ApiRaw $row) {
        return $row->method ? $row->method->name : null;
    }
];

$timeLabel = sprintf('Время вызова, %s', $filterModel->getQueryTimezone());
$periodLabel = sprintf('Период, %s', $filterModel->getQueryTimezone());

$periodRangeColumn = function (string $label) use ($filterModel) {
    return [
        'attribute' => 'connect_time',
        'label' => $label,
        'class' => DateRangeDoubleColumn::class,
        'value' => function () use ($filterModel) {
            return $filterModel->connect_time_from . ' - ' . $filterModel->connect_time_to;
        }
    ];
};

$weightTotalColumn = [
    'attribute' => 'api_weight_total',
    'label' => 'Суммарный вес вызова',
    'value' => function (ApiRaw $row) use ($integerFormat) {
        return $row instanceof BillingApiFilter ? $integerFormat($row->api_weight_total) : null;
    }
] + $moneyColumnOptions;

$costTotalColumn = [
    'attribute' => 'cost_total',
    'label' => 'Суммарная стоимость',
    'value' => function (ApiRaw $row) use ($moneyFormat) {
        return $row instanceof BillingApiFilter ? $moneyFormat($row->cost_total) : null;
    }
] + $moneyColumnOptions;

$costPriceTotalColumn = [
    'attribute' => 'cost_price_total',
    'label' => 'Себестоимость',
    'value' => function (ApiRaw $row) use ($moneyWithCurrencyFormat, $resolveCostPriceCurrency) {
        return $row instanceof BillingApiFilter
            ? $moneyWithCurrencyFormat($row->cost_price_total, $resolveCostPriceCurrency($row))
            : null;
    }
] + $moneyColumnOptions;

$columns = [];

if ($filterModel->isGroupByMethod()) {
    $this->registerJs(<<<JS
if (!window.billingApiMethodAccountSortBound) {
    window.billingApiMethodAccountSortBound = true;
document.addEventListener('click', function (event) {
    var trigger = event.target.closest('.js-billing-api-method-account-sort');
    if (!trigger) {
        return;
    }

    event.preventDefault();

    var table = trigger.closest('.js-billing-api-method-account-table');
    if (!table) {
        return;
    }

    var tbody = table.querySelector('tbody');
    if (!tbody) {
        return;
    }

    var sortField = trigger.getAttribute('data-sort-field');
    var currentField = table.getAttribute('data-sort-field');
    var currentDirection = table.getAttribute('data-sort-direction') || 'desc';
    var nextDirection = 'desc';

    if (currentField === sortField && currentDirection === 'desc') {
        nextDirection = 'asc';
    }

    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    rows.sort(function (leftRow, rightRow) {
        var leftValue = parseFloat(leftRow.getAttribute('data-' + sortField)) || 0;
        var rightValue = parseFloat(rightRow.getAttribute('data-' + sortField)) || 0;

        if (leftValue === rightValue) {
            var leftAccountId = parseInt(leftRow.getAttribute('data-account-id'), 10) || 0;
            var rightAccountId = parseInt(rightRow.getAttribute('data-account-id'), 10) || 0;
            return leftAccountId - rightAccountId;
        }

        if (nextDirection === 'asc') {
            return leftValue - rightValue;
        }

        return rightValue - leftValue;
    });

    rows.forEach(function (row) {
        tbody.appendChild(row);
    });

    table.setAttribute('data-sort-field', sortField);
    table.setAttribute('data-sort-direction', nextDirection);

    Array.prototype.forEach.call(
        table.querySelectorAll('.js-billing-api-method-account-sort'),
        function (link) {
            var indicator = link.querySelector('.js-billing-api-method-account-sort-indicator');
            if (!indicator) {
                return;
            }

            if (link === trigger) {
                indicator.textContent = nextDirection === 'desc' ? ' v' : ' ^';
                return;
            }

            indicator.textContent = '';
        }
    );
});
}
JS
    );

    $columns = [
        [
            'class' => 'kartik\grid\ExpandRowColumn',
            'width' => '50px',
            'header' => '',
            'filter' => false,
            'value' => function () {
                return GridView::ROW_COLLAPSED;
            },
            'detail' => function (ApiRaw $row) use ($filterModel, $integerFormat, $moneyFormat, $moneyWithCurrencyFormat) {
                $details = $filterModel->getMethodAccountDetails((int)$row->api_method_id);

                if (!$details) {
                    return Html::tag('div', 'Нет данных', ['class' => 'text-muted', 'style' => 'padding: 10px;']);
                }

                $tableRows = '';
                foreach ($details as $detail) {
                    $tableRows .= Html::beginTag('tr', [
                        'data-account-id' => (int)$detail['account_id'],
                        'data-api_weight_total' => (float)$detail['api_weight_total'],
                        'data-cost_total' => (float)$detail['cost_total'],
                        'data-cost_price_total' => (float)$detail['cost_price_total'],
                    ]) .
                        Html::tag('td', Html::a(
                            $detail['account_id'],
                            ClientAccount::getUrlById($detail['account_id']),
                            ['target' => '_blank']
                        )) .
                        Html::tag('td', $integerFormat($detail['api_weight_total']), ['style' => 'text-align: right; white-space: nowrap;']) .
                        Html::tag('td', $moneyFormat($detail['cost_total']), ['style' => 'text-align: right; white-space: nowrap;']) .
                        Html::tag('td', $moneyWithCurrencyFormat($detail['cost_price_total'], $detail['price_currency_id']), ['style' => 'text-align: right; white-space: nowrap;']) .
                        Html::endTag('tr');
                }

                return Html::beginTag('table', [
                    'class' => 'table table-hover table-bordered table-striped js-billing-api-method-account-table',
                    'style' => 'margin: 10px 0;',
                    'data-sort-field' => 'cost_total',
                    'data-sort-direction' => 'desc',
                ]) .
                    Html::beginTag('thead') .
                        Html::beginTag('tr') .
                            Html::tag('th', 'ЛС') .
                            Html::tag('th', Html::a(
                                'Суммарный вес вызова' .
                                Html::tag('span', '', ['class' => 'js-billing-api-method-account-sort-indicator']),
                                '#',
                                [
                                    'class' => 'js-billing-api-method-account-sort',
                                    'data-sort-field' => 'api_weight_total',
                                    'style' => 'color: inherit; text-decoration: none;',
                                ]
                            ), ['style' => 'text-align: center;']) .
                            Html::tag('th', Html::a(
                                'Суммарная стоимость' .
                                Html::tag('span', ' v', ['class' => 'js-billing-api-method-account-sort-indicator']),
                                '#',
                                [
                                    'class' => 'js-billing-api-method-account-sort',
                                    'data-sort-field' => 'cost_total',
                                    'style' => 'color: inherit; text-decoration: none;',
                                ]
                            ), ['style' => 'text-align: center;']) .
                            Html::tag('th', Html::a(
                                'Себестоимость' .
                                Html::tag('span', '', ['class' => 'js-billing-api-method-account-sort-indicator']),
                                '#',
                                [
                                    'class' => 'js-billing-api-method-account-sort',
                                    'data-sort-field' => 'cost_price_total',
                                    'style' => 'color: inherit; text-decoration: none;',
                                ]
                            ), ['style' => 'text-align: center;']) .
                        Html::endTag('tr') .
                    Html::endTag('thead') .
                    Html::beginTag('tbody') .
                        $tableRows .
                    Html::endTag('tbody') .
                Html::endTag('table');
            },
            'contentOptions' => ['style' => 'text-align: center; vertical-align: middle; width: 50px;'],
            'headerOptions' => ['style' => 'width: 50px;'],
            'filterOptions' => ['style' => 'width: 50px;'],
        ],
        $periodRangeColumn($periodLabel),
        $methodColumn,
        $weightTotalColumn,
        $costTotalColumn,
        $costPriceTotalColumn,
    ];
} elseif ($filterModel->isGroupByAccount()) {
    $columns = [
        $periodRangeColumn($timeLabel),
        [
            'attribute' => 'account_id',
            'label' => 'ЛС',
            'class' => ClientAccountColumn::class,
        ],
        $weightTotalColumn,
        $costTotalColumn,
        $costPriceTotalColumn,
    ];
} elseif ($filterModel->isGroupedByDate()) {
    $periodLabels = [
        BillingApiFilter::GROUP_BY_DAY => sprintf('День, %s', $filterModel->getQueryTimezone()),
        BillingApiFilter::GROUP_BY_MONTH => sprintf('Месяц, %s', $filterModel->getQueryTimezone()),
        BillingApiFilter::GROUP_BY_YEAR => sprintf('Год, %s', $filterModel->getQueryTimezone()),
    ];

    $columns = [
        [
            'attribute' => 'connect_time',
            'label' => $periodLabels[$filterModel->group_by] ?? 'Период, UTC',
            'class' => DateRangeDoubleColumn::class,
            'value' => function (ApiRaw $row) use ($filterModel) {
                if (!$row instanceof BillingApiFilter || !$row->period_group) {
                    return null;
                }

                $period = new DateTimeImmutable($row->period_group, new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));

                if ($filterModel->group_by === BillingApiFilter::GROUP_BY_YEAR) {
                    return $period->format('Y');
                }

                if ($filterModel->group_by === BillingApiFilter::GROUP_BY_MONTH) {
                    return $period->format('Y-m');
                }

                return $period->format(DateTimeZoneHelper::DATE_FORMAT);
            }
        ],
        $weightTotalColumn,
        $costTotalColumn,
        $costPriceTotalColumn,
    ];
} else {
    if (!$filterModel->accountId) {
        $columns[] = [
            'attribute' => 'account_id',
            'label' => 'ЛС',
            'class' => ClientAccountColumn::class,
        ];
    }

        $columns = array_merge($columns, [
        [
            'attribute' => 'connect_time',
            'label' => $timeLabel,
            'class' => DateRangeDoubleColumn::class,
            'value' => function (ApiRaw $row) use ($filterModel) {
                $time = new DateTimeImmutable($row->connect_time, new DateTimeZone(DateTimeZoneHelper::TIMEZONE_UTC));
                return $time->setTimezone(new DateTimeZone($filterModel->getQueryTimezone()))
                    ->format(DateTimeZoneHelper::DATETIME_FORMAT);
            }
        ],
        $methodColumn,
        [
            'attribute' => 'api_weight',
            'class' => IntegerRangeColumn::class,
        ],
        [
            'attribute' => 'rate',
            'class' => IntegerRangeColumn::class,
        ] + $moneyColumnOptions,
        [
            'attribute' => 'cost',
            'class' => IntegerRangeColumn::class,
            'value' => function (ApiRaw $row) use ($moneyFormat) {
                return $moneyFormat(-$row->cost);
            }
        ] + $moneyColumnOptions,
        [
            'attribute' => 'cost_price_total',
            'label' => 'Себестоимость',
            'filter' => false,
            'value' => function (ApiRaw $row) use ($moneyWithCurrencyFormat, $resolveCostPriceCurrency) {
                return $moneyWithCurrencyFormat(
                    (float)$row->price_rate * (float)$row->api_weight,
                    $resolveCostPriceCurrency($row)
                );
            }
        ] + $moneyColumnOptions
    ]);
}

echo GridView::widget([
    'dataProvider' => $filterModel->search(),
    'filterModel' => $filterModel,
    'columns' => $columns,
]);

ActiveForm::end(); ?>
