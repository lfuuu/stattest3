<?php

namespace app\modules\uu\models_light;

use app\classes\Html;
use app\helpers\MediaFileHelper;
use app\models\Person;
use yii\base\Component;

class InvoicePersonLight extends Component implements InvoiceLightInterface
{

    public
        $name_nominative,
        $name_genitive,
        $post_nominative,
        $post_genitive,
        $signature_file_name,
        $signature;

    /**
     * @param Person $person
     */
    public function __construct(Person $person)
    {
        parent::__construct();

        $this->name_nominative = $person->name_nominative;
        $this->name_genitive = $person->name_genitive;
        $this->post_nominative = $person->post_nominative;
        $this->post_genitive = $person->post_genitive;
        $this->signature_file_name = $person->signature_file_name;
        $this->signature = $this->getSignature($person);
    }

    /**
     * @return string
     */
    public static function getKey()
    {
        return 'person';
    }

    /**
     * @return string
     */
    public static function getTitle()
    {
        return 'Данные об ответственном лице';
    }

    /**
     * @return array
     */
    public static function attributeLabels()
    {
        return [
            'name_nominative' => 'ФИО',
            'name_genitive' => 'ФИО (род. п.)',
            'post_nominative' => 'Должность',
            'post_genitive' => 'Должность (род. п.)',
            'signature' => 'Подпись',
        ];
    }

    public function getSignature(?Person $person)
    {
        if (!$person) {
            return '';
        }

        if (MediaFileHelper::checkExists('SIGNATURE_DIR', $person->signature_file_name)) {

            $image_options = [
                'width' => 140,
                'border' => 0,
//                'align' => 'top',
                'style' => ['position' => 'absolute', 'left' => '80px', /* 'top' => '-15px', */ 'z-index' => '-10', 'transform' => 'translateY(calc(-50% + 5px))'],
            ];

            return Html::tag('div',
                Html::img(MediaFileHelper::getFile('SIGNATURE_DIR', $person->signature_file_name), $image_options),
                ['style' => ['position' => 'relative', 'display' => 'block', 'width' => 0, 'height' => 0]]
            );

//            if ($inline_img):
//                echo Html::inlineImg(MediaFileHelper::getFile('SIGNATURE_DIR', $accountant->signature_file_name), $image_options);


            return '<!-- the person has no signature -->';
        }
    }

}