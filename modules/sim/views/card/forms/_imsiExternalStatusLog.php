<?php

$extLog = $imsi->externalStatusLog;
if (!$extLog) {
    return '';
}
?>
<div class="well">
    <h1>Внешний лог состояния IMSI <?= $imsi->imsi ?></h1>
    <div class="row" style="border: 1px solid #ddd; border-radius: 5px; min-height: 100px; height: 100px; overflow-y: scroll;">
        <div class="col-md-12">
            <?php
            /** @var \app\modules\sim\models\ImsiExternalStatusLog[] $logs */
            $logs = $imsi->getExternalStatusLog()->orderBy(['id' => SORT_ASC])->all();
            $lastFullStatus = null;
            foreach ($logs as $log) {
                if (!$log->isRef()) {
                    $lastFullStatus = $log->status;
                } elseif ($lastFullStatus !== null) {
                    $log->resolvedRefStatus = $lastFullStatus;
                }
            }
            foreach (array_reverse($logs) as $log) {
                echo $log->statusStringHtml;
            }
            ?>
        </div>
    </div>
</div>

<div class="modal fade" id="eslInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Подробности</h4>
            </div>
            <div class="modal-body">
                <pre id="eslInfoContent" style="max-height: 400px; overflow: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<?php
$this->registerJs(<<<JS
$(document).on('click', '.esl-info-btn', function(e) {
    e.preventDefault();
    var info = $(this).data('info');
    $('#eslInfoContent').text(typeof info === 'string' ? info : JSON.stringify(info, null, 2));
    $('#eslInfoModal').modal('show');
});
JS
);

