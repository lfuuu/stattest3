<?php

namespace app\classes;

use app\classes\QRcode\QRcode;

class BillQRCode
{
    const NUMBER_FORMAT_LENGTH = 15; // new document number format
    const NUMBER_FORMAT_LENGTH_OLD = 13; // old format
    const NUMBER_FORMAT_LENGTH_UU = 12; // uu-format

    const NUMBER_FORMAT_LENGTHS = [
        self::NUMBER_FORMAT_LENGTH,
        self::NUMBER_FORMAT_LENGTH_OLD,
        self::NUMBER_FORMAT_LENGTH_UU,
    ];

    public static $codes = [
        "bill" => ["code" => "01", "c" => "bill", "name" => "Счет"],
        "akt-1" => ["code" => "11", "c" => "akt", "s" => 1, "name" => "Акт 1"],
        "akt-2" => ["code" => "12", "c" => "akt", "s" => 2, "name" => "Акт 2"],
        "upd-1" => ["code" => "21", "c" => "upd", "s" => 1, "name" => "УПД 1"],
        "upd-2" => ["code" => "22", "c" => "upd", "s" => 2, "name" => "УПД 2"],
        "upd-3" => ["code" => "23", "c" => "upd", "s" => 3, "name" => "УПД Т"],
        "upd2-1" => ["code" => "31", "c" => "upd2", "s" => 1, "name" => "УПД2 1"],
        "upd2-2" => ["code" => "32", "c" => "upd2", "s" => 2, "name" => "УПД2 2"],
        "upd2-3" => ["code" => "33", "c" => "upd2", "s" => 3, "name" => "УПД2 3"],
        "upd2-4" => ["code" => "34", "c" => "upd2", "s" => 4, "name" => "УПД2 4"],
    ];

    public static function encode($docType, $billNo)
    {
        if (!isset(self::$codes[$docType])) {
            return false;
        }

        return
            self::$codes[$docType]["code"]
            . self::convertBillNo($billNo);
    }

    public static function getNo($billNo)
    {
        $billNo = self::convertBillNo($billNo);
        $result = [];

        foreach (self::$codes as $code) {
            if (isset($code["s"])) {
                $result[$code["c"]][$code["s"]] = $code["code"] . "" . $billNo;
            } else {
                $result[$code["c"]] = $code["code"] . "" . $billNo;
            }
        }

        return $result;
    }

    public static function decodeNo($no)
    {
        if (in_array(strlen($no), self::NUMBER_FORMAT_LENGTHS)) {
            $type = self::getType(substr($no, 0, 2));
            $number = self::getNumber(substr($no, 2));

            if ($type) {
                return ["type" => $type, "number" => $number];
            }
        }

        return false;
    }

    public static function decodeFile($file)
    {
        exec("zbarimg -q " . $file, $result);

        if (!$result) {
            return false;
        }

        foreach ($result as $line) {
            list($code, $number) = explode(":", $line);

            if ($code == "QR-Code") {
                return $number;
            }
        }

        return false;
    }

    public static function getImgUrl($billNo, $docType = 'bill')
    {
        $docType = $docType ?: 'bill';
        $data = self::encode($docType, $billNo);
        return $data ? '/utils/qr-code/get?data=' . $data : '';
    }

    public static function getImgUrlByData($data)
    {
        return $data ? '/utils/qr-code/get?data=' . $data : '';
    }

    public static function getImgTag($billNo, $docType = 'bill')
    {
        $url = self::getImgUrl($billNo, $docType);

        if ($url) {
            return '<img src="' . $url . '" border="0"/>';
        }

        return '';
    }

    private static $qrCodeErrorCorrectionLevel = 'H';
    private static $gifSize = 4;
    private static $gifMargin = 2;

    public static function setGifOptions($errorCorrectionLevel = 'H', $size = 4, $margin = 2)
    {
        self::$qrCodeErrorCorrectionLevel = $errorCorrectionLevel;
        self::$gifSize = $size;
        self::$gifMargin = $margin;
    }

    public static function generateGifData($data)
    {
        if (!$data) {
            return '';
        }

        ob_start();
        QRcode::gif(trim($data), false, self::$qrCodeErrorCorrectionLevel, self::$gifSize, self::$gifMargin);
        $imageData = ob_get_clean();

        return $imageData === false ? '' : $imageData;
    }

    public static function getInlineImgTagByData($data, $options = [], $mimeType = 'image/gif')
    {
        if (!$data) {
            return '';
        }

        $imageData = self::generateGifData($data);

        if (!$imageData) {
            return '';
        }

        $options['src'] = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

        return Html::tag('img', '', $options);
    }

    private static function convertBillNo($billNo)
    {
        $billNo = str_replace("-", "1", $billNo);
        $billNo = str_replace("/", "2", $billNo);
        return $billNo;
    }

    private static function getType($type)
    {
        foreach (self::$codes as $code) {
            if ($code["code"] == $type) {
                return $code;
            }
        }

        return false;
    }

    private static function getNumber($no)
    {
        if (strlen($no) == self::NUMBER_FORMAT_LENGTH_UU) { //uu document
            return $no;
        }

        switch ($no[6]) {
            case '1' :
                $no[6] = "-";
                break;
            case '2' :
                $no[6] = "/";
                break;
            default:
                return false;
        }

        return $no;
    }

}