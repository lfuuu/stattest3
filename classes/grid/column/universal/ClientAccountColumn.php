<?php

namespace app\classes\grid\column\universal;

use app\classes\grid\column\DataColumn;
use app\classes\Html;
use app\models\ClientAccount;

class ClientAccountColumn extends DataColumn
{
    public $isTargetBlank = true;
    public $filter = false;

    protected function renderDataCellContent($model, $key, $index)
    {
        $value = $this->getDataCellValue($model, $key, $index);

        if ($value === null || $value === '') {
            return $this->grid->emptyCell;
        }

        return Html::a(
            $value,
            ClientAccount::getUrlById($value),
            $this->isTargetBlank ? ['target' => '_blank'] : []
        );
    }
}
