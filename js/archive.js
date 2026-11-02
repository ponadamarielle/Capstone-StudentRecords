(function () {
    'use strict';

    const isDeleting = (e) => !!(e && e.inputType && e.inputType.indexOf('delete') === 0);

    function formatStudentNumber(value, e) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 10);
        const deleting = isDeleting(e);
        let result = digits.slice(0, 4);
        if (digits.length > 4 || (digits.length === 4 && !deleting)) result += '-';
        if (digits.length > 4) result += digits.slice(4, 9);
        if (digits.length > 9 || (digits.length === 9 && !deleting)) result += '-BN-';
        if (digits.length > 9) result += digits.slice(9, 10);
        return result;
    }

    const SEARCH_NOT_ALLOWED = /[^A-Za-z\u00C0-\u024F0-9\s.,'\-]/g;

    function formatSearch(value, e) {
        const trimmed = value.replace(/^\s+/, '');
        return /^[0-9]/.test(trimmed) ? formatStudentNumber(trimmed, e) : value.replace(SEARCH_NOT_ALLOWED, '');
    }

    const searchInput = document.getElementById('archiveSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const cursorFromEnd = searchInput.value.length - searchInput.selectionStart;
            searchInput.value = formatSearch(searchInput.value, e);
            const pos = Math.max(0, searchInput.value.length - cursorFromEnd);
            searchInput.setSelectionRange(pos, pos);
        });
        if (searchInput.value) {
            searchInput.value = formatSearch(searchInput.value);
        }
    }

    const filterForm = document.getElementById('archiveFilterForm');
    ['archiveCourseFilter', 'archiveYearSectionFilter'].forEach((id) => {
        const select = document.getElementById(id);
        if (select && filterForm) {
            select.addEventListener('change', () => filterForm.submit());
        }
    });

    if (window.bootstrap && bootstrap.Dropdown) {
        document.querySelectorAll('.archive-files-btn').forEach((btn) => {
            bootstrap.Dropdown.getOrCreateInstance(btn, {
                popperConfig: (defaultConfig) => Object.assign({}, defaultConfig, { strategy: 'fixed' }),
            });
        });
    }

    async function readJson(res) {
        const raw = await res.text();
        try {
            return JSON.parse(raw);
        } catch (err) {
            console.error('Non-JSON response from server:', raw);
            throw new Error('The server returned an unexpected response (HTTP ' + res.status + ').');
        }
    }

    const RESTORE_URL       = 'archive.php?action=restore_student';
    const restoreModalEl    = document.getElementById('archiveRestoreModal');
    const restoreModal      = restoreModalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(restoreModalEl) : null;
    const restoreNameEl     = document.getElementById('archiveRestoreName');
    const restoreErrorEl    = document.getElementById('archiveRestoreError');
    const restoreCancelBtn  = document.getElementById('archiveRestoreCancelBtn');
    const restoreConfirmBtn = document.getElementById('archiveRestoreConfirmBtn');

    let restoreBtn      = null;
    let restoreInFlight = false;

    function setRestoreError(message) {
        if (!restoreErrorEl) {
            if (message) alert(message);
            return;
        }
        restoreErrorEl.textContent = message || '';
        restoreErrorEl.classList.toggle('d-none', !message);
    }

    function setRestoreBusy(busy) {
        restoreInFlight = busy;
        if (restoreConfirmBtn) {
            restoreConfirmBtn.disabled = busy;
            restoreConfirmBtn.textContent = busy ? 'Restoring…' : 'Restore';
        }
        if (restoreCancelBtn) restoreCancelBtn.disabled = busy;
    }

    function removeRow(row) {
        if (!row) return;
        const body = row.parentElement;
        row.remove();
        if (body && !body.querySelector('.archive-restore-btn')) {
            const emptyRow = document.createElement('tr');
            emptyRow.innerHTML = '<td colspan="7" class="no-students-message">No archived records found.</td>';
            body.appendChild(emptyRow);
        }
    }

    async function restoreStudent() {
        if (!restoreBtn || restoreInFlight) return;

        const btn = restoreBtn;
        setRestoreError('');
        setRestoreBusy(true);

        const formData = new FormData();
        formData.append('student_id', btn.dataset.id);

        let done = false;
        try {
            const res  = await fetch(RESTORE_URL, { method: 'POST', body: formData });
            const data = await readJson(res);

            if (data.success) {
                done = true;
                removeRow(btn.closest('tr'));
            } else {
                setRestoreError(data.message || 'Could not restore the student.');
            }
        } catch (err) {
            setRestoreError(err.message || 'Could not restore the student.');
        } finally {
            setRestoreBusy(false);
            if (done) {
                restoreBtn = null;
                if (restoreModal) restoreModal.hide();
            }
        }
    }

    document.querySelectorAll('.archive-restore-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            restoreBtn = btn;
            if (restoreNameEl) restoreNameEl.textContent = btn.dataset.name || 'this student';
            setRestoreError('');

            if (restoreModal) {
                restoreModal.show();
            } else if (window.confirm('Restore ' + (btn.dataset.name || 'this student') + ' to the Student Directory?')) {
                restoreStudent();
            }
        });
    });

    if (restoreModalEl) {
        restoreModalEl.addEventListener('hide.bs.modal', (e) => {
            if (restoreInFlight) e.preventDefault();
        });
        restoreModalEl.addEventListener('hidden.bs.modal', () => {
            restoreBtn = null;
            setRestoreError('');
        });
    }

    if (restoreConfirmBtn) restoreConfirmBtn.addEventListener('click', restoreStudent);
})();

document.addEventListener('DOMContentLoaded', function () {
    const panel     = document.getElementById('documentPreviewPanel');
    const title     = document.getElementById('documentPreviewTitle');
    const body      = document.getElementById('documentPreviewBody');
    const openTab   = document.getElementById('documentPreviewOpenTab');
    const closeBtn  = document.getElementById('documentPreviewClose');
    const handle    = document.getElementById('documentPreviewResize');
    if (!panel || !title || !body) return;

    const MIN_WIDTH = 320;
    const MAX_WIDTH = 900;
    const mobileQuery = window.matchMedia('(max-width: 768px)');
    let widthPx = null;
    let activeLink = null;
    const shrinkAreas = document.querySelectorAll('.admin-topbar, .main-container');

    function shrinkMain(on) {
        shrinkAreas.forEach(function (el) {
            if (on && !mobileQuery.matches) {
                el.style.transition = 'margin-right 0.2s ease';
                el.style.marginRight = 'var(--doc-preview-width)';
            } else {
                el.style.removeProperty('margin-right');
            }
        });
    }

    function clampWidth(px) {
        const maxByViewport = window.innerWidth * 0.7;
        return Math.round(Math.max(MIN_WIDTH, Math.min(px, MAX_WIDTH, maxByViewport)));
    }

    function applyWidth() {
        const root = document.documentElement;
        if (mobileQuery.matches) {
            root.style.removeProperty('--doc-preview-width');
            return;
        }
        const width = clampWidth(widthPx !== null ? widthPx : window.innerWidth * 0.3);
        root.style.setProperty('--doc-preview-width', width + 'px');
    }

    function openPreview(link) {
        const url = link.getAttribute('href');
        const label = link.dataset.fileLabel || link.dataset.fileName || url.split('/').pop();
        const ext = ((link.dataset.fileName || url).split('?')[0].split('.').pop() || '').toLowerCase();

        if (activeLink) activeLink.classList.remove('active');
        activeLink = link;
        link.classList.add('active');

        title.textContent = label;
        title.title = link.dataset.fileName || label;
        if (openTab) openTab.href = url;
        body.innerHTML = '';

        if (['jpg', 'jpeg', 'png'].includes(ext)) {
            const img = document.createElement('img');
            img.src = url;
            img.alt = label;
            body.appendChild(img);
        } else if (ext === 'pdf') {
            const frame = document.createElement('iframe');
            frame.src = url + (url.indexOf('#') === -1 ? '#view=FitH' : '');
            frame.title = label;
            body.appendChild(frame);
        } else {
            const note = document.createElement('div');
            note.className = 'document-preview-fallback text-muted';
            note.textContent = 'Preview not available for this file type. Use "Open in new tab".';
            body.appendChild(note);
        }

        applyWidth();
        panel.removeAttribute('inert');
        panel.setAttribute('aria-hidden', 'false');
        panel.classList.add('is-open');
        document.body.classList.add('has-document-preview');
        shrinkMain(true);
    }

    function hidePreview() {
        if (!panel.classList.contains('is-open')) return;
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        panel.setAttribute('inert', '');
        document.body.classList.remove('has-document-preview');
        shrinkMain(false);
        if (activeLink) activeLink.classList.remove('active');
        activeLink = null;
        setTimeout(function () {
            if (!panel.classList.contains('is-open')) body.innerHTML = '';
        }, 250);
    }

    document.addEventListener('click', function (e) {
        const link = e.target.closest('.archive-file-link');
        if (!link) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        openPreview(link);
    });

    if (closeBtn) closeBtn.addEventListener('click', hidePreview);

    if (handle) {
        handle.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            handle.setPointerCapture(e.pointerId);
            panel.classList.add('is-resizing');
            document.body.classList.add('is-resizing-preview');
        });
        handle.addEventListener('pointermove', function (e) {
            if (!handle.hasPointerCapture(e.pointerId)) return;
            widthPx = clampWidth(window.innerWidth - e.clientX);
            applyWidth();
        });
        const stopResize = function (e) {
            if (handle.hasPointerCapture(e.pointerId)) handle.releasePointerCapture(e.pointerId);
            panel.classList.remove('is-resizing');
            document.body.classList.remove('is-resizing-preview');
        };
        handle.addEventListener('pointerup', stopResize);
        handle.addEventListener('pointercancel', stopResize);
    }

    window.addEventListener('resize', function () {
        if (panel.classList.contains('is-open')) applyWidth();
    });
    mobileQuery.addEventListener('change', function () {
        if (panel.classList.contains('is-open')) {
            applyWidth();
            shrinkMain(true);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open') && !document.body.classList.contains('modal-open')) {
            hidePreview();
        }
    });
});