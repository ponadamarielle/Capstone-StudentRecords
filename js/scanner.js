document.addEventListener('DOMContentLoaded', function () {
    const SCANNER_URL = 'scanner.php';
    const scanBtn     = document.querySelector('.scan-document-btn');
    const uploadInput = document.getElementById('uploadFileInput');
    if (!scanBtn || !uploadInput || !window.bootstrap) return;

    scanBtn.disabled = false;
    scanBtn.removeAttribute('title');

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    const modalEl = el('div', 'modal fade scan-modal');
    modalEl.id = 'scanDocumentModal';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.setAttribute('data-bs-focus', 'false');
    modalEl.setAttribute('data-bs-backdrop', 'static');

    const dialog  = el('div', 'modal-dialog modal-dialog-centered modal-lg');
    const content = el('div', 'modal-content');
    const header  = el('div', 'modal-header');
    header.appendChild(el('h5', 'modal-title scan-title', 'Scan Document'));
    const closeX = el('button', 'btn-close');
    closeX.type = 'button';
    closeX.setAttribute('aria-label', 'Close');
    header.appendChild(closeX);

    const body = el('div', 'modal-body');
    const instructions = el('div', 'scan-instructions');
    instructions.appendChild(el('i', 'bi bi-printer'));
    const steps = el('ol', 'scan-steps');
    const STEPS = {
        naps2: [
            'Insert the document face down into the Plustek scanner.',
            'Wait until the scanner pulls the paper in slightly and holds it.',
            'Click START SCAN here.',
        ],
        folder: [
            'Insert the document face down into the Plustek scanner.',
            'The scanner scans it automatically.',
            'The scanned document will appear here in a few seconds.',
            'To add more pages, just insert the next page. It is added automatically.',
        ],
    };
    function showSteps(mode) {
        steps.innerHTML = '';
        (STEPS[mode] || STEPS.folder).forEach((text) => steps.appendChild(el('li', '', text)));
    }
    showSteps('folder');
    instructions.appendChild(steps);
    const deviceBox = el('div', 'scan-device scan-device-checking');
    const deviceIcon = el('i', 'bi bi-hourglass-split');
    const deviceText = el('span', 'scan-device-text', 'Checking scanner connection...');
    const deviceRetry = el('button', 'btn btn-link scan-device-retry', 'Check again');
    deviceRetry.type = 'button';
    deviceRetry.hidden = true;
    deviceBox.appendChild(deviceIcon);
    deviceBox.appendChild(deviceText);
    deviceBox.appendChild(deviceRetry);
    const statusBox = el('div', 'scan-status');
    statusBox.hidden = true;
    const spinner = el('span', 'spinner-border spinner-border-sm');
    spinner.setAttribute('role', 'status');
    const statusText = el('span', 'scan-status-text');
    statusBox.appendChild(spinner);
    statusBox.appendChild(statusText);
    const errorBox = el('div', 'scan-error');
    errorBox.hidden = true;
    const errorDetails = el('div', 'scan-error-details');
    errorDetails.hidden = true;
    const previewWrap = el('div', 'scan-preview');
    previewWrap.hidden = true;
    const previewInfo = el('div', 'scan-preview-info');
    const previewFrame = el('iframe', 'scan-preview-frame');
    previewFrame.title = 'Scanned document preview';
    previewWrap.appendChild(previewInfo);
    previewWrap.appendChild(previewFrame);
    const readyInfo = el('div', 'scan-ready');
    readyInfo.hidden = true;
    body.appendChild(deviceBox);
    body.appendChild(readyInfo);
    body.appendChild(instructions);
    body.appendChild(statusBox);
    body.appendChild(errorBox);
    body.appendChild(errorDetails);
    body.appendChild(previewWrap);

    const footer = el('div', 'modal-footer scan-footer');
    const cancelBtn = el('button', 'btn btn-cancel', 'CANCEL');
    const rescanBtn = el('button', 'btn btn-outline-secondary scan-secondary-btn', 'RESCAN');
    const addPageBtn = el('button', 'btn btn-outline-secondary scan-secondary-btn', 'ADD PAGE');
    const startBtn = el('button', 'btn btn-save', 'START SCAN');
    const useBtn = el('button', 'btn btn-save', 'USE THIS SCAN');
    const nextBtn = el('button', 'btn btn-outline-secondary scan-secondary-btn', 'NEXT DOCUMENT');
    [cancelBtn, rescanBtn, addPageBtn, startBtn, useBtn, nextBtn].forEach((b) => { b.type = 'button'; });
    footer.appendChild(cancelBtn);
    footer.appendChild(rescanBtn);
    footer.appendChild(addPageBtn);
    footer.appendChild(startBtn);
    footer.appendChild(nextBtn);
    footer.appendChild(useBtn);

    content.appendChild(header);
    content.appendChild(body);
    content.appendChild(footer);
    dialog.appendChild(content);
    modalEl.appendChild(dialog);
    document.body.appendChild(modalEl);

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl, { focus: false, backdrop: 'static' });
    let currentToken = null;
    let busy = false;
    let scannerReady = false;
    let scanMode = 'folder';
    let waiting = false;
    let waitSeq = 0;
    const WAIT_LIMIT_MS = 5 * 60 * 1000;

    let pollPromise = Promise.resolve(null);
    let readyFiles = [];

    function updateReady() {
        const count = readyFiles.length + (currentToken ? 1 : 0);
        useBtn.textContent = count > 1 ? 'USE ' + count + ' SCANS' : 'USE THIS SCAN';
        readyInfo.hidden = readyFiles.length === 0;
        readyInfo.textContent = readyFiles.length
            ? readyFiles.length + ' document' + (readyFiles.length === 1 ? '' : 's') + ' ready. Each document is saved as its own file.'
            : '';
    }

    function applyScannerReady() {
        const folder = scanMode === 'folder';
        startBtn.disabled = busy || waiting || !scannerReady;
        addPageBtn.disabled = busy || waiting || !scannerReady;
        rescanBtn.disabled = busy || !scannerReady || (!folder && waiting);
        [startBtn, rescanBtn, addPageBtn].forEach((b) => {
            b.title = scannerReady ? '' : 'Connect the scanner first';
        });
        useBtn.disabled = busy || (!folder && waiting);
        nextBtn.disabled = busy || !scannerReady;
        if (folder) addPageBtn.hidden = true;
    }

    function stopWaiting() {
        waitSeq++;
        waiting = false;
    }

    function showWaiting(message) {
        statusBox.hidden = false;
        statusText.textContent = message;
        errorBox.hidden = true;
        errorDetails.hidden = true;
        startBtn.hidden = true;
        instructions.hidden = !!currentToken;
        applyScannerReady();
    }

    function waitingMessage() {
        if (currentToken) {
            return 'To add another page to this document, insert it into the scanner now. For a different document, click NEXT DOCUMENT. Otherwise, click ' + useBtn.textContent + '.';
        }
        return readyFiles.length
            ? 'Waiting for the next document... insert it into the scanner now.'
            : 'Waiting for the scanner... insert the document now.';
    }

    async function waitForScan(append) {
        if (busy || waiting || !scannerReady) return;
        if (!append) resetScan();
        setView(currentToken ? 'preview' : 'idle');
        waiting = true;
        const seq = ++waitSeq;
        showWaiting(waitingMessage());
        const start = new FormData();
        start.append('action', 'watch_start');
        try {
            await fetch(SCANNER_URL, { method: 'POST', body: start });
        } catch (err) {}
        const startedAt = Date.now();
        while (seq === waitSeq) {
            await new Promise((r) => setTimeout(r, 1500));
            if (seq !== waitSeq) return;
            if (!currentToken && Date.now() - startedAt > WAIT_LIMIT_MS) {
                stopWaiting();
                setView('error', 'No scanned document was received. Insert the document and click START SCAN to try again.');
                return;
            }
            const poll = new FormData();
            poll.append('action', 'watch_poll');
            if (currentToken) poll.append('append', currentToken);
            pollPromise = fetch(SCANNER_URL, { method: 'POST', body: poll })
                .then(readJson)
                .catch(() => null);
            const result = await pollPromise;
            if (!result) continue;
            if (result.success && result.waiting) {
                if (seq === waitSeq) showWaiting(result.receiving ? 'Receiving the scanned page...' : waitingMessage());
                continue;
            }
            if (!result.success) {
                if (seq !== waitSeq) return;
                stopWaiting();
                if (!currentToken) {
                    setView('error', result.message || 'Scanning failed.');
                    return;
                }
                await waitForScan(true);
                errorBox.hidden = false;
                errorBox.textContent = result.message || 'The new page could not be added.';
                return;
            }
            if (!modalEl.classList.contains('show')) {
                discard(result.token);
                return;
            }
            showResult(result);
            closeScannerFolder();
            if (seq !== waitSeq) return;
            stopWaiting();
            waitForScan(true);
            return;
        }
    }

    function closeScannerFolder() {
        [0, 2000, 5000].forEach((delay) => setTimeout(() => {
            const data = new FormData();
            data.append('action', 'close_folder');
            fetch(SCANNER_URL, { method: 'POST', body: data }).catch(() => {});
        }, delay));
    }

    function showResult(result) {
        currentToken = result.token;
        previewFrame.src = result.url + '&t=' + Date.now() + '#page=1&view=Fit&pagemode=none&toolbar=1';
        previewInfo.textContent = result.pages + ' page' + (result.pages === 1 ? '' : 's')
            + ' scanned. Check that the scan is clear and complete before using it.';
        setView('preview');
    }

    function startScan(append) {
        if (scanMode === 'folder') {
            waitForScan(append);
        } else {
            runScan(append);
        }
    }

    function setView(state, message) {
        busy = state === 'scanning' || state === 'loading';
        instructions.hidden = state === 'preview' && !waiting;
        statusBox.hidden = !busy;
        statusText.textContent = busy ? message : '';
        errorBox.hidden = state !== 'error';
        errorBox.textContent = state === 'error' ? message : '';
        if (state !== 'error') {
            errorDetails.hidden = true;
            errorDetails.textContent = '';
        }
        previewWrap.hidden = state !== 'preview' && !(busy && currentToken);
        startBtn.hidden = !!currentToken;
        rescanBtn.hidden = !currentToken;
        addPageBtn.hidden = !currentToken;
        useBtn.hidden = !currentToken && !readyFiles.length;
        nextBtn.hidden = !currentToken;
        updateReady();
        [startBtn, rescanBtn, addPageBtn, useBtn, nextBtn, cancelBtn, closeX].forEach((b) => { b.disabled = busy; });
        startBtn.hidden = !!currentToken || (scanMode === 'folder' && waiting);
        applyScannerReady();
    }

    function discard(token) {
        if (!token) return;
        const data = new FormData();
        data.append('action', 'discard');
        data.append('token', token);
        fetch(SCANNER_URL, { method: 'POST', body: data }).catch(() => {});
    }

    function resetScan() {
        discard(currentToken);
        currentToken = null;
        previewFrame.removeAttribute('src');
        previewInfo.textContent = '';
    }

    async function readJson(res) {
        const raw = await res.text();
        try {
            return JSON.parse(raw);
        } catch (e) {
            return { success: false, message: res.ok ? 'Unexpected response from the scanner service.' : 'Scanner service error (HTTP ' + res.status + ').' };
        }
    }

    async function runScan(append) {
        if (busy) return;
        const data = new FormData();
        data.append('action', 'scan');
        if (append && currentToken) data.append('append', currentToken);
        if (!append) resetScan();
        setView('scanning', append ? 'Scanning the next page... please wait.' : 'Scanning... please wait.');
        try {
            const res = await fetch(SCANNER_URL, { method: 'POST', body: data });
            const result = await readJson(res);
            if (!result.success) {
                setView(currentToken ? 'preview' : 'error', result.message || 'Scanning failed.');
                checkDevice();
                if (result.details) {
                    errorDetails.hidden = false;
                    errorDetails.textContent = 'Scanner message: ' + result.details;
                }
                if (currentToken) {
                    errorBox.hidden = false;
                    errorBox.textContent = result.message || 'Scanning failed.';
                }
                return;
            }
            showResult(result);
        } catch (err) {
            setView(currentToken ? 'preview' : 'error', 'Could not reach the scanner service. Please try again.');
            if (currentToken) {
                errorBox.hidden = false;
                errorBox.textContent = 'Could not reach the scanner service. Please try again.';
            }
        }
    }

    function scanFileName(number) {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return 'scanned_' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + '_' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds())
            + (number > 1 ? '-' + number : '') + '.pdf';
    }

    async function currentScanFile() {
        const res = await fetch(SCANNER_URL + '?action=preview&token=' + encodeURIComponent(currentToken), { cache: 'no-store' });
        if (!res.ok) throw new Error('missing');
        const blob = await res.blob();
        return new File([blob], scanFileName(readyFiles.length + 1), { type: 'application/pdf' });
    }

    async function nextDocument() {
        if (busy) return;
        stopWaiting();
        await pollPromise;
        if (!currentToken) return;
        setView('loading', 'Keeping this document...');
        try {
            readyFiles.push(await currentScanFile());
            resetScan();
            busy = false;
            setView('idle');
            waitForScan(false);
        } catch (err) {
            setView('preview');
            errorBox.hidden = false;
            errorBox.textContent = 'The scanned document could not be loaded. Please rescan.';
        }
    }

    async function useScan() {
        if (busy) return;
        stopWaiting();
        await pollPromise;
        if (!currentToken && !readyFiles.length) return;
        setView('loading', 'Preparing the scanned document...');
        try {
            const files = readyFiles.slice();
            if (currentToken) files.push(await currentScanFile());
            const first = files.shift();
            const transfer = new DataTransfer();
            transfer.items.add(first);
            uploadInput.files = transfer.files;
            uploadInput.dataset.scannedFile = first.name;
            uploadInput.scanQueue = files;
            uploadInput.scanTotal = files.length + 1;
            uploadInput.scanIndex = 1;
            readyFiles = [];
            resetScan();
            busy = false;
            modal.hide();
            uploadInput.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (err) {
            setView('preview');
            errorBox.hidden = false;
            errorBox.textContent = 'The scanned document could not be loaded. Please rescan.';
        }
    }

    let deviceCheck = 0;
    async function checkDevice() {
        const seq = ++deviceCheck;
        scannerReady = false;
        applyScannerReady();
        deviceBox.className = 'scan-device scan-device-checking';
        deviceIcon.className = 'bi bi-hourglass-split';
        deviceText.textContent = 'Checking scanner connection...';
        deviceRetry.hidden = true;
        let result;
        try {
            const res = await fetch(SCANNER_URL + '?action=devices', { cache: 'no-store' });
            result = await readJson(res);
        } catch (err) {
            result = { success: false, message: 'Could not reach the scanner service.' };
        }
        if (seq !== deviceCheck) return;
        deviceRetry.hidden = false;
        scannerReady = !!result.connected;
        if (result.mode) {
            scanMode = result.mode;
            showSteps(scanMode);
        }
        if (!scannerReady) stopWaiting();
        applyScannerReady();
        if (scannerReady && scanMode === 'folder' && !currentToken && !waiting && modalEl.classList.contains('show')) {
            waitForScan(false);
        }
        if (result.connected) {
            deviceBox.className = 'scan-device scan-device-ok';
            deviceIcon.className = 'bi bi-check-circle-fill';
            deviceText.textContent = 'Scanner connected: ' + result.device
                + (result.warning ? ' — ' + result.warning : '');
        } else if (result.success && result.mode === 'folder' && result.message) {
            deviceBox.className = 'scan-device scan-device-bad';
            deviceIcon.className = 'bi bi-folder-x';
            deviceText.textContent = result.message;
        } else if (result.success && result.driver && result.plugged === false) {
            deviceBox.className = 'scan-device scan-device-bad';
            deviceIcon.className = 'bi bi-plug-fill';
            deviceText.textContent = 'Scanner not plugged in. Connect the Plustek scanner with its USB cable, then click Check again.';
        } else if (result.success) {
            deviceBox.className = 'scan-device scan-device-bad';
            deviceIcon.className = 'bi bi-x-circle-fill';
            deviceText.textContent = result.devices && result.devices.length
                ? 'Plustek scanner not found. Detected: ' + result.devices.join(', ')
                : 'No scanner detected. Check that the Plustek scanner is plugged in and turned on.';
        } else {
            deviceBox.className = 'scan-device scan-device-bad';
            deviceIcon.className = 'bi bi-x-circle-fill';
            deviceText.textContent = result.message || 'Scanner not available.';
        }
    }
    deviceRetry.addEventListener('click', checkDevice);

    scanBtn.addEventListener('click', function () {
        stopWaiting();
        readyFiles = [];
        resetScan();
        setView('idle');
        modal.show();
        checkDevice();
    });
    startBtn.addEventListener('click', () => startScan(false));
    rescanBtn.addEventListener('click', async () => {
        if (busy) return;
        stopWaiting();
        await pollPromise;
        startScan(false);
    });
    addPageBtn.addEventListener('click', () => startScan(true));
    useBtn.addEventListener('click', useScan);
    nextBtn.addEventListener('click', nextDocument);
    [cancelBtn, closeX].forEach((b) => b.addEventListener('click', function () {
        if (busy) return;
        stopWaiting();
        readyFiles = [];
        resetScan();
        modal.hide();
    }));
    modalEl.addEventListener('hide.bs.modal', stopWaiting);

    modalEl.addEventListener('shown.bs.modal', function () {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) backdrops[backdrops.length - 1].classList.add('scan-modal-backdrop');
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
        if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
    });
    window.addEventListener('pagehide', function () {
        if (currentToken && navigator.sendBeacon) {
            const data = new FormData();
            data.append('action', 'discard');
            data.append('token', currentToken);
            navigator.sendBeacon(SCANNER_URL, data);
        }
    });
});