(function ($) {
	'use strict';

	$(function () {
		var mediaFrame = null;
		var $imageId = $('#fighter_image_id');
		var $removeImage = $('#remove_fighter_image');
		var $preview = $('.mmaf-fighter-image-preview');
		var $removeButton = $('#mmaf-remove-fighter-image');
		var $birthDate = $('#date_of_birth');
		var $birthYear = $('#birth_year');
		var $gender = $('#gender');
		var $weightClass = $('#weight_class');
		var weightClasses = {
			male: [
				'flyweight',
				'bantamweight',
				'featherweight',
				'lightweight',
				'welterweight',
				'middleweight',
				'light_heavyweight',
				'heavyweight'
			],
			female: [
				'women_strawweight',
				'women_flyweight',
				'women_bantamweight',
				'women_featherweight'
			]
		};
		var weightLabels = {
			unknown: 'unknown',
			flyweight: 'flyweight',
			bantamweight: 'bantamweight',
			featherweight: 'featherweight',
			lightweight: 'lightweight',
			welterweight: 'welterweight',
			middleweight: 'middleweight',
			light_heavyweight: 'light heavyweight',
			heavyweight: 'heavyweight',
			women_strawweight: 'women strawweight',
			women_flyweight: 'women flyweight',
			women_bantamweight: 'women bantamweight',
			women_featherweight: 'women featherweight'
		};

		function syncBirthYear() {
			var value = $birthDate.val();

			if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
				$birthYear.val(value.substring(0, 4)).prop('readonly', true);
				return;
			}

			$birthYear.prop('readonly', false);
		}

		function setPreview(attachment) {
			var url = attachment.url;

			if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
				url = attachment.sizes.thumbnail.url;
			}

			$preview.html($('<img>', {
				src: url,
				alt: '',
				css: {
					display: 'block',
					marginBottom: '8px',
					maxWidth: '96px',
					height: 'auto'
				}
			}));
		}

		function ensureWeightNote() {
			var $note = $('#mmaf-weight-class-note');

			if (!$note.length) {
				$note = $('<p>', {
					id: 'mmaf-weight-class-note',
					class: 'description'
				}).insertAfter($weightClass);
			}

			return $note;
		}

		function setWeightNote(message) {
			ensureWeightNote().text(message);
		}

		function addWeightOption(value) {
			$weightClass.append($('<option>', {
				value: value,
				text: weightLabels[value] || value.replace(/_/g, ' ')
			}));
		}

		function syncWeightClasses(showResetNote) {
			var gender = $gender.val();
			var current = $weightClass.val() || 'unknown';
			var allowed = ['unknown'];
			var valid;

			if (weightClasses[gender]) {
				allowed = allowed.concat(weightClasses[gender]);
			}

			valid = allowed.indexOf(current) !== -1;
			$weightClass.empty();

			allowed.forEach(addWeightOption);

			if (!valid) {
				current = 'unknown';
				if (showResetNote) {
					setWeightNote('Weight class was reset because it does not match the selected gender.');
				}
			} else if (!gender) {
				setWeightNote('Select a gender before choosing a weight class.');
			} else if (showResetNote) {
				setWeightNote('');
			}

			$weightClass.val(current);
			$weightClass.prop('disabled', !gender);
		}

		$birthDate.on('change input', syncBirthYear);
		syncBirthYear();
		$gender.on('change', function () {
			syncWeightClasses(true);
		});
		syncWeightClasses(false);

		$('#mmaf-select-fighter-image').on('click', function (event) {
			event.preventDefault();

			if (!window.wp || !wp.media) {
				return;
			}

			if (mediaFrame) {
				mediaFrame.open();
				return;
			}

			mediaFrame = wp.media({
				title: 'Select fighter image',
				button: {
					text: 'Use this image'
				},
				library: {
					type: 'image'
				},
				multiple: false
			});

			mediaFrame.on('select', function () {
				var attachment = mediaFrame.state().get('selection').first().toJSON();

				$imageId.val(attachment.id);
				$removeImage.val('0');
				setPreview(attachment);
				$removeButton.show();
			});

			mediaFrame.open();
		});

		$removeButton.on('click', function (event) {
			event.preventDefault();

			$imageId.val('');
			$removeImage.val('1');
			$preview.empty();
			$removeButton.hide();
		});

		$('#mmaf-select-all-fighters').on('change', function () {
			$('.mmaf-fighter-row-checkbox').prop('checked', $(this).prop('checked'));
		});
	});
}(jQuery));
