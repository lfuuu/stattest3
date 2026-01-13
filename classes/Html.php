<?php

namespace app\classes;

class Html extends \yii\helpers\Html
{

    /**
     * @param string $src
     * @param array $options
     * @param bool|string $mimeType
     * @return string
     */
    public static function inlineImg($src, $options = [], $mimeType = false)
    {
        if (strpos($src, '://') === false) {
            $filename = \Yii::$app->basePath . '/web' . $src;
            $file = file_get_contents($filename);
            if ($mimeType === false) {
                $mimeType = mime_content_type($filename);
            }
        } else {
            $filename = tempnam('/tmp', 'img_');
            $file = file_get_contents($src);
            if ($mimeType === false) {
                $mimeType = mime_content_type($filename);
            }
            file_put_contents($filename, $file);
            unlink($filename);
        }

        $options['src'] = 'data:' . $mimeType . ';base64,' . base64_encode($file);

        return static::tag('img', '', $options);
    }

    /**
     * @param string $data
     * @param array $options
     * @param string $mimeType
     * @return string
     */
    public static function inlineImgFromBinaryData($data, $options = [], $mimeType = 'image/gif')
    {
        if ($data === '' || $data === false || $data === null) {
            return '';
        }

        $options['src'] = 'data:' . $mimeType . ';base64,' . base64_encode($data);

        return static::tag('img', '', $options);
    }

    /**
     * @param string $text
     * @param array $options
     * @return string
     */
    public static function formLabel($text, $options = [])
    {
        return parent::tag('legend', $text, $options);
    }

    /**
     * Вернуть текст с многоточием
     *
     * @param string $text
     * @param string $width
     * @return string
     */
    public static function ellipsis($text, $width = '110px')
    {
        return self::tag(
            'span',
            $text,
            [
                'class' => 'text-overflow-ellipsis',
                'title' => strip_tags($text),
                'style' => 'width: ' . $width,
            ]
        );
    }
}