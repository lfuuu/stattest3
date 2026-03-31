<?php

use app\classes\Html;
use app\forms\client\AccountEditForm;
use app\models\ClientAccount;
use app\models\ClientAccountOptions;
use app\models\Currency;
use app\models\dictionary\TrustLevel;
use app\models\GoodPriceType;
use app\models\Region;
use app\models\Timezone;
use app\models\PriceLevel;
use app\modules\sbisTenzor\classes\SBISExchangeStatus;
use app\modules\sbisTenzor\models\SBISExchangeGroup;
use app\modules\uu\models\TariffStatus;
use kartik\widgets\ActiveForm;

/** @var ActiveForm $f */
/** @var AccountEditForm $model */

$disabled = ['disabled' => 'disabled'];

$creditEditOptions = \Yii::$app->user->can('clients.new') ? [] : $disabled;

?>

<div class="max-screen">
    <div class="row">
        <div class="col-sm-3">
            <?= $f->field($model, 'region')->dropDownList(Region::getList(), ['class' => 'select2']) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'timezone_name')->dropDownList(Timezone::getList()) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'is_postpaid')->dropDownList(ClientAccount::$paymentTypes) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'effective_vat_rate')
                ->textInput($disabled)
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <?= $f->field($model, 'nal')->dropDownList(ClientAccount::$nalTypes) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'currency')->dropDownList(Currency::map(), ['class' => 'select2']) ?>
        </div>
        <!--        <div class="col-sm-3">-->
        <!--            --><?php /* echo $f->field($model, 'price_type')->dropDownList(GoodPriceType::getList()) */ ?>
        <!--        </div>-->
        <div class="col-sm-3">
            <?= $f->field($model, 'options[trust_level_id]')
                ->dropDownList(TrustLevel::getList())
                ->label($model->getAttributeLabel('trust_level_id'))
            ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'pay_bill_until_days') ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <?= $f->field($model, 'credit', ['options' => ['id' => 'credit-size']])->textInput($creditEditOptions) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'anti_fraud_disabled')->checkbox()->label('') ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'lk_balance_view_mode')->dropDownList(ClientAccount::$balanceViewMode) ?>
        </div>
        <div class="col-sm-3">
            <?php
            $accountVersionList = ClientAccount::$versions;
            $accountVersionOptions = [];
            if ($model->account_version == ClientAccount::VERSION_BILLER_UNIVERSAL) {
                unset($accountVersionList[ClientAccount::VERSION_BILLER_USAGE]);
                $accountVersionOptions = ['disabled' => 'disabled'];
            }
            ?>
            <?= $f->field($model, 'account_version')->dropDownList(
                $accountVersionList,
                $accountVersionOptions
            ) ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <?= $f->field($model, 'voip_credit_limit_day')->textInput($creditEditOptions) ?>
        </div>
        <div class="col-sm-3">
            <?php
            $voipCreditLimitDayWhen = (array)$model->getModel()->getOption(ClientAccountOptions::OPTION_VOIP_CREDIT_LIMIT_DAY_WHEN);
            $voipCreditLimitDayValue = (array)$model->getModel()->getOption(ClientAccountOptions::OPTION_VOIP_CREDIT_LIMIT_DAY_VALUE);

            $result = [];

            if (count($voipCreditLimitDayWhen)) {
                $result[] = 'Дата пересчета: ' . array_shift($voipCreditLimitDayWhen);
            }

            if (count($voipCreditLimitDayValue)) {
                $result[] = 'Пересчитанное значение: ' . array_shift($voipCreditLimitDayValue);
            }

            echo $f->field($model, 'voip_is_day_calc')->checkbox()->label('');
            echo implode(Html::tag('br'), $result);
            ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'price_level')->dropDownList(PriceLevel::getList()) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'uu_tariff_status_id')->dropDownList(TariffStatus::getList($serviceTypeId = null, $isWithEmpty = true)) ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <?= $f->field($model, 'voip_limit_mn_day')->textInput($creditEditOptions) ?>
        </div>
        <div class="col-sm-3">
            <?php
            $voipCreditLimitDayMNWhen = (array)$model->getModel()->getOption(ClientAccountOptions::OPTION_VOIP_CREDIT_LIMIT_DAY_MN_WHEN);
            $voipCreditLimitDayMNValue = (array)$model->getModel()->getOption(ClientAccountOptions::OPTION_VOIP_CREDIT_LIMIT_DAY_MN_VALUE);

            $result = [];

            if (count($voipCreditLimitDayMNWhen)) {
                $result[] = 'Дата пересчета: ' . array_shift($voipCreditLimitDayMNWhen);
            }

            if (count($voipCreditLimitDayMNValue)) {
                $result[] = 'Пересчитанное значение: ' . array_shift($voipCreditLimitDayMNValue);
            }

            echo $f->field($model, 'voip_is_mn_day_calc')->checkbox()->label('');
            echo implode(Html::tag('br'), $result);
            ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'show_in_lk')->dropDownList(ClientAccount::getShowInLkList()) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'options[settings_advance_invoice]')
                ->dropDownList(ClientAccountOptions::$settingsAdvance)
                ->label($model->getAttributeLabel('settings_advance_invoice'))
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-9">
            &nbsp;
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, ClientAccountOptions::OPTION_UPLOAD_TO_SALES_BOOK)
                ->checkbox(\Yii::$app->user->can('newaccounts_payments.delete') ? [] : ['disabled' => true]) ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <?= $f
                ->field($model, 'options[mail_delivery_variant]')
                ->dropDownList([
                    'undefined' => 'Не определились / Не отправляем',
                    'payment' => 'Платная рассылка почтой РФ',
                    'by_self' => 'Самовывоз',
                ])
                ->label('Тип рассылки документов')
            ?>
        </div>
        <div class="col-sm-3">
            <?= $f
                ->field($model, 'options[black_list]')
                ->checkbox(['label' => 'Черный список'])
                ->label('')
            ?>
        </div>
        <div class="col-sm-3">
            <?= $f
                ->field($model, 'is_with_consignee')
                ->checkbox(['id' => 'with-consignee'])
                ->label('')
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <?= $f->field($model, 'address_post') ?>
        </div>
        <div class="col-sm-6">
            <?= $f->field($model, 'head_company') ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <?= $f->field($model, 'address_post_real') ?>
        </div>
        <div class="col-sm-6">
            <?= $f->field($model, 'head_company_address_jur') ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <?= $f->field($model, 'mail_who') ?>
        </div>
        <div class="col-sm-6">
            <?= $f->field($model, 'consignee', ['options' => ['id' => 'consignee']]) ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <?= $f->field($model, 'form_type')->dropDownList(ClientAccount::$formTypes) ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'stamp')->checkbox()->label('') ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'is_upd_without_sign')->checkbox()->label('') ?>
        </div>
        <div class="col-sm-3">
            <?= $f->field($model, 'type_of_bill')->checkbox()->label('') ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-3">
            <?= $f
                ->field($model, 'bill_rename1')
                ->radioList([
                    'yes' => 'Оказанные услуги по Договору',
                    'no' => 'Абонентская плата по Договору',
                ])
            ?>
        </div>
        <?php
        $exchangeError = $model->getExchangeGroupError();

        $contractorId = !$model->isNewRecord ? $model->getContractorInfo()->contractor->id : 0;
        ?>
        <div class="col-sm-3">
            <div class="form-group">
                <label>Интеграция со СБИС</label>
                <?= $contractorId ? $f->field($model, 'contractor_exchange_id')
                    ->dropDownList(\app\modules\sbisTenzor\models\SBISContractorExchange::getList($contractorId, $isWithEmpty = true)) : ''
                ?>
                <?php if ($exchangeError) { ?>
                    <br>
                    <span class="text-warning"><?= $exchangeError ?></span>
                <?php } else { ?>
                    <?= $f->field($model, 'exchange_group_id')->dropDownList(SBISExchangeGroup::getList($model->getModel(), $isWithEmpty = true)) ?>
                    <?= $model->getEdfOperatorHtml() ?>
                <?php } ?>
            </div>
        </div>

        <div class="col-sm-3">
            <?= $f
                ->field($model, "options[" . ClientAccountOptions::OPTION_SBIS_DOC_BASE . "]")
                ->dropDownList(ClientAccountOptions::$sbisDocumentBaseList)
                ->label('СБИС. Основание. (Акт)')
            ?>
        </div>
        <div class="col-sm-3">
            <?php
            if (!$exchangeError) {
                echo $f->field($model, 'exchange_status')->dropDownList(SBISExchangeStatus::$states);
            }
            ?>
        </div>
        <div class="col-sm-3"><?= $f->field($model, 'transfer_params_from')->dropDownList($model->getNearAccounts()) ?></div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <?= $f->field($model, 'bik') ?>
        </div>
        <div class="col-sm-6">
            <?= $f
                ->field($model, 'corr_acc')
                ->textInput([
                    'disabled' => 'disabled',
                ])
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <?= $f->field($model, 'pay_acc') ?>
        </div>
        <div class="col-sm-6">
            <?= $f
                ->field($model, 'bank_name')
                ->textInput([
                    'disabled' => 'disabled',
                ])
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6"></div>
        <div class="col-sm-6">
            <?= $f
                ->field($model, 'bank_city')
                ->textInput([
                    'disabled' => 'disabled',
                ])
            ?>
        </div>
    </div>
    <?php if ($model->isShowTransferContract()) : ?>
        <div class="row">
            <div class="col-sm-6"></div>
            <div class="col-sm-6">
                <?= $f
                    ->field($model, 'transfer_contract_id')
                    ->dropDownList($model->getNearContracts())
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>