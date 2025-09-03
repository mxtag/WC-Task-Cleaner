/* global jQuery, STSCleanerI18n */
(function ($) {
	'use strict';

	$(function () {
		// Select all checkboxes.
		var $selectAll = $('#select-all');
		if ($selectAll.length) {
			$selectAll.on('change', function () {
				var checked = $(this).is(':checked');
				$('input[name="selected_hooks[]"]').prop('checked', checked);
			});
		}

		// Confirm: Clean All.
		$('#stsc-clean-all').on('click', function (e) {
			if (!window.confirm(STSCleanerI18n.confirmCleanAll)) {
				e.preventDefault();
			}
		});

		// Confirm: Clear Logs.
		$('#stsc-clear-logs').on('click', function (e) {
			if (!window.confirm(STSCleanerI18n.confirmClearLogs)) {
				e.preventDefault();
			}
		});

		// Validate "Clean Selected" form.
		$('#stsc-clean-selected-form').on('submit', function (e) {
			if ($('input[name="selected_hooks[]"]:checked').length === 0) {
				e.preventDefault();
				window.alert(STSCleanerI18n.pleaseSelectAtLeastOne);
			}
		});
	});
})(jQuery);
