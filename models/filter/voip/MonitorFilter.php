<?php

namespace app\models\filter\voip;

use app\classes\Form;
use app\classes\validators\FormFieldValidator;
use app\helpers\DateTimeZoneHelper;
use app\models\billing\CallsCdr;
use app\models\voip\StateServiceVoip;
use yii\data\ArrayDataProvider;

class MonitorFilter extends Form
{
//    public $range;
    public $number;

    public $date_from;
    public $date_to;

    public $account;

    public $is_with_session_time = 1;

    public function init()
    {
        $this->date_from = date('Y-m-d\TH:i', strtotime('+3 hours', strtotime('-5 minute')));
        $this->date_to = date('Y-m-d\TH:i', strtotime('+3 hours'));
    }


    public function rules()
    {
        global $fixclient_data;

        return [
//            ['range', 'required'],
//            ['range', 'in', 'range' => $this->getRanges()],
//            [['date_from', 'date_to'], 'require'],
            [['date_from', 'date_to'], 'datetime'],
            [['number'], 'string'],
            [['number'], FormFieldValidator::class],
            ['is_with_session_time', 'integer'],
            [['account'], 'integer'],
//            ['orig_account', 'default', 'value' => $fixclient_data['id'] ?? null],

        ];
    }

    public function attributeLabels()
    {
        return [
            'charge_time' => 'Дата/время',
            'src_number' => 'Номера А',
            'dst_number' => 'Номера В',
            'dst_route' => 'Исходящий транк',
            'cost' => 'Стоимость',
            'cost_gr' => 'Цена',
            'rate' => 'Ставка',
            'count' => 'Кол-во частей',

            'connect_time' => 'Время',
            'session_time' => 'Длительность',
            'is_with_session_time' => 'Звонки с длительностью',

            'account' => 'ЛС номера А/B',

            'number' => 'Номера А/B',

            'date_from' => 'Период начала звонка "С" (Мск)',
            'date_to' => 'Период начала звонка "По" (Мск)',

            'cdr_connect_time' => 'Время соединения (Мск)',
        ];
    }

    public function search()
    {
        $from = \DateTime::createFromFormat('Y-m-d\TH:i', $this->date_from)->modify('-3 hours');
        $to = \DateTime::createFromFormat('Y-m-d\TH:i', $this->date_to)->modify('-3 hours');

        $fromStr = $from->format(DateTimeZoneHelper::DATETIME_FORMAT);
        $toStr = $to->format(DateTimeZoneHelper::DATETIME_FORMAT);

        $this->account = preg_replace('/[^\d]/', '', $this->account);
        $this->number = preg_replace('/[^\d]/', '', $this->number);

        $whereSql = '';

        if ($this->number) {
            $whereSql .= ' AND (src_number = '.$this->number.' OR dst_number = '.$this->number.')';
        }
        if ($this->account) {
            $whereSql .= ' AND account_id = '.$this->account;
        }

        $query = <<< SQL
WITH calls0 AS (
    SELECT *
    FROM calls_raw.calls_raw
    WHERE (connect_time BETWEEN '{$fromStr}' AND '{$toStr}')
        {$whereSql}
), cdr AS (
    SELECT distinct d.*
    FROM calls_cdr.cdr d
    JOIN calls0 c ON d.instance_id = c.instance_id and d.id = c.cdr_id
    WHERE d.connect_time BETWEEN '{$fromStr}' AND '{$toStr}'
), calls AS (
    SELECT c.* FROM calls_raw.calls_raw c
    JOIN cdr d ON d.instance_id = c.instance_id and d.id = c.cdr_id
    WHERE (c.connect_time BETWEEN '{$fromStr}' AND '{$toStr}')
)

SELECT cdr.server_id, cdr.id as cdr_id, cdr.mcn_callid,
       cdr.src_number as cdr_num_a, cdr.dst_number  as cdr_num_b, cdr.connect_time + INTERVAL '3 hours' as cdr_connect_time, cdr.setup_time, cdr.session_time, src_route, dst_route
, c_orig.src_number as orig_num_a, c_orig.dst_number as orig_num_b, c_orig.account_id as orig_account
, c_term.src_number as term_num_a, c_term.dst_number as term_num_b, c_term.account_id as term_account
FROM cdr
LEFT JOIN calls c_orig on cdr.instance_id = c_orig.instance_id and cdr.id = c_orig.cdr_id and c_orig.orig
LEFT JOIN calls c_term on cdr.instance_id = c_term.instance_id and cdr.id = c_term.cdr_id and not c_term.orig
WHERE True
SQL;

        if ($this->is_with_session_time) {
            $query .= ' AND cdr.session_time > 0';
        }

        $query .= PHP_EOL . "ORDER BY cdr.connect_time DESC";

//        echo "<pre>";
//        print_r($query);
//        echo "</pre>";

        return new ArrayDataProvider([
            'allModels' => CallsCdr::getDb()->createCommand($query)->queryAll(),
        ]);
    }


}
