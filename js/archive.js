(function () {
    'use strict';

    const isDeleting = (e) => !!(e && e.inputType && e.inputType.indexOf('delete') === 0);

    function formatStudentNumber(value, e) {
        const segments = [4, 5, 2, 1];
        const types    = ['digit', 'digit', 'letter', 'digit'];
        const raw      = value.toUpperCase().replace(/-/g, '');

        let result   = '';
        let segIndex = 0;
        let segCount = 0;

        for (const ch of raw) {
            if (segIndex >= segments.length) break;

            const valid = types[segIndex] === 'digit' ? /[0-9]/.test(ch) : /[A-Z]/.test(ch);
            if (!valid) continue;

            result += ch;
            segCount++;

            if (segCount === segments[segIndex]) {
                segIndex++;
                segCount = 0;
                if (segIndex < segments.length) result += '-';
            }
        }

        if (isDeleting(e) && result.endsWith('-')) {
            result = result.slice(0, -1);
        }
        return result;
    }

    function formatSearch(value, e) {
        const trimmed = value.replace(/^\s+/, '');
        return /^[0-9]/.test(trimmed) ? formatStudentNumber(trimmed, e) : value;
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