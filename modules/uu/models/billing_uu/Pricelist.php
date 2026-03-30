<?php

namespace app\modules\uu\models\billing_uu;

use app\classes\helpers\DependecyHelper;
use app\classes\model\ActiveRecord;
use Yii;
use yii\caching\TagDependency;
use yii\db\Expression;

/**
 * ННП прайслисты v.2
 */
class Pricelist extends ActiveRecord
{
    const ID_SERVICE_TYPE_VOICE   = 1;
    const ID_SERVICE_TYPE_SMS_A2P = 2;
    const ID_SERVICE_TYPE_DATA    = 3;
    const ID_SERVICE_TYPE_SMS_P2P = 4;
    const ID_SERVICE_TYPE_MAV     = 5;

    const ID_SERVICE_GROUP_SMS = [self::ID_SERVICE_TYPE_SMS_A2P, self::ID_SERVICE_TYPE_SMS_P2P];

    // Перевод названий полей модели
    use \app\classes\traits\AttributeLabelsTraits;

    // Определяет getList (список для selectbox)
    use \app\classes\traits\GetListTrait {
        getList as getListTrait;
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'billing_uu.pricelist';
    }
    
    /**
     * Returns the database connection
     *
     * @return \yii\db\Connection
     */
    public static function getDb()
    {
        return Yii::$app->dbPgNnp;
    }

    /**
     * Вернуть список всех доступных значений
     *
     * @param bool|string $isWithEmpty false - без пустого, true - с '----', string - с этим значением
     * @param bool $isWithNullAndNotNull
     * @param int $serviceTypeId
     * @return string[]
     */
    public static function getList(
        $isWithEmpty = false,
        $isWithNullAndNotNull = false,
        $serviceTypeId = null
    ) {
        return self::getListTrait(
            $isWithEmpty,
            $isWithNullAndNotNull,
            $indexBy = 'id',
            $select = 'name',
            $orderBy = ['name' => SORT_ASC],
            $where = ($serviceTypeId ? ['service_type_id' => $serviceTypeId] : [])
        );
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLocation()
    {
        return $this->hasMany(PricelistLocation::className(), ['pricelist_id' => 'id'])
            ->select([
                'billing_uu.pricelist_location.*',
                'mcc_string' => new Expression('(select string_agg(country, \', \') from nnp.mcc where nnp.mcc.mcc = ANY (billing_uu.pricelist_location.mcc::text[]))'),
                'mnc_string' => new Expression('array_to_string(mnc, \', \')'),
                'mnc_name_string' => new Expression('(select string_agg(network, \', \') from nnp.mnc where nnp.mnc.mnc = ANY (billing_uu.pricelist_location.mnc::text[]) and nnp.mnc.mcc = ANY (billing_uu.pricelist_location.mcc::text[]))'),
                'delta_price' => new Expression('round(billing_uu.pricelist_location.delta_price, 6)'),
                'sim_partner' => new Expression('(select string_agg(name, \', \') from billing_uu.sim_imsi_partner where billing_uu.sim_imsi_partner.id = ANY (billing_uu.pricelist_location.sim_partner))'),
                'sim_profile' => new Expression('(select string_agg(name, \', \') from billing_uu.sim_imsi_profile where billing_uu.sim_imsi_profile.id = ANY (billing_uu.pricelist_location.sim_profile))'),
            ])
            ->orderBy('mcc_string');
    }

    public static function getVoipPackagePriceV2Record($priceListId)
    {
        $cacheKey = 'pricelistv2api' . $priceListId;

        if ($data = Yii::$app->cache->get($cacheKey)) {
            return $data;
        }

        $pricelist = self::find()
            ->with('location.filterA.filterB.prefixPriceNoLimit')
            ->where(['id' => $priceListId])
            ->asArray()
            ->one();


        $data = [];

        foreach ($pricelist['location'] as $location) {
            if (empty($location['filterA'])) {
                continue;
            }

            foreach ($location['filterA'] as $filterA) {
                if (empty($filterA['filterB'])) {
                    continue;
                }

                foreach ($filterA['filterB'] as $filterB) {
                    if (empty($filterB['prefixPriceNoLimit'])) {
                        continue;
                    }

                    [$filterBText, $isExcept] = self::getFilterName($filterB);

                    if (empty($filterBText)) {
                        continue;
                    }


                    $data[self::getMapValue($filterBText)] = ['price' => round($filterB['prefixPriceNoLimit'][0]['b_number_price'], 2)] + ($isExcept ? ['is_except' => true] : []);
                    continue;
                }
            }
        }


        array_walk($data, function(&$item, $key){
            $item = ['destination' => $key] + $item;
        });

        $data = array_values($data);

        Yii::$app->cache->set($cacheKey, $data, DependecyHelper::TIMELIFE_MONTH, (new TagDependency(['tags' => [DependecyHelper::TAG_PRICELIST]])));

        return $data;
    }

    private static function getFilterName($filter)
    {
        $isExcept = false;
        if (!empty($filter['description'])) {
            return [trim($filter['description']), $isExcept];
        }

        if (isset($filter['nnp_ndc_type_name'])) {
            if ($filter['f_inv_nnp_ndc_type']) {
                $isExcept = true;
            }

            if ($filter['nnp_ndc_type_name'] != 'Geographic') {
                return [$filter['nnp_ndc_type_name'], $isExcept];
            }
        }

        if (isset($filter['nnp_city_name'])) {
            return [$filter['nnp_city_name'], $isExcept];
        }

        if (isset($filter['nnp_region_name'])) {
            return [$filter['nnp_region_name'], $isExcept];
        }

        if (isset($filter['nnp_country_name_eng'])) {
            return [$filter['nnp_country_name_eng'], $isExcept];
        }

        if (isset($filter['nnp_operator_name'])) {
            return [$filter['nnp_operator_name'], $isExcept];
        }

        return ['???', $isExcept];
    }

    public static function getMapValue($value)
    {
        $map = [
            'Freephone' => '7800 (Freephone)',
            'Адыгея' => 'Республика Адыгея',
            'Алтай' => 'Республика Алтай',
            'Алтайский край' => 'Алтайский край',
            'Амурская обл.' => 'Амурская область',
            'Архангельская обл.' => 'Архангельская область',
            'Архангельск' => 'Архангельск',
            'Астраханская обл.' => 'Астраханская область',
            'Башкирия' => 'Республика Башкортостан',
            'Благовещенск' => 'Благовещенск стац',
            'Астрахань' => 'Астрахань стац',
            'Уфа' => 'Уфа (стац)',
            'Майкоп' => 'Майкоп',
            'Горно-Алтайск' => 'Горно-Алтайск',
            'Барнаул' => 'Барнаул',
            'Белгородская обл.' => 'Белгородская область',
            'Белгород' => 'Белгород стац',
            'Брянская обл.' => 'Брянская область',
            'Брянск' => 'Брянск стац',
            'Бурятия' => 'Республика Бурятия',
            'Улан-Удэ' => 'Улан-Удэ',
            'Владимирская обл.' => 'Владимирская область',
            'Владимир' => 'Владимир стац',
            'Волгоградская обл.' => 'Волгоградская область',
            'Волгоград' => 'Волгоград стац',
            'Вологодская обл.' => 'Вологодская область',
            'Вологда' => 'Вологда',
            'Воронежская обл.' => 'Воронежская область',
            'Воронеж' => 'Воронеж',
            'Дагестан' => 'Республика Дагестан',
            'Махачкала' => 'Махачкала',
            'Еврейская автономная обл.' => 'Еврейская автономная область',
            'Биробиджан' => 'Биробиджан',
            'Забайкальский край' => 'Забайкальский край',
            'Чита' => 'Чита стац',
            'Ивановская обл.' => 'Ивановская область',
            'Иваново' => 'Иваново стац',
            'Ингушетия' => 'Республика Ингушетия',
            'Магас' => 'Магас',
            'Иркутская обл.' => 'Иркутская область',
            'Иркутск' => 'Иркутск стац',
            'Кабардино-Балкария' => 'Кабардино-Балкарская Республика',
            'Нальчик' => 'Нальчик',
            'Калининградская обл.' => 'Калининградская область',
            'Калининград' => 'Калининград (стац)',
            'Калмыкия' => 'Республика Калмыкия',
            'Элиста' => 'Элиста',
            'Калужская обл.' => 'Калужская область',
            'Калуга' => 'Калуга стац',
            'Камчатка' => 'Камчатский край',
            'Петропавловск-Камчатский' => 'Петропавловск-Камчатский',
            'Карачаево-Черкессия' => 'Карачаево-Черкесская Республика',
            'Черкесск' => 'Черкесск',
            'Карелия' => 'Республика Карелия',
            'Петрозаводск' => 'Петрозаводск',
            'Кемеровская обл.' => 'Кемеровская область',
            'Кемерово' => 'Кемерово (стац)',
            'Кировская обл.' => 'Кировская область',
            'Киров' => 'Киров (стац)',
            'Коми' => 'Республика Коми',
            'Сыктывкар' => 'Сыктывкар',
            'Костромская обл.' => 'Костромская область',
            'Кострома' => 'Кострома стац',
            'Краснодарский край' => 'Краснодарский край',
            'Краснодар' => 'Краснодар',
            'Красноярский край' => 'Красноярский край',
            'Красноярск' => 'Красноярск (стац)',
            'Крым' => 'Республика Крым',
            'Севастополь' => 'Севастополь',
            'Курганская обл.' => 'Курганская область',
            'Курган' => 'Курган стац',
            'Курская обл.' => 'Курская область',
            'Курск' => 'Курск стац',
            'Ленинградская обл.' => 'Ленинградская область',
            'Липецкая обл.' => 'Липецкая область',
            'Липецк' => 'Липецк стац',
            'Магаданская обл.' => 'Магаданская область',
            'Магадан' => 'Магадан',
            'Марий Эл' => 'Республика Марий Эл',
            'Йошкар-Ола' => 'Йошкар-Ола',
            'Мордовия' => 'Республика Мордовия',
            'Саранск' => 'Саранск стац',
            'Москва' => 'Москва стац',
            'Московская обл.' => 'Московская область',
            'Мурманская обл.' => 'Мурманская область',
            'Мурманск' => 'Мурманск (стац)',
            'Ненецкий АО' => 'Ненецкий автономный округ',
            'Нарьян-Мар' => 'Нарьян-Мар',
            'Нижегородская обл.' => 'Нижегородская область',
            'Нижний Новгород' => 'Нижний Новгород',
            'Новгородская обл.' => 'Новгородская область',
            'Великий Новгород' => 'Великий Новгород',
            'Новосибирская обл.' => 'Новосибирская область',
            'Новосибирск' => 'Новосибирск',
            'Омская обл.' => 'Омская область',
            'Омск' => 'Омск (стац)',
            'Оренбургская обл.' => 'Оренбургская область',
            'Оренбург' => 'Оренбург стац',
            'Орловская обл.' => 'Орловская область',
            'Орёл' => 'Орёл стац',
            'Пензенская обл.' => 'Пензенская область',
            'Пенза' => 'Пенза стац',
            'Пермский край' => 'Пермский край',
            'Пермь' => 'Пермь',
            'Приморский край' => 'Приморский край',
            'Владивосток' => 'Владивосток',
            'Псковская обл.' => 'Псковская область',
            'Псков' => 'Псков',
            'Ростовская обл.' => 'Ростовская область',
            'Ростов-на-Дону' => 'Ростов-на-Дону',
            'Рязанская обл.' => 'Рязанская область',
            'Рязань' => 'Рязань',
            'Самарская обл.' => 'Самарская область',
            'Самара' => 'Самара',
            'Санкт-Петербург' => 'Санкт-Петербург',
            'Саратовская обл.' => 'Саратовская область',
            'Саратов' => 'Саратов',
            'Сахалинская обл.' => 'Сахалинская область',
            'Южно-Сахалинск' => 'Южно-Сахалинск',
            'Свердловская обл.' => 'Свердловская область',
            'Екатеринбург' => 'Екатеринбург',
            'Северная Осетия-Алания' => 'Республика Северная Осетия-Алания',
            'Владикавказ' => 'Владикавказ стац',
            'Смоленская обл.' => 'Смоленская область',
            'Смоленск' => 'Смоленск стац',
            'Ставропольский край' => 'Ставропольский край',
            'Ставрополь' => 'Ставрополь стац',
            'Тамбовская обл.' => 'Тамбовская область',
            'Тамбов' => 'Тамбов стац',
            'Татарстан' => 'Республика Татарстан',
            'Казань' => 'Казань',
            'Тверская обл.' => 'Тверская область',
            'Тверь' => 'Тверь стац',
            'Томская обл.' => 'Томская область',
            'Томск' => 'Томск',
            'Тульская обл.' => 'Тульская область',
            'Тула' => 'Тула',
            'Тыва' => 'Республика Тыва',
            'Кызыл' => 'Кызыл',
            'Тюменская обл.' => 'Тюменская область',
            'Тюмень' => 'Тюмень',
            'Удмуртия' => 'Удмуртская Республика',
            'Ижевск' => 'Ижевск стац',
            'Ульяновская обл.' => 'Ульяновская область',
            'Ульяновск' => 'Ульяновск (стац)',
            'Хабаровский край' => 'Хабаровский край',
            'Хабаровск' => 'Хабаровск стац',
            'Хакасия' => 'Республика Хакасия',
            'Абакан' => 'Абакан',
            'Ханты-Мансийский АО' => 'Ханты-Мансийский автономный округ — Югра',
            'Сургут' => 'Сургут стац',
            'Челябинская обл.' => 'Челябинская область',
            'Челябинск' => 'Челябинск (кроме 735190)',
            'Чечня' => 'Чеченская Республика',
            'Грозный' => 'Грозный',
            'Чувашия' => 'Чувашская Республика',
            'Чебоксары' => 'Чебоксары стац',
            'Чукотка' => 'Чукотский автономный округ',
            'Анадырь' => 'Анадырь',
            'Якутия' => 'Республика Саха (Якутия)',
            'Якутск' => 'Якутск стац',
            'Ямало-Ненецкий АО' => 'Ямало-Ненецкий автономный округ',
            'Салехард' => 'Салехард',
            'Ярославская обл.' => 'Ярославская область',
            'Ярославль' => 'Ярославль стац',
            'Mobile' => 'Мобильные РФ',
            'Satellite' => 'Спутниковые сети (7954)',
        ];

        return isset($map[$value]) ? $map[$value] : $value;
    }

}