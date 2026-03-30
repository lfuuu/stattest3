<?php
namespace app\classes;

use app\classes\traits\ApplicationCountryTrait;
use app\models\User;
use welltime\graylog\GelfMessage;
use Yii;

/**
 * @property \yii\db\Connection $db2
 */

class ConsoleApplication extends \yii\console\Application
{
    use ApplicationCountryTrait;
    public $enableCoreCommands = false;

    public function init()
    {
        parent::init();

        Yii::$app->user->setIdentity(User::findOne(User::SYSTEM_USER_ID));

        register_shutdown_function(function () {
            $messageData = '';
            $request = Yii::$app->request;
            list($route, $params) = $request->resolve();

            if ($params) {
                $messageData .= "PARAMS:\n";
                $messageData .= json_encode($params,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $messageData .= "\n\n";
            }

            $argv = isset($_SERVER['argv']) ? implode(' ', $_SERVER['argv']) : '';

            if (isset($_SERVER['USER'])) {
                $username = $_SERVER['USER'];
            } elseif (isset($_SERVER['USERNAME'])) {
                $username = $_SERVER['USERNAME'];
            } else {
                $username = '';
            }

            Yii::info(
                GelfMessage::create()
                    ->setTimestamp(YII_BEGIN_TIME)
                    ->setShortMessage('CONSOLE ' . $argv)
                    ->setFullMessage($messageData)
                    ->setAdditional('route', $route)
                    ->setAdditional('username', $username)
                    ->setAdditional('duration', microtime(true) - YII_BEGIN_TIME),
                'request'
            );
        });

    }

    public function getUser()
    {
        return $this->get('user');
    }


}
