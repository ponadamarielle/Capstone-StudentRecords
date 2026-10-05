(function () {
    const statusLabels     = { active: 'ENROLLEE', graduated: 'GRADUATE', inactive: 'IN-ACTIVE', onleave: 'ON-LEAVE' };
    const ARCHIVE_STATUSES = ['graduated', 'inactive', 'onleave'];

    const confirmModalEl = document.getElementById('confirmStatusModal');
    const confirmModal   = confirmModalEl ? new bootstrap.Modal(confirmModalEl) : null;
    const titleEl        = document.getElementById('confirmStatusTitle');
    const nameEl         = document.getElementById('confirmStatusStudentName');
    const oldValueEl     = document.getElementById('confirmStatusOldValue');
    const newValueEl     = document.getElementById('confirmStatusNewValue');
    const archiveNoteEl  = document.getElementById('confirmStatusArchiveNote');
    const errorEl        = document.getElementById('confirmStatusError');
    const cancelBtn      = document.getElementById('confirmStatusCancelBtn');
    const confirmBtn     = document.getElementById('confirmStatusChangeBtn');

    let pendingSelect = null;
    let inFlight      = false;

    function isArchiveStatus(value) {
        return ARCHIVE_STATUSES.indexOf(value) !== -1;
    }

    function showError(message) {
        if (!errorEl) {
            alert(message);
            return;
        }
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function clearError() {
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.classList.add('d-none');
        }
    }

    function removeRowFromTable(row) {
        if (!row) return;
        const body = row.parentElement;
        row.remove();
        if (body && !body.querySelector('.status-fliter')) {
            const emptyRow = document.createElement('tr');
            emptyRow.innerHTML = '<td colspan="7" class="no-students-message">No students found.</td>';
            body.appendChild(emptyRow);
        }
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

    if (confirmBtn) {
        confirmBtn.style.whiteSpace = 'nowrap';
        confirmBtn.style.width = 'auto';
        confirmBtn.style.minWidth = '110px';
    }

    function setBusy(busy, archiving) {
        inFlight = busy;
        if (confirmBtn) {
            confirmBtn.disabled = busy;
            confirmBtn.textContent = busy
                ? (archiving ? 'Archiving…' : 'Saving…')
                : (archiving ? 'Archive' : 'Confirm');
        }
        if (cancelBtn) cancelBtn.disabled = busy;
    }

    async function submitStatusChange() {
        if (!pendingSelect || inFlight) return;

        const select    = pendingSelect;
        const newStatus = select.value;
        const archiving = isArchiveStatus(newStatus);

        clearError();
        setBusy(true, archiving);

        const formData = new FormData();
        formData.append('student_id', select.dataset.id);
        formData.append('status', newStatus);

        let closeModal = false;
        try {
            const url  = archiving ? 'archive.php?action=archive_student' : 'student_directory.php?action=update_status';
            const res  = await fetch(url, { method: 'POST', body: formData });
            const data = await readJson(res);

            if (data.success) {
                select.dataset.previousValue = newStatus;
                closeModal = true;

                if (data.archived) {
                    removeRowFromTable(select.closest('tr'));
                    if (data.skipped && data.skipped.length) {
                        alert('Archived, but these files were missing on the server and could not be moved:\n- '
                            + data.skipped.join('\n- '));
                    }
                }
            } else {
                showError(data.message || 'Failed to update status.');
            }
        } catch (err) {
            showError(err.message || 'An error occurred while updating status.');
        } finally {
            setBusy(false, archiving);
            if (closeModal) {
                pendingSelect = null;
                if (confirmModal) confirmModal.hide();
            }
        }
    }

    document.querySelectorAll('.status-fliter').forEach(function (select) {
        select.dataset.previousValue = select.value;

        select.addEventListener('change', function () {
            pendingSelect = select;

            const row = select.closest('tr');
            const studentName = row ? row.children[1].textContent.trim() : 'this student';
            const archiving = isArchiveStatus(select.value);

            if (nameEl)     nameEl.textContent     = studentName;
            if (oldValueEl) oldValueEl.textContent = statusLabels[select.dataset.previousValue] || select.dataset.previousValue;
            if (newValueEl) newValueEl.textContent = statusLabels[select.value] || select.value;
            if (titleEl)    titleEl.textContent    = archiving ? 'Confirm Archive' : 'Confirm Status Change';
            if (archiveNoteEl) archiveNoteEl.classList.toggle('d-none', !archiving);
            if (confirmBtn) confirmBtn.textContent = archiving ? 'Archive' : 'Confirm';
            clearError();

            if (confirmModal) {
                confirmModal.show();
            } else if (window.confirm('Change status to ' + (statusLabels[select.value] || select.value) + '?')) {
                submitStatusChange();
            } else {
                select.value = select.dataset.previousValue;
                pendingSelect = null;
            }
        });
    });

    if (confirmModalEl) {
        confirmModalEl.addEventListener('hide.bs.modal', function (e) {
            if (inFlight) e.preventDefault();
        });

        confirmModalEl.addEventListener('hidden.bs.modal', function () {
            if (pendingSelect && pendingSelect.value !== pendingSelect.dataset.previousValue) {
                pendingSelect.value = pendingSelect.dataset.previousValue;
            }
            pendingSelect = null;
            clearError();
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', submitStatusChange);
    }
})();

document.querySelectorAll('.view-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        const studentId = btn.dataset.id;
        window.location.href = `view_student.php?id=${studentId}`;
    });
});

(function () {
    const lettersOnly = (value) => value.replace(/[^A-Za-z\s]/g, '');
    const digitsOnly  = (value) => value.replace(/[^0-9]/g, '');

    const isDeleting = (e) => !!(e && e.inputType && e.inputType.indexOf('delete') === 0);

    function formatYearSection(value, e) {
        const digits = digitsOnly(value);

        if (digits.length === 0) return '';
        if (digits.length === 1) return isDeleting(e) ? digits : digits + '-';
        return digits.slice(0, 1) + '-' + digits.slice(1, 2);
    }

    function formatStudentNumber(value, e) {
        const segments = [4, 5, 2, 1];
        const types    = ['digit', 'digit', 'letter', 'digit'];
        const raw      = value.toUpperCase().replace(/-/g, '');

        let result   = '';
        let segIndex = 0;
        let segCount = 0;

        for (const ch of raw) {
            if (segIndex >= segments.length) break;

            const isDigit  = /[0-9]/.test(ch);
            const isLetter = /[A-Z]/.test(ch);
            const valid    = types[segIndex] === 'digit' ? isDigit : isLetter;

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
        if (/^[0-9]/.test(trimmed)) {
            return formatStudentNumber(trimmed, e);
        }
        return value;
    }

    const bindFilterEl = (el, transform) => {
        if (!el) return;
        el.addEventListener('input', (e) => {
            const cursorFromEnd = el.value.length - el.selectionStart;
            el.value = transform(el.value, e);
            const pos = Math.max(0, el.value.length - cursorFromEnd);
            el.setSelectionRange(pos, pos);
        });
    };

    const bindFilter = (id, transform) => bindFilterEl(document.getElementById(id), transform);

    bindFilter('last_name', (v) => v.replace(/[^A-Za-zÑñ\s.,'\-]/g, ''));
    bindFilter('first_name', (v) => v.replace(/[^A-Za-zÑñ\s.,'\-]/g, ''));
    bindFilter('middle_name', (v) => v.replace(/[^A-Za-zÑñ\s.,'\-]/g, ''));
    bindFilter('course', (v) => v.replace(/[^A-Za-z\s\-]/g, ''));
    bindFilter('sex', lettersOnly);
    bindFilter('mobile_number', (v) => digitsOnly(v).slice(0, 11));
    bindFilter('year_section', formatYearSection);
    bindFilter('student_number', formatStudentNumber);

    const searchInput = document.querySelector('#directoryFilterForm input[name="search"]');
    bindFilterEl(searchInput, formatSearch);

    if (searchInput && searchInput.value) {
        searchInput.value = formatSearch(searchInput.value);
    }

    const emailEl         = document.getElementById('email');
    const emailFeedbackEl = document.getElementById('emailFeedback');
    const emailPattern    = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

    function validateEmail() {
        if (!emailEl) return true;

        const value = emailEl.value.trim();

        if (value === '') {
            emailEl.classList.remove('is-invalid');
            if (emailFeedbackEl) emailFeedbackEl.textContent = '';
            return true;
        }

        const valid = emailPattern.test(value);

        if (valid) {
            emailEl.classList.remove('is-invalid');
            if (emailFeedbackEl) emailFeedbackEl.textContent = '';
        } else {
            emailEl.classList.add('is-invalid');
            if (emailFeedbackEl) emailFeedbackEl.textContent = 'Please enter a valid email address.';
        }

        return valid;
    }

    if (emailEl) {
        emailEl.addEventListener('input', validateEmail);
        emailEl.addEventListener('blur', validateEmail);
    }

    const addStudentForm = document.getElementById('addStudentForm');
    if (addStudentForm) {
        addStudentForm.addEventListener('submit', function (e) {
            if (!validateEmail()) {
                e.preventDefault();
                e.stopPropagation();
                if (emailEl) emailEl.focus();
            }
        });
    }

    function blockIfEmailInvalid(e) {
        if (!validateEmail()) {
            e.preventDefault();
            e.stopImmediatePropagation();

            const studentTab = document.getElementById('student-info-tab');
            if (studentTab && studentTab.click) studentTab.click();

            if (emailEl) emailEl.focus();

            const confirmSaveModalEl = document.getElementById('confirmSaveModal');
            if (confirmSaveModalEl && window.bootstrap) {
                const instance = bootstrap.Modal.getInstance(confirmSaveModalEl);
                if (instance) instance.hide();
            }
        }
    }

    const saveStudentBtn = document.getElementById('saveStudentBtn');
    if (saveStudentBtn) {
        saveStudentBtn.addEventListener('click', blockIfEmailInvalid, true);
    }

    document.addEventListener('click', function (e) {
        const target = e.target && e.target.closest && e.target.closest('#confirmSaveBtn');
        if (target) blockIfEmailInvalid(e);
    }, true);

    const mobileNumberEl = document.getElementById('mobile_number');
    if (mobileNumberEl) {
        mobileNumberEl.addEventListener('focus', function () {
            if (this.value === '') {
                this.value = '09';
            }
        });
    }
})();