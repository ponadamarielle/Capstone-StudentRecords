(function () {
    const statusLabels = { active: 'ACTIVE', inactive: 'IN-ACTIVE', onleave: 'ON-LEAVE' };

    const confirmModalEl = document.getElementById('confirmStatusModal');
    const confirmModal   = confirmModalEl ? new bootstrap.Modal(confirmModalEl) : null;
    const nameEl         = document.getElementById('confirmStatusStudentName');
    const oldValueEl     = document.getElementById('confirmStatusOldValue');
    const newValueEl     = document.getElementById('confirmStatusNewValue');
    const confirmBtn     = document.getElementById('confirmStatusChangeBtn');

    let pendingSelect = null;

    document.querySelectorAll('.status-fliter').forEach(function (select) {
        select.dataset.previousValue = select.value;

        select.addEventListener('change', function () {
            pendingSelect = select;

            const row = select.closest('tr');
            const studentName = row ? row.children[1].textContent.trim() : 'this student';

            nameEl.textContent     = studentName;
            oldValueEl.textContent = statusLabels[select.dataset.previousValue] || select.dataset.previousValue;
            newValueEl.textContent = statusLabels[select.value] || select.value;

            if (confirmModal) {
                confirmModal.show();
            }
        });
    });

    if (confirmModalEl) {
        confirmModalEl.addEventListener('hidden.bs.modal', function () {
            if (pendingSelect && pendingSelect.value !== pendingSelect.dataset.previousValue) {
                pendingSelect.value = pendingSelect.dataset.previousValue;
            }
            pendingSelect = null;
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            if (!pendingSelect) return;

            const select     = pendingSelect;
            const newStatus  = select.value;
            const studentId  = select.dataset.id;

            confirmBtn.disabled = true;

            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('status', newStatus);

            fetch('student_directory.php?action=update_status', {
                method: 'POST',
                body: formData
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        select.dataset.previousValue = newStatus;
                    } else {
                        select.value = select.dataset.previousValue;
                        alert(data.message || 'Failed to update status.');
                    }
                })
                .catch(function () {
                    select.value = select.dataset.previousValue;
                    alert('An error occurred while updating status.');
                })
                .finally(function () {
                    confirmBtn.disabled = false;
                    pendingSelect = null;
                    if (confirmModal) confirmModal.hide();
                });
        });
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

    function formatYearSection(value, e) {
        const digits = digitsOnly(value);
        const deleting = e && e.inputType && e.inputType.indexOf('delete') === 0;

        if (digits.length === 0) return '';
        if (digits.length === 1) return deleting ? digits : digits + '-';
        return digits.slice(0, 1) + '-' + digits.slice(1, 3);
    }

    function formatStudentNumber(value) {
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

        return result;
    }

    const bindFilter = (id, transform) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', (e) => {
            const cursorFromEnd = el.value.length - el.selectionStart;
            el.value = transform(el.value, e);
            const pos = Math.max(0, el.value.length - cursorFromEnd);
            el.setSelectionRange(pos, pos);
        });
    };

    bindFilter('name', lettersOnly);
    bindFilter('course', lettersOnly);
    bindFilter('sex', lettersOnly);
    bindFilter('mobile_number', (v) => digitsOnly(v).slice(0, 11));
    bindFilter('year_section', formatYearSection);
    bindFilter('student_number', formatStudentNumber);

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