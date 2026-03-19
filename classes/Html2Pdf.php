<?php

namespace app\classes;


use yii\base\BaseObject;

/**
 * Класс генерации PDF из HTML
 *
 * Class Html2Pdf
 *
 * @property string $html
 * @property string $pdf
 *
 * @package app\classes
 */
class Html2Pdf extends BaseObject
{

    /** @var string */
    private $html = '';

    /** @var string */
    private $pdf = '';

    /** @var boolean */
    private $isLandscape = false;

    private $execTool = '/usr/local/bin/wkhtmltopdf';

    /**
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        if (!is_file($this->execTool)) {
            throw new \Exception('wkhtmltopdf not found');
        }

        if (!is_executable($this->execTool)) {
            throw new \Exception('wkhtmltopdf not executable');
        }

        if (($config['landscape'] ?? false) === true) {
            $this->isLandscape = true;
        }
        unset($config['landscape']);

        parent::__construct($config);
    }

    /**
     * @param string $html
     */
    public function setHtml($html = '')
    {
        $this->html = $html;
        $this->pdf = '';
    }

    /**
     * @return string
     */
    public function getPdf()
    {
        if ($this->pdf) {
            return $this->pdf;
        }

        $this->pdf = $this->generatePdf();

        return $this->pdf;
    }

    /**
     * Генерация PDF'а
     * @return string
     */
    private function generatePdf()
    {
        $tmpDir = sys_get_temp_dir();

        $filenameOfHtml = tempnam($tmpDir, 'html_to_pdf');
        $filenameOfPdf = tempnam($tmpDir, 'html_to_pdf');

        unlink($filenameOfHtml);
        unlink($filenameOfPdf);

        $filenameOfHtml .= '.html';
        $filenameOfPdf .= '.pdf';

        /** wkhtmltopdf */
        $options = ' --quiet --encoding utf-8 -L 15 -R 15 -T 15 -B 15';

        if ($this->isLandscape) {
            $options .= ' -O landscape';
        }

        if (stripos($this->html, '<head>') === false) {
            $this->html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />'
                . "<base href='" . \Yii::$app->params['SITE_URL'] . "' />"
                . '</head><body>' . $this->html . '</body></html>';
        } else {
            $this->html = str_ireplace(
                '<head>',
                "<head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" /><base href='" . \Yii::$app->params['SITE_URL'] . "' />",
                $this->html
            );
        }

        file_put_contents($filenameOfHtml, $this->html);

        exec($this->execTool . ' ' . $options . ' ' . $filenameOfHtml . ' ' . $filenameOfPdf);

        $content = file_get_contents($filenameOfPdf);

        unlink($filenameOfHtml);
        unlink($filenameOfPdf);

        return $content;
    }

}
