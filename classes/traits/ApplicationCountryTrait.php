<?php

namespace app\classes\traits;

/**
 * Определение страны продукта (RU/EU) по $_SERVER['COUNTRY']
 *
 * @see \app\classes\WebApplication
 * @see \app\classes\ConsoleApplication
 */
trait ApplicationCountryTrait
{
    private function _getProductCountry()
    {
        return ($_SERVER['COUNTRY'] ?? 'RU');
    }

    public function isEu()
    {
        return $this->_getProductCountry() == 'EU';
    }

    public function isRus()
    {
        return $this->_getProductCountry() == 'RU';
    }
}
