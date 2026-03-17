<?php
/**
 * Вознаграждения партнеров. Грид
 *
 * @var \app\classes\BaseView $this
 * @var PartnerRewardsNewFilter $filterModel
 */

use app\classes\grid\GridView;
use app\classes\Html;
use app\models\filter\PartnerRewardsNewFilter;

$baseView = $this;

echo GridView::widget([
    'dataProvider' => $filterModel->search(),
    'filterModel' => $filterModel,
    'beforeHeader' => [
        [
            'columns' => [
                ['content' => '', 'options' => ['rowspan' => 2],],
                ['content' => 'Наименование клиента', 'options' => ['rowspan' => 2],],
                ['content' => 'Дата регистрации клиента', 'options' => ['rowspan' => 2],],
                ['content' => 'Сумма оплаченных услуг, за которые начисленно вознаграждение', 'options' => ['rowspan' => 2],],
                ['content' => 'Сумма оплаченных счетов', 'options' => ['rowspan' => 2],],
                ['content' => 'Сумма вознаграждения'],
            ],
            'options' => [
                'class' => GridView::DEFAULT_HEADER_CLASS,
            ],
        ],
    ],
    'columns' => [
        [
            'class' => 'kartik\grid\ExpandRowColumn',
            'width' => '50px',
            'value' => function () {
                return GridView::ROW_COLLAPSED;
            },
            'detail' => function ($row) use ($baseView, $filterModel) {
                return $baseView->render('_details', [
                    'isExtendsMode' => $filterModel->isExtendsMode,
                    'details' => $row['details'],
                    'issues' => $row['issues'],
                ]);
            },
            'headerOptions' => ['class' => 'hidden kartik-sheet-style'],
        ],
        [
            'headerOptions' => ['class' => 'hidden'],
            'format' => 'raw',
            'value' => function ($row) {
                $content = Html::a(
                    $row['contragent_name'],
                    ['client/view', 'id' => $row['client_id']],
                    ['target' => '_blank']
                );

                if (!empty($row['issues'])) {
                    $content .= Html::tag(
                        'div',
                        implode('; ', $row['issues']),
                        ['class' => 'text-danger small']
                    );
                }

                return $content;
            },
        ],
        [
            'headerOptions' => ['class' => 'hidden'],
            'format' => 'raw',
            'value' => function ($row) {
                return $row['client_created'];
            },
        ],
        [
            'headerOptions' => ['class' => 'hidden'],
            'format' => 'raw',
            'value' => function ($row) {
                return PartnerRewardsNewFilter::getNumberFormat($row['paid_summary_reward']);
            },
        ],
        [
            'headerOptions' => ['class' => 'hidden'],
            'format' => 'raw',
            'value' => function ($row) {
                return PartnerRewardsNewFilter::getNumberFormat($row['paid_summary']);
            },
        ],
        [
            'headerOptions' => ['class' => 'hidden'],
            'format' => 'raw',
            'value' => function ($row) {
                return PartnerRewardsNewFilter::getNumberFormat($row['sum']);
            },
        ],

    ],
    'afterHeader' => [
        [
            'options' => ['class' => \kartik\grid\GridView::TYPE_WARNING],
            'columns' => [
                [
                    'content' => '',
                ],
                [
                    'content' => Yii::t('common', 'Summary'),
                    'options' => ['colspan' => 2, 'class' => 'text-left'],
                ],
                [
                    'content' => PartnerRewardsNewFilter::getNumberFormat($filterModel->summary['paid_summary_reward']),
                ],
                [
                    'content' => PartnerRewardsNewFilter::getNumberFormat($filterModel->summary['paid_summary']),
                ],
                [
                    'content' => PartnerRewardsNewFilter::getNumberFormat($filterModel->summary['sum']),
                ],
            ],
        ]
    ],
    'resizableColumns' => false,
]);
