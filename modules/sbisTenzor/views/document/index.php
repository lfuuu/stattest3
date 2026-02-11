<?php

use app\classes\Html;
use app\helpers\DateTimeZoneHelper;
use app\modules\sbisTenzor\classes\SBISDocumentStatus;
use app\modules\sbisTenzor\classes\SBISExchangeStatus;
use app\modules\sbisTenzor\helpers\SBISUtils;
use app\modules\sbisTenzor\models\SBISDocument;
use yii\data\ActiveDataProvider;
use yii\helpers\Url;
use yii\widgets\Breadcrumbs;
use kartik\grid\ActionColumn;
use app\classes\grid\GridView;

list(, $assetsUrl) = Yii::$app->assetManager->publish(Yii::getAlias('@app/modules/sbisTenzor/assets'));
$this->registerJsFile($assetsUrl . '/document-statuses.js', ['position' => yii\web\View::POS_END]);

/**
 * @var ActiveDataProvider $dataProvider
 * @var \app\classes\BaseView $baseView
 * @var int $clientId
 * @var int $isAuto
 * @var int $state
 * @var string $title
 * @var int $sendAutoCount
 * @var string $sendAutoConfirmText
 */

$baseView = $this;

$this->title = $title;

echo Html::formLabel($title);
echo Breadcrumbs::widget([
    'links' => [
        'СБИС',
        ['label' => $this->title = $title,],
    ],
]);

?>

    <div class="text-right">
        <?= $this->render('//layouts/_buttonLink', [
            'url' => \Yii::$app->getRequest()->getUrl(),
            'text' => 'Обновить',
            'glyphicon' => 'glyphicon-refresh',
        ])?>
    </div>

<?php
    if ($clientId && $isAuto) :
?>
    <div class="text-center text-success">
        Для данного клиента включена автоматическая генерация пакетов документов для отправки в СБИС.<br />
        Выключить данную настройку вы можете в <a href="<?= Url::toRoute(['/account/edit', 'id' => $clientId]) ?>">профиле клиента</a>.
    </div>
<?php
    elseif ($clientId) :
?>
    <div class="text-center text-warning">
        Для данного клиента выключена автоматическая генерация пакетов документов для отправки в СБИС.<br />
        Включить данную настройку вы можете в <a href="<?= Url::toRoute(['/account/edit', 'id' => $clientId]) ?>">профиле клиента</a>.
    </div>
<?php
    endif;
?>


<?php

echo GridView::widget([
    'dataProvider' => $dataProvider,
    'columns' => [
        [
            'attribute' => 'id',
            'label' => 'ID',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                return Html::a($model->id, $model->getUrl());
            },
        ],
        [
            'attribute' => 'clientAccount.organization.id',
            'label' => 'От кого',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                $organizationFrom = $model->clientAccount->organization;

                $html = SBISUtils::getShortOrganizationName($organizationFrom);
                $organization = $model->sbisOrganization->organization;
                if ($organization->id != $organizationFrom->id) {
                    // исходная организация не совпадает с той, через которую отправляем в СБИС
                    $html =  sprintf(
                    '<strong>%s</strong><br /><small>(через %s)</small>',
                        $html,
                        SBISUtils::getShortOrganizationName($organization)
                    );
                }

                return $html = Html::tag(
                    'span',
                    $html,
                    ['class' => 'text-nowrap']
                );
            },
        ],
        [
            'attribute' => 'clientAccount.contragent.name_full',
            'label' => 'Кому',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                $client = $model->clientAccount;

                $text = $client->contragent->name;

                $text .=
                    SBISExchangeStatus::isVerifiedById($client->exchange_status) ?
                        '&nbsp;' . Html::tag('i', '', ['class' => 'glyphicon glyphicon-ok text-success']) :
                        '';

                return Html::a($text, $client->getUrl());
            },
        ],
        [
            'attribute' => 'number',
            'label' => 'Номер',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                return Html::a($model->number, $model->getUrl());
            },
        ],
        [
            'attribute' => 'date',
            'label' => 'Дата',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                return Html::a($model->date, $model->getUrl());
            },
        ],
        [
            'attribute' => 'comment',
            'label' => 'Комментарий',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                return Html::a($model->comment, $model->getUrl());
            },
        ],
        [
            'attribute' => 'attachments',
            'label' => 'Вложений',
            'format' => 'html',
            'value'     => function (SBISDocument $model) {
                return
                    implode(
                        '',
                        array_map(function($attachment) {
                            return
                                sprintf(
                                    '<a href="%s" title="%s"><span class="file20 file_%s"></span></a>',
                                    Url::toRoute([
                                        '/sbisTenzor/document/download-attachment',
                                        'id' => $attachment->id,
                                    ]),
                                    $attachment->file_name,
                                    $attachment->extension
                                );
                        }, $model->attachments)
                    );
            },
        ],
        [
            'attribute' => 'attachments',
            'label' => 'Подписан',
            'format' => 'html',
            'contentOptions' => function (SBISDocument $model) {
                return ['data-doc-signed' => $model->id];
            },
            'value'     => function (SBISDocument $model) use ($baseView) {
                return
                    $model->isSigned() ?
                        Html::tag('strong', 'Да', ['class' => 'text-success']) :
                        Html::tag('strong', 'Нет', ['class' => 'text-danger']);
            },
        ],
        [
            'attribute' => 'state',
            'format' => 'html',
            'contentOptions' => function (SBISDocument $model) {
                $isProcessing = $model->state >= SBISDocumentStatus::PROCESSING
                    && $model->state < SBISDocumentStatus::SENT;
                return [
                    'data-doc-id' => $model->id,
                    'data-doc-state' => $isProcessing ? 'active' : 'final',
                ];
            },
            'value'     => function (SBISDocument $model) {
                $progressValue = 0;
                $progressStyle = 'info';

                if (
                    $model->state >= SBISDocumentStatus::PROCESSING &&
                    $model->state < SBISDocumentStatus::SENT
                ) {
                    $progressValue = 25;
                    $progressStyle = 'danger';

                    if ($model->state == SBISDocumentStatus::SAVED) {
                        $progressValue = 50;
                        $progressStyle = 'warning';
                    } else if (in_array($model->state, [SBISDocumentStatus::NOT_SIGNED, SBISDocumentStatus::READY])) {
                        $progressValue = 75;
                        $progressStyle = 'info';
                    }
                }

                $html = '';
                if ($progressValue) {
                    $html .= '<div class="progress">
<div class="progress-bar progress-bar-' . $progressStyle . ' progress-bar-striped" role="progressbar" aria-valuenow="' . $progressValue . '"
aria-valuemin="0" aria-valuemax="100" style="width:' . $progressValue . '%">
</div>
</div>';
                }

                $external = $model->external_state_name ? : '';
                $external = $external ? sprintf('<br /><small>(%s)</small>', $external) : '';

                $linkHtml = Html::tag(
                    'span',
                    sprintf('<strong>%s</strong>%s', $model->stateName, $external),
                    ['class' => 'text-nowrap']
                );

                return
                    $html .
                        Html::a($linkHtml, $model->getUrl());
            },
        ],
        [
            'attribute' => 'created_at',
            'value'     => function (SBISDocument $model) {
                return DateTimeZoneHelper::getDateTime($model->created_at);
            },
        ],
        [
            'attribute' => 'updated_at',
            'value'     => function (SBISDocument $model) {
                return DateTimeZoneHelper::getDateTime($model->updated_at);
            },
        ],
        [
            'class' => ActionColumn::class,
            'template' => '{view} {start} {restart}',
            'buttons' => [
                'view' => function ($url, SBISDocument $model) use ($baseView) {
                    return $baseView->render('//layouts/_actionView', [
                        'url' => $model->getUrl(),
                    ]);
                },
                'start' => function ($url, SBISDocument $model) use ($baseView) {
                    if ($model->state == SBISDocumentStatus::CREATED) {
                        return $baseView->render('//layouts/_link', [
                            'url' => '/sbisTenzor/document/start?id=' . $model->id,
                            'glyphicon' => 'glyphicon-send text-success',
                            'params' => [
                                'onClick' => 'return confirm("Отправить данный пакет?")',
                            ],
                        ]);
                    }

                    return false;
                },
                'restart' => function ($url, SBISDocument $model) use ($baseView) {
                    if ($model->state == SBISDocumentStatus::ERROR) {
                        return $baseView->render('//layouts/_link', [
                            'url' => '/sbisTenzor/document/restart?id=' . $model->id,
                            'glyphicon' => 'glyphicon-send text-warning',
                            'params' => [
                                'onClick' => 'return confirm("Отправить данный пакет?")',
                            ],
                        ]);
                    }

                    return false;
                },
            ],
            'hAlign' => GridView::ALIGN_CENTER,
        ],
    ],
    'extraButtons' =>
        $this->render('//layouts/_buttonLink', [
                'url' => '/sbisTenzor/document/',
                'text' => 'Активные',
                'glyphicon' => 'glyphicon-filter',
                'class' => 'btn-xs btn-' . ($state == 0 ? 'primary' : 'default'),
            ]
        ) .
            $this->render('//layouts/_buttonLink', [
                    'url' => '/sbisTenzor/document/?state=' . SBISDocumentStatus::CREATED,
                    'text' => 'На отправку',
                    'glyphicon' => 'glyphicon-filter',
                    'class' => 'btn-xs btn-' . ($state == SBISDocumentStatus::CREATED ? 'primary' : 'default'),
                ]
            ) .
            $this->render('//layouts/_buttonLink', [
                    'url' => '/sbisTenzor/document/?state=' . SBISDocumentStatus::CANCELLED,
                    'text' => 'Отменённые',
                    'glyphicon' => 'glyphicon-filter',
                    'class' => 'btn-xs btn-' . ($state == SBISDocumentStatus::CANCELLED ? 'primary' : 'default'),
                ]
            ) .
            $this->render('//layouts/_buttonLink', [
                    'url' => '/sbisTenzor/document/?state=' . SBISDocumentStatus::ACCEPTED,
                    'text' => 'Принятые',
                    'glyphicon' => 'glyphicon-filter',
                    'class' => 'btn-xs btn-' . ($state == SBISDocumentStatus::ACCEPTED ? 'primary' : 'default'),
                ]
            ) .
            $this->render('//layouts/_buttonLink', [
                    'url' => '/sbisTenzor/document/?state=' . SBISDocumentStatus::ERROR,
                    'text' => 'Ошибка',
                    'glyphicon' => 'glyphicon-filter',
                    'class' => 'btn-xs btn-' . ($state == SBISDocumentStatus::ERROR ? 'primary' : 'default'),
                ]
            ) .
            '&nbsp;&nbsp;&nbsp;' .
            $this->render('//layouts/_buttonCreate', ['url' => '/sbisTenzor/document/add']) .
            $this->render(
                '//layouts/_link',
                [
                    'url' => '/sbisTenzor/document/send-auto',
                    'text' => sprintf('Отправить подготовленные пакеты (%s)', $sendAutoCount),
                    'glyphicon' => 'glyphicon-send',
                    'params' => [
                        'onClick' => 'return confirm("' . $sendAutoConfirmText . '")',
                        'class' => 'btn btn-success',
                    ],
                ]
            ),
    'isFilterButton' => false,
    'floatHeader' => false,
]);