<?php

namespace app\modules\sim\classes\externalStatusLog;

use app\modules\sim\models\ImsiExternalStatusLog;

class StatusContentRecognition extends \app\classes\Singleton
{
    public function getAsString(ImsiExternalStatusLog $log, $asHtml = false)
    {
        $result = $this->getResultItem($log->status);
        $result->insertDt = $log->insert_dt;
        $result->asHtml = $asHtml;
        return (string)$result;
    }

    private function getResultItem($status): EslResultItem
    {
        return (new EslRecognizerFactory($status))->process();
    }
}


class EslRecognizerFactory
{
    protected array $status = [];

    public function __construct(array $status)
    {
        $this->status = $status;
    }

    public function process(): EslResultItem
    {
        foreach ($this->getRecognizers() as $recognizerClass) {
            /** @var EslRecognizer $recognizer */
            $recognizer = new $recognizerClass($this->status);

            if ($recognizer->isDetect()) {
                return $recognizer->makeResult();
            }
        }

        return (new EslDefaultRecognizer($this->status))->makeResult();
    }

    private function getRecognizers()
    {
        return [
            EslRefRecognizer::class,
            EslResultCode0Recognizer::class,
            EslSpmlRecognizer::class,
            EslStatusErrorRecognizer::class,
            EslError111Recognizer::class,
        ];
    }
}

abstract class EslRecognizer
{
    protected array $status = [];

    public function __construct(array $status)
    {
        $this->status = $status;
    }

    abstract public function isDetect(): bool;

    abstract public function makeResult(): EslResultItem;
}

class EslDefaultRecognizer extends EslRecognizer
{
    public function isDetect(): bool
    {
        return true; // always
    }

    public function makeResult(): EslResultItem
    {
        return new EslResultItem(['itemStatus' => EslResultItem::STATUS_RAW, 'itemText' => strip_tags(var_export($this->status, true))]);
    }
}

class EslStatusErrorRecognizer extends EslRecognizer
{
    public function isDetect(): bool
    {
        return isset($this->status['status']) && strtoupper($this->status['status']) == 'ERROR';
    }

    public function makeResult(): EslResultItem
    {
        return new EslResultItem(['itemStatus' => EslResultItem::STATUS_TRANSPORT_ERROR, 'itemText' => 'Code: ' . ($this->status['code'] ?? '') . '. ' . ($this->status['result'] ?? '')]);
    }
}

class EslError111Recognizer extends EslRecognizer
{
    public function isDetect(): bool
    {
        return isset($this->status['result']) && strpos($this->status['result'], 'ERROR;-111') === 0;
    }

    public function makeResult(): EslResultItem
    {
        $result = $this->status['result'];
        $resultCode = '';
        if (preg_match('/<ResultCode>(\d+)<\/ResultCode>/', $result, $out)) {
            $resultCode = $out[1];
        }

        $resultStr = '';
        if (preg_match('/<ResultDesc>([^<]+)<\/ResultDesc>/', $result, $out)) {
            $resultStr = $out[1];
        }

        return new EslResultItem(['itemStatus' => EslResultItem::STATUS_ERROR, 'itemText' => 'Code: ' . $resultCode . '. ' . $resultStr]);
    }
}


class EslResultCode0Recognizer extends EslRecognizer
{
    public function isDetect(): bool
    {
        return isset($this->status['result']['ResultCode']) && $this->status['result']['ResultCode'] === '0';
    }

    public function makeResult(): EslResultItem
    {
        $group = $this->status['result']['ResultData']['Group'];
        $flatResult = $this->flatten($group);

        $strA = [];
        foreach (['ISDN', 'HLRSN', 'CardType'] as $f) {
            if (isset($flatResult[$f])) {
                $strA[] = $f . ': ' . $flatResult[$f];
            }
        }

        return new EslResultItem([
            'itemStatus' => EslResultItem::STATUS_INFO,
            'itemText' => implode('; ', $strA),
            'info' => $group,
        ]);
    }

    private function flatten(array $array)
    {
        $return = array();
        array_walk_recursive($array, function ($a, $k) use (&$return) {
            $return[$k] = $a;
        });
        return $return;
    }
}


class EslRefRecognizer extends EslRecognizer
{
    public function isDetect(): bool
    {
        return !empty($this->status['_ref']);
    }

    public function makeResult(): EslResultItem
    {
        return new EslResultItem(['itemStatus' => EslResultItem::STATUS_INFO, 'itemText' => 'без изменений']);
    }
}


class EslSpmlRecognizer extends EslRecognizer
{
    public function isDetect(): bool
    {
        return (bool)($this->status['result']['spmlsearchResponse'] ?? false);
    }

    public function makeResult(): EslResultItem
    {
        $spml = $this->status['result']['spmlsearchResponse'];

        $hlr = [];
        if (isset($spml['objects']['hlr'])) {
            $status = EslResultItem::STATUS_INFO;
            $hlr = $spml['objects']['hlr'];
            $isdn = $hlr['ts11']['msisdn'] ?? $hlr['ts12']['msisdn'] ?? $hlr['ts22']['msisdn'] ?? $hlr['gprs']['msisdn'] ?? '???';
            $apn = $hlr['epsPdnContext']['apn'] ?? false;
            $str = 'ISDN: ' . $isdn . ($apn ? ', APN: ' . $apn : '');
        } else {
            $str = 'Subscriber not defined';
            $status = EslResultItem::STATUS_WARNING;
        }

        return new EslResultItem([
            'itemStatus' => $status,
            'itemText' => 'Spml: ' . $str,
            'info' => $hlr,
        ]);
    }
}
