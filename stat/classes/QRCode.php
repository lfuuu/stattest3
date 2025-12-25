<?php

use app\classes\BillQRCode;

class QRCode
{
    public static $codes = array(
            "bill"  => array("code" => "01", "c" => "bill",          "name" => "Счет"),
            "akt-1" => array("code" => "11", "c" => "akt", "s" => 1, "name" => "Акт 1"),
            "akt-2" => array("code" => "12", "c" => "akt", "s" => 2, "name" => "Акт 2"),
            "upd-1" => array("code" => "21", "c" => "upd", "s" => 1, "name" => "УПД 1"),
            "upd-2" => array("code" => "22", "c" => "upd", "s" => 2, "name" => "УПД 2"),
            "upd-3" => array("code" => "23", "c" => "upd", "s" => 3, "name" => "УПД Т"),
            "upd2-1" => ["code" => "31", "c" => "upd2", "s" => 1, "name" => "УПД2 1"],
            "upd2-2" => ["code" => "32", "c" => "upd2", "s" => 2, "name" => "УПД2 2"],
            "upd2-3" => ["code" => "33", "c" => "upd2", "s" => 2, "name" => "УПД2 3"],
            "upd2-4" => ["code" => "34", "c" => "upd2", "s" => 2, "name" => "УПД2 4"],
            );

    public static function encode($docType, $billNo)
    {
        self::_prepareBillNo($billNo);

        if(!isset(self::$codes[$docType])) return false;

        return self::$codes[$docType]["code"].$billNo;
    }

    private static function _prepareBillNo(&$billNo)
    {
        $billNo = str_replace("-", "1", $billNo);
        $billNo = str_replace("/", "2", $billNo);
    }

    public static function getNo($billNo)
    {
        $billNo = str_replace("-", "1", $billNo);
        $billNo = str_replace("/", "2", $billNo);

        foreach(self::$codes as $c)
        {
            if(isset($c["s"]))
            {
                $r[$c["c"]][$c["s"]] = $c["code"]."".$billNo;
            }else{
                $r[$c["c"]] = $c["code"]."".$billNo;
            }
        }

        return $r;
    }

    public static function decodeNo($no)
    {
        if (in_array(strlen($no), BillQRCode::NUMBER_FORMAT_LENGTHS)) {
            $type = self::_getType(substr($no, 0, 2));
            $number = self::_getNumber(substr($no, 2));

            if ($type) {
                return ["type" => $type, "number" => $number];
            }
        }

        return false;
    }

    private static function _getType($t)
    {
        foreach(self::$codes as $c)
        {
            if($c["code"] == $t) return $c;
        }

        return false;
    }

    private static function _getNumber($no)
    {
        switch($no[6])
        {
            case '1' : $no[6] = "-"; break;
            case '2' : $no[6] = "/"; break;
            default: return false;
        }

        return $no;
    }

    public static function decodeFile($file)
    {
        exec("zbarimg -q ".$file, $o);

        if(!$o) 
            return false;

        foreach($o as $l)
        {
            list($code, $number) = explode(":", $l);

            if($code == "QR-Code")
                return $number;
        }

        return false;
    }
}