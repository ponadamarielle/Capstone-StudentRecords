document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('reportTemplatePage');
    if (!page) return;

    var csrf = page.dataset.csrf;
    var flash = document.getElementById('rtFlash');
    var fileInput = document.getElementById('rtFile');
    var current = document.getElementById('rtCurrent');
    var editor = document.getElementById('rtEditor');
    var stage = document.getElementById('rtStage');
    var draftImg = document.getElementById('rtDraftImg');
    var headerLine = document.getElementById('rtHeaderLine');
    var footerLine = document.getElementById('rtFooterLine');
    var shadeTop = document.getElementById('rtShadeTop');
    var shadeBottom = document.getElementById('rtShadeBottom');
    var saveBtn = document.getElementById('rtSaveBtn');
    var cancelBtn = document.getElementById('rtCancelBtn');
    var headerImg = document.getElementById('rtHeaderImg');
    var footerImg = document.getElementById('rtFooterImg');

    var draft = null;
    var busy = false;

    function showFlash(message, kind) {
        flash.hidden = !message;
        flash.innerHTML = '';
        if (!message) return;
        flash.className = 'rt-flash rt-flash-' + (kind || 'success');
        var icon = document.createElement('i');
        icon.className = 'bi ' + (kind === 'error' ? 'bi-x-circle-fill' : kind === 'busy' ? 'bi-hourglass-split' : 'bi-check-circle-fill');
        var text = document.createElement('span');
        text.textContent = message;
        flash.appendChild(icon);
        flash.appendChild(text);
    }

    async function post(data) {
        data.append('csrf_token', csrf);
        var res = await fetch(window.location.pathname, { method: 'POST', body: data, credentials: 'same-origin' });
        var text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            return { success: false, message: 'The server sent an unexpected reply. Check the PHP error log.' };
        }
    }

    function setBusy(state) {
        busy = state;
        [saveBtn, cancelBtn].forEach(function (b) { if (b) b.disabled = state; });
    }

    function placeLines() {
        if (!draft) return;
        var scale = draftImg.clientHeight / draft.height;
        var top = draft.headerEnd * scale;
        var bottom = draft.footerStart * scale;
        headerLine.style.top = top + 'px';
        footerLine.style.top = bottom + 'px';
        shadeTop.style.height = top + 'px';
        shadeBottom.style.top = bottom + 'px';
        shadeBottom.style.height = Math.max(0, draftImg.clientHeight - bottom) + 'px';
        headerLine.setAttribute('aria-valuenow', String(draft.headerEnd));
        footerLine.setAttribute('aria-valuenow', String(draft.footerStart));
    }

    function setLine(which, value) {
        var minGap = Math.round(draft.height * 0.1);
        if (which === 'header') {
            draft.headerEnd = Math.round(Math.max(20, Math.min(value, draft.footerStart - minGap, draft.height * 0.45)));
        } else {
            draft.footerStart = Math.round(Math.min(draft.height - 20, Math.max(value, draft.headerEnd + minGap, draft.height * 0.55)));
        }
        placeLines();
    }

    function openEditor(data) {
        draft = {
            width: data.width,
            height: data.height,
            headerEnd: data.header_end,
            footerStart: data.footer_start,
            fileName: data.file_name || '',
        };
        draftImg.onload = placeLines;
        draftImg.src = data.draft;
        current.hidden = true;
        editor.hidden = false;
        if (draftImg.complete) placeLines();
    }

    function closeEditor() {
        draft = null;
        editor.hidden = true;
        current.hidden = false;
        draftImg.removeAttribute('src');
    }

    fileInput.addEventListener('change', async function () {
        var file = fileInput.files[0];
        fileInput.value = '';
        if (!file || busy) return;
        setBusy(true);
        showFlash('Reading ' + file.name + '...', 'busy');
        var data = new FormData();
        data.append('action', 'upload');
        data.append('template', file);
        try {
            var result = await post(data);
            if (!result.success) {
                showFlash(result.message, 'error');
                return;
            }
            showFlash('');
            openEditor(result);
        } catch (e) {
            showFlash('The file could not be uploaded. Check your connection and try again.', 'error');
        } finally {
            setBusy(false);
        }
    });

    [headerLine, footerLine].forEach(function (line) {
        line.addEventListener('pointerdown', function (event) {
            if (!draft) return;
            event.preventDefault();
            line.setPointerCapture(event.pointerId);
            line.classList.add('dragging');
            var which = line.dataset.line;
            function move(ev) {
                var rect = draftImg.getBoundingClientRect();
                var y = (ev.clientY - rect.top) * (draft.height / rect.height);
                setLine(which, y);
            }
            function up() {
                line.classList.remove('dragging');
                line.removeEventListener('pointermove', move);
                line.removeEventListener('pointerup', up);
                line.removeEventListener('pointercancel', up);
            }
            line.addEventListener('pointermove', move);
            line.addEventListener('pointerup', up);
            line.addEventListener('pointercancel', up);
        });
        line.addEventListener('keydown', function (event) {
            if (!draft || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
            event.preventDefault();
            var step = (event.shiftKey ? 20 : 2) * (event.key === 'ArrowUp' ? -1 : 1);
            var which = line.dataset.line;
            setLine(which, (which === 'header' ? draft.headerEnd : draft.footerStart) + step);
        });
    });

    window.addEventListener('resize', placeLines);

    saveBtn.addEventListener('click', async function () {
        if (!draft || busy) return;
        setBusy(true);
        showFlash('Saving the template...', 'busy');
        var data = new FormData();
        data.append('action', 'save');
        data.append('header_end', String(draft.headerEnd));
        data.append('footer_start', String(draft.footerStart));
        data.append('width', String(draft.width));
        data.append('height', String(draft.height));
        data.append('file_name', draft.fileName);
        try {
            var result = await post(data);
            if (!result.success) {
                showFlash(result.message, 'error');
                return;
            }
            headerImg.src = result.header;
            footerImg.src = result.footer;
            closeEditor();
            showFlash(result.message, 'success');
        } catch (e) {
            showFlash('The template could not be saved. Try again.', 'error');
        } finally {
            setBusy(false);
        }
    });

    cancelBtn.addEventListener('click', async function () {
        if (busy) return;
        closeEditor();
        showFlash('');
        var data = new FormData();
        data.append('action', 'cancel');
        try { await post(data); } catch (e) { }
    });
});