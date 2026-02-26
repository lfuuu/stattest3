<?php

namespace app\classes;

use app\classes\api\ApiVpbx;
use app\classes\traits\ApplicationCountryTrait;
use welltime\graylog\GelfMessage;
use Yii;

/**
* @property \yii\redis\Mutex $mutex
*/
class WebApplication extends \yii\web\Application
{
    use ApplicationCountryTrait;
    public function init()
    {
        parent::init();

        $isLogAAA = isset($this->params['isLogAAA']) ? $this->params['isLogAAA'] : false;

        if ($isLogAAA) {
            $messageData = '';
            $request = Yii::$app->request;
            list($route, $params) = $request->resolve();

            if ($params) {
                $messageData .= "PARAMS:\n";
                $messageData .= json_encode($params,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $messageData .= "\n\n";
            }

            if ($request->getBodyParams()) {
                $messageData .= "BODY:\n";
                $messageData .= json_encode($request->getBodyParams(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $messageData .= "\n\n";
            }

            $messageData = preg_replace('/("password(Repeat|Current)?": ")[^"]+"/', '$1xxx"', $messageData);;

            if (isset($_SERVER['REQUEST_URI_ORIG'])) {
                $requestUri = $_SERVER['REQUEST_URI_ORIG'];
            } else {
                $requestUri = $_SERVER['REQUEST_URI'];
            }

            Yii::info(
                GelfMessage::create()
                    ->setTimestamp(YII_BEGIN_TIME)
                    ->setShortMessage('AAA START ' . $request->getMethod() . ' ' . $requestUri)
                    ->setFullMessage($messageData)
                    ->setAdditional('route', $route)
                    ->setAdditional('duration', microtime(true) - YII_BEGIN_TIME)
                    ->setAdditional('remote_addr', $_SERVER['REMOTE_ADDR'] ?? '')
                    ->setAdditional('http_x_real_ip', $_SERVER['HTTP_X_REAL_IP'] ?? '')
                    ->setAdditional('http_x_forwarded_for', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
                    ->setAdditional('UserLogin', Yii::$app->user && Yii::$app->user->identity ? Yii::$app->user->identity->user : ''),
                'request'
            );
        }


        register_shutdown_function(function ($isLogAAA) {
            $messageData = '';
            $request = Yii::$app->request;
            list($route, $params) = $request->resolve();

            if ($params) {
                $messageData .= "PARAMS:\n";
                $messageData .= json_encode($params,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $messageData .= "\n\n";

            }

            if ($request->getBodyParams()) {
                $messageData .= "BODY:\n";
                $messageData .= json_encode($request->getBodyParams(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $messageData .= "\n\n";
            }

            $messageData = preg_replace('/("password(Repeat|Current)?": ")[^"]+"/', '$1xxx"', $messageData);;

            if (isset($_SERVER['REQUEST_URI_ORIG'])) {
                $requestUri = $_SERVER['REQUEST_URI_ORIG'];
            } else {
                $requestUri = $_SERVER['REQUEST_URI'];
            }

            if ($isLogAAA) {
                $response = Yii::$app->response;
                Yii::info(
                    GelfMessage::create()
                        ->setTimestamp(microtime(true))
                        ->setShortMessage('AAA END ' . $request->getMethod() . ' ' . $requestUri)
                        ->setFullMessage(substr($response->content, 0, 1024))
                        ->setAdditional('route', $route)
                        ->setAdditional('duration', microtime(true) - YII_BEGIN_TIME)
                        ->setAdditional('remote_addr', $_SERVER['REMOTE_ADDR'] ?? '')
                        ->setAdditional('http_x_real_ip', $_SERVER['HTTP_X_REAL_IP'] ?? '')
                        ->setAdditional('http_x_forwarded_for', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
                        ->setAdditional('UserLogin', Yii::$app->user && Yii::$app->user->identity ? Yii::$app->user->identity->user : ''),
                    'request'
                );
            }

            Yii::info(
                GelfMessage::create()
                    ->setTimestamp(YII_BEGIN_TIME)
                    ->setShortMessage($request->getMethod() . ' ' . $requestUri)
                    ->setFullMessage($messageData)
                    ->setAdditional('route', $route)
                    ->setAdditional('duration', microtime(true) - YII_BEGIN_TIME)
                    ->setAdditional('remote_addr', $_SERVER['REMOTE_ADDR'] ?? '')
                    ->setAdditional('http_x_real_ip', $_SERVER['HTTP_X_REAL_IP'] ?? '')
                    ->setAdditional('http_x_forwarded_for', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
                    ->setAdditional('UserLogin', Yii::$app->user && Yii::$app->user->identity ? Yii::$app->user->identity->user : ''),
                'request'
            );
        }, $isLogAAA);
    }

    public function is2fAuth()
    {
        return $this->isRus() && (getenv('IS_TEST') === "0") && ApiVpbx::me()->isAvailable();
    }
}
