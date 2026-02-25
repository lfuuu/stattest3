<?php

return [
    'params' => [
        'isEnabled' => false,

        // Номер версии API. Подставляется в URL
        'apiVersion' => 'v4',

        // URL, на который Атол пришлет результат обработки чека. Если не указывать - не пришлет. Протокол + хост + '/atol/api/'
        // Например, 'https://stat.mcn.ru/atol/api/'
        'callbackUrl' => '', // @todo надо указать в params.local.php

        'access' => [
            'organization_' . \app\models\Organization::MCN_TELECOM => [
                'password' => 'password_1',
                'login' => 'login_1',
                'groupCode' => 'group_code_1',
                'inn' => 'inn_1',
                'sno' => 'osn',
            ],
            'organization_' . \app\models\Organization::MCN_TELECOM_RETAIL => [
                'password' => 'password_11',
                'login' => 'login_11',
                'groupCode' => 'group_code_11',
                'inn' => 'inn_11',
            ],
            'organization_' . \app\models\Organization::MCN_TELECOM_SERVICE => [
                'password' => 'password_21',
                'login' => 'login_21',
                'groupCode' => 'group_code_21',
                'inn' => 'inn_21',
            ],
            'organization_' . \app\models\Organization::AB_SERVICE_MARCOMNET => [
                'password' => 'password_14',
                'login' => 'login_14',
                'groupCode' => 'group_code_14',
                'inn' => 'inn_14',
            ],
        ],

        // Получение токена
        'getToken' => [
            // URL. Брать из документации ecr_balancer.pdf (п. 5.1)
            // "<api_version>" так и надо оставить
            'url' => 'https://online.atol.ru/possystem/<api_version>/getToken',

            // Метод. Брать из документации ecr_balancer.pdf (п. 5.1)
            // Например, 'post' или 'get'
            'method' => 'post',
        ],

        // Регистрация документа (прихода, расхода, возврата, коррекции) в ККТ
        'buyOrSell' => [
            // URL. Брать из документации ecr_balancer.pdf (п. 5.2)
            // "<api_version>", "<group_code>", "<operation>", "<token>" так и надо оставить
            'url' => 'https://online.atol.ru/possystem/<api_version>/<group_code>/<operation>',

            // Адрес места расчетов. Брать из ЛК https://online.atol.ru/lk/Account/Login
            // Например, 'magazin.ru'
            // Используется для предотвращения ошибочных регистраций чеков на ККТ зарегистрированных с другим адресом места расчёта (сравнивается со значением в ФН).
            // Максимальная длина строки – 256 символов.
            'paymentAddress' => '', // @todo надо указать в params.local.php

            // Система налогообложения.
            // «osn» – общая СН;
            // «usn_income» – упрощенная СН (доходы);
            // «usn_income_outcome» – упрощенная СН (доходы минус расходы);
            // «envd» – единый налог на вмененный доход;
            // «esn» – единый сельскохозяйственный налог;
            // «patent» – патентная СН.
            // Поле необязательно, если у организации один тип налогообложения.
            'sno' => '', // @todo надо указать в params.local.php

            // Наименование товара.
            // Максимальная длина строки – 64 символа.
            'itemName' => '', // @todo надо указать в params.local.php
        ],

        // Получение результата обработки документа
        'report' => [
            // URL. Брать из документации ecr_balancer.pdf (п. 5.3)
            // "<api_version>", "<group_code>", "<uuid>", "<token>" так и надо оставить
            'url' => 'https://online.atol.ru/possystem/<api_version>/<group_code>/report/<uuid>',
        ],
    ],
];
