(function () {
	'use strict';

	var portal = document.getElementById('portal');
	if (!portal) {
		return;
	}

	var CONFIG = {
		token: portal.getAttribute('data-token') || '',
		nonce: portal.getAttribute('data-nonce') || '',
		uploadUrl: portal.getAttribute('data-upload-url') || '',
		maxFiles: parseInt(portal.getAttribute('data-max-files') || '0', 10),
		maxSizeMB: parseInt(portal.getAttribute('data-max-size-mb') || '0', 10),
		expiresAt: parseInt(portal.getAttribute('data-expires-at') || '0', 10),
		allowedTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml']
	};

	var uploadCount = parseInt(portal.getAttribute('data-existing') || '0', 10);
	var uploading = 0;
	var expired = false;

	var dropzone = document.getElementById('dropzone');
	var fileInput = document.getElementById('file-input');
	var browseBtn = document.getElementById('browse-btn');
	var uploadsGrid = document.getElementById('uploads-grid');
	var fileCountEl = document.getElementById('file-count');
	var countdownEl = document.getElementById('countdown');
	var errorEl = document.getElementById('error-message');
	var actionsEl = document.getElementById('actions');
	var doneBtn = document.getElementById('done-btn');
	var doneMsg = document.getElementById('done-message');

	function updateCountdown() {
		var now = Math.floor(Date.now() / 1000);
		var remaining = CONFIG.expiresAt - now;

		if (remaining <= 0) {
			countdownEl.textContent = '0:00';
			countdownEl.classList.add('urgent');
			expired = true;
			portal.classList.add('disabled');
			errorEl.textContent = 'Session expired. Please request a new upload link.';
			errorEl.style.display = 'block';
			return;
		}

		var minutes = Math.floor(remaining / 60);
		var seconds = remaining % 60;
		countdownEl.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

		if (remaining <= 120) {
			countdownEl.classList.add('urgent');
		}

		setTimeout(updateCountdown, 1000);
	}

	function showError(msg) {
		errorEl.textContent = msg;
		errorEl.style.display = 'block';
		setTimeout(function () {
			errorEl.style.display = 'none';
		}, 5000);
	}

	function updateProgress(item, pct) {
		var fg = item.querySelector('.progress-ring .fg');
		if (fg) {
			var circumference = 106.8;
			var offset = circumference - (pct / 100) * circumference;
			fg.setAttribute('stroke-dashoffset', offset);
		}
	}

	function markComplete(item) {
		var overlay = item.querySelector('.progress-overlay');
		if (overlay) {
			overlay.remove();
		}

		var check = document.createElement('div');
		check.className = 'checkmark';
		check.innerHTML = '&#10003;';
		item.appendChild(check);
	}

	function markError(item, message) {
		var overlay = item.querySelector('.progress-overlay');
		if (overlay) {
			overlay.remove();
		}

		var badge = document.createElement('div');
		badge.className = 'error-badge';
		badge.innerHTML = '!';
		badge.title = message;
		item.appendChild(badge);

		showError(message);
	}

	function createUploadItem(file) {
		var div = document.createElement('div');
		div.className = 'upload-item';

		var img = document.createElement('img');
		img.src = URL.createObjectURL(file);
		div.appendChild(img);

		var overlay = document.createElement('div');
		overlay.className = 'progress-overlay';
		overlay.innerHTML = '<svg class="progress-ring" viewBox="0 0 40 40">' +
			'<circle class="bg" cx="20" cy="20" r="17"/>' +
			'<circle class="fg" cx="20" cy="20" r="17" stroke-dasharray="106.8" stroke-dashoffset="106.8"/>' +
			'</svg>';
		div.appendChild(overlay);

		var nameEl = document.createElement('div');
		nameEl.className = 'filename';
		nameEl.textContent = file.name;
		div.appendChild(nameEl);

		uploadsGrid.appendChild(div);
		return div;
	}

	function uploadFile(file) {
		uploading++;
		var item = createUploadItem(file);

		var formData = new FormData();
		formData.append('file', file);
		formData.append('token', CONFIG.token);
		formData.append('_wpnonce', CONFIG.nonce);

		var xhr = new XMLHttpRequest();
		xhr.open('POST', CONFIG.uploadUrl, true);
		xhr.withCredentials = false;

		xhr.upload.addEventListener('progress', function (e) {
			if (e.lengthComputable) {
				updateProgress(item, Math.round((e.loaded / e.total) * 100));
			}
		});

		xhr.addEventListener('load', function () {
			uploading--;
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					var resp = JSON.parse(xhr.responseText);
					if (resp.success) {
						markComplete(item);
						uploadCount++;
						fileCountEl.textContent = uploadCount;
						actionsEl.style.display = 'block';
					} else {
						markError(item, resp.error || 'Upload failed');
					}
				} catch (e2) {
					markError(item, 'Invalid server response');
				}
			} else {
				try {
					var errResp = JSON.parse(xhr.responseText);
					markError(item, errResp.error || errResp.message || 'Upload failed (HTTP ' + xhr.status + ')');
				} catch (e3) {
					markError(item, 'Upload failed (HTTP ' + xhr.status + ')');
				}
			}
		});

		xhr.addEventListener('error', function () {
			uploading--;
			markError(item, 'Network error. Please try again.');
		});

		xhr.send(formData);
	}

	function handleFiles(fileList) {
		if (expired) {
			return;
		}

		for (var i = 0; i < fileList.length; i++) {
			var file = fileList[i];

			if (uploadCount + uploading >= CONFIG.maxFiles) {
				showError('Maximum ' + CONFIG.maxFiles + ' files per session.');
				break;
			}

			if (CONFIG.allowedTypes.indexOf(file.type) === -1) {
				showError(file.name + ': File type not supported.');
				continue;
			}

			if (file.size > CONFIG.maxSizeMB * 1024 * 1024) {
				showError(file.name + ': Exceeds ' + CONFIG.maxSizeMB + 'MB limit.');
				continue;
			}

			uploadFile(file);
		}
	}

	updateCountdown();

	dropzone.addEventListener('dragover', function (e) {
		e.preventDefault();
		e.stopPropagation();
		dropzone.classList.add('dragover');
	});

	dropzone.addEventListener('dragleave', function (e) {
		e.preventDefault();
		e.stopPropagation();
		dropzone.classList.remove('dragover');
	});

	dropzone.addEventListener('drop', function (e) {
		e.preventDefault();
		e.stopPropagation();
		dropzone.classList.remove('dragover');
		if (e.dataTransfer && e.dataTransfer.files) {
			handleFiles(e.dataTransfer.files);
		}
	});

	browseBtn.addEventListener('click', function () {
		fileInput.click();
	});

	fileInput.addEventListener('change', function () {
		if (fileInput.files) {
			handleFiles(fileInput.files);
		}
		fileInput.value = '';
	});

	doneBtn.addEventListener('click', function () {
		doneMsg.style.display = 'block';
		doneBtn.disabled = true;
		doneBtn.textContent = 'Done!';
		doneBtn.style.background = '#86efac';
		doneBtn.style.color = '#166534';
	});
})();
