
(function() {
    var REFRESH_INTERVAL = 5000; // 5 секунд
    var timerId = null;

    function refreshStatuses() {
        var cells = document.querySelectorAll('td[data-doc-state="active"]');
        if (!cells.length) {
            if (timerId) {
                clearInterval(timerId);
                timerId = null;
            }
            return;
        }

        var ids = [];
        cells.forEach(function(cell) {
            ids.push(cell.getAttribute('data-doc-id'));
        });

        $.ajax({
            url: '/sbisTenzor/document/statuses',
            data: { ids: ids.join(',') },
            dataType: 'json',
            success: function(data) {
                cells.forEach(function(cell) {
                    var id = cell.getAttribute('data-doc-id');
                    var info = data[id];
                    if (!info) return;

                    var html = '';
                    if (info.progressValue) {
                        html += '<div class="progress">' +
                            '<div class="progress-bar progress-bar-' + info.progressStyle + ' progress-bar-striped" role="progressbar" ' +
                            'aria-valuenow="' + info.progressValue + '" aria-valuemin="0" aria-valuemax="100" ' +
                            'style="width:' + info.progressValue + '%"></div></div>';
                    }

                    var external = info.externalStateName ? '<br /><small>(' + info.externalStateName + ')</small>' : '';
                    html += '<a href="/sbisTenzor/document/view?id=' + id + '">' +
                        '<span class="text-nowrap"><strong>' + info.stateName + '</strong>' + external + '</span></a>';

                    cell.innerHTML = html;

                    if (!info.isProcessing) {
                        cell.setAttribute('data-doc-state', 'final');
                    }

                    // обновить ячейку "Подписан"
                    var signedCell = document.querySelector('td[data-doc-signed="' + id + '"]');
                    if (signedCell) {
                        signedCell.innerHTML = info.isSigned
                            ? '<strong class="text-success">Да</strong>'
                            : '<strong class="text-danger">Нет</strong>';
                    }
                });

                // все стали финальными — остановить таймер
                if (!document.querySelectorAll('td[data-doc-state="active"]').length && timerId) {
                    clearInterval(timerId);
                    timerId = null;
                }
            }
        });
    }

    refreshStatuses();
    timerId = setInterval(refreshStatuses, REFRESH_INTERVAL);
})();
