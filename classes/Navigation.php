<?php

namespace app\classes;

use app\helpers\DateTimeZoneHelper;
use app\models\billing\Pricelist;
use app\models\Business;
use app\models\BusinessProcess;
use Yii;
use yii\helpers\Url;


/**
 * Class Navigation
 */
class Navigation
{
    private $_blocks = [];

    /**
     * Navigation constructor.
     *
     * @throws \yii\base\InvalidParamException
     */
    private function __construct()
    {
        global $fixclient_data;

        $isRus = \Yii::$app->isRus();

        $this->addBlock(
            NavigationBlock::create()
                ->setRights(['clients.read'])
                ->setTitle('Клиенты')
                ->addItem('Новый клиент', Url::toRoute(['/client/create']), 'clients.new')
                ->addItem('Мои клиенты', Url::toRoute([
                    '/client/search',
                    'manager' => Yii::$app->user->identity->user,
                    'account_manager' => Yii::$app->user->identity->user
                ]), 'clients.read')
                ->addItem('Каналы продаж', '/sale-channel/index', 'clients.edit')
                ->addItem('Отчет по файлам', '/file/report', 'clients.edit')
        );
        $this->_addBlockNewClients();

        if ($isRus) {
            $this->_addBlockForStatModule('services');
        }

        $accountBlock = NavigationBlock::create()
            ->setTitle('Бухгалтерия')
            ->addStatModuleItems('newaccounts');

        if ($fixclient_data) {
            $accountBlock->addItem('Перенос платежей', '/payment/yandex-transfer', 'newaccounts_payments.delete');
        }

        $accountBlock->addItem('Реестр неоплаченных счетов', '/report/operator-pay/', 'clients.edit');
        $accountBlock->addItem('Платежи',
            [
                '/report/accounting/pay-report/',
            ],
            'newaccounts_payments.read'
        );

        $accountBlock->addItem('Печать закрывающих документов', '/report/closing-statements-print/', 'clients.edit');
        $accountBlock->addItem('Контроль закрывающих документов', ['/report/accounting/closing-document-coverage'], ['clients.read']);

        $this->addBlock($accountBlock);


        if ($isRus) {
            $this->addBlock(
                NavigationBlock::create()
                    ->setTitle('Тарифы')
                    ->addItem('Телефония', ['/tariff/voip'], ['tarifs.read'])
                    ->addItem('Телефония Пакеты', ['/tariff/voip-package'], ['tarifs.read'])
                    ->addItem('Звонок_чат', ['/tariff/call-chat'], ['tarifs.read'])
                    ->addStatModuleItems('tarifs')
            );
        }
        $this->_addBlockForStatModule('tt');
        $this->addBlock(
            NavigationBlock::create()
                ->setTitle('Статистика')
                ->addStatModuleItems('stats')
                ->addItem('Мобильный интернет', ['/voip/data-raw'], ['stats.report'])
                ->addItem('SMS', ['/voip/sms'], ['stats.report'])
                ->addItem('SMS (A2P)', ['/stats/a2p'], ['stats.report'])
                ->addItem('Отчёт по файлам', ['/file/report'], ['stats.report'])
                ->addItem('Отчет по OnLime', ['/reports/onlime-report'], ['stats.report'])
                ->addItem('Отчет по OnLime оборудование', ['/reports/onlime-devices-report'], ['stats.report'])
                ->addItem('Себестоимость звонков', ['/report/voip/cost-report'], ['stats.report'])
                ->addItem('Статистика: Вызовы-API', ['/stats/billing-api'], ['stats.r'])
                ->addItem('ИИ-агент: Диалоги', ['/stats/ai-dialogs'], ['stats.r'])
        );
        if ($isRus) {
            $this->_addBlockForStatModule('routers');
        }

        $monitorBlock = NavigationBlock::create()
            ->setTitle('Мониторинг')
            ->addStatModuleItems('monitoring')
            ->addItem('Перемещаемые услуги', ['/monitoring/transfered-usages'], [])
            ->addItem('Ключевые события', ['/monitoring'], [])
            ->addItem('Очередь событий', ['/monitoring/event-queue'], []);

        if ($isRus) {
            $monitorBlock->addItem('Монитор "здоровья"', 'https://voipgui.mcn.ru/health/health.html', []);
        } else {
            $monitorBlock->addItem('Монитор "здоровья"', 'https://voipgui.kompaas.tech/health/health.html', []);
        }

        $monitorBlock->addItem('Изменившиеся счета', ['/monitoring/changed-bills'], ['clients.edit']);

        $this->addBlock($monitorBlock);

        $this->addBlock(
            NavigationBlock::create()
                ->setTitle('Управление доступом')
                ->addItem('Операторы', ['/user/control'], ['users.r'])
                ->addItem('Группы', ['/user/group'], ['users.r'])
                ->addItem('Отделы', ['/user/department'], ['users.r'])
        //->addItem('Обновить права в БД', ['/user/control/update-rights'], ['users.r'])
        );
//        $this->_addBlockForStatModule('send');
        if ($isRus) {
            $this->_addBlockForStatModule('employeers');
        }


        $mailBlock = NavigationBlock::create()
            ->setTitle('Письма клиентам')
            ->addStatModuleItems('mail');

        if ($isRus && ($_SERVER['MCHS_API_KEY'] ?? false)) {
            $mailBlock->addItem('Сообщение от МЧС', ['/mchs'], ['mchs.read']);
        }

        $this->addBlock($mailBlock);

        $phonesBlock = NavigationBlock::create()
            ->setTitle('Телефония');

        if ($isRus) {
            $phonesBlock
                ->addStatModuleItems('voipnew')
                ->addItem('Прайс-листы Клиент Ориг', ['/voip/pricelist/list', 'type' => Pricelist::TYPE_CLIENT, 'orig' => 1], ['voip.access'])
                ->addItem('Прайс-листы Клиент Терм', ['/voip/pricelist/list', 'type' => Pricelist::TYPE_CLIENT, 'orig' => 0], ['voip.access'])
                ->addItem('Прайс-листы Опер Ориг', ['/voip/pricelist/list', 'type' => Pricelist::TYPE_OPERATOR, 'orig' => 1], ['voip.access'])
                ->addItem('Прайс-листы Опер Терм', ['/voip/pricelist/list', 'type' => Pricelist::TYPE_OPERATOR, 'orig' => 0], ['voip.access'])
                ->addItem('Прайс-листы Местные Терм', ['/voip/pricelist/list', 'type' => Pricelist::TYPE_LOCAL, 'orig' => 0], ['voip.access'])
                ->addItem('Местные Префиксы', ['/voip/network-config/list'], ['voip.access'])
                ->addItem('Списки префиксов', ['/voip/prefixlist'], ['voip.access'])
                ->addItem('Направления', ['/voip/destination'], ['voip.access']);
        }
        $phonesBlock->addItem('DID группы', ['/tariff/did-group/'], ['tarifs.read'])
            ->addItem('Номера', ['/voip/number'], ['stats.report'])
            ->addItem('Реестр номеров', ['/voip/registry'], ['voip.access'])
            ->addItem('Реестр номеров 2.0 (ННП)', ['/voip/nnpreg'], ['voip.access'])
            ->addItem('Отчет по calls_raw (старая склейка)', ['/voip/raw/old'], ['voip.access'])
            ->addItem('Отчет по calls_raw', ['/voip/raw/with-cache'], ['voip.access'])
            ->addItem('Отчет по calls_raw (таблица склейки)', ['/voip/raw/unite'], ['voip.access'])
            ->addItem('Статистика (4 класс + 5 класс)', ['/voip/combined-statistics'], ['voip.access']);

        $this->addBlock($phonesBlock);


        $this->addBlock(
            NavigationBlock::create()
                ->setTitle('Межоператорка (Отчеты)')
                ->addStatModuleItems('voipreports')
                ->addItem('Загруженность номеров', ['/voipreport/cdr-workload'], ['voipreports.access'])
        );

        // $this->addBlockForStatModule('voipreports');
//        $this->_addBlockForStatModule('ats');
//        $this->_addBlockForStatModule('data');
        if ($isRus) {
            $this->_addBlockForStatModule('incomegoods');
        }

        $this->addBlock(
            NavigationBlock::create()
                ->setTitle('Логи')
                ->addStatModuleItems('logs')
                ->addItem('Значимые события', ['/important_events/report'])
        );

        $dictBlock = NavigationBlock::create()
            ->setId('dictionaries')
            ->setTitle('Словари')
            ->addItem('Организации', ['/organization'], ['organization.read'])
            ->addItem('Ответственные лица', ['/person'], ['person.read'])
            ->addItem('Названия событий', ['/important_events/names'], ['dictionary-important-event.important-events-names'])
            ->addItem('Группы событий', ['/important_events/groups'], ['dictionary-important-event.important-events-groups'])
            ->addItem('Источники событий', ['/important_events/sources'], ['dictionary-important-event.important-events-sources'])
            ->addItem('Страны', ['/dictionary/country/'], ['dictionary.read'])
            ->addItem('Города', ['/dictionary/city/'], ['dictionary.read'])
            ->addItem('Регионы (точки подключения)', ['/dictionary/region/'], ['dictionary.read'])
            ->addItem('Методы биллингования', ['/dictionary/city-billing-methods/'], ['dictionary.read'])
            ->addItem('Настройки платежных документов', ['/dictionary/invoice-settings'], ['dictionary.read'])
            ->addItem('Точка входа', ['/dictionary/entry-point'], ['dictionary.read'])
            ->addItem('Публичные сайты', ['/dictionary/public-site'], ['dictionary.read'])
            ->addItem('Метки договоров', ['/dictionary/tags'], ['dictionary.read'])
            ->addItem('Статусы бизнес процессов', ['/dictionary/business-process-status'], ['dictionary-statuses.read'])
            ->addItem('Общие настройки', ['/settings/'], ['dictionary.read'])
            ->addItem('API Каналы платежей', ['/dictionary/' . \app\models\PaymentApiChannel::NAVIGATION])
            ->addItem('Уровни цен', ['/dictionary/price-level'], ['dictionary.read'])
            ->addItem('Телефония: Источники', ['/dictionary/voip/source'], ['dictionary.read']);

        if ($isRus) {
            $dictBlock->addItem('Roistat. Настройки номеров.', ['/dictionary/roistat-number-fields']);
        }

        $this->addBlock($dictBlock);

        $this->addBlock(
            NavigationBlock::create()
                ->setId('templates')
                ->setTitle('Шаблоны')
                ->addItem('Договоры', ['/templates/document/template'])
                ->addItem('Универсальные счета-фактуры', ['/templates/uu/invoice'], ['newaccounts_balance.read'])
                ->addItem('Типы для документов', ['/dictionary/payment-template-type'], ['dictionary.read'])
                ->addItem('Шаблоны для документов', ['/templates/uu/payment'], ['dictionary.read'])
        );

        /** @var \app\modules\uu\Module $module */
        if ($module = Yii::$app->getModule('uu')) {
            $module->getNavigation($this);
        }

        /** @var \app\modules\nnp\Module $module */
        if ($module = Yii::$app->getModule('nnp')) {
            $module->getNavigation($this);
        }

        /** @var \app\modules\nnp2\Module $module */
        if ($isRus && $module = Yii::$app->getModule('nnp2')) {
            $module->getNavigation($this);
        }

        /** @var \app\modules\notifier\Module $module */
        if ($module = Yii::$app->getModule('notifier')) {
            $module->getNavigation($this);
        }

        /** @var \app\modules\sbisTenzor\Module $module */
        if ($isRus && $module = Yii::$app->getModule('sbisTenzor')) {
            $module->getNavigation($this);
        }

        /** @var \app\modules\sim\Module $module */
        if ($isRus && $module = Yii::$app->getModule('sim')) {
            $module->getNavigation($this);
        }

        if ($isRus && access('sorm', 'read') && $module = Yii::$app->getModule('sorm')) {
            $module->getNavigation($this);
        }
    }

    /**
     * @return Navigation
     */
    public static function create()
    {
        if (!function_exists('access')) {
            include_once Yii::$app->basePath . '/classes/compatibility.php';
        }

        return new self();
    }

    /**
     * @return NavigationBlock[]
     */
    public function getBlocks()
    {
        return $this->_blocks;
    }

    /**
     * Добавление блока навигации
     *
     * @param NavigationBlock $block
     * @return $this
     */
    public function addBlock(NavigationBlock $block)
    {
        if (!$block->id) {
            $block->id = 'block' . md5($block->title);
        }

        if (empty($block->items)) {
            return $this;
        }

        if ($block->rights) {
            foreach ($block->rights as $right) {
                if (Yii::$app->user->can($right)) {
                    $this->_blocks[] = $block;
                    break;
                }
            }
        } else {
            $this->_blocks[] = $block;
        }

        return $this;
    }

    /**
     * Добавление навигации из статовского модуля
     *
     * @param string $moduleName
     * @return $this|null
     */
    private function _addBlockForStatModule($moduleName)
    {
        $statModule = StatModule::getHeadOrModule($moduleName);

        list($title, $items) = $statModule->GetPanel(null);

        if (!$title || !$items) {
            return null;
        }

        $block = NavigationBlock::create()
            ->setId($moduleName)
            ->setTitle($title);

        foreach ($items as $item) {
            $url = substr($item[1], 0, 1) == '/' ? $item[1] : '?' . $item[1];
            $block->addItem($item[0], $url);
        }

        if ($block !== null) {
            $this->addBlock($block);
        }

        return $this;
    }

    /**
     * Add Block New Clients
     */
    private function _addBlockNewClients()
    {
        $exclusion = [
            2 => '/?module=tt&action=view_type&type_pk=8',
            3 => '/?module=tt&action=view_type&type_pk=4',
            5 => '/?module=tt&action=view_type&type_pk=7',
        ];
        $businesses = Business::find()
            ->innerJoinWith('businessProcesses')
            ->orderBy([
                Business::tableName() . '.sort' => SORT_ASC,
                BusinessProcess::tableName() . '.sort' => SORT_ASC,
            ])
            ->all();

        foreach ($businesses as $business) {
            $block = NavigationBlock::create()
                ->setId('client_' . $business->id)
                ->setRights(['clients.read'])
                ->setTitle($business->name);

            foreach ($business->businessProcesses as $process) {
                $block->addItem($process->name,
                    isset($exclusion[$process->id]) ?
                        $exclusion[$process->id] :
                        Url::toRoute(['/client/grid', 'businessProcessId' => $process->id])
                );
            }

            $this->addBlock($block);
        }
    }
}
