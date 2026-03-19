<?php

namespace app\classes\excel;

use Yii;
use yii\base\Component;

class PartnerRewardsNewDocumentExcel extends Component
{
    /** @var array */
    public $documentData = [];

    /**
     * @param string $fileName
     * @throws \yii\base\ExitException
     */
    public function download($fileName)
    {
        $document = $this->buildDocument();
        $writer = \PHPExcel_IOFactory::createWriter($document, 'Excel2007');

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        Yii::$app->response->sendContentAsFile(
            $content,
            $fileName . '.xlsx',
            ['mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
        Yii::$app->end();
    }

    /**
     * @return \PHPExcel
     */
    private function buildDocument()
    {
        $document = new \PHPExcel();
        $sheet = $document->setActiveSheetIndex(0);
        $sheet->setTitle('Вознаграждение');

        $document->getProperties()
            ->setCreator('stat')
            ->setTitle('Вознаграждения партнеров')
            ->setSubject('Вознаграждения партнеров');

        $sheet->mergeCells('A1:B1');
        $sheet->mergeCells('C1:E1');
        $sheet->setCellValue('A1', 'Агент: ' . $this->documentData['partnerName']);
        $sheet->setCellValue('C1', 'Расчетный период ' . $this->documentData['periodText']);

        $headerRow = 3;
        $summaryRow = 4;
        $dataStartRow = 5;

        $headers = [
            'A' => 'Наименование клиента',
            'B' => 'Дата регистрации клиента',
            'C' => 'Сумма оплаченных услуг, за которые начислено вознаграждение',
            'D' => 'Сумма оплаченных счетов',
            'E' => 'Сумма вознаграждения',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue($column . $headerRow, $label);
        }

        $sheet->mergeCells('A' . $summaryRow . ':B' . $summaryRow);
        $sheet->setCellValue('A' . $summaryRow, 'Итого');
        $sheet->setCellValue('C' . $summaryRow, (float)$this->documentData['summary']['paid_summary_reward']);
        $sheet->setCellValue('D' . $summaryRow, (float)$this->documentData['summary']['paid_summary']);
        $sheet->setCellValue('E' . $summaryRow, (float)$this->documentData['summary']['sum']);

        $currentRow = $dataStartRow;
        foreach ($this->documentData['rows'] as $row) {
            $sheet->setCellValue('A' . $currentRow, $row['contragent_name']);
            $sheet->setCellValueExplicit('B' . $currentRow, (string)$row['client_created'], \PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $currentRow, (float)$row['paid_summary_reward']);
            $sheet->setCellValue('D' . $currentRow, (float)$row['paid_summary']);
            $sheet->setCellValue('E' . $currentRow, (float)$row['sum']);
            $currentRow++;
        }

        if ($currentRow === $dataStartRow) {
            $sheet->mergeCells('A' . $dataStartRow . ':E' . $dataStartRow);
            $sheet->setCellValue('A' . $dataStartRow, 'Нет данных для выбранного периода.');
            $currentRow++;
        }

        $totalRow = $currentRow + 1;
        $sheet->mergeCells('A' . $totalRow . ':E' . $totalRow);
        $sheet->setCellValue(
            'A' . $totalRow,
            'Итого сумма вознаграждения ' . number_format((float)$this->documentData['summary']['sum'], 2, ',', ' ') . ' руб.'
        );

        $signRow = $totalRow + 4;
        $sheet->mergeCells('A' . $signRow . ':B' . $signRow);
        $sheet->mergeCells('D' . $signRow . ':E' . $signRow);
        $sheet->setCellValue(
            'A' . $signRow,
            trim($this->documentData['operatorDirectorPost'] . ' ' . $this->documentData['operatorOrganizationName'])
        );
        $sheet->setCellValue('D' . $signRow, $this->documentData['partnerName']);

        $nameRow = $signRow + 2;
        $sheet->mergeCells('A' . $nameRow . ':B' . $nameRow);
        $sheet->mergeCells('D' . $nameRow . ':E' . $nameRow);
        $sheet->setCellValue('A' . $nameRow, $this->documentData['operatorDirectorName']);
        $sheet->setCellValue('D' . $nameRow, '____________________');

        $this->applyStyles($sheet, $headerRow, $summaryRow, $dataStartRow, $currentRow - 1, $totalRow, $signRow, $nameRow);

        return $document;
    }

    /**
     * @param \PHPExcel_Worksheet $sheet
     * @param int $headerRow
     * @param int $summaryRow
     * @param int $dataStartRow
     * @param int $dataEndRow
     * @param int $totalRow
     * @param int $signRow
     * @param int $nameRow
     */
    private function applyStyles(
        \PHPExcel_Worksheet $sheet,
        $headerRow,
        $summaryRow,
        $dataStartRow,
        $dataEndRow,
        $totalRow,
        $signRow,
        $nameRow
    ) {
        $sheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(10);

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(28);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(18);

        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A1:E1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A' . $headerRow . ':E' . $headerRow)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER,
                'wrap' => true,
            ],
            'fill' => [
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
                'color' => ['rgb' => 'D9EAF7'],
            ],
            'borders' => $this->getThinBorders(),
        ]);

        $sheet->getStyle('A' . $summaryRow . ':E' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'type' => \PHPExcel_Style_Fill::FILL_SOLID,
                'color' => ['rgb' => 'F5F0DA'],
            ],
            'borders' => $this->getThinBorders(),
        ]);

        if ($dataEndRow >= $dataStartRow) {
            $sheet->getStyle('A' . $dataStartRow . ':E' . $dataEndRow)->applyFromArray([
                'alignment' => [
                    'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER,
                ],
                'borders' => $this->getThinBorders(),
            ]);
        }

        $sheet->getStyle('C' . $summaryRow . ':E' . max($summaryRow, $dataEndRow))
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        if ($dataEndRow >= $dataStartRow) {
            $sheet->getStyle('C' . $dataStartRow . ':E' . $dataEndRow)
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        $sheet->getStyle('B' . $dataStartRow . ':B' . max($dataStartRow, $dataEndRow))
            ->getAlignment()
            ->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('C' . $dataStartRow . ':E' . max($dataStartRow, $dataEndRow))
            ->getAlignment()
            ->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle('A' . $totalRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $signRow . ':E' . $nameRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
    }

    /**
     * @return array
     */
    private function getThinBorders()
    {
        return [
            'allborders' => [
                'style' => \PHPExcel_Style_Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ];
    }
}
