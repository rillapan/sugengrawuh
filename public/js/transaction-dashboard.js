(function () {
    'use strict';

    /**
     * Safely parse bootstrap JSON embedded in the transaction index view.
     */
    function parseBootstrapData() {
        var el = document.getElementById('pos-bootstrap-data');
        if (!el || !el.textContent) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (err) {
            console.error('POS bootstrap parse error:', err);
            return null;
        }
    }

    /**
     * Create a DOM element safely using textContent (never innerHTML).
     */
    function createEl(tag, options, text) {
        var node = document.createElement(tag);
        if (options) {
            if (options.className) {
                node.className = options.className;
            }
            if (options.id) {
                node.id = options.id;
            }
            if (options.type) {
                node.type = options.type;
            }
            if (options.value !== undefined) {
                node.value = options.value;
            }
            if (options.name) {
                node.name = options.name;
            }
            if (options.href) {
                node.href = options.href;
            }
            if (options.target) {
                node.target = options.target;
            }
            if (options.rel) {
                node.rel = options.rel;
            }
            if (options.hidden) {
                node.hidden = true;
            }
            if (options.disabled) {
                node.disabled = true;
            }
            if (options.attrs) {
                Object.keys(options.attrs).forEach(function (k) {
                    node.setAttribute(k, String(options.attrs[k]));
                });
            }
            if (options.dataset) {
                Object.keys(options.dataset).forEach(function (k) {
                    node.dataset[k] = String(options.dataset[k]);
                });
            }
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function pad2(n) {
        return n < 10 ? '0' + String(n) : String(n);
    }

    function formatHMS(totalSeconds) {
        var sec = Math.max(0, Math.floor(Number(totalSeconds) || 0));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        return pad2(h) + ':' + pad2(m) + ':' + pad2(s);
    }

    function formatClockTime(timestampMs) {
        var d = new Date(timestampMs);
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function formatRupiah(amount) {
        var num = Math.round(Number(amount) || 0);
        return 'Rp ' + num.toLocaleString('id-ID');
    }

    function formatStationLabel(device) {
        if (!device) {
            return 'Stasiun';
        }
        if (device.station_number) {
            return 'Station ' + device.station_number + ' - ' + device.nama;
        }
        return device.nama || 'Stasiun';
    }

    function extractErrorMessage(data, fallback) {
        if (data && typeof data.message === 'string' && data.message.trim() !== '') {
            return data.message;
        }
        if (data && typeof data.error === 'string' && data.error.trim() !== '') {
            return data.error;
        }
        if (data && data.errors && typeof data.errors === 'object') {
            var keys = Object.keys(data.errors);
            for (var i = 0; i < keys.length; i++) {
                var val = data.errors[keys[i]];
                if (Array.isArray(val) && val.length > 0) {
                    return String(val[0]);
                }
                if (typeof val === 'string' && val.trim() !== '') {
                    return val;
                }
            }
        }
        return fallback;
    }

    function initDashboard() {
        var bootstrap = parseBootstrapData();
        if (!bootstrap) {
            return;
        }

        var clientClockOffset = Date.now() - Number(bootstrap.serverTimestamp || Date.now());
        var csrfToken = bootstrap.csrfToken ||
            (document.querySelector('meta[name="csrf-token"]') && document.querySelector('meta[name="csrf-token"]').getAttribute('content')) ||
            '';

        function getServerNow() {
            return Date.now() - clientClockOffset;
        }

        function findDevice(deviceId) {
            var list = Array.isArray(bootstrap.devices) ? bootstrap.devices : [];
            for (var i = 0; i < list.length; i++) {
                if (String(list[i].id) === String(deviceId)) {
                    return list[i];
                }
            }
            return null;
        }

        function showBanner(type, message) {
            var banner = document.getElementById('pos-feedback-banner');
            var msgEl = document.getElementById('pos-feedback-message');
            if (!banner || !msgEl) {
                return;
            }
            banner.classList.remove('is-info', 'is-success', 'is-error');
            banner.classList.add(type === 'success' ? 'is-success' : (type === 'error' ? 'is-error' : 'is-info'));
            msgEl.textContent = String(message || '');
            banner.hidden = false;
        }

        function setButtonLoading(btn, isLoading, loadingText, withSpinner) {
            if (!btn) {
                return;
            }
            if (isLoading) {
                if (btn.dataset.origLabel === undefined) {
                    btn.dataset.origLabel = btn.textContent;
                }
                btn.disabled = true;
                btn.replaceChildren();
                if (withSpinner) {
                    var icon = createEl('i', {
                        className: 'fas fa-spinner fa-spin',
                        attrs: { 'aria-hidden': 'true' }
                    });
                    btn.appendChild(icon);
                    btn.appendChild(document.createTextNode(' ' + loadingText));
                } else {
                    btn.textContent = loadingText;
                }
            } else {
                btn.disabled = false;
                if (btn.dataset.origLabel !== undefined) {
                    btn.textContent = btn.dataset.origLabel;
                    delete btn.dataset.origLabel;
                }
            }
        }

        function apiFetchJson(url, options) {
            var opts = options || {};
            var headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken
            }, opts.headers || {});

            return fetch(url, Object.assign({}, opts, { headers: headers })).then(function (res) {
                return res.text().then(function (raw) {
                    var parsed = null;
                    if (raw) {
                        try {
                            parsed = JSON.parse(raw);
                        } catch (e) {
                            parsed = null;
                        }
                    }
                    return { ok: res.ok, status: res.status, data: parsed };
                });
            });
        }

        // ------------------------------------------------------------------
        // Native <dialog> Management
        // ------------------------------------------------------------------
        function clearDialogErrors(dialog) {
            if (!dialog) {
                return;
            }
            var errors = dialog.querySelectorAll('.pos-dialog-error');
            errors.forEach(function (errEl) {
                errEl.textContent = '';
                errEl.hidden = true;
            });
        }

        function showDialogError(errorEl, message) {
            if (!errorEl) {
                return;
            }
            errorEl.textContent = String(message || 'Terjadi kesalahan.');
            errorEl.hidden = false;
        }

        function openDialog(id) {
            var dialog = document.getElementById(id);
            if (!dialog) {
                return;
            }
            clearDialogErrors(dialog);
            if (!dialog.open && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        }

        function closeDialog(id) {
            var dialog = document.getElementById(id);
            if (!dialog) {
                return;
            }
            clearDialogErrors(dialog);
            if (dialog.open && typeof dialog.close === 'function') {
                dialog.close();
            }
        }

        function isPointOutsideRect(clientX, clientY, rect) {
            return (
                clientX < rect.left ||
                clientX > rect.right ||
                clientY < rect.top ||
                clientY > rect.bottom
            );
        }

        function initDialogs() {
            document.querySelectorAll('[data-dialog-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var dialog = btn.closest('dialog');
                    if (dialog) {
                        closeDialog(dialog.id);
                    }
                });
            });

            var supportsClosedBy = typeof HTMLDialogElement !== 'undefined' &&
                ('closedBy' in HTMLDialogElement.prototype);

            document.querySelectorAll('dialog.pos-dialog').forEach(function (dialog) {
                var pointerStartedOutside = false;

                dialog.addEventListener('close', function () {
                    pointerStartedOutside = false;
                    clearDialogErrors(dialog);
                });

                dialog.addEventListener('pointercancel', function () {
                    pointerStartedOutside = false;
                });

                dialog.addEventListener('pointerdown', function (e) {
                    if (supportsClosedBy && dialog.closedBy === 'any') {
                        return;
                    }
                    if (e.target !== dialog) {
                        pointerStartedOutside = false;
                        return;
                    }
                    pointerStartedOutside = isPointOutsideRect(e.clientX, e.clientY, dialog.getBoundingClientRect());
                });

                dialog.addEventListener('pointerup', function (e) {
                    if (supportsClosedBy && dialog.closedBy === 'any') {
                        return;
                    }
                    var shouldDismiss = pointerStartedOutside &&
                        e.target === dialog &&
                        isPointOutsideRect(e.clientX, e.clientY, dialog.getBoundingClientRect());
                    pointerStartedOutside = false;
                    if (shouldDismiss) {
                        closeDialog(dialog.id);
                    }
                });
            });
        }

        // ------------------------------------------------------------------
        // Accessible Tabs (Stasiun | Riwayat Transaksi)
        // ------------------------------------------------------------------
        function initTabs() {
            var tablist = document.querySelector('.pos-tablist');
            if (!tablist) {
                return;
            }
            var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"][data-tab]'));
            var panelStations = document.getElementById('panel-stations');
            var panelHistory = document.getElementById('panel-history');

            function activateTab(tabName, shouldFocus) {
                var target = tabName === 'history' ? 'history' : 'stations';

                tabs.forEach(function (tab) {
                    var isActive = tab.dataset.tab === target;
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    tab.setAttribute('tabindex', isActive ? '0' : '-1');
                    if (isActive && shouldFocus) {
                        tab.focus();
                    }
                });

                if (panelStations) {
                    panelStations.hidden = (target !== 'stations');
                }
                if (panelHistory) {
                    panelHistory.hidden = (target !== 'history');
                }

                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', target);
                    window.history.replaceState(null, '', url.toString());
                } catch (e) {
                    // Ignore History API edge errors in restricted contexts
                }
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    activateTab(tab.dataset.tab, false);
                });
            });

            tablist.addEventListener('keydown', function (e) {
                if (tabs.length === 0) {
                    return;
                }
                var currentIndex = tabs.indexOf(document.activeElement);
                if (currentIndex === -1) {
                    currentIndex = tabs.findIndex(function (t) {
                        return t.getAttribute('aria-selected') === 'true';
                    });
                }
                if (currentIndex < 0) {
                    currentIndex = 0;
                }

                var nextIndex = null;
                if (e.key === 'ArrowRight') {
                    nextIndex = (currentIndex + 1) % tabs.length;
                } else if (e.key === 'ArrowLeft') {
                    nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                } else if (e.key === 'Home') {
                    nextIndex = 0;
                } else if (e.key === 'End') {
                    nextIndex = tabs.length - 1;
                }

                if (nextIndex !== null) {
                    e.preventDefault();
                    activateTab(tabs[nextIndex].dataset.tab, true);
                }
            });
        }

        // ------------------------------------------------------------------
        // Server-Synced Live Timers & Polling
        // ------------------------------------------------------------------
        function computeLostTimeEstimate(hourlyRate, elapsedSeconds) {
            var rate = Number(hourlyRate || 0);
            if (rate <= 0) {
                return 0;
            }
            var elapsedMinutes = Math.floor(elapsedSeconds / 60);
            if (elapsedMinutes <= 0) {
                return 0;
            }
            var raw = Math.round((elapsedMinutes * rate) / 60);
            var lowerThousand = Math.floor(raw / 1000) * 1000;
            var diff = raw - lowerThousand;
            if (diff === 0) {
                return raw;
            }
            if (diff <= 500) {
                return lowerThousand + 500;
            }
            return lowerThousand + 1000;
        }

        function initTimersAndPolling() {
            var isSyncInFlight = false;
            var expiredSyncTxIds = {};
            var expiredSyncDebounce = null;

            function syncTimersWithServer() {
                if (isSyncInFlight || !bootstrap.routes || !bootstrap.routes.timersSync) {
                    return;
                }
                isSyncInFlight = true;

                apiFetchJson(bootstrap.routes.timersSync, { method: 'GET' })
                    .then(function (res) {
                        if (!res.ok || !res.data) {
                            return;
                        }
                        var data = res.data;
                        if (data.server_timestamp) {
                            clientClockOffset = Date.now() - Number(data.server_timestamp);
                        }

                        var serverDevices = Array.isArray(data.devices) ? data.devices : [];
                        var statusChanged = false;

                        for (var i = 0; i < serverDevices.length; i++) {
                            var dev = serverDevices[i];
                            var card = document.getElementById('station-card-' + dev.id);
                            if (!card) {
                                continue;
                            }
                            var domIsUsed = card.classList.contains('is-used');
                            var serverIsUsed = dev.status === 'Digunakan';

                            var timerEl = card.querySelector('.js-pos-timer');
                            var domTxId = (timerEl && timerEl.dataset.txId) ? Number(timerEl.dataset.txId) : null;
                            var serverTxId = (dev.transaction && dev.transaction.id_transaksi)
                                ? Number(dev.transaction.id_transaksi)
                                : null;

                            if (domIsUsed !== serverIsUsed || domTxId !== serverTxId) {
                                statusChanged = true;
                                break;
                            }
                        }

                        if (statusChanged &&
                            !document.querySelector('dialog[open]') &&
                            !document.querySelector('.modal.show')) {
                            window.location.reload();
                        }
                    })
                    .catch(function () {
                        // Silent network hiccup on poll; next interval retries
                    })
                    .finally(function () {
                        isSyncInFlight = false;
                    });
            }

            function scheduleExpiredSync() {
                if (expiredSyncDebounce) {
                    return;
                }
                expiredSyncDebounce = setTimeout(function () {
                    expiredSyncDebounce = null;
                    syncTimersWithServer();
                }, 400);
            }

            function updateAllTimers() {
                var now = getServerNow();
                var timers = document.querySelectorAll('.js-pos-timer');
                var triggerExpiredSync = false;

                timers.forEach(function (timerEl) {
                    var tipe = timerEl.dataset.tipe || '';
                    var deviceId = timerEl.dataset.deviceId || '';
                    var txId = timerEl.dataset.txId || '';

                    if (tipe === 'postpaid') {
                        var startIso = timerEl.dataset.lostTimeStart || timerEl.dataset.startedAt || '';
                        var startMs = startIso ? Date.parse(startIso) : NaN;
                        if (Number.isNaN(startMs)) {
                            return;
                        }
                        var elapsedSec = Math.max(0, Math.floor((now - startMs) / 1000));
                        timerEl.textContent = formatHMS(elapsedSec);

                        var estEl = document.getElementById('pos-est-rental-' + deviceId);
                        if (estEl) {
                            var est = computeLostTimeEstimate(timerEl.dataset.hourlyRate, elapsedSec);
                            estEl.textContent = formatRupiah(est);
                        }
                    } else {
                        var expIso = timerEl.dataset.expiresAt || '';
                        var expMs = expIso ? Date.parse(expIso) : NaN;
                        if (Number.isNaN(expMs)) {
                            return;
                        }
                        var remainingSec = Math.max(0, Math.ceil((expMs - now) / 1000));
                        if (now >= expMs) {
                            timerEl.textContent = '00:00:00 (Waktu Habis)';
                            if (txId && !expiredSyncTxIds[txId]) {
                                expiredSyncTxIds[txId] = true;
                                triggerExpiredSync = true;
                            }
                        } else {
                            timerEl.textContent = formatHMS(remainingSec);
                        }
                    }
                });

                if (triggerExpiredSync) {
                    scheduleExpiredSync();
                }
            }

            updateAllTimers();
            setInterval(updateAllTimers, 1000);
            setInterval(syncTimersWithServer, 5000);
        }

        // ------------------------------------------------------------------
        // Lost Time Quick Start
        // ------------------------------------------------------------------
        function initLostTime() {
            var form = document.getElementById('lost-time-form');
            var deviceIdInput = document.getElementById('lost-time-device-id');
            var psIdInput = document.getElementById('lost-time-playstation-id');
            var stationLabelEl = document.getElementById('lost-time-station-label');
            var namaInput = document.getElementById('lost-time-nama');
            var teleponInput = document.getElementById('lost-time-telepon');
            var optionsContainer = document.getElementById('lost-time-category-options');
            var errorEl = document.getElementById('lost-time-dialog-error');
            var submitBtn = document.getElementById('btn-submit-lost-time');

            function startDirectLostTime(btn, device) {
                if (btn.disabled) {
                    return;
                }
                setButtonLoading(btn, true, 'Memulai...', true);

                var fd = new FormData();
                fd.append('_token', csrfToken);
                fd.append('tipe_transaksi', 'postpaid');
                fd.append('device_id', String(device.id));
                if (Array.isArray(device.playstations) && device.playstations.length > 0) {
                    fd.append('playstation_id', String(device.playstations[0].id));
                }
                fd.append('nama', '');

                apiFetchJson(bootstrap.routes.storeTransaction, {
                    method: 'POST',
                    body: fd
                })
                    .then(function (res) {
                        if (res.ok && res.data && res.data.success) {
                            window.location.href = window.location.pathname + '?tab=stations';
                            return;
                        }
                        showBanner('error', extractErrorMessage(res.data, 'Gagal memulai sesi Lost Time.'));
                        setButtonLoading(btn, false);
                    })
                    .catch(function () {
                        showBanner('error', 'Koneksi gagal saat memulai sesi Lost Time.');
                        setButtonLoading(btn, false);
                    });
            }

            function openLostTimePicker(device) {
                if (!deviceIdInput || !psIdInput || !optionsContainer) {
                    return;
                }
                deviceIdInput.value = String(device.id);
                if (stationLabelEl) {
                    stationLabelEl.textContent = formatStationLabel(device);
                }
                if (namaInput) {
                    namaInput.value = '';
                }
                if (teleponInput) {
                    teleponInput.value = '';
                }

                optionsContainer.replaceChildren();
                var list = Array.isArray(device.playstations) ? device.playstations : [];
                var selectedPsId = null;

                list.forEach(function (ps, idx) {
                    var isChecked = (device.playstation_id && String(ps.id) === String(device.playstation_id)) ||
                        (!device.playstation_id && idx === 0);
                    if (isChecked) {
                        selectedPsId = ps.id;
                    }

                    var label = createEl('label', {
                        className: 'pos-category-option d-flex align-items-center justify-content-between p-2 border rounded mb-0'
                    });
                    var left = createEl('span', { className: 'd-flex align-items-center', attrs: { style: 'gap: 0.5rem;' } });
                    var radio = createEl('input', {
                        type: 'radio',
                        name: 'lost_time_ps_choice',
                        value: String(ps.id)
                    });
                    if (isChecked) {
                        radio.checked = true;
                    }
                    radio.addEventListener('change', function () {
                        if (radio.checked) {
                            psIdInput.value = String(ps.id);
                        }
                    });
                    left.appendChild(radio);
                    left.appendChild(createEl('strong', null, ps.nama));

                    var right = createEl('span', { className: 'small text-muted font-weight-bold' }, formatRupiah(ps.harga) + '/jam');
                    label.appendChild(left);
                    label.appendChild(right);
                    optionsContainer.appendChild(label);
                });

                psIdInput.value = selectedPsId !== null ? String(selectedPsId) : '';
                openDialog('lostTimeCategoryDialog');
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-start-lost-time');
                if (!btn) {
                    return;
                }
                var device = findDevice(btn.dataset.deviceId);
                if (!device) {
                    showBanner('error', 'Data stasiun tidak ditemukan.');
                    return;
                }
                var psList = Array.isArray(device.playstations) ? device.playstations : [];
                if (psList.length > 1) {
                    openLostTimePicker(device);
                } else {
                    startDirectLostTime(btn, device);
                }
            });

            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (!submitBtn || submitBtn.disabled) {
                        return;
                    }
                    var checked = optionsContainer ? optionsContainer.querySelector('input[name="lost_time_ps_choice"]:checked') : null;
                    if (checked && psIdInput) {
                        psIdInput.value = checked.value;
                    }
                    if (!psIdInput || !psIdInput.value) {
                        showDialogError(errorEl, 'Pilih kategori permainan terlebih dahulu.');
                        return;
                    }

                    setButtonLoading(submitBtn, true, 'Memulai...', false);
                    var fd = new FormData(form);
                    fd.delete('lost_time_ps_choice');

                    apiFetchJson(bootstrap.routes.storeTransaction, {
                        method: 'POST',
                        body: fd
                    })
                        .then(function (res) {
                            if (res.ok && res.data && res.data.success) {
                                window.location.href = window.location.pathname + '?tab=stations';
                                return;
                            }
                            showDialogError(errorEl, extractErrorMessage(res.data, 'Gagal memulai sesi Lost Time.'));
                            setButtonLoading(submitBtn, false);
                        })
                        .catch(function () {
                            showDialogError(errorEl, 'Koneksi gagal saat memulai sesi Lost Time.');
                            setButtonLoading(submitBtn, false);
                        });
                });
            }
        }

        // ------------------------------------------------------------------
        // Paket & Custom Paket Modal
        // ------------------------------------------------------------------
        function initPaketModal() {
            var form = document.getElementById('paket-form');
            if (!form) {
                return;
            }
            var paketDialog = document.getElementById('paketDialog');

            var deviceIdInput = document.getElementById('paket-device-id');
            var actionInput = document.getElementById('paket-action-input');
            var tipeInput = document.getElementById('paket-tipe-transaksi');
            var hargaInput = document.getElementById('paket-harga-input');
            var totalInput = document.getElementById('paket-total-input');
            var durationSecondsInput = document.getElementById('paket-duration-seconds-input');

            var stationLabel = document.getElementById('paket-station-label');
            var btnModePrepaid = document.getElementById('btn-mode-prepaid');
            var btnModeCustom = document.getElementById('btn-mode-custom');
            var namaInput = document.getElementById('paket-nama');
            var teleponInput = document.getElementById('paket-telepon');

            var prepaidSection = document.getElementById('paket-prepaid-section');
            var customSection = document.getElementById('paket-custom-section');

            var psSelect = document.getElementById('paket-playstation-id');
            var durationSelect = document.getElementById('paket-duration-select');
            var durationPresetsEl = document.getElementById('paket-duration-presets');
            var customDurationFields = document.getElementById('paket-custom-duration-fields');
            var customHoursInput = document.getElementById('paket-custom-hours');
            var customMinutesInput = document.getElementById('paket-custom-minutes');
            var customSecondsInput = document.getElementById('paket-custom-seconds');
            var prepaidPreview = document.getElementById('paket-prepaid-price-preview');

            var customPkgSelect = document.getElementById('paket-custom-package-id');
            var customPkgDetail = document.getElementById('paket-custom-package-detail');
            var errorEl = document.getElementById('paket-dialog-error');
            var simpanBtn = document.getElementById('btn-submit-paket-simpan');
            var bayarBtn = document.getElementById('btn-submit-paket-bayar');
            var actionButtons = [simpanBtn, bayarBtn].filter(Boolean);

            var currentDevice = null;
            var currentCompatiblePackages = [];
            var currentDurations = [];
            var currentBasePrice = 0;
            var hourlyAbortController = null;
            var customAbortController = null;
            var customDebounceTimer = null;
            var isCustomCalcPending = false;
            var customCalcSeq = 0;
            var isSubmittingPaket = false;
            var paketSubmitSeq = 0;
            var lastTriggeredAction = 'bayar';

            function setPaketSubmittingState(isSubmitting, triggeredAction) {
                isSubmittingPaket = Boolean(isSubmitting);
                if (isSubmitting) {
                    var triggeredBtn = triggeredAction === 'simpan' ? simpanBtn : bayarBtn;
                    actionButtons.forEach(function (btn) {
                        if (btn === triggeredBtn) {
                            setButtonLoading(btn, true, 'Memproses...', false);
                        } else {
                            if (btn.dataset.origLabel === undefined) {
                                btn.dataset.origLabel = btn.textContent;
                            }
                            btn.disabled = true;
                        }
                    });
                } else {
                    actionButtons.forEach(function (btn) {
                        setButtonLoading(btn, false);
                    });
                }
            }

            function resolvePaketAction(submitter) {
                if (submitter && submitter.dataset &&
                    (submitter.dataset.paketAction === 'simpan' || submitter.dataset.paketAction === 'bayar')) {
                    return submitter.dataset.paketAction;
                }
                if (lastTriggeredAction === 'simpan' || lastTriggeredAction === 'bayar') {
                    return lastTriggeredAction;
                }
                return 'bayar';
            }

            function resetPaketSubmissionState() {
                paketSubmitSeq++;
                lastTriggeredAction = 'bayar';
                if (actionInput) {
                    actionInput.value = 'bayar';
                }
                setPaketSubmittingState(false);
            }

            function getCustomTotalSeconds() {
                var h = Math.max(0, parseInt(customHoursInput ? customHoursInput.value : '0', 10) || 0);
                var m = Math.max(0, parseInt(customMinutesInput ? customMinutesInput.value : '0', 10) || 0);
                var s = Math.max(0, parseInt(customSecondsInput ? customSecondsInput.value : '0', 10) || 0);
                return { h: h, m: m, s: s, total: (h * 3600) + (m * 60) + s };
            }

            function clearCustomCalcTimer() {
                if (customDebounceTimer) {
                    clearTimeout(customDebounceTimer);
                    customDebounceTimer = null;
                }
            }

            function invalidateCustomCalcState() {
                durationSecondsInput.value = '';
                totalInput.value = '';
                isCustomCalcPending = true;
            }

            function getCompatibleCustomPackages(device) {
                var allPkgs = Array.isArray(bootstrap.customPackages) ? bootstrap.customPackages : [];
                var devicePsIds = (Array.isArray(device.playstations) ? device.playstations : []).map(function (ps) {
                    return String(ps.id);
                });
                return allPkgs.filter(function (pkg) {
                    var pkgPs = Array.isArray(pkg.playstations) ? pkg.playstations : [];
                    return pkgPs.some(function (p) {
                        return devicePsIds.indexOf(String(p.id)) !== -1;
                    });
                });
            }

            function renderPrepaidPreview(durationText, durationSec, totalPrice) {
                if (!prepaidPreview) {
                    return;
                }
                if (!durationSec || durationSec <= 0) {
                    prepaidPreview.textContent = 'Durasi: 0 detik | Total: Rp 0';
                    return;
                }
                var estEnd = formatClockTime(getServerNow() + Number(durationSec) * 1000);
                prepaidPreview.textContent =
                    'Durasi: ' + durationText +
                    ' | Estimasi Selesai: ' + estEnd +
                    ' | Total: ' + formatRupiah(totalPrice);
            }

            function updatePresetChipsHighlight(selectedVal) {
                if (!durationPresetsEl) {
                    return;
                }
                durationPresetsEl.querySelectorAll('.pos-chip').forEach(function (chip) {
                    var active = String(chip.dataset.durationSeconds) === String(selectedVal);
                    chip.classList.toggle('bg-primary', active);
                    chip.classList.toggle('text-white', active);
                    chip.classList.toggle('border-primary', active);
                    chip.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            }

            function runCustomDurationCalc() {
                if (!currentDevice || !psSelect || !psSelect.value) {
                    isCustomCalcPending = false;
                    return Promise.resolve(false);
                }
                if (customAbortController) {
                    customAbortController.abort();
                }
                customAbortController = new AbortController();
                var seq = ++customCalcSeq;
                isCustomCalcPending = true;

                var spec = getCustomTotalSeconds();

                var qs = new URLSearchParams({
                    device: String(currentDevice.id),
                    playstation_id: String(psSelect.value),
                    hours: String(spec.h),
                    minutes: String(spec.m),
                    seconds: String(spec.s)
                });

                return apiFetchJson(bootstrap.routes.apiCalcDurationPrice + '?' + qs.toString(), {
                    method: 'GET',
                    signal: customAbortController.signal
                })
                    .then(function (res) {
                        if (seq !== customCalcSeq) {
                            return false;
                        }
                        isCustomCalcPending = false;
                        if (!res.ok || !res.data) {
                            return false;
                        }
                        var data = res.data;
                        var sec = Number(data.duration_seconds || 0);
                        var price = Number(data.price || 0);
                        durationSecondsInput.value = sec > 0 ? String(sec) : '';
                        hargaInput.value = String(currentBasePrice || 0);
                        totalInput.value = String(price);
                        renderPrepaidPreview(data.human || data.formatted || (sec + ' detik'), sec, price);
                        return true;
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') {
                            return false;
                        }
                        if (seq === customCalcSeq) {
                            isCustomCalcPending = false;
                        }
                        return false;
                    });
            }

            function scheduleCustomDurationCalc(delayMs) {
                clearCustomCalcTimer();
                isCustomCalcPending = true;
                if (delayMs <= 0) {
                    runCustomDurationCalc();
                    return;
                }
                customDebounceTimer = setTimeout(function () {
                    customDebounceTimer = null;
                    runCustomDurationCalc();
                }, delayMs);
            }

            function handleDurationChange() {
                if (!durationSelect) {
                    return;
                }
                var val = durationSelect.value;
                updatePresetChipsHighlight(val);

                if (val === 'custom') {
                    if (customDurationFields) {
                        customDurationFields.hidden = false;
                    }
                    invalidateCustomCalcState();
                    scheduleCustomDurationCalc(0);
                    return;
                }

                if (customDurationFields) {
                    customDurationFields.hidden = true;
                }
                clearCustomCalcTimer();
                if (customAbortController) {
                    customAbortController.abort();
                }
                customCalcSeq++;
                isCustomCalcPending = false;

                var matched = null;
                for (var i = 0; i < currentDurations.length; i++) {
                    if (String(currentDurations[i].duration_seconds) === String(val)) {
                        matched = currentDurations[i];
                        break;
                    }
                }

                if (matched) {
                    durationSecondsInput.value = String(matched.duration_seconds);
                    hargaInput.value = String(currentBasePrice || 0);
                    totalInput.value = String(matched.price);
                    renderPrepaidPreview(matched.human || matched.formatted, matched.duration_seconds, matched.price);
                }
            }

            function normalizeDurationsPayload(data) {
                if (data && Array.isArray(data.durations) && data.durations.length > 0) {
                    return data.durations.map(function (d) {
                        return {
                            duration_seconds: Number(d.duration_seconds),
                            formatted: d.formatted || formatHMS(d.duration_seconds),
                            human: d.human || d.formatted || (d.duration_seconds + ' detik'),
                            price: Number(d.price || 0)
                        };
                    });
                }
                if (data && data.prices && typeof data.prices === 'object') {
                    return Object.keys(data.prices)
                        .map(function (h) {
                            var hours = Number(h);
                            var sec = hours * 3600;
                            return {
                                duration_seconds: sec,
                                formatted: formatHMS(sec),
                                human: hours + ' jam',
                                price: Number(data.prices[h] || 0)
                            };
                        })
                        .sort(function (a, b) {
                            return a.duration_seconds - b.duration_seconds;
                        });
                }
                return [];
            }

            function loadHourlyPrices(deviceId, psId) {
                if (!durationSelect || !durationPresetsEl) {
                    return;
                }
                if (hourlyAbortController) {
                    hourlyAbortController.abort();
                }
                hourlyAbortController = new AbortController();

                durationSelect.replaceChildren(createEl('option', { value: '' }, 'Memuat durasi...'));
                durationPresetsEl.replaceChildren();
                durationSecondsInput.value = '';
                totalInput.value = '';
                if (prepaidPreview) {
                    prepaidPreview.textContent = 'Memuat tarif...';
                }

                var qs = new URLSearchParams({
                    device: String(deviceId),
                    playstation_id: String(psId || '')
                });

                apiFetchJson(bootstrap.routes.apiHourlyPrices + '?' + qs.toString(), {
                    method: 'GET',
                    signal: hourlyAbortController.signal
                })
                    .then(function (res) {
                        if (!res.ok || !res.data) {
                            durationSelect.replaceChildren(createEl('option', { value: 'custom' }, 'Custom (input manual)'));
                            handleDurationChange();
                            return;
                        }

                        currentBasePrice = Number(res.data.base_price || 0);
                        currentDurations = normalizeDurationsPayload(res.data);

                        durationSelect.replaceChildren();
                        durationPresetsEl.replaceChildren();

                        currentDurations.forEach(function (d) {
                            var optText = (d.human || d.formatted) + ' - ' + formatRupiah(d.price);
                            durationSelect.appendChild(createEl('option', {
                                value: String(d.duration_seconds)
                            }, optText));

                            var chip = createEl('button', {
                                type: 'button',
                                className: 'pos-chip',
                                dataset: { durationSeconds: String(d.duration_seconds) },
                                attrs: { style: 'cursor: pointer;' }
                            }, (d.human || d.formatted) + ' (' + formatRupiah(d.price) + ')');

                            chip.addEventListener('click', function () {
                                durationSelect.value = String(d.duration_seconds);
                                handleDurationChange();
                            });

                            durationPresetsEl.appendChild(chip);
                        });

                        durationSelect.appendChild(createEl('option', { value: 'custom' }, 'Custom (input manual)'));
                        durationSelect.value = currentDurations.length > 0
                            ? String(currentDurations[0].duration_seconds)
                            : 'custom';
                        handleDurationChange();
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') {
                            return;
                        }
                        durationSelect.replaceChildren(createEl('option', { value: 'custom' }, 'Custom (input manual)'));
                        handleDurationChange();
                    });
            }

            function renderCustomPackageDetail() {
                if (!customPkgSelect || !customPkgDetail) {
                    return;
                }
                var selectedId = customPkgSelect.value;
                var pkg = null;
                for (var i = 0; i < currentCompatiblePackages.length; i++) {
                    if (String(currentCompatiblePackages[i].id) === String(selectedId)) {
                        pkg = currentCompatiblePackages[i];
                        break;
                    }
                }

                if (!pkg) {
                    hargaInput.value = '';
                    totalInput.value = '';
                    durationSecondsInput.value = '';
                    customPkgDetail.textContent = 'Tidak ada detail paket tersedia.';
                    return;
                }

                hargaInput.value = String(pkg.harga_total);
                totalInput.value = String(pkg.harga_total);
                durationSecondsInput.value = '';

                var psParts = (Array.isArray(pkg.playstations) ? pkg.playstations : []).map(function (p) {
                    return p.nama + ' (' + p.lama_main + ' menit)';
                });
                var fnbParts = (Array.isArray(pkg.fnbs) ? pkg.fnbs : []).map(function (f) {
                    return f.nama + ' x' + f.quantity;
                });

                var lines = [pkg.nama_paket + ' - Total: ' + formatRupiah(pkg.harga_total)];
                if (psParts.length > 0) {
                    lines.push('Main: ' + psParts.join(', '));
                }
                if (fnbParts.length > 0) {
                    lines.push('Bonus FnB: ' + fnbParts.join(', '));
                }
                customPkgDetail.textContent = lines.join(' | ');
            }

            function setPaketMode(mode) {
                var isCustom = (mode === 'custom_package');
                if (isCustom && currentCompatiblePackages.length === 0) {
                    return;
                }

                tipeInput.value = isCustom ? 'custom_package' : 'prepaid';
                if (btnModePrepaid) {
                    btnModePrepaid.setAttribute('aria-pressed', isCustom ? 'false' : 'true');
                }
                if (btnModeCustom) {
                    btnModeCustom.setAttribute('aria-pressed', isCustom ? 'true' : 'false');
                }
                if (prepaidSection) {
                    prepaidSection.hidden = isCustom;
                }
                if (customSection) {
                    customSection.hidden = !isCustom;
                }

                // Disable hidden mode fields so unrelated values aren't submitted
                if (psSelect) {
                    psSelect.disabled = isCustom;
                }
                if (durationSelect) {
                    durationSelect.disabled = isCustom;
                }
                if (customPkgSelect) {
                    customPkgSelect.disabled = !isCustom;
                }

                if (isCustom) {
                    if (customPkgSelect) {
                        customPkgSelect.replaceChildren();
                        currentCompatiblePackages.forEach(function (pkg) {
                            customPkgSelect.appendChild(createEl('option', {
                                value: String(pkg.id)
                            }, pkg.nama_paket + ' - ' + formatRupiah(pkg.harga_total)));
                        });
                        if (currentCompatiblePackages.length > 0) {
                            customPkgSelect.value = String(currentCompatiblePackages[0].id);
                        }
                    }
                    renderCustomPackageDetail();
                } else {
                    handleDurationChange();
                }
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-open-paket');
                if (!btn) {
                    return;
                }
                var device = findDevice(btn.dataset.deviceId);
                if (!device) {
                    showBanner('error', 'Data stasiun tidak ditemukan.');
                    return;
                }

                currentDevice = device;
                if (deviceIdInput) {
                    deviceIdInput.value = String(device.id);
                }
                if (stationLabel) {
                    stationLabel.textContent = formatStationLabel(device);
                }
                if (namaInput) {
                    namaInput.value = '';
                }
                if (teleponInput) {
                    teleponInput.value = '';
                }
                clearCustomCalcTimer();
                if (customAbortController) {
                    customAbortController.abort();
                }
                customCalcSeq++;
                isCustomCalcPending = false;
                if (customHoursInput) {
                    customHoursInput.value = '1';
                }
                if (customMinutesInput) {
                    customMinutesInput.value = '0';
                }
                if (customSecondsInput) {
                    customSecondsInput.value = '0';
                }
                if (customDurationFields) {
                    customDurationFields.hidden = true;
                }
                durationSecondsInput.value = '';
                totalInput.value = '';

                if (psSelect) {
                    psSelect.replaceChildren();
                    var psList = Array.isArray(device.playstations) ? device.playstations : [];
                    psList.forEach(function (ps) {
                        psSelect.appendChild(createEl('option', {
                            value: String(ps.id)
                        }, ps.nama + ' (' + formatRupiah(ps.harga) + '/jam)'));
                    });
                    if (psList.length > 0) {
                        psSelect.value = device.playstation_id ? String(device.playstation_id) : String(psList[0].id);
                    }
                }

                currentCompatiblePackages = getCompatibleCustomPackages(device);
                if (btnModeCustom) {
                    var hasCompat = currentCompatiblePackages.length > 0;
                    btnModeCustom.disabled = !hasCompat;
                    btnModeCustom.textContent = hasCompat ? 'Custom Paket' : 'Custom Paket (Tidak tersedia)';
                }

                setPaketMode('prepaid');
                resetPaketSubmissionState();
                openDialog('paketDialog');
                loadHourlyPrices(device.id, psSelect ? psSelect.value : '');
            });

            if (paketDialog) {
                paketDialog.addEventListener('close', function () {
                    clearCustomCalcTimer();
                    if (customAbortController) {
                        customAbortController.abort();
                    }
                    customCalcSeq++;
                    isCustomCalcPending = false;
                    resetPaketSubmissionState();
                });
            }

            actionButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (isSubmittingPaket) {
                        return;
                    }
                    var btnAction = btn.dataset && btn.dataset.paketAction === 'simpan' ? 'simpan' : 'bayar';
                    lastTriggeredAction = btnAction;
                    if (actionInput) {
                        actionInput.value = btnAction;
                    }
                });
            });

            // Pressing Enter inside an input defaults cleanly to 'bayar' rather than the first DOM submit button
            form.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') {
                    e.preventDefault();
                    if (isSubmittingPaket || !bayarBtn || bayarBtn.disabled) {
                        return;
                    }
                    lastTriggeredAction = 'bayar';
                    if (actionInput) {
                        actionInput.value = 'bayar';
                    }
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(bayarBtn);
                    } else {
                        bayarBtn.click();
                    }
                }
            });

            if (btnModePrepaid) {
                btnModePrepaid.addEventListener('click', function () {
                    setPaketMode('prepaid');
                });
            }
            if (btnModeCustom) {
                btnModeCustom.addEventListener('click', function () {
                    setPaketMode('custom_package');
                });
            }
            if (psSelect) {
                psSelect.addEventListener('change', function () {
                    if (currentDevice) {
                        loadHourlyPrices(currentDevice.id, psSelect.value);
                    }
                });
            }
            if (durationSelect) {
                durationSelect.addEventListener('change', handleDurationChange);
            }
            [customHoursInput, customMinutesInput, customSecondsInput].forEach(function (inputEl) {
                if (inputEl) {
                    inputEl.addEventListener('input', function () {
                        if (durationSelect && durationSelect.value === 'custom') {
                            invalidateCustomCalcState();
                            scheduleCustomDurationCalc(200);
                        }
                    });
                }
            });
            if (customPkgSelect) {
                customPkgSelect.addEventListener('change', renderCustomPackageDetail);
            }

            function submitPaketTransaction(resolvedAction) {
                var action = resolvedAction === 'simpan' ? 'simpan' : 'bayar';
                if (actionInput) {
                    actionInput.value = action;
                }
                var fd = new FormData(form);
                fd.set('action', action);
                var seq = ++paketSubmitSeq;

                apiFetchJson(bootstrap.routes.storeTransaction, {
                    method: 'POST',
                    body: fd
                })
                    .then(function (res) {
                        if (res.ok && res.data && res.data.success) {
                            window.location.href = res.data.redirect_url || (window.location.pathname + '?tab=stations');
                            return;
                        }
                        if (seq === paketSubmitSeq) {
                            showDialogError(errorEl, extractErrorMessage(res.data, 'Gagal memproses transaksi timer.'));
                            setPaketSubmittingState(false);
                        }
                    })
                    .catch(function () {
                        if (seq === paketSubmitSeq) {
                            showDialogError(errorEl, 'Koneksi gagal saat mengirim transaksi.');
                            setPaketSubmittingState(false);
                        }
                    });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (isSubmittingPaket ||
                    actionButtons.length === 0 ||
                    actionButtons.some(function (btn) { return btn.disabled; })) {
                    return;
                }

                var resolvedAction = resolvePaketAction(e.submitter);
                if (actionInput) {
                    actionInput.value = resolvedAction;
                }
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.hidden = true;
                }

                var mode = tipeInput.value;
                if (mode === 'prepaid') {
                    if (durationSelect && durationSelect.value === 'custom') {
                        var customSpec = getCustomTotalSeconds();
                        if (customSpec.total <= 0) {
                            showDialogError(errorEl, 'Durasi bermain harus lebih dari 0 detik.');
                            return;
                        }
                        var needsCalc = isCustomCalcPending ||
                            Boolean(customDebounceTimer) ||
                            Number(durationSecondsInput.value || 0) !== customSpec.total;

                        if (needsCalc) {
                            clearCustomCalcTimer();
                            setPaketSubmittingState(true, resolvedAction);
                            var calcSubmitSeq = paketSubmitSeq;
                            runCustomDurationCalc().then(function (ok) {
                                if (calcSubmitSeq !== paketSubmitSeq || !paketDialog || !paketDialog.open || !isSubmittingPaket) {
                                    if (calcSubmitSeq === paketSubmitSeq) {
                                        setPaketSubmittingState(false);
                                    }
                                    return;
                                }
                                var resolvedSec = Number(durationSecondsInput.value || 0);
                                if (!ok || resolvedSec <= 0) {
                                    showDialogError(errorEl, 'Gagal menghitung tarif durasi custom. Silakan coba lagi.');
                                    setPaketSubmittingState(false);
                                    return;
                                }
                                submitPaketTransaction(resolvedAction);
                            });
                            return;
                        }
                    }

                    var sec = Number(durationSecondsInput.value || 0);
                    if (!sec || sec <= 0) {
                        showDialogError(errorEl, 'Durasi bermain harus lebih dari 0 detik.');
                        return;
                    }
                } else if (mode === 'custom_package') {
                    if (!customPkgSelect || !customPkgSelect.value) {
                        showDialogError(errorEl, 'Pilih Custom Paket terlebih dahulu.');
                        return;
                    }
                }

                setPaketSubmittingState(true, resolvedAction);
                submitPaketTransaction(resolvedAction);
            });
        }

        // ------------------------------------------------------------------
        // Detail Sesi Modal + Cafe Opener Callback
        // ------------------------------------------------------------------
        var openCafeDialogFn = null;
        var openBerhentiDialogFn = null;
        var openSelesaiDialogFn = null;

        function initDetailModal() {
            var headerEl = document.getElementById('detail-session-station-header');
            var fallbackNote = document.getElementById('detail-session-fallback-note');
            var metaEl = document.getElementById('detail-session-meta');
            var fnbListEl = document.getElementById('detail-session-fnb-list');
            var actionsEl = document.getElementById('detail-session-actions');

            function appendMetaRow(key, value) {
                var item = createEl('div', { className: 'pos-detail-item' });
                item.appendChild(createEl('div', { className: 'pos-detail-key' }, key));
                item.appendChild(createEl('div', { className: 'pos-detail-val' }, value));
                metaEl.appendChild(item);
            }

            function renderDetailFnbList(tx) {
                fnbListEl.replaceChildren();
                var items = Array.isArray(tx.fnb_items) ? tx.fnb_items : [];
                if (items.length === 0) {
                    fnbListEl.textContent = 'Tidak ada order FnB.';
                    return;
                }

                var wrap = createEl('div', { className: 'table-responsive' });
                var table = createEl('table', { className: 'table table-sm table-bordered mb-0' });
                var thead = createEl('thead');
                var headRow = createEl('tr');
                ['Item', 'Qty', 'Harga', 'Subtotal', 'Status'].forEach(function (col) {
                    headRow.appendChild(createEl('th', { attrs: { scope: 'col' } }, col));
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = createEl('tbody');
                items.forEach(function (it) {
                    var tr = createEl('tr');
                    var itemCell = createEl('td');
                    itemCell.appendChild(createEl('span', { className: 'font-weight-bold' }, it.nama));
                    if (it.source === 'fnb_order' && it.order_number) {
                        itemCell.appendChild(createEl('span', { className: 'badge badge-light ml-1' }, it.order_number));
                    }

                    var statusLabel = it.payment_status === 'paid' ? 'Lunas' : 'Belum Bayar';
                    tr.appendChild(itemCell);
                    tr.appendChild(createEl('td', null, String(it.qty)));
                    tr.appendChild(createEl('td', null, formatRupiah(it.harga_jual)));
                    tr.appendChild(createEl('td', null, formatRupiah(it.subtotal)));
                    tr.appendChild(createEl('td', null, statusLabel));
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody);
                wrap.appendChild(table);
                fnbListEl.appendChild(wrap);
            }

            function renderDetailActions(device, tx) {
                actionsEl.replaceChildren();
                if (!bootstrap.canOperate) {
                    return;
                }

                if (!tx) {
                    var resetBtn = createEl('button', {
                        type: 'button',
                        className: 'pos-btn pos-btn-warning pos-btn-sm'
                    }, 'Berhenti');
                    resetBtn.addEventListener('click', function () {
                        closeDialog('detailSessionDialog');
                        if (openBerhentiDialogFn) {
                            openBerhentiDialogFn(device.id, device.nama);
                        }
                    });
                    actionsEl.appendChild(resetBtn);
                    return;
                }

                if (tx.payment_status === 'unpaid') {
                    var payUrl = bootstrap.routes.paymentTemplate.replace('__TX_ID__', String(tx.id_transaksi));
                    actionsEl.appendChild(createEl('a', {
                        href: payUrl,
                        className: 'pos-btn pos-btn-success pos-btn-sm'
                    }, 'Bayar'));

                    var editUrl = bootstrap.routes.editTemplate.replace('__TX_ID__', String(tx.id_transaksi));
                    actionsEl.appendChild(createEl('a', {
                        href: editUrl,
                        className: 'pos-btn pos-btn-outline-primary pos-btn-sm'
                    }, 'Edit'));
                } else if (tx.payment_status === 'paid') {
                    var printUrl = bootstrap.routes.printTemplate.replace('__TX_ID__', String(tx.id_transaksi));
                    actionsEl.appendChild(createEl('a', {
                        href: printUrl,
                        target: '_blank',
                        rel: 'noopener',
                        className: 'pos-btn pos-btn-outline-secondary pos-btn-sm'
                    }, 'Struk'));
                }

                var cafeBtn = createEl('button', {
                    type: 'button',
                    className: 'pos-btn pos-btn-accent pos-btn-sm'
                }, 'Cafe');
                cafeBtn.addEventListener('click', function () {
                    closeDialog('detailSessionDialog');
                    if (openCafeDialogFn) {
                        openCafeDialogFn('session', device.id, tx.id_transaksi);
                    }
                });
                actionsEl.appendChild(cafeBtn);

                var berhentiBtn = createEl('button', {
                    type: 'button',
                    className: 'pos-btn pos-btn-warning pos-btn-sm'
                }, 'Berhenti');
                berhentiBtn.addEventListener('click', function () {
                    closeDialog('detailSessionDialog');
                    if (openBerhentiDialogFn) {
                        openBerhentiDialogFn(device.id, device.nama);
                    }
                });
                actionsEl.appendChild(berhentiBtn);

                if (tx.tipe_transaksi === 'postpaid' && tx.status_transaksi === 'berjalan') {
                    var selesaiBtn = createEl('button', {
                        type: 'button',
                        className: 'pos-btn pos-btn-success pos-btn-sm'
                    }, 'Selesai');
                    selesaiBtn.addEventListener('click', function () {
                        closeDialog('detailSessionDialog');
                        if (openSelesaiDialogFn) {
                            var endUrl = bootstrap.routes.transactionEndTemplate.replace('__TX_ID__', String(tx.id_transaksi));
                            openSelesaiDialogFn(tx.id_transaksi, device.nama, endUrl);
                        }
                    });
                    actionsEl.appendChild(selesaiBtn);
                }
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-open-detail');
                if (!btn) {
                    return;
                }
                var deviceId = btn.dataset.deviceId;
                var device = findDevice(deviceId);
                var activeMap = bootstrap.activeTransactions || {};
                var tx = activeMap[String(deviceId)] || null;

                if (headerEl) {
                    var psSummary = tx && tx.playstation_nama
                        ? tx.playstation_nama
                        : (device && Array.isArray(device.playstations)
                            ? device.playstations.map(function (p) { return p.nama; }).join(', ')
                            : '');
                    headerEl.textContent = formatStationLabel(device) + (psSummary ? ' (' + psSummary + ')' : '');
                }

                metaEl.replaceChildren();

                if (!tx) {
                    if (fallbackNote) {
                        fallbackNote.hidden = false;
                    }
                    fnbListEl.textContent = 'Tidak ada order FnB.';
                    renderDetailActions(device || { id: deviceId, nama: 'Stasiun' }, null);
                    openDialog('detailSessionDialog');
                    return;
                }

                if (fallbackNote) {
                    fallbackNote.hidden = true;
                }

                var txTypeLabel = tx.tipe_transaksi === 'prepaid'
                    ? 'Timer'
                    : (tx.tipe_transaksi === 'custom_package' ? 'Custom Paket' : 'Lost Time');
                var isRunningPostpaid = (tx.tipe_transaksi === 'postpaid' && tx.status_transaksi === 'berjalan');
                var fnbSubtotal = Number(tx.fnb_paid_total || 0) + Number(tx.fnb_unpaid_total || 0);
                var rentalDisplay = isRunningPostpaid
                    ? 'Dihitung saat selesai'
                    : formatRupiah(tx.rental_subtotal);
                var totalDisplay = isRunningPostpaid
                    ? formatRupiah(Math.max(Number(tx.total || 0), fnbSubtotal))
                    : formatRupiah(Math.max(Number(tx.total || 0), Number(tx.rental_subtotal || 0) + fnbSubtotal));

                appendMetaRow('ID Transaksi', '#' + tx.id_transaksi);
                appendMetaRow('Kategori', tx.playstation_nama || '-');
                appendMetaRow('Tipe Transaksi', txTypeLabel);
                appendMetaRow('Nama Pelanggan', tx.nama || 'Pelanggan');
                if (tx.no_telepon) {
                    appendMetaRow('No. Telepon', tx.no_telepon);
                }
                appendMetaRow('Waktu Mulai', tx.waktu_mulai || '-');
                appendMetaRow('Waktu Selesai / Estimasi', tx.waktu_Selesai || 'Berjalan');
                appendMetaRow('Durasi', tx.display_duration || '-');
                appendMetaRow('Tarif Per Jam', formatRupiah(tx.harga));
                appendMetaRow('Biaya Sewa', rentalDisplay);
                appendMetaRow('Subtotal FnB', formatRupiah(fnbSubtotal));
                appendMetaRow('Diskon', Number(tx.diskon || 0) > 0 ? (tx.diskon + '%') : '-');
                appendMetaRow('Total Tagihan', totalDisplay);
                appendMetaRow('Status Pembayaran', tx.payment_status === 'paid' ? 'LUNAS' : 'BELUM BAYAR');
                appendMetaRow('Status Transaksi', tx.status_transaksi || '-');

                renderDetailFnbList(tx);
                renderDetailActions(device || { id: deviceId, nama: 'Stasiun' }, tx);
                openDialog('detailSessionDialog');
            });
        }

        // ------------------------------------------------------------------
        // Cafe / F&B Order Modal
        // ------------------------------------------------------------------
        function initCafeModal() {
            var form = document.getElementById('cafe-order-form');
            if (!form) {
                return;
            }

            var txIdInput = document.getElementById('cafe-transaction-id');
            var contextEl = document.getElementById('cafe-order-context');
            var standaloneFields = document.getElementById('cafe-standalone-fields');
            var locationSelect = document.getElementById('cafe-location-id');
            var customerNameInput = document.getElementById('cafe-customer-name');
            var menuSearchInput = document.getElementById('cafe-menu-search');
            var menuListEl = document.getElementById('cafe-menu-list');
            var cartSummaryEl = document.getElementById('cafe-cart-summary');
            var notesInput = document.getElementById('cafe-order-notes');
            var actionModeSelect = document.getElementById('cafe-action-mode');
            var payNowFields = document.getElementById('cafe-pay-now-fields');
            var paymentMethodSelect = document.getElementById('cafe-payment-method');
            var discountInput = document.getElementById('cafe-discount');
            var amountPaidInput = document.getElementById('cafe-amount-paid');
            var errorEl = document.getElementById('cafe-dialog-error');
            var submitBtn = document.getElementById('btn-submit-cafe');

            var cart = new Map();
            var amountPaidTouched = false;

            function findFnb(fnbId) {
                var list = Array.isArray(bootstrap.fnbs) ? bootstrap.fnbs : [];
                for (var i = 0; i < list.length; i++) {
                    if (String(list[i].id) === String(fnbId)) {
                        return list[i];
                    }
                }
                return null;
            }

            function computeCartTotals() {
                var itemCount = 0;
                var subtotal = 0;
                cart.forEach(function (qty, fnbId) {
                    if (qty > 0) {
                        var fnb = findFnb(fnbId);
                        if (fnb) {
                            itemCount += qty;
                            subtotal += qty * Number(fnb.effective_price || fnb.harga_jual || 0);
                        }
                    }
                });
                var discountPct = Math.min(100, Math.max(0, Number(discountInput ? discountInput.value : 0) || 0));
                var netTotal = Math.max(0, subtotal * (1 - discountPct / 100));
                return { itemCount: itemCount, subtotal: subtotal, discountPct: discountPct, netTotal: netTotal };
            }

            function updateCartSummaryAndPayment() {
                var totals = computeCartTotals();
                if (cartSummaryEl) {
                    if (totals.itemCount === 0) {
                        cartSummaryEl.textContent = 'Belum ada item dipilih. Subtotal: Rp 0';
                    } else if (actionModeSelect && actionModeSelect.value === 'pay_now' && totals.discountPct > 0) {
                        cartSummaryEl.textContent =
                            totals.itemCount + ' item dipilih | Subtotal: ' + formatRupiah(totals.subtotal) +
                            ' | Total Net: ' + formatRupiah(totals.netTotal);
                    } else {
                        cartSummaryEl.textContent =
                            totals.itemCount + ' item dipilih | Subtotal: ' + formatRupiah(totals.subtotal);
                    }
                }
                if (actionModeSelect && actionModeSelect.value === 'pay_now' && amountPaidInput && !amountPaidTouched) {
                    amountPaidInput.value = String(Math.round(totals.netTotal));
                }
            }

            function togglePayNowFields() {
                if (!actionModeSelect || !payNowFields) {
                    return;
                }
                var isPayNow = actionModeSelect.value === 'pay_now';
                payNowFields.hidden = !isPayNow;
                updateCartSummaryAndPayment();
            }

            function setCartQty(fnb, newQty) {
                var clamped = Math.max(0, parseInt(newQty, 10) || 0);
                if (fnb.stok !== -1) {
                    clamped = Math.min(clamped, Math.max(0, Number(fnb.stok)));
                }
                if (clamped <= 0) {
                    cart.delete(fnb.id);
                } else {
                    cart.set(fnb.id, clamped);
                }
                return clamped;
            }

            function renderCafeMenuList() {
                if (!menuListEl) {
                    return;
                }
                var query = (menuSearchInput ? menuSearchInput.value : '').trim().toLowerCase();
                var allFnbs = Array.isArray(bootstrap.fnbs) ? bootstrap.fnbs : [];
                var filtered = allFnbs.filter(function (fnb) {
                    return !query || String(fnb.nama).toLowerCase().indexOf(query) !== -1;
                });

                menuListEl.replaceChildren();
                if (filtered.length === 0) {
                    menuListEl.appendChild(createEl('p', { className: 'text-muted small mb-0 p-2' }, 'Menu cafe tidak ditemukan.'));
                    return;
                }

                filtered.forEach(function (fnb) {
                    var currentQty = cart.get(fnb.id) || 0;
                    var isOutOfStock = (fnb.stok !== -1 && Number(fnb.stok) <= 0);
                    var priceVal = Number(fnb.effective_price || fnb.harga_jual || 0);
                    var stockLabel = fnb.stok === -1 ? 'Unlimited' : ('Stok: ' + fnb.stok);

                    var row = createEl('div', { className: 'pos-cafe-item-row' });

                    var info = createEl('div', { className: 'd-flex flex-column' });
                    info.appendChild(createEl('strong', { className: 'small' }, fnb.nama));
                    info.appendChild(createEl('span', { className: 'small text-muted' }, formatRupiah(priceVal) + ' \u2022 ' + stockLabel));

                    var controls = createEl('div', { className: 'd-flex align-items-center', attrs: { style: 'gap: 0.35rem;' } });
                    var minusBtn = createEl('button', {
                        type: 'button',
                        className: 'pos-btn pos-btn-outline-secondary pos-btn-sm',
                        attrs: { 'aria-label': 'Kurangi ' + fnb.nama }
                    }, '-');
                    var qtyInput = createEl('input', {
                        type: 'number',
                        className: 'form-control form-control-sm text-center',
                        value: String(currentQty),
                        attrs: {
                            min: '0',
                            style: 'width: 56px;',
                            'aria-label': 'Jumlah ' + fnb.nama
                        }
                    });
                    if (fnb.stok !== -1) {
                        qtyInput.max = String(Math.max(0, fnb.stok));
                    }
                    var plusBtn = createEl('button', {
                        type: 'button',
                        className: 'pos-btn pos-btn-outline-primary pos-btn-sm',
                        attrs: { 'aria-label': 'Tambah ' + fnb.nama }
                    }, '+');

                    function syncRowButtons(qty) {
                        qtyInput.value = String(qty);
                        minusBtn.disabled = qty <= 0;
                        plusBtn.disabled = isOutOfStock || (fnb.stok !== -1 && qty >= Number(fnb.stok));
                        qtyInput.disabled = isOutOfStock;
                    }

                    minusBtn.addEventListener('click', function () {
                        var next = setCartQty(fnb, (cart.get(fnb.id) || 0) - 1);
                        syncRowButtons(next);
                        updateCartSummaryAndPayment();
                    });

                    plusBtn.addEventListener('click', function () {
                        var next = setCartQty(fnb, (cart.get(fnb.id) || 0) + 1);
                        syncRowButtons(next);
                        updateCartSummaryAndPayment();
                    });

                    qtyInput.addEventListener('input', function () {
                        var rawVal = qtyInput.value;
                        if (rawVal === '') {
                            cart.delete(fnb.id);
                            minusBtn.disabled = true;
                            plusBtn.disabled = isOutOfStock;
                            updateCartSummaryAndPayment();
                            return;
                        }
                        var next = setCartQty(fnb, rawVal);
                        if (String(next) !== rawVal) {
                            qtyInput.value = String(next);
                        }
                        minusBtn.disabled = next <= 0;
                        plusBtn.disabled = isOutOfStock || (fnb.stok !== -1 && next >= Number(fnb.stok));
                        updateCartSummaryAndPayment();
                    });

                    function normalizeQtyInput() {
                        var next = setCartQty(fnb, qtyInput.value);
                        syncRowButtons(next);
                        updateCartSummaryAndPayment();
                    }

                    qtyInput.addEventListener('change', normalizeQtyInput);
                    qtyInput.addEventListener('blur', normalizeQtyInput);

                    syncRowButtons(currentQty);
                    controls.appendChild(minusBtn);
                    controls.appendChild(qtyInput);
                    controls.appendChild(plusBtn);

                    row.appendChild(info);
                    row.appendChild(controls);
                    menuListEl.appendChild(row);
                });
            }

            function populateLocationOptions(preselectLocationId) {
                if (!locationSelect) {
                    return;
                }
                locationSelect.replaceChildren();
                locationSelect.appendChild(createEl('option', { value: '' }, 'Tanpa Lokasi Khusus'));
                var locs = Array.isArray(bootstrap.locations) ? bootstrap.locations : [];
                locs.forEach(function (loc) {
                    locationSelect.appendChild(createEl('option', {
                        value: String(loc.id)
                    }, loc.name + (loc.code ? ' (' + loc.code + ')' : '')));
                });
                locationSelect.value = preselectLocationId ? String(preselectLocationId) : '';
            }

            function openCafeModal(mode, deviceId, txId) {
                var resolvedMode = mode === 'session' ? 'session' : 'standalone';
                var device = deviceId ? findDevice(deviceId) : null;

                cart.clear();
                amountPaidTouched = false;
                if (menuSearchInput) {
                    menuSearchInput.value = '';
                }
                if (notesInput) {
                    notesInput.value = '';
                }
                if (customerNameInput) {
                    customerNameInput.value = '';
                }
                if (discountInput) {
                    discountInput.value = '0';
                }
                if (amountPaidInput) {
                    amountPaidInput.value = '0';
                }

                if (resolvedMode === 'session' && txId) {
                    txIdInput.value = String(txId);
                    if (standaloneFields) {
                        standaloneFields.hidden = true;
                    }
                    if (contextEl) {
                        contextEl.textContent = 'Order Cafe Sesi - ' + formatStationLabel(device) + ' (Transaksi #' + txId + ')';
                    }
                    if (actionModeSelect) {
                        actionModeSelect.value = 'save';
                    }
                } else {
                    txIdInput.value = '';
                    if (standaloneFields) {
                        standaloneFields.hidden = false;
                    }
                    populateLocationOptions(device && device.default_location_id ? device.default_location_id : '');
                    if (contextEl) {
                        contextEl.textContent = device
                            ? ('Order Cafe Mandiri - ' + formatStationLabel(device))
                            : 'Order Cafe Mandiri';
                    }
                    if (actionModeSelect) {
                        actionModeSelect.value = 'pay_now';
                    }
                }

                renderCafeMenuList();
                togglePayNowFields();
                openDialog('cafeOrderDialog');
            }

            openCafeDialogFn = openCafeModal;

            var standaloneBtn = document.getElementById('btn-standalone-cafe');
            if (standaloneBtn) {
                standaloneBtn.addEventListener('click', function () {
                    openCafeModal('standalone', null, null);
                });
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-open-cafe');
                if (!btn) {
                    return;
                }
                openCafeModal(btn.dataset.mode || 'standalone', btn.dataset.deviceId, btn.dataset.txId);
            });

            if (menuSearchInput) {
                menuSearchInput.addEventListener('input', renderCafeMenuList);
            }
            if (actionModeSelect) {
                actionModeSelect.addEventListener('change', togglePayNowFields);
            }
            if (discountInput) {
                discountInput.addEventListener('input', updateCartSummaryAndPayment);
            }
            if (amountPaidInput) {
                amountPaidInput.addEventListener('input', function () {
                    amountPaidTouched = true;
                });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!submitBtn || submitBtn.disabled) {
                    return;
                }

                var items = [];
                cart.forEach(function (qty, fnbId) {
                    if (qty > 0) {
                        items.push({ fnb_id: Number(fnbId), qty: Number(qty) });
                    }
                });

                if (items.length === 0) {
                    showDialogError(errorEl, 'Pilih minimal 1 item menu cafe.');
                    return;
                }

                var action = actionModeSelect ? actionModeSelect.value : 'save';
                var txIdVal = txIdInput ? txIdInput.value.trim() : '';
                var payload = {
                    transaction_id: txIdVal ? Number(txIdVal) : null,
                    location_id: (!standaloneFields || !standaloneFields.hidden) && locationSelect && locationSelect.value
                        ? Number(locationSelect.value)
                        : null,
                    customer_name: (!standaloneFields || !standaloneFields.hidden) && customerNameInput
                        ? (customerNameInput.value.trim() || null)
                        : null,
                    notes: notesInput ? (notesInput.value.trim() || null) : null,
                    action: action,
                    payment_method: action === 'pay_now' && paymentMethodSelect ? paymentMethodSelect.value : null,
                    discount: action === 'pay_now' && discountInput ? Number(discountInput.value || 0) : 0,
                    amount_paid: action === 'pay_now' && amountPaidInput ? Number(amountPaidInput.value || 0) : null,
                    items: items
                };

                setButtonLoading(submitBtn, true, 'Memproses...', false);

                apiFetchJson(bootstrap.routes.fnbStore, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                    .then(function (res) {
                        if (res.ok && res.data && res.data.success) {
                            if (action === 'pay_now' && res.data.redirect_url) {
                                window.location.href = res.data.redirect_url;
                                return;
                            }
                            closeDialog('cafeOrderDialog');
                            showBanner('success', 'Order cafe berhasil disimpan.');
                            window.location.reload();
                            return;
                        }
                        showDialogError(errorEl, extractErrorMessage(res.data, 'Gagal menyimpan order cafe.'));
                        setButtonLoading(submitBtn, false);
                    })
                    .catch(function () {
                        showDialogError(errorEl, 'Koneksi gagal saat mengirim order cafe.');
                        setButtonLoading(submitBtn, false);
                    });
            });
        }

        // ------------------------------------------------------------------
        // Berhenti & Selesai Confirmation Modals
        // ------------------------------------------------------------------
        function initConfirmDialogs() {
            var berhentiDeviceName = document.getElementById('berhenti-device-name');
            var berhentiError = document.getElementById('berhenti-dialog-error');
            var confirmBerhentiBtn = document.getElementById('btn-confirm-berhenti');
            var pendingBerhentiDeviceId = null;

            var selesaiDeviceName = document.getElementById('selesai-device-name');
            var selesaiTxId = document.getElementById('selesai-tx-id');
            var confirmSelesaiBtn = document.getElementById('btn-confirm-selesai');
            var pendingSelesaiUrl = null;

            openBerhentiDialogFn = function (deviceId, deviceName) {
                pendingBerhentiDeviceId = deviceId;
                if (berhentiDeviceName) {
                    berhentiDeviceName.textContent = deviceName || ('Device #' + deviceId);
                }
                openDialog('berhentiConfirmDialog');
            };

            openSelesaiDialogFn = function (txId, deviceName, actionUrl) {
                pendingSelesaiUrl = actionUrl;
                if (selesaiDeviceName) {
                    selesaiDeviceName.textContent = deviceName || '-';
                }
                if (selesaiTxId) {
                    selesaiTxId.textContent = txId ? String(txId) : '-';
                }
                openDialog('selesaiConfirmDialog');
            };

            document.addEventListener('click', function (e) {
                var berhentiTrigger = e.target.closest('.js-open-berhenti');
                if (berhentiTrigger) {
                    openBerhentiDialogFn(berhentiTrigger.dataset.deviceId, berhentiTrigger.dataset.deviceName);
                    return;
                }

                var selesaiTrigger = e.target.closest('.js-open-selesai');
                if (selesaiTrigger) {
                    openSelesaiDialogFn(
                        selesaiTrigger.dataset.txId,
                        selesaiTrigger.dataset.deviceName,
                        selesaiTrigger.dataset.action
                    );
                }
            });

            if (confirmBerhentiBtn) {
                confirmBerhentiBtn.addEventListener('click', function () {
                    if (confirmBerhentiBtn.disabled || !pendingBerhentiDeviceId) {
                        return;
                    }
                    setButtonLoading(confirmBerhentiBtn, true, 'Menghentikan...', false);
                    var stopUrl = bootstrap.routes.deviceStopTemplate.replace(
                        '__DEVICE_ID__',
                        String(pendingBerhentiDeviceId)
                    );

                    apiFetchJson(stopUrl, { method: 'POST' })
                        .then(function (res) {
                            if (res.ok && res.data && res.data.success) {
                                closeDialog('berhentiConfirmDialog');
                                window.location.reload();
                                return;
                            }
                            showDialogError(berhentiError, extractErrorMessage(res.data, 'Gagal menghentikan stasiun.'));
                            setButtonLoading(confirmBerhentiBtn, false);
                        })
                        .catch(function () {
                            showDialogError(berhentiError, 'Koneksi gagal saat menghentikan stasiun.');
                            setButtonLoading(confirmBerhentiBtn, false);
                        });
                });
            }

            if (confirmSelesaiBtn) {
                confirmSelesaiBtn.addEventListener('click', function () {
                    if (confirmSelesaiBtn.disabled || !pendingSelesaiUrl) {
                        return;
                    }
                    setButtonLoading(confirmSelesaiBtn, true, 'Menyelesaikan...', false);

                    var postForm = createEl('form', {
                        attrs: {
                            method: 'POST',
                            action: pendingSelesaiUrl,
                            style: 'display:none;'
                        }
                    });
                    postForm.appendChild(createEl('input', {
                        type: 'hidden',
                        name: '_token',
                        value: csrfToken
                    }));
                    document.body.appendChild(postForm);
                    postForm.submit();
                });
            }
        }

        initDialogs();
        initTabs();
        initTimersAndPolling();
        initLostTime();
        initPaketModal();
        initDetailModal();
        initCafeModal();
        initConfirmDialogs();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboard);
    } else {
        initDashboard();
    }
})();

