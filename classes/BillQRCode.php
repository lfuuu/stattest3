<?php

namespace app\classes;

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

    public static function getImgTag($billNo)
    {
        $result = self::getNo($billNo);

        if (isset($result['bill'])) {
            return '<img src="/utils/qr-code/get?data=' . $result['bill'] . '" border="0"/>';
        }

        return '';
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
