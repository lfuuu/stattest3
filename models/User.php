<?php

namespace app\models;

use app\classes\model\ActiveRecord;
use app\dao\user\UserDao;
use app\queries\UserQuery;
use Yii;
use yii\base\NotSupportedException;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * @property integer $id
 * @property string $user
 * @property string $pass
 * @property string $usergroup
 * @property string $name
 * @property string $email
 * @property string $phone_work
 * @property string $phone_mobile
 * @property string $incoming_phone
 * @property string $data_flag
 * @property integer $depart_id
 * @property string $enabled
 * @property string $rocket_nick
 * @property UserFlashCode $flashCode
 *
 * @method static User findOne($condition)
 */
class User extends ActiveRecord implements \yii\web\IdentityInterface
{
    // Определяет getList (список для selectbox)
    use \app\classes\traits\GetListTrait {
        getList as getListTrait;
    }

    const USER_KIM = 'kim'; // Ким Александр
    const USER_KIM_ID = 70; // Ким Александр

    const USER_DUTOV = 'dutov'; // Дутов
    const USER_DUTOV_ID = 216; // Дутов

    const SYSTEM_USER = 'system';
    const SYSTEM_USER_ID = 60;
    const CLIENT_USER_ID = 25;
    const LK_USER_ID = 177;
    const USER_NICK = 'nick'; // Михайлов Николай
    const USER_KOSHELEV = 'koshelev'; // Кошелев Сергей
    const USER_VOSTROKNUTOV = 'vostroknutov'; // Михаил Вострокнутов

    const DEPART_SALES = 28;
    const DEPART_PURCHASE = 29;

    const PHOTO_SIZE_OF_SQUARE_SIDE = 250;

    const DEFAULT_INCOMING_PHONE = '+7 (495) 105-99-99';

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'user_users';
    }

    /**
     * @return UserDao
     */
    public static function dao()
    {
        return UserDao::me();
    }

    /**
     * @return UserQuery
     */
    public static function find()
    {
        return new UserQuery(get_called_class());
    }

    /**
     * @param int $id
     * @return User
     */
    public static function findIdentity($id)
    {
        return $id == static::SYSTEM_USER_ID ? null : static::findOne(['id' => $id, 'enabled' => 'yes']);
    }

    /**
     * @param mixed $token
     * @param null $type
     * @return User|null
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        if ($token == Yii::$app->params['API_SECURE_KEY']) {
            return self::findOne(['id' => Yii::$app->user->getId() ?: static::SYSTEM_USER_ID]);
        }

        return null;
    }

    /**
     * Кеш findByUsername в рамках одного запроса
     * @var array
     */
    private static $_findByUsernameCache = [];

    /**
     * @param string $user
     * @return User
     */
    public static function findByUsername($user)
    {
        if ($user === null || $user === '') {
            return null;
        }

        if (array_key_exists($user, static::$_findByUsernameCache)) {
            return static::$_findByUsernameCache[$user];
        }

        $userModel = static::findOne(['user' => $user]);

        if ($userModel && $userModel->enabled != 'yes') {
            $userModel->name = "(--" . $userModel->name . "--)";
        }

        static::$_findByUsernameCache[$user] = $userModel;

        return $userModel;
    }

    /**
     * @param string $token
     * @throws NotSupportedException
     */
    public static function findByPasswordResetToken($token)
    {
        throw new NotSupportedException('"findByPasswordResetToken" is not implemented.');
    }

    public static function getDefaultAccountManagerUserId()
    {
        return \Yii::$app->isRus() ? self::USER_KIM_ID : self::USER_DUTOV_ID;
    }

    public static function getDefaultAccountManagerUser()
    {
        return \Yii::$app->isRus() ? self::USER_KIM : self::USER_DUTOV;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->getPrimaryKey();
    }


    /**
     * @return bool
     */
    public function getAuthKey()
    {
        return false;
    }

    /**
     * @param string $authKey
     * @return bool
     */
    public function validateAuthKey($authKey)
    {
        return false; // $this->getAuthKey() === $authKey;
    }

    /**
     * @param string $password
     * @return bool
     */
    public function validatePassword($password)
    {
        return $this->pass === md5($password);
    }

    /**
     * @param string $password
     * @return bool
     */
    public function validatePasswordMd5($password)
    {
        return $this->pass === $password;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getGroupRights()
    {
        return $this->hasMany(UserGrantGroups::class, ['name' => 'usergroup']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUserRights()
    {
        return $this->hasMany(UserGrantUsers::class, ['name' => 'user']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getGroup()
    {
        return $this->hasOne(UserGroups::class, ['usergroup' => 'usergroup']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getDepartment()
    {
        return $this->hasOne(UserDeparts::class, ['id' => 'depart_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getFlashCode()
    {
        UserFlashCode::clean();

        return $this->hasOne(UserFlashCode::class, ['user_id' => 'id']);
    }

    /**
     * @param bool $isWithEmpty
     * @return array
     */
    public static function getAccountManagerList($isWithEmpty = false)
    {
        return self::getListTrait(
            $isWithEmpty,
            $isWithNullAndNotNull = false,
            $indexBy = 'user',
            $select = 'name',
            $orderBy = [],
            $where = ['enabled' => 'yes', 'depart_id' => self::DEPART_SALES]
        );
    }

    /**
     * @return array
     */
    public static function getManagerList()
    {
        return self::getListTrait(
            $isWithEmpty = false,
            $isWithNullAndNotNull = false,
            $indexBy = 'user',
            $select = 'name',
            $orderBy = [],
            $where = ['enabled' => 'yes', 'depart_id' => self::DEPART_SALES]
        );
    }

    /**
     * @param int $departmentId - Department ID
     * @param boolean $isEnabled
     * @param string $primary - primary column for array
     * @return array
     */
    public static function getUserListByDepart($departmentId, $isEnabled = null, $primary = 'id')
    {
        return self::getListTrait(
            $isWithEmpty = true,
            $isWithNullAndNotNull = false,
            $indexBy = $primary,
            $select = 'name',
            $orderBy = [],
            $where = [
                'AND',
                ['depart_id' => $departmentId],
                $isEnabled !== null ? ['enabled' => $isEnabled ? 'yes' : 'no'] : []
            ]
        );
    }

    /**
     * Вернуть список всех доступных значений
     *
     * @param bool|string $isWithEmpty false - без пустого, true - с '----', string - с этим значением
     * @param bool $isWithNullAndNotNull
     * @param string $indexBy
     * @return \string[]
     */
    public static function getList(
        $isWithEmpty = false,
        $isWithNullAndNotNull = false,
        $indexBy = 'user'
    ) {
        return self::getListTrait(
            $isWithEmpty,
            $isWithNullAndNotNull,
            $indexBy,
            $select = 'CONCAT(name, " (", user, ")")',
            $orderBy = ['name' => SORT_ASC],
            $where = ['enabled' => 'yes']
        );
    }

    /**
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getUrl()
    {
        return Url::to(['/', 'module' => 'employeers', 'user' => $this->user]);
    }

    /**
     * @return string
     */
    public function getLink()
    {
        return Html::a($this->name, $this->getUrl());
    }

    /**
     * Залогиненный юзер (true). Незалогиненный или системный (false)
     *
     * @return bool
     */
    public static function isLogged()
    {
        $userId = Yii::$app->user->getId();
        return $userId && $userId != User::SYSTEM_USER_ID;
    }

    /**
     * Проверяем, что текущий пользователь является менеджером или аккаунт-менеджером
     *
     * @return bool
     */
    public static function isManagerLogined()
    {
        $managers = self::find()
            ->select(['user'])
            ->where([
                'depart_id' => User::DEPART_SALES, 'enabled' => 'yes'
            ])
            ->column();

        return in_array(Yii::$app->user->identity->user, $managers);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->name;
    }
}
