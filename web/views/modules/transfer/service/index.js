+function ($, clientAccountId) {
    'use strict';

    $(function () {
        var
            universalVersion = 5,

            $services = $('.service-checkbox'),
            $clientSearchField = $('input.account-search'),
            $clientAccount = $('input[name="data[clientAccountId]"]'),
            clientAccountVersion = $clientAccount.data('account-version'),
            searchQuery = '/transfer/service/account-search?clientAccountId=' + $clientAccount.val() +
                // If current account is universal then disable regular accounts from the search
                (clientAccountVersion == universalVersion ? '&clientAccountVersion=' + universalVersion : ''),

            isPossibleToTransfer = function () {
                var isDisabled = true;

                if (
                    $clientSearchField.data('autocomplete').selectedItem instanceof Object
                    && $services.filter(':checked').length
                ) {
                    isDisabled = false;
                }

                $('.process-btn').prop('disabled', isDisabled);
            },

            enableExtendsScenario = function (withKeepAsIs, targetClientAccountId) {
                var $tariffChoose = $('[data-tariff-choose]');

                $tariffChoose.find('select').select2('data', null);
                $tariffChoose.removeClass('collapse');

                $tariffChoose.each(function () {
                    var $selectBox = $(this)
                        .find('select')
                        .on('change', function () {
                            var $selectedItem = $(this).find('option:selected');
                            var selectedVal = $selectedItem.val();

                            if (!selectedVal) {
                                $(this).parent('div').find('div.tariff-info').html('');
                                return;
                            }

                            $(this).parent('div').find('div.tariff-info').find('a').replaceWith(
                                $('<a />')
                                    .attr('href', '/uu/tariff/edit-by-tariff-period?tariffPeriodId=' + selectedVal)
                                    .attr('target', '_blank')
                                    .text('Подробнее о тарифном плане')
                            )
                        });

                    $.ajax({
                        url: '/transfer/service/get-universal-tariffs',
                        data: $.extend({
                            'clientAccountId': targetClientAccountId,
                            'serviceTypeKey': $selectBox.data('service-type'),
                            'serviceValue': $selectBox.data('service-value')
                        }, $selectBox.data('service-extends-data')),
                        dataType: 'html'
                    }).then(function (response) {
                        if (response == '') {
                            $selectBox
                                .parents('tr')
                                    .find('input[type="checkbox"]')
                                        .prop('disabled', true)
                                        .prop('checked', false);
                            return false;
                        }

                        $selectBox
                            .parents('tr')
                                .find('input[type="checkbox"]')
                                    .prop('disabled', false)
                                    .prop('checked', false);

                        if (withKeepAsIs) {
                            var currentTariff = $selectBox.data('current-tariff');
                            var keepAsIsOption = '<option value="">Оставить как есть (' + currentTariff + ')</option>';
                            $selectBox.val('').html(keepAsIsOption + response);
                            $selectBox.val('');
                        } else {
                            $selectBox.val('').html(response);
                        }

                        $selectBox.trigger('change');
                    });
                });
            },
            disableExtendsScenario = function () {
                var $tariffChoose = $('[data-tariff-choose]');

                $tariffChoose.find('select').select2('data', null);
                $tariffChoose.addClass('collapse');
                $tariffChoose.find('div.tariff-info').html('');
            };

        $clientSearchField
            .on('keydown', function(e) {
                if (e.keyCode === $.ui.keyCode.TAB && $(this).autocomplete('instance').menu.active) {
                    e.preventDefault();
                }
                if (e.keyCode === $.ui.keyCode.ENTER) {
                    $(this).blur();
                }
            })
            .autocomplete({
                source: searchQuery,
                minLength: 2,
                focus: function() {
                    return false;
                },
                select: function(event, ui) {
                    // Apply scenario based at target account version
                    if (ui.item.version >= clientAccountVersion) {
                        enableExtendsScenario(false, ui.item.value);
                    } else if (ui.item.version == universalVersion) {
                        enableExtendsScenario(true, ui.item.value);
                    } else {
                        disableExtendsScenario();
                    }

                    // Apply selected value
                    $(this).val(ui.item.label);
                    $('input[name="data[targetClientAccountId]"]').val(ui.item.value);

                    // Check possibility to transfer
                    isPossibleToTransfer();

                    return false;
                },
                change: function () {
                    if (!($(this).data('autocomplete').selectedItem instanceof Object)) {
                        return false;
                    }

                    var selectedItem = $(this).data('autocomplete').selectedItem;

                    $.ajax({
                        url: '/transfer/service/get-client-account-balance',
                        data: {
                            'clientAccountId': selectedItem.value
                        },
                        dataType: 'html',
                        success: function (response) {
                            // Apply selected value for info blocks
                            var $infoBlock = $('.target-account');
                            $infoBlock.find('div:eq(0)').text(selectedItem.full);
                            $infoBlock.find('div.label').text('Баланс: ' + response);

                            // Check possibility to transfer
                            isPossibleToTransfer();
                        }
                    });
                }
            })
            .data('autocomplete')._renderItem = function(ul, item) {
                return $('<li />')
                    .addClass('autocomplete-item')
                    .data('item.autocomplete', item)
                    .append('<a title="' + item.full + '">' + item.label + '</a>')
                    .appendTo(ul);
            };

        $('#transfer-select-all').on('change', function() {
            $services
                .prop('checked', !$services.prop('checked'))
                .trigger('change');
            return false;
        });

        $services.on('change', isPossibleToTransfer);

        $('input[name="data[processedFromDate]"]').on('change', function () {
            $('input.process-datepicker[name^="data[fromDate]"]').kvDatepicker('update', $(this).val());
        });

    })
}(jQuery);
