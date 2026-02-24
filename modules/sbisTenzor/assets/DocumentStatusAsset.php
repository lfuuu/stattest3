<?php

namespace app\modules\sbisTenzor\assets;

use yii\web\AssetBundle;

class DocumentStatusAsset extends AssetBundle
{
    public $sourcePath = __DIR__;

    public $js = [
        'document-statuses.js',
    ];

    public $depends = [
        'app\assets\AppAsset',
    ];
}
