<?php

namespace app\modules\sim\classes\externalStatusLog;

use app\helpers\DateTimeZoneHelper;

class EslResultItem extends \yii\base\Component
{
    const STATUS_INFO = 'info';
    const STATUS_ERROR = 'error';
    const STATUS_WARNING = 'warning';
    const STATUS_TRANSPORT_ERROR = 'transport_error';
    const STATUS_RAW = 'raw';

    private array $textClassMap = [
        self::STATUS_INFO => 'text-success',
        self::STATUS_ERROR => 'text-info',
        self::STATUS_WARNING => 'text-warning',
        self::STATUS_TRANSPORT_ERROR => 'text-danger',
        self::STATUS_RAW => 'text-danger',
    ];

    public string $itemStatus = self::STATUS_INFO;
    public string $itemText = '';
    public array $info = [];
    public string $insertDt = '';
    public bool $asHtml = false;
    public bool $isRef = false;

    private function getMarker(): string
    {
        if ($this->isRef) {
            return '<span style="color:#999">&gt; </span>';
        }
        return '<span style="color:#5cb85c">* </span>';
    }

    public function __toString()
    {
        if (!$this->asHtml) {
            return $this->itemText;
        }

        $insertDate = $this->insertDt ? DateTimeZoneHelper::getDateTime($this->insertDt) : '';
        $infoBtn = '';

        if ($this->info) {
            $infoData = htmlspecialchars(json_encode($this->info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            $infoBtn = " <a href=\"#\" class=\"esl-info-btn\" data-info=\"{$infoData}\"><span class=\"glyphicon glyphicon-info-sign\"></span></a>";
        }

        return <<<HTML
        <div class="row">
            <div class="col-md-3">{$insertDate}</div>
            <div class="col-md-9 {$this->textClassMap[$this->itemStatus]}">{$this->getMarker()}{$this->itemText}{$infoBtn}</div>
        </div>
HTML;
    }
}
