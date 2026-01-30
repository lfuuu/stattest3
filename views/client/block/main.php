<?php

use app\classes\api\ApiCore;
use app\classes\DateTimeWithUserTimezone;
use app\classes\Html;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\Locks;
use app\models\ClientAccount;
use app\models\ClientContract;
use app\models\ClientSuper;
use app\models\PriceLevel;
use app\models\EventQueueIndicator;
use yii\helpers\Url;

/** @var ClientSuper $client */
/** @var \app\models\ClientAccount $account */

$priceLevels = PriceLevel::getList();
?>

<div class="main-client">

    <div class="row">
        <div class="col-sm-6">
            <h2 class="c-blue-color no-margin">
                <?= Html::a(trim($client->name) ? $client->name : '<i>Не задано</i>', ['/account/super-client-edit', 'id' => $client->id, 'childId' => $account->id]) ?>
                <small>(<?= ($client->entry_point_id ? $client->entryPoint->name : '---') . '/' . ($client->entry_point_id && $client->entryPoint->country_id ? $client->entryPoint->country->name : '---') ?>)</small>
            </h2>
        </div>
        <div class="col-sm-4" class="c-blue-color">
            <?php if (ApiCore::isAvailable()) : ?>
                    <a href="<?= ClientAccount::getLinkToLk($account->id) ?>" target="_blank">
                        Переход в ЛК
                    </a>
                <?php if ($client->isShowLkLink()) : ?>
                <?php elseif ($indicator = EventQueueIndicator::findOne(['object' => ClientSuper::tableName(), 'object_id' => $account->super_id])) : ?>
                    <?php

                    echo $this->render('//layouts/_eventIndicator', ['indicator' => $indicator]);

                    if ($indicator->event && $indicator->event->log_error && strpos($indicator->event->log_error, '[-] 503:') === 0) {
                        echo $this->render('add_admin_email', ['emails' => $client->getAdminEmails(), 'account' => $account]);
                    }

                    ?>
                <?php elseif ($adminEmails = $client->getAdminEmails()) : ?>
                    <?= $this->render('add_admin_email', ['emails' => $adminEmails, 'account' => $account]) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="col-sm-2 c-blue-color">
            <a href="<?= Url::toRoute(['contragent/create', 'parentId' => $client->id, 'childId' => $account->id]) ?>">
                <span class="c-blue-color">
                    <i class="glyphicon glyphicon-plus"></i> Новый контрагент
                </span>
            </a>
        </div>
        <div class="col-sm-12">
            <?php $contragents = $client->contragents;
            foreach ($contragents as $k => $contragent): ?>
                <div class="row contragent-wrap" id="contragent<?= $contragent->id ?>">
                    <div class="col-sm-5 title">
                        <a href="<?= Url::toRoute(['contragent/edit', 'id' => $contragent->id, 'childId' => $account->id]) ?>">
                            <span class="c-blue-color">
                                <?= trim($contragent->name) ? $contragent->name : '<i>Не задано</i>' ?>
                                <?php if ($contragent->is_lk_first) :
                                    $importLkStatus = $contragent->importLkStatus;
                                    ?>
                                <?=Html::tag('span', '', [
                                    'class' => 'text-'.($importLkStatus ? ($importLkStatus->status_code == 'ok' ? 'info' : 'danger') : 'warning').' small glyphicon glyphicon-warning-sign',
                                    'title' => ($importLkStatus && $importLkStatus->status_code != 'ok' ? $importLkStatus->status_text . PHP_EOL : '') . 'Редактирование основных данных контрагента доступно только в ЛК',
                                ]) ?>
                                <?php endif; ?>
                            </span>
                        </a>
                    </div>
                    <div class="col-sm-5 address">
                        <span><?= $contragent->address ?: '...' ?></span>
                    </div>
                    <div class="col-sm-2">
                        <a href="<?= Url::toRoute(['contract/create', 'parentId' => $contragent->id, 'childId' => $account->id]) ?>">
                            <span class="c-blue-color">
                                <i class="glyphicon glyphicon-plus"></i> Новый договор
                            </span>
                        </a>
                    </div>
                    <div class="col-sm-12">
                        <?php $contracts = $contragent->contracts;
                        foreach ($contracts as $contract):
                            ?>
                            <div class="row contract-block">
                                <div class="col-sm-5">
                                    <a href="<?= Url::toRoute(['contract/edit', 'id' => $contract->id, 'childId' => $account->id]) ?>">
                                        <span class="c-blue-color">
                                            Договор № <?= $contract->number ?: 'Без номера' ?> (id: <?= $contract->id ?>)
                                            (<?= $contract->organization->name ?>)
                                        </span>
                                        &nbsp;
                                        <span>
                                            <i class="<?= $contract->state == ClientContract::STATE_UNCHECKED ? 'uncheck' : 'check' ?>"></i>
                                            <?php $states = ClientContract::$states;
                                            echo $states[$contract->state]; ?>
                                        </span>
                                    </a>
                                </div>
                                <div class="col-sm-3">
                                    <?php $bps = $contract->businessProcessStatus; ?>
                                    <span><?= $contract->business ?></span>&nbsp;
                                    <?php /*/&nbsp;<?= $contract->businessProcess ?></span>&nbsp;*/
                                    ?>
                                    /&nbsp;<b
                                            style="background:<?= isset($bps['color']) ? $bps['color'] : '' ?>;"><?= isset($bps['name']) ? $bps['name'] : '' ?></b>
                                </div>
                                <div class="col-sm-4">
                                    <?php if ($contract->managerName) : ?>
                                        <span class="pull-left"
                                              style="background-color: <?= $contract->managerColor ?>;">
                                            М: <?= $contract->managerName ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($contract->accountManagerName) : ?>
                                        <span class="pull-right"
                                              style="background-color: <?= $contract->accountManagerColor ?>;">
                                            Ак.М: <?= $contract->accountManagerName ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-sm-12">
                                    <?php foreach ($contract->comments as $comment) {
                                        if ($comment->is_publish) : ?>
                                            <div class="col-sm-12">
                                                <b><?= $comment->user ?> <?= DateTimeZoneHelper::getDateTime($comment->ts) ?>
                                                    : </b><?= $comment->comment ?>
                                            </div>
                                        <?php endif;
                                    } ?>
                                </div>
                                <div class="col-sm-12">
                                    <?php
                                    $lastAccountComment = null;
                                    ?>
                                    <?php foreach ($contract->accounts as $index => $contractAccount): ?>
                                        <?php
                                        $warnings = $contractAccount->voipWarnings;
                                        $isLastDt = isset($warnings[ClientAccount::WARNING_LAST_DT]) && $warnings[ClientAccount::WARNING_LAST_DT];
                                        $contractBlockers = [];

                                        $lockByCredit = isset($warnings[ClientAccount::WARNING_CREDIT]) || isset($warnings[ClientAccount::WARNING_FINANCE]);
                                        $lockByDayLimit = isset($warnings[ClientAccount::WARNING_LIMIT_DAY]);
                                        ?>
                                        <div class="edit-lnk<?= $index ? ' indent' : '' ?>">
                                            <a href="<?= Url::toRoute(['/account/edit', 'id' => $contractAccount->id]) ?>">
                                                <img src="/images/icons/edit.gif"/>
                                            </a>
                                        </div>
                                        <?php
                                        $accountBlockClasses = ['row', 'row-ls'];
                                        $account && $account->id == $contractAccount->id && $accountBlockClasses[] = ($account->contract->organization->vat_rate == 0 ? 'active-client-mcm' : 'active-client');
                                        $index && $accountBlockClasses[] = 'indent';
                                        $lastAccountComment && $accountBlockClasses[] = 'indent_with_comment';
                                        ?>
                                        <div
                                                data-contract-id="<?= $contractAccount->id ?>"
                                                class="<?= implode(' ', $accountBlockClasses) ?>"
                                        >
                                            <span class="col-sm-2 account-type<?= ($contractAccount->is_active) ? ' active' : '' ?>">
                                                <?= $contractAccount->getAccountTypeAndId() ?>
                                            </span>
                                            <span class="col-sm-2 locks">
                                                <?php
                                                if ($contractAccount->is_blocked) {
                                                    $contractBlockers[] = 'Заблокирован' .
                                                        ($isLastDt ?
                                                            ': ' . (new DateTimeWithUserTimezone($warnings[ClientAccount::WARNING_LAST_DT], $account->timezone))->format('H:i:s d.m.Y') :
                                                            ''
                                                        );
                                                }

                                                if (isset($warnings[ClientAccount::WARNING_OVERRAN])) {
                                                    $contractBlockers[] = Html::tag('abbr',
                                                        'Блок превышение' .
                                                        (
                                                        $isLastDt ?
                                                            ': ' . (new DateTimeWithUserTimezone($warnings[ClientAccount::WARNING_LAST_DT], $account->timezone))->format('H:i:s d.m.Y') :
                                                            ''
                                                        ),
                                                        [
                                                            'title' => 'ЛС заблокирован по превышению лимитов. Возможно, его взломали'
                                                        ]
                                                    );
                                                }

                                                if (isset($warnings[ClientAccount::WARNING_MN_OVERRAN])) {
                                                    $contractBlockers[] = Html::tag('abbr',
                                                        'Блок превышение МН' .
                                                        (
                                                        $isLastDt ?
                                                            ': ' . (new DateTimeWithUserTimezone($warnings[ClientAccount::WARNING_LAST_DT], $account->timezone))->format('H:i:s d.m.Y') :
                                                            ''
                                                        ),
                                                        [
                                                            'title' => 'ЛС заблокирован по превышению лимитов (МН). Возможно, его взломали'
                                                        ]
                                                    );
                                                }

                                                if ($lockByDayLimit) {
                                                    $contractBlockers[] = Html::tag('abbr', 'Блок лимит', ['title' => 'ЛС заблокирован по превышению дневного лимита']);
                                                }

                                                if ($lockByCredit) {
                                                    $contractBlockers[] = Html::tag('abbr', 'Блок фин.', ['title' => 'ЛС находится в финансовой блокировке. Она автоматически снимется после пополнения счета']);
                                                }

                                                echo implode(' / ', $contractBlockers);
                                                ?>
                                            </span>
                                            <span class="col-sm-1 text-right">
                                                <?= $contractAccount->regionName ?>
                                            </span>
                                            <span class="col-sm-2 text-right">
                                                <abbr
                                                        title="Текущий баланс лицевого счета"
                                                        class="text-nowrap"
                                                        style="color:<?= ($lockByCredit ? 'red' : 'green'); ?>;"
                                                >
                                                    <div id="balance_<?= $contractAccount->id ?>" class="balance_info"
                                                         data-id="<?= $contractAccount->id ?>"><?= sprintf('%0.2f', $contractAccount->billingCounters->realtimeBalance) ?>
                                                        <?= $contractAccount->currency ?></div>
                                                </abbr>
                                                <br/>
                                                <abbr title="Размер кредита" class="text-nowrap">
                                                    <?= $contractAccount->credit >= 0 ? 'Кредит: ' . $contractAccount->credit : '' ?>
                                                </abbr>
                                            </span>
                                            <span class="col-sm-1 text-right">
                                                <?php if ($contract->business_id == \app\models\Business::OPERATOR) : ?>
                                                    <abbr
                                                            title="Реалтаймовый счетчик стоимости всех входящих звонков по ЛС"
                                                            class="text-nowrap"
                                                            style="color:<?= ($lockByDayLimit ? 'red' : 'green'); ?>;"
                                                    >
                                                        Ориг.: <?= abs($contractAccount->interopCounter->outcome_sum); ?> <?= $contractAccount->currency; ?>
                                                    </abbr>
                                                    <br/>
                                                    <abbr
                                                            title="Реалтаймовое счетчик стоимости всех исходящих звонков по ЛС"
                                                            class="text-nowrap"
                                                            style="color:<?= ($lockByDayLimit ? 'red' : 'green'); ?>;"
                                                    >
                                                        Терм.: <?= abs($contractAccount->interopCounter->income_sum); ?> <?= $contractAccount->currency; ?>
                                                    </abbr>

                                                <?php else: ?>
                                                    <abbr
                                                            title="Суточный расход" class="text-nowrap"
                                                            style="color:<?= ($lockByDayLimit ? 'red' : 'green'); ?>;"
                                                    >
                                                        <?= abs($contractAccount->billingCounters->daySummary); ?>
                                                        <?= $contractAccount->currency; ?>
                                                    </abbr>
                                                    <br/>
                                                    <abbr title="Дневной лимит" class="text-nowrap">
                                                        <?php
                                                        echo ($contractAccount->voip_is_day_calc === 1 ? 'Авто лим. ' : 'Сут.лим. ') . ': ';
                                                        echo $contractAccount->voip_credit_limit_day;
                                                        ?>
                                                    </abbr>
                                                <?php endif; ?>
                                            </span>
                                            <span class="col-sm-2 text-right">
                                                <?= $priceLevels[$contractAccount->price_level] ?> / <?= ClientAccount::$paymentTypes[$contractAccount->is_postpaid] ?? 'unknown' ?>
                                            </span>
                                            <div class="btn-group pull-right">
                                                <?php if ($contractAccount->hasVoip) : ?>
                                                    <?php if ($contractAccount->voip_disabled /*&& !isset($warnings[ClientAccount::WARNING_BILL_PAY_OVERDUE])*/) : ?>
                                                        <button
                                                                type="button"
                                                                class="btn btn-sm set-voip-disabled <?= $contractAccount->voip_disabled ? 'btn-danger' : 'btn-success' ?>"
                                                                style="width: 120px;padding: 3px 10px;"
                                                                data-id="<?= $contractAccount->id ?>"
                                                                title="<?= $contractAccount->voip_disabled ? 'Выключить локальную блокировку' : 'Включить локальную блокировку' ?>"
                                                        >
                                                            <?= $contractAccount->voip_disabled ? 'Лок. разблок.' : 'Лок. блок.' ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <button
                                                        type="button"
                                                        class="btn btn-sm set-block <?= $contractAccount->is_blocked ? 'btn-danger' : 'btn-success' ?>"
                                                        data-id="<?= $contractAccount->id ?>"
                                                >
                                                    <?= $contractAccount->is_blocked ? 'Разблокировать' : 'Заблокировать' ?>

                                                    <?= $this->render('//layouts/_eventIndicator', [
                                                        'object' => ClientAccount::tableName(),
                                                        'objectId' => $contractAccount->id,
                                                        'section' => EventQueueIndicator::SECTION_ACCOUNT_BLOCK
                                                    ]) ?>
                                                </button>
                                            </div>
                                            <?php if ($warnings) : ?>
                                                <div class="col-sm-9">
                                                    <?php foreach ($warnings as $warningCode => $warningText): ?>
                                                        <?php if (is_string($warningText)) : ?>
                                                            <span class="label label-danger"><?= $warningText; ?></span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <?php if (
                                                            isset($warnings[ClientAccount::WARNING_FINANCE_LAG])
                                                            && \Yii::$app->user->can('clients.read')
                                                            && \Yii::$app->isRus()
                                                    ): ?>
                                                    <a
                                                            class="btn btn-sm btn-warning"
                                                            style="padding: 0px 4px;"
                                                            href="<?= Url::toRoute(['/account/fix-fin-lock', 'id' => $contractAccount->id]) ?>"
                                                    >
                                                        Принудительный сброс фин.блокировки
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                        if ($lastAccountComment = $contractAccount->lastAccountComment): ?>
                                            <div class="col-sm-12">
                                                <?= $lastAccountComment->user ?> <?= DateTimeZoneHelper::getDateTime($lastAccountComment->created_at) ?>
                                                : <?= $lastAccountComment ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
