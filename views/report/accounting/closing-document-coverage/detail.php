<?php
/**
 * @var \yii\data\ArrayDataProvider $dataProvider
 */

use app\classes\grid\GridView;

echo GridView::widget([
    'dataProvider' => $dataProvider,
    'columns' => [
        [
            'attribute' => 'line_pk',
            'label' => 'Строка счета',
        ],
        [
            'attribute' => 'item',
            'label' => 'Наименование',
        ],
        [
            'attribute' => 'sum',
            'label' => 'Сумма',
            'contentOptions' => ['style' => 'text-align: right; white-space: nowrap;'],
            'value' => function ($detailRow) {
                return number_format((float)$detailRow['sum'], 2, '.', ' ');
            }
        ],
        [
            'attribute' => 'date_from',
            'label' => 'Период от',
        ],
        [
            'attribute' => 'date_to',
            'label' => 'Период до',
        ],
    ],
    'floatHeader' => false,
    'isFilterButton' => false,
    'isFilterQuery' => false,
    'exportWidget' => false,
    'toggleData' => false,
    'toolbar' => [],
    'panel' => false,
]);
