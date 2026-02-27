<?php

namespace app\modules\sim\models;

use app\classes\model\ActiveRecord;
use app\classes\Utils;
use app\helpers\DateTimeZoneHelper;
use app\modules\sim\classes\externalStatusLog\StatusContentRecognition;
use Yii;
use yii\db\Expression;

/**
 * @property int $imsi
 * @property string $insert_dt
 * @property string $status
 * @property-read string $insertDate
 * @property-read string $statusStringHtml
 */
class ImsiExternalStatusLog extends ActiveRecord
{
    const REF_STATUS = ['_ref' => true];

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'billing_uu.sim_imsi_external_status_log';
    }

    /**
     * Returns the database connection
     *
     * @return \yii\db\Connection
     */
    public static function getDb()
    {
        return Yii::$app->dbPgNnp;
    }

    public function isRef(): bool
    {
        return $this->status['_ref'] ?? false;
    }

    public static function makeLog($imsi, $status): int
    {
        if (!is_string($status)) {
            $status = Utils::toJson($status);
        }

        $lastStatus = self::getDb()->createCommand(
            'SELECT status FROM ' . self::tableName()
            . ' WHERE imsi = :imsi AND status != :ref ORDER BY id DESC LIMIT 1',
            [':imsi' => $imsi, ':ref' => Utils::toJson(self::REF_STATUS)]
        )->queryScalar();

        if ($lastStatus !== false && json_encode(json_decode($lastStatus, true)) === json_encode(json_decode($status, true))) {
            $status = Utils::toJson(self::REF_STATUS);
        }

        return self::getDb()
            ->createCommand()
            ->insert(self::tableName(), ['imsi' => $imsi, 'status' => new Expression("'" . $status . "'::jsonb")])
            ->execute();
    }

    public function getInsertDate()
    {
        return DateTimeZoneHelper::getDateTime($this->insert_dt);
    }

    public function getStatusStringHtml()
    {
        return StatusContentRecognition::me()->getAsString($this, true);
    }

    public function __toString()
    {
        return StatusContentRecognition::me()->getAsString($this, false);
    }
}
