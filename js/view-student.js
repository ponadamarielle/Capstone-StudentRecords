document.addEventListener('DOMContentLoaded', function () {
    const ACTIONS_URL = 'view_student.php';

    let faceModelsPromise = null;

    function loadFaceModels() {
        if (faceModelsPromise) return faceModelsPromise;
        faceModelsPromise = (async () => {
            try {
                await faceapi.tf.setBackend('cpu');
                await faceapi.tf.ready();
            } catch (e) {
                console.warn('Could not force CPU backend, continuing with default:', e);
            }
            await faceapi.nets.tinyFaceDetector.loadFromUri('../js/models');
        })();
        return faceModelsPromise;
    }

    loadFaceModels();

    function setStudentPhoto(dataUrl) {
        const img = document.getElementById('modalStudentPhoto');
        if (img) img.src = dataUrl;

        const photoFeedback = document.getElementById('photoFeedback');
        if (photoFeedback) photoFeedback.textContent = '';

        fetch(dataUrl)
            .then((res) => res.blob())
            .then((blob) => {
                const file = new File([blob], 'scanned-photo.jpg', { type: blob.type || 'image/jpeg' });
                const dt = new DataTransfer();
                dt.items.add(file);
                const replaceInput = document.getElementById('replacePhotoInput');
                if (replaceInput) replaceInput.files = dt.files;
                updateSaveButtonState();
            })
            .catch((err) => console.error('Failed to stage scanned photo:', err));
    }

    async function extractFaceFromScan(imageUrl) {
        try {
            await loadFaceModels();

            const img = await faceapi.fetchImage(imageUrl);
            const detection = await faceapi.detectSingleFace(
                img,
                new faceapi.TinyFaceDetectorOptions()
            );

            if (!detection) {
                console.warn('No face detected in scanned document.');
                return null;
            }

            const box = detection.box;
            const cx = box.x + box.width / 2;
            const cy = box.y + box.height / 2;

            const cropW = box.width * 1.55;
            const cropH = box.height * 2.00;
            const topFraction = 0.22;
            const leftFraction = 0.44;

            let sx = cx - cropW * leftFraction;
            let sy = cy - cropH * topFraction;

            sx = Math.max(0, Math.min(sx, img.width - cropW));
            sy = Math.max(0, Math.min(sy, img.height - cropH));
            const sw = Math.min(cropW, img.width - sx);
            const sh = Math.min(cropH, img.height - sy);

            const canvas = document.createElement('canvas');
            canvas.width = 300;
            canvas.height = 300;
            const ctx = canvas.getContext('2d');

            const scale = Math.max(300 / sw, 300 / sh);
            const drawW = sw * scale;
            const drawH = sh * scale;
            const dx = (300 - drawW) / 2;
            const dy = (300 - drawH) / 2;
            ctx.drawImage(img, sx, sy, sw, sh, dx, dy, drawW, drawH);

            const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
            setStudentPhoto(dataUrl);
            return dataUrl;
        } catch (err) {
            console.error('Face extraction failed:', err);
            return null;
        }
    }

    const form  = document.getElementById('updateRecordForm');
    if (!form) return;

    const replacePhotoInput = document.getElementById('replacePhotoInput');
    const stagedPhotoPath   = document.getElementById('stagedPhotoPath');

    function restrictInput(id, disallowedCharsRegex) {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('input', () => {
            const cleaned = input.value.replace(disallowedCharsRegex, '');
            if (cleaned !== input.value) input.value = cleaned;
        });
    }

    const isDeleting = (e) => !!(e && e.inputType && e.inputType.indexOf('delete') === 0);

    function formatYearSection(value, e) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 2);
        if (digits.length === 2) return digits[0] + '-' + digits[1];
        if (digits.length === 1) return isDeleting(e) ? digits : digits + '-';
        return '';
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

        if (isDeleting(e) && result.endsWith('-')) result = result.slice(0, -1);
        return result;
    }

    function bindFilter(id, transform) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', (e) => {
            const cursorFromEnd = el.value.length - el.selectionStart;
            const formatted = transform(el.value, e);
            if (formatted === el.value) return;
            el.value = formatted;
            const pos = Math.max(0, el.value.length - cursorFromEnd);
            el.setSelectionRange(pos, pos);
        });
    }

    bindFilter('edit_name', (v) => v.replace(/[^A-Za-z\s.,]/g, ''));
    bindFilter('edit_course', (v) => v.replace(/[^A-Za-z\s\-]/g, ''));
    bindFilter('edit_sex', (v) => v.replace(/[^A-Za-z\s]/g, ''));
    bindFilter('edit_mobile_number', (v) => v.replace(/[^0-9]/g, '').slice(0, 11));
    bindFilter('edit_year_section', formatYearSection);
    bindFilter('edit_student_number', formatStudentNumber);

    const editMobile = document.getElementById('edit_mobile_number');
    if (editMobile) {
        editMobile.addEventListener('focus', () => {
            if (editMobile.value === '') editMobile.value = '09';
        });
        editMobile.addEventListener('blur', () => {
            if (editMobile.value === '09') editMobile.value = '';
        });
    }

    const editEmail         = document.getElementById('edit_email');
    const editEmailFeedback = document.getElementById('editEmailFeedback');
    function validateEditEmail() {
        if (!editEmail) return true;
        const value = editEmail.value.trim();
        const valid = value === '' || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value);
        editEmail.classList.toggle('is-invalid', !valid);
        if (editEmailFeedback) editEmailFeedback.textContent = valid ? '' : 'Please enter a valid email address.';
        return valid;
    }
    if (editEmail) {
        editEmail.addEventListener('input', validateEditEmail);
        editEmail.addEventListener('blur', validateEditEmail);
    }

    const uploadInput         = document.getElementById('uploadFileInput');
    const uploadFileBtn       = document.getElementById('uploadFileBtn');
    const scanBtn             = document.querySelector('.scan-document-btn');
    const saveBtn             = document.getElementById('saveDocumentBtn');
    const ocrStatus           = document.getElementById('ocrStatus');
    const scannedPreview      = document.getElementById('scannedFilePreview');
    const addDocumentFeedback = document.getElementById('addDocumentFeedback');
    const requirementChecklist = document.getElementById('requirementChecklist');
    const documentList        = document.getElementById('documentList');
    const modalDocumentList   = document.getElementById('modalDocumentList');
    const trackSelect         = document.getElementById('edit_admission_track');
    const studentId           = form.elements['student_id'].value;

    const PAGE_DATA = (function () {
        const el = document.getElementById('documentPageData');
        try { return el && el.dataset.json ? JSON.parse(el.dataset.json) : {}; } catch (e) { console.error('Bad documentPageData', e); return {}; }
    })();
    const REQUIREMENTS       = Array.isArray(PAGE_DATA.requirements) ? PAGE_DATA.requirements : [];
    const REQUIREMENT_GROUPS = PAGE_DATA.groups || {};
    const ADMISSION_TRACKS   = PAGE_DATA.tracks || {};
    const requirementByCode  = {};
    REQUIREMENTS.forEach((r) => { requirementByCode[r.code] = r; });

    let storedDocs = (Array.isArray(PAGE_DATA.documents) ? PAGE_DATA.documents : []).map((d) => ({
        document_id: String(d.document_id),
        requirement_code: d.requirement_code || null,
        file_name: d.file_name,
        file_path: d.file_path,
        needs_manual_type: !d.requirement_code,
    }));

    let stagedDocs = [];
    const stagedSources = new Map();   
    let needsReload = false;           

    function requirementLabel(code) {
        return code && requirementByCode[code] ? requirementByCode[code].label : 'Other / unclassified';
    }

    function currentTrack() {
        return trackSelect && trackSelect.value ? trackSelect.value : 'shs_current';
    }

    function isRequiredForTrack(r) {
        return Array.isArray(r.required_for) && r.required_for.indexOf(currentTrack()) !== -1;
    }

    function storedByCode(code, exceptId) {
        if (!code) return null;
        return storedDocs.find((d) => d.requirement_code === code && d.document_id !== exceptId) || null;
    }

    function stagedIndexByCode(code, exceptIndex) {
        if (!code) return -1;
        return stagedDocs.findIndex((d, i) => i !== exceptIndex && d.requirement_code === code);
    }

    function requirementWillBeOnFile(code) {
        return !!storedByCode(code) || stagedIndexByCode(code, -1) !== -1;
    }

    async function sha256OfFile(file) {
        if (!window.crypto || !window.crypto.subtle) return null;
        try {
            const digest = await window.crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
        } catch (err) {
            return null;
        }
    }

    const ocrFieldMap = {
        name: 'edit_name',
        student_number: 'edit_student_number',
        course: 'edit_course',
        year_section: 'edit_year_section',
        sex: 'edit_sex',
        date_of_birth: 'edit_date_of_birth',
        place_of_birth: 'edit_place_of_birth',
        mobile_number: 'edit_mobile_number',
        email: 'edit_email',
        address: 'edit_address',
    };

    const originalFieldValues = {};
    Object.keys(ocrFieldMap).forEach((key) => {
        const input = document.getElementById(ocrFieldMap[key]);
        originalFieldValues[key] = input ? input.value : '';
    });
    const originalTrack = currentTrack();

    function hasUnsavedChanges() {
        const fieldsChanged = Object.keys(ocrFieldMap).some((key) => {
            const input = document.getElementById(ocrFieldMap[key]);
            return input && input.value !== originalFieldValues[key];
        });
        const photoChanged = !!(replacePhotoInput && replacePhotoInput.files && replacePhotoInput.files.length > 0)
            || !!(stagedPhotoPath && stagedPhotoPath.value);

        return fieldsChanged || photoChanged || stagedDocs.length > 0 || currentTrack() !== originalTrack;
    }

    let pendingUploads = 0;
    let isSaving = false;
    let recordSaved = false;

    function updateSaveButtonState() {
        if (!saveBtn) return;
        const disabled = pendingUploads > 0 || !hasUnsavedChanges();
        saveBtn.disabled = disabled;
        saveBtn.style.opacity = disabled ? '0.55' : '';
        saveBtn.style.cursor = disabled ? 'not-allowed' : '';
        saveBtn.style.pointerEvents = disabled ? 'none' : '';
    }

    Object.keys(ocrFieldMap).forEach((key) => {
        const input = document.getElementById(ocrFieldMap[key]);
        if (input) input.addEventListener('input', updateSaveButtonState);
    });

    if (trackSelect) {
        trackSelect.addEventListener('change', () => {
            renderRequirementChecklist();
            updateSaveButtonState();
        });
    }

    function forceCloseModal(modalEl) {
        if (!modalEl) return;
        const instance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.hide();
    }

    const addRecordsModalEl = document.getElementById('addRecordsModal');
    if (addRecordsModalEl) {
        addRecordsModalEl.addEventListener('hidden.bs.modal', hideDocumentPreview);
    }

    function toggleSaveDisabled(disabled) {
        if (disabled) {
            pendingUploads++;
        } else {
            pendingUploads = Math.max(0, pendingUploads - 1);
        }
        updateSaveButtonState();
    }

    updateSaveButtonState();

    function setStatus(message, type) {
        if (!ocrStatus) return;
        ocrStatus.textContent = message;
        ocrStatus.className = 'ocr-status ocr-status-' + type;
    }

    const originalPhotoSrc = (document.getElementById('modalStudentPhoto') || {}).src || '';
    let photoOwnerSource = null;   // source_hash of the upload whose photo is staged

    function setServerPhoto(photoPath) {
        const img = document.getElementById('modalStudentPhoto');
        if (img) img.src = photoPath;
        if (stagedPhotoPath) stagedPhotoPath.value = photoPath;

        if (replacePhotoInput) replacePhotoInput.value = '';

        const fb = document.getElementById('photoFeedback');
        if (fb) fb.textContent = '';

        updateSaveButtonState();
    }

    function releaseStagedPhoto(revertImage) {
        const path = stagedPhotoPath ? stagedPhotoPath.value : '';
        photoOwnerSource = null;
        if (!path) return;

        cleanupStagedFile('', '', path);
        stagedPhotoPath.value = '';

        if (revertImage) {
            const img = document.getElementById('modalStudentPhoto');
            if (img && originalPhotoSrc) img.src = originalPhotoSrc;
        }

        updateSaveButtonState();
    }

    function populateEmptyFields(extracted) {
        const applied = [];
        if (!extracted) return applied;
        Object.keys(ocrFieldMap).forEach((key) => {
            const input = document.getElementById(ocrFieldMap[key]);
            const value = (extracted[key] || '').trim();
            if (input && value && !input.value.trim()) {
                input.value = value;
                input.classList.add('ocr-filled');
                applied.push(ocrFieldMap[key]);
            }
        });
        return applied;
    }

    function clearAppliedFields(doc) {
        (doc.appliedFields || []).forEach((fieldId) => {
            const input = document.getElementById(fieldId);
            if (input) {
                input.value = '';
                input.classList.remove('ocr-filled');
            }
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    const previewPanel     = document.getElementById('documentPreviewPanel');
    const previewTitle     = document.getElementById('documentPreviewTitle');
    const previewBody      = document.getElementById('documentPreviewBody');
    const previewOpenTab   = document.getElementById('documentPreviewOpenTab');
    const previewCloseBtn  = document.getElementById('documentPreviewClose');
    const previewHandle    = document.getElementById('documentPreviewResize');

    const PREVIEW_MIN_WIDTH = 320;
    const PREVIEW_MAX_WIDTH = 900;
    const mobileQuery = window.matchMedia('(max-width: 768px)');

    let previewWidthPx = null;   
    let currentPreviewPath = null;

    function clampPreviewWidth(px) {
        const maxByViewport = window.innerWidth * 0.7;
        return Math.round(Math.max(PREVIEW_MIN_WIDTH, Math.min(px, PREVIEW_MAX_WIDTH, maxByViewport)));
    }

    function applyPreviewWidth() {
        const root = document.documentElement;
        if (mobileQuery.matches) {
            root.style.removeProperty('--doc-preview-width');
            return;
        }
        const width = clampPreviewWidth(previewWidthPx !== null ? previewWidthPx : window.innerWidth * 0.3);
        root.style.setProperty('--doc-preview-width', width + 'px');
    }

    function setModalFocusTrap(active) {
        try {
            if (!addRecordsModalEl) return;
            const instance = bootstrap.Modal.getInstance(addRecordsModalEl);
            const trap = instance && instance._focustrap;
            if (!trap) return;
            if (!active) {
                trap.deactivate();
            } else if (addRecordsModalEl.classList.contains('show')) {
                trap.activate();
            }
        } catch (err) {
            console.warn('Could not toggle modal focus trap:', err);
        }
    }

    function openDocumentPreview(filePath, fileName) {
        if (!previewPanel || !filePath) return;

        const label = fileName || filePath.split('/').pop();
        const ext = (filePath.split('.').pop() || '').toLowerCase();

        currentPreviewPath = filePath;
        previewTitle.textContent = label;
        previewTitle.title = label;
        previewOpenTab.href = filePath;
        previewBody.innerHTML = '';

        if (['jpg', 'jpeg', 'png'].includes(ext)) {
            const img = document.createElement('img');
            img.src = filePath;
            img.alt = label;
            previewBody.appendChild(img);
        } else if (ext === 'pdf') {
            const frame = document.createElement('iframe');
            frame.src = filePath + (filePath.indexOf('#') === -1 ? '#view=FitH' : '');
            frame.title = label;
            previewBody.appendChild(frame);
        } else {
            const note = document.createElement('div');
            note.className = 'document-preview-fallback text-muted';
            note.textContent = 'Preview not available for this file type. Use "Open in new tab".';
            previewBody.appendChild(note);
        }

        applyPreviewWidth();
        previewPanel.removeAttribute('inert');
        previewPanel.setAttribute('aria-hidden', 'false');
        previewPanel.classList.add('is-open');
        document.body.classList.add('has-document-preview');
        setModalFocusTrap(false);
    }

    function hideDocumentPreview() {
        if (!previewPanel || !previewPanel.classList.contains('is-open')) return;

        previewPanel.classList.remove('is-open');
        previewPanel.setAttribute('aria-hidden', 'true');
        previewPanel.setAttribute('inert', '');
        document.body.classList.remove('has-document-preview');
        currentPreviewPath = null;
        setModalFocusTrap(true);

        setTimeout(() => {
            if (!previewPanel.classList.contains('is-open')) previewBody.innerHTML = '';
        }, 250);
    }

    function previewFromRow(row) {
        if (!row || !row.dataset.filePath) return;
        const nameEl = row.querySelector('.document-name');
        openDocumentPreview(row.dataset.filePath, nameEl ? nameEl.textContent.trim() : '');
    }

    if (previewCloseBtn) previewCloseBtn.addEventListener('click', hideDocumentPreview);

    if (previewHandle && previewPanel) {
        previewHandle.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            previewHandle.setPointerCapture(e.pointerId);
            previewPanel.classList.add('is-resizing');
            document.body.classList.add('is-resizing-preview');
        });

        previewHandle.addEventListener('pointermove', (e) => {
            if (!previewHandle.hasPointerCapture(e.pointerId)) return;
            previewWidthPx = clampPreviewWidth(window.innerWidth - e.clientX);
            applyPreviewWidth();
        });

        const stopResize = (e) => {
            if (previewHandle.hasPointerCapture(e.pointerId)) {
                previewHandle.releasePointerCapture(e.pointerId);
            }
            previewPanel.classList.remove('is-resizing');
            document.body.classList.remove('is-resizing-preview');
        };
        previewHandle.addEventListener('pointerup', stopResize);
        previewHandle.addEventListener('pointercancel', stopResize);
    }

    window.addEventListener('resize', () => {
        if (previewPanel && previewPanel.classList.contains('is-open')) applyPreviewWidth();
    });
    mobileQuery.addEventListener('change', () => {
        if (previewPanel && previewPanel.classList.contains('is-open')) applyPreviewWidth();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && previewPanel && previewPanel.classList.contains('is-open')
            && !document.body.classList.contains('modal-open')) {
            hideDocumentPreview();
        }
    });

    function cleanupStagedFile(filePath, imagePath, photoPath) {
        if (!filePath && !imagePath && !photoPath) return;
        const cleanupData = new FormData();
        if (filePath) cleanupData.append('file_path', filePath);
        if (imagePath) cleanupData.append('image_path', imagePath);
        if (photoPath) cleanupData.append('photo_path', photoPath);
        fetch(ACTIONS_URL + '?action=delete_staged_document', {
            method: 'POST',
            body: cleanupData,
        }).catch((err) => console.error('Failed to clean up staged file:', err));
    }

    function renderRequirementChecklist() {
        if (!requirementChecklist) return;
        requirementChecklist.innerHTML = '';
        if (!REQUIREMENTS.length) return;

        const required = REQUIREMENTS.filter(isRequiredForTrack);
        const done = required.filter((r) => requirementWillBeOnFile(r.code));

        const heading = document.createElement('div');
        heading.className = 'requirement-checklist-heading';
        heading.textContent = (ADMISSION_TRACKS[currentTrack()] || 'Requirements') + ': '
            + done.length + ' of ' + required.length + ' required documents';
        requirementChecklist.appendChild(heading);

        Object.keys(REQUIREMENT_GROUPS).forEach((groupKey) => {
            const reqs = REQUIREMENTS.filter((r) => r.group === groupKey
                && (isRequiredForTrack(r) || r.required_for.length === 0 || requirementWillBeOnFile(r.code)));
            if (!reqs.length) return;

            const groupEl = document.createElement('div');
            groupEl.className = 'requirement-group';
            const label = document.createElement('div');
            label.className = 'requirement-group-label';
            label.textContent = REQUIREMENT_GROUPS[groupKey];
            groupEl.appendChild(label);

            const items = document.createElement('div');
            items.className = 'requirement-items';
            reqs.forEach((r) => {
                const onFile = !!storedByCode(r.code);
                const staged = stagedIndexByCode(r.code, -1) !== -1;
                const required = isRequiredForTrack(r);
                const item = document.createElement('span');
                item.className = 'requirement-item' + (onFile || staged ? ' is-attached' : '')
                    + (staged ? ' is-staged' : '') + (required ? ' is-required' : '');
                item.title = (r.ref ? r.ref + ' — ' : '') + (r.note || '')
                    + (staged ? ' (new, saved on UPDATE)'
                        : onFile ? ' (on file)' : required ? ' (required, missing)' : ' (if applicable)');
                const icon = document.createElement('i');
                icon.className = 'bi ' + (staged ? 'bi-plus-circle-fill' : onFile ? 'bi-check-circle-fill' : 'bi-circle');
                item.appendChild(icon);
                item.appendChild(document.createTextNode(' ' + r.label + (required ? ' *' : '')));
                items.appendChild(item);
            });
            groupEl.appendChild(items);
            requirementChecklist.appendChild(groupEl);
        });
    }

    function missingRequiredLabels() {
        return REQUIREMENTS.filter((r) => isRequiredForTrack(r) && !requirementWillBeOnFile(r.code)).map((r) => r.label);
    }

    function fillTypeSelect(select, currentCode, kind, key) {
        select.innerHTML = '';
        const other = document.createElement('option');
        other.value = '';
        other.textContent = '— Other / unclassified —';
        select.appendChild(other);

        Object.keys(REQUIREMENT_GROUPS).forEach((groupKey) => {
            const reqs = REQUIREMENTS.filter((r) => r.group === groupKey);
            if (!reqs.length) return;
            const optgroup = document.createElement('optgroup');
            optgroup.label = REQUIREMENT_GROUPS[groupKey];
            reqs.forEach((r) => {
                const opt = document.createElement('option');
                opt.value = r.code;
                const stored = storedByCode(r.code, kind === 'stored' ? key : null);
                const stagedIdx = stagedIndexByCode(r.code, kind === 'staged' ? key : -1);
                let suffix = '';
                if (kind === 'stored') {
                    opt.disabled = !!stored || stagedIdx !== -1;
                    if (opt.disabled) suffix = ' (already attached)';
                } else {
                    opt.disabled = stagedIdx !== -1 || !!stored;
                    if (stored) suffix = ' (already on file)';
                    else if (stagedIdx !== -1) suffix = ' (already in new uploads)';
                }
                opt.textContent = r.label + suffix;
                optgroup.appendChild(opt);
            });
            select.appendChild(optgroup);
        });
        select.value = currentCode || '';
    }

    function refreshStoredRows() {
        if (!modalDocumentList) return;
        modalDocumentList.querySelectorAll('.document-row').forEach((row) => {
            const id = row.dataset.documentId;
            const doc = storedDocs.find((d) => d.document_id === id);
            if (!doc) return;
            const title = row.querySelector('.document-row-title');
            if (title) title.textContent = requirementLabel(doc.requirement_code);
            const select = row.querySelector('.document-type-select');
            if (select) {
                let changeLink = row.querySelector('.stored-change-type');
                if (!changeLink) {
                    changeLink = document.createElement('button');
                    changeLink.type = 'button';
                    changeLink.className = 'btn btn-link change-type-link stored-change-type';
                    changeLink.textContent = 'Change type';
                    select.insertAdjacentElement('afterend', changeLink);
                }
                if (doc.needs_manual_type) {
                    select.hidden = false;
                    changeLink.hidden = true;
                    fillTypeSelect(select, doc.requirement_code, 'stored', id);
                } else {
                    select.hidden = true;
                    changeLink.hidden = false;
                }
            }
        });
    }

    function refreshDocumentsUi() {
        refreshStoredRows();
        renderStagedDocuments();
        renderRequirementChecklist();
        updateSaveButtonState();
    }

    if (modalDocumentList) {
        modalDocumentList.addEventListener('change', async (e) => {
            const select = e.target.closest('.document-type-select');
            if (!select) return;
            const row = select.closest('.document-row');
            const doc = storedDocs.find((d) => d.document_id === row.dataset.documentId);
            if (!doc) return;

            const data = new FormData();
            data.append('document_id', doc.document_id);
            data.append('requirement_code', select.value);
            select.disabled = true;
            try {
                const res = await fetch(ACTIONS_URL + '?action=set_document_type', { method: 'POST', body: data });
                const result = await parseJsonResponse(res);
                if (!result.success) {
                    setStatus(result.message || 'Could not change the document type.', 'error');
                } else {
                    doc.requirement_code = result.requirement_code || null;
                    row.dataset.requirementCode = doc.requirement_code || '';
                    needsReload = true;
                    setStatus('Document type updated to ' + requirementLabel(doc.requirement_code) + '.', 'success');
                }
            } catch (err) {
                setStatus(err.message || 'Could not change the document type.', 'error');
            } finally {
                select.disabled = false;
                refreshDocumentsUi();
            }
        });
    }

    function renderStagedDocuments() {
        if (!scannedPreview) return;
        scannedPreview.innerHTML = '';
        if (!stagedDocs.length) {
            scannedPreview.innerHTML = '<div class="text-muted document-empty-state">No new file attached yet.</div>';
            return;
        }
        stagedDocs.slice().reverse().forEach((doc) => {
            const index = stagedDocs.indexOf(doc);
            const row = document.createElement('div');
            row.className = 'document-row staged-row';
            row.dataset.index = index;
            row.innerHTML = `
                <div class="document-row-body flex-grow-1">
                    <div class="document-row-title ${doc.requirement_code ? '' : 'text-danger'}">${escapeHtml(doc.requirement_code ? requirementLabel(doc.requirement_code) : 'Unclassified — choose a type below')}</div>
                    <span class="document-name d-block text-muted small">${escapeHtml(doc.file_name)}</span>
                    ${doc.needs_manual_type
                        ? '<select class="form-select form-select-sm document-type-select" aria-label="Document type"></select>'
                        : '<button type="button" class="btn btn-link change-type-link staged-change-type">Change type</button>'}
                </div>
                <div class="document-actions">
                    <button type="button" class="btn btn-sm btn-outline-secondary staged-view-btn" title="Preview file"><i class="bi bi-eye"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger staged-delete-btn" title="Remove this upload"><i class="bi bi-trash"></i></button>
                </div>`;
            if (doc.needs_manual_type) fillTypeSelect(row.querySelector('.document-type-select'), doc.requirement_code, 'staged', index);
            scannedPreview.appendChild(row);
        });
    }

    function removeStagedDocument(index, keepFields) {
        const doc = stagedDocs[index];
        if (!doc) return;
        if (doc.file_path === currentPreviewPath) hideDocumentPreview();
        cleanupStagedFile(doc.file_path, '', '');
        if (!keepFields) clearAppliedFields(doc);
        stagedDocs.splice(index, 1);

        const stillUsed = stagedDocs.some((d) => d.source_hash === doc.source_hash);
        if (!stillUsed) {
            stagedSources.delete(doc.source_hash);
            if (photoOwnerSource === doc.source_hash) releaseStagedPhoto(true);
        }
    }

    function discardAllStaged() {
        while (stagedDocs.length) removeStagedDocument(stagedDocs.length - 1, false);
        releaseStagedPhoto(true);
        renderStagedDocuments();
    }

    if (scannedPreview) {
        scannedPreview.addEventListener('click', (e) => {
            const row = e.target.closest('.staged-row');
            if (!row) return;
            const index = Number(row.dataset.index);
            if (e.target.closest('.staged-delete-btn')) {
                removeStagedDocument(index, false);
                setStatus('', '');
                refreshDocumentsUi();
            } else if (e.target.closest('.staged-view-btn')) {
                openDocumentPreview(stagedDocs[index].file_path, stagedDocs[index].file_name);
            } else if (e.target.closest('.staged-change-type')) {
                stagedDocs[index].needs_manual_type = true;
                refreshDocumentsUi();
            }
        });

        scannedPreview.addEventListener('change', (e) => {
            const select = e.target.closest('.document-type-select');
            if (!select) return;
            const index = Number(select.closest('.staged-row').dataset.index);
            const doc = stagedDocs[index];
            const code = select.value || null;
            if (code && (stagedIndexByCode(code, index) !== -1 || storedByCode(code))) {
                setStatus(requirementLabel(code) + ' is already attached. Use the Replace button on that document to change it.', 'error');
                select.value = doc.requirement_code || '';
                return;
            }
            doc.requirement_code = code;
            doc.manual = true;
            refreshDocumentsUi();
        });
    }

    function resetModalAfterClose() {
        if (isSaving || recordSaved) return;

        discardAllStaged();

        Object.keys(ocrFieldMap).forEach((key) => {
            const input = document.getElementById(ocrFieldMap[key]);
            if (!input) return;
            input.value = originalFieldValues[key];
            input.classList.remove('ocr-filled', 'is-invalid');
        });
        if (trackSelect) trackSelect.value = originalTrack;

        if (replacePhotoInput) replacePhotoInput.value = '';
        const photoImg = document.getElementById('modalStudentPhoto');
        if (photoImg && originalPhotoSrc) photoImg.src = originalPhotoSrc;
        const photoFb = document.getElementById('photoFeedback');
        if (photoFb) photoFb.textContent = '';

        if (addDocumentFeedback) addDocumentFeedback.textContent = '';
        setStatus('', '');

        const infoTab = document.getElementById('edit-info-tab');
        if (infoTab) bootstrap.Tab.getOrCreateInstance(infoTab).show();

        if (needsReload) {
            location.reload();
            return;
        }
        refreshDocumentsUi();
    }

    if (addRecordsModalEl) {
        addRecordsModalEl.addEventListener('hidden.bs.modal', resetModalAfterClose);
        addRecordsModalEl.addEventListener('show.bs.modal', updateSaveButtonState);
    }

    window.addEventListener('pagehide', () => {
        if (recordSaved || isSaving) return;
        const paths = stagedDocs.map((d) => d.file_path);
        if (stagedPhotoPath && stagedPhotoPath.value) paths.push('photo:' + stagedPhotoPath.value);
        paths.forEach((p) => {
            const data = new FormData();
            if (p.indexOf('photo:') === 0) data.append('photo_path', p.slice(6));
            else data.append('file_path', p);
            navigator.sendBeacon(ACTIONS_URL + '?action=delete_staged_document', data);
        });
    });

    function triggerScan() {
        if (uploadInput) uploadInput.click();
    }

    if (scanBtn) scanBtn.addEventListener('click', triggerScan);
    if (uploadFileBtn) uploadFileBtn.addEventListener('click', triggerScan);

    function isPdfFile(file) {
        const extOk = /\.pdf$/i.test(file.name);
        const typeOk = !file.type || file.type === 'application/pdf';
        return extOk && typeOk;
    }

    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            const file = uploadInput.files[0];
            if (!file) return;

            if (!isPdfFile(file)) {
                setStatus('Only PDF files are allowed.', 'error');
                uploadInput.value = '';
                return;
            }

            uploadAndExtract(file);
            uploadInput.value = '';
        });
    }

    async function uploadAndExtract(file) {
        if (addDocumentFeedback) addDocumentFeedback.textContent = '';

        const localHash = await sha256OfFile(file);
        if (localHash && stagedSources.has(localHash)) {
            setStatus('"' + file.name + '" was already uploaded.', 'error');
            return;
        }

        setStatus('Uploading and classifying... please wait.', 'loading');
        toggleSaveDisabled(true);

        const formData = new FormData();
        formData.append('document', file);
        formData.append('student_id', studentId);

        let data;
        try {
            const res = await fetch(ACTIONS_URL + '?action=ocr_extract', { method: 'POST', body: formData });
            data = await parseJsonResponse(res);
        } catch (err) {
            toggleSaveDisabled(false);
            setStatus('Upload failed: ' + err.message, 'error');
            return;
        }
        toggleSaveDisabled(false);

        const modalClosed = addRecordsModalEl && !addRecordsModalEl.classList.contains('show');
        if (!data.success || modalClosed || stagedSources.has(data.source_hash)) {
            (data.documents || []).forEach((d) => cleanupStagedFile(d.file_path, '', ''));
            cleanupStagedFile('', data.image_path || '', data.photo_path || '');
            if (!modalClosed) {
                setStatus(!data.success ? (data.message || 'OCR extraction failed.') : '"' + file.name + '" was already uploaded.', 'error');
            }
            return;
        }

        const accepted = [];
        const rejected = [];
        (data.documents || []).forEach((doc) => {
            doc.classified_by = data.classified_by || '';
            doc.source_hash = data.source_hash;
            doc.manual = false;
            doc.needs_manual_type = !doc.requirement_code;
            const label = requirementLabel(doc.requirement_code);

            if (doc.duplicate_of) {
                cleanupStagedFile(doc.file_path, '', '');
                rejected.push(label + ' is already on file');
                return;
            }
            const sameStagedFile = stagedDocs.find((d) => d.file_hash === doc.file_hash);
            if (sameStagedFile) {
                cleanupStagedFile(doc.file_path, '', '');
                rejected.push(label + ' was already uploaded');
                return;
            }
            if (doc.on_file) {
                cleanupStagedFile(doc.file_path, '', '');
                rejected.push(label + ' is already on file; use its Replace button to change it');
                return;
            }
            if (stagedIndexByCode(doc.requirement_code, -1) !== -1) {
                cleanupStagedFile(doc.file_path, '', '');
                rejected.push(label + ' is already in the new uploads');
                return;
            }
            stagedDocs.push(doc);
            accepted.push(doc);
        });

        if (accepted.length) {
            stagedSources.set(data.source_hash, file.name);
            accepted[0].appliedFields = populateEmptyFields(data.extracted);

            if (data.photo_path && !stagedPhotoPath.value) {
                setServerPhoto(data.photo_path);
                photoOwnerSource = data.source_hash;
            } else if (data.photo_path) {
                cleanupStagedFile('', '', data.photo_path);
            }
        } else {
            cleanupStagedFile('', '', data.photo_path || '');
        }

        if (data.image_path) {
            if (accepted.length && !stagedPhotoPath.value) {
                extractFaceFromScan(data.image_path).finally(() => cleanupStagedFile('', data.image_path, ''));
            } else {
                cleanupStagedFile('', data.image_path, '');
            }
        }

        const parts = [];
        if (accepted.length) {
            const detected = accepted.filter((d) => d.requirement_code);
            const unknown = accepted.length - detected.length;
            if (detected.length) {
                parts.push((detected.length === 1 ? 'Document detected: ' : 'Documents detected: ') + detected.map((d) => requirementLabel(d.requirement_code)).join(', ') + '.');
            }
            if (unknown) {
                parts.push(unknown === 1
                    ? 'The document type could not be identified; please choose it below.'
                    : unknown + ' documents could not be identified; please choose their types below.');
            }
        }
        if (data.skipped_pages && data.skipped_pages.length) {
            parts.push('Skipped info pages: ' + data.skipped_pages.map((p) => 'p' + p.page).join(', ') + '.');
        }
        if (rejected.length) parts.push('Not added (duplicate): ' + rejected.join('; ') + '.');
        (data.warnings || []).forEach((w) => parts.push(w));
        if (accepted.length) parts.push('Review the Documents tab, then click UPDATE.');

        const warn = rejected.length || (data.warnings && data.warnings.length)
            || accepted.some((d) => d.confidence === 'low' || d.confidence === 'none');
        setStatus(parts.join(' '), !accepted.length ? 'error' : warn ? 'warning' : 'success');

        const docsTab = document.getElementById('edit-docs-tab');
        if (accepted.length && docsTab) bootstrap.Tab.getOrCreateInstance(docsTab).show();

        refreshDocumentsUi();
    }

    const replacePhotoBtn   = document.getElementById('replacePhotoBtn');
    const modalStudentPhoto = document.getElementById('modalStudentPhoto');
    const photoFeedback     = document.getElementById('photoFeedback');

    if (replacePhotoBtn && replacePhotoInput) {
        replacePhotoBtn.addEventListener('click', () => replacePhotoInput.click());

        replacePhotoInput.addEventListener('change', () => {
            const file = replacePhotoInput.files[0];
            if (photoFeedback) photoFeedback.textContent = '';
            if (!file) {
                updateSaveButtonState();
                return;
            }

            const allowed = ['image/jpeg', 'image/png'];
            if (!allowed.includes(file.type)) {
                if (photoFeedback) photoFeedback.textContent = 'Please choose a JPG or PNG image.';
                replacePhotoInput.value = '';
                updateSaveButtonState();
                return;
            }

            releaseStagedPhoto(false);
            if (modalStudentPhoto) modalStudentPhoto.src = URL.createObjectURL(file);
            updateSaveButtonState();
        });
    }

    async function parseJsonResponse(res) {
        const raw = await res.text();
        try {
            return JSON.parse(raw);
        } catch (err) {
            console.error('Non-JSON response from server:', raw);
            if (!res.ok) {
                throw new Error(`Server error (HTTP ${res.status}). Check that you're still signed in and try again.`);
            }
            if (/signin\.php|<html/i.test(raw)) {
                throw new Error('Your session may have expired. Please sign in again and retry.');
            }
            throw new Error('Server returned an unexpected response. Please try again.');
        }
    }

    async function parseJsonResponse(res) {
        const raw = await res.text();
        try {
            return JSON.parse(raw);
        } catch (err) {
            console.error('Non-JSON response from server:', raw);
            if (!res.ok) {
                throw new Error(`Server error (HTTP ${res.status}). Check that you're still signed in and try again.`);
            }
            if (/signin\.php|<html/i.test(raw)) {
                throw new Error('Your session may have expired. Please sign in again and retry.');
            }
            throw new Error('Server returned an unexpected response. Please try again.');
        }
    }

    const REQUIRED_FIELDS = [
        { name: 'student_number', label: 'Student Number' },
        { name: 'name',           label: 'Name' },
        { name: 'course',         label: 'Course' },
        { name: 'year_section',   label: 'Year & Section' },
    ];

    function clearRequiredFieldErrors() {
        REQUIRED_FIELDS.forEach((f) => {
            const el = form.elements[f.name];
            if (el) el.classList.remove('is-invalid');
        });
    }

    function collectAndValidateRequiredFields() {
        const values = {};
        const missing = [];

        REQUIRED_FIELDS.forEach((f) => {
            const el = form.elements[f.name];
            const value = (el && el.value ? el.value : '').trim();
            values[f.name] = value;

            if (el) el.classList.toggle('is-invalid', !value);
            if (!value) missing.push(f.label);
        });

        return { values, missing };
    }

    REQUIRED_FIELDS.forEach((f) => {
        const el = form.elements[f.name];
        if (el) {
            el.addEventListener('input', () => {
                if (el.value.trim()) el.classList.remove('is-invalid');
            });
        }
    });

    form.addEventListener('invalid', (e) => {
        const pane = e.target.closest('.tab-pane');
        const tabBtn = pane && document.querySelector(`[data-bs-target="#${pane.id}"]`);
        if (tabBtn && !pane.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
        if (addDocumentFeedback) addDocumentFeedback.textContent = 'Please correct: ' + (e.target.labels && e.target.labels[0] ? e.target.labels[0].textContent.trim() : e.target.name);
    }, true);

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (addDocumentFeedback) addDocumentFeedback.textContent = '';

        const { values, missing } = collectAndValidateRequiredFields();

        if (missing.length > 0) {
            if (addDocumentFeedback) addDocumentFeedback.textContent = 'Please fill in: ' + missing.join(', ') + '.';
            const infoTab = document.getElementById('edit-info-tab');
            if (infoTab) bootstrap.Tab.getOrCreateInstance(infoTab).show();
            return;
        }

        clearRequiredFieldErrors();

        if (!hasUnsavedChanges()) {
            forceCloseModal(addRecordsModalEl);
            return;
        }

        showConfirmModal({
            studentNumber: values.student_number,
            name: values.name,
            course: values.course,
            yearSection: values.year_section,
            docCount: storedDocs.length + stagedDocs.length,
        });
    });

    function showConfirmModal(summary) {
        const modalEl = document.getElementById('confirmSaveModal');
        if (!modalEl) {
            if (window.confirm('Save these changes to the student record?')) submitUpdate();
            return;
        }

        document.getElementById('confirmSummaryNumber').textContent = summary.studentNumber;
        document.getElementById('confirmSummaryName').textContent = summary.name;
        document.getElementById('confirmSummaryCourse').textContent = summary.course || '—';
        document.getElementById('confirmSummarySection').textContent = summary.yearSection || '—';
        document.getElementById('confirmSummaryDocs').textContent = summary.docCount + ' document(s)';
        const trackEl = document.getElementById('confirmSummaryTrack');
        if (trackEl) trackEl.textContent = ADMISSION_TRACKS[currentTrack()] || '—';
        const missingEl = document.getElementById('confirmSummaryMissing');
        if (missingEl) {
            const missingDocs = missingRequiredLabels();
            missingEl.textContent = missingDocs.length ? missingDocs.join(', ') : 'None';
            missingEl.classList.toggle('text-danger', missingDocs.length > 0);
        }

        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        const confirmBtn = document.getElementById('confirmSaveBtn');
        const freshBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(freshBtn, confirmBtn);
        freshBtn.addEventListener('click', () => {
            bsModal.hide();
            submitUpdate();
        });
    }

    function failSave(message) {
        toggleSaveDisabled(false);
        isSaving = false;
        setStatus('', '');
        if (addDocumentFeedback) addDocumentFeedback.textContent = message;
    }

    async function submitUpdate() {
        if (addDocumentFeedback) addDocumentFeedback.textContent = '';
        toggleSaveDisabled(true);
        isSaving = true;
        setStatus('Saving changes...', 'loading');

        const formData = new FormData(form);

        try {
            const infoRes = await fetch(ACTIONS_URL + '?action=update_student_info', {
                method: 'POST',
                body: formData,
            });
            const infoData = await parseJsonResponse(infoRes);

            if (!infoData.success) {
                failSave(infoData.message || 'Failed to update student information.');
                return;
            }

            if (stagedDocs.length) {
                const docFormData = new FormData();
                docFormData.append('student_id', studentId);
                docFormData.append('documents', JSON.stringify(stagedDocs.map((d) => ({
                    file_path: d.file_path,
                    file_name: d.file_name,
                    requirement_code: d.requirement_code || '',
                }))));

                const docRes = await fetch(ACTIONS_URL + '?action=add_documents', {
                    method: 'POST',
                    body: docFormData,
                });
                const docData = await parseJsonResponse(docRes);

                if (!docData.success) {
                    failSave((docData.message || 'The documents failed to save.') + ' Student information was already updated.');
                    const docsTab = document.getElementById('edit-docs-tab');
                    if (docsTab) bootstrap.Tab.getOrCreateInstance(docsTab).show();
                    return;
                }
            }

            recordSaved = true;
            setStatus('Saved!', 'success');
            location.reload();
        } catch (err) {
            failSave(err.message || 'Error updating student record.');
        }
    }

    let activeRowForReplace = null;

    function getRowsForDocument(documentId) {
        const rows = [];
        [documentList, modalDocumentList].forEach((container) => {
            if (!container) return;
            const row = container.querySelector(`.document-row[data-document-id="${documentId}"]`);
            if (row) rows.push(row);
        });
        return rows;
    }

    function performDeleteDocument(documentId, deletedPath) {
        const formDataDel = new FormData();
        formDataDel.append('document_id', documentId);

        fetch(ACTIONS_URL + '?action=delete_document_record', {
            method: 'POST',
            body: formDataDel,
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    const row = modalDocumentList && modalDocumentList.querySelector(`.document-row[data-document-id="${documentId}"]`);
                    if (row) row.remove();
                    if (modalDocumentList && !modalDocumentList.querySelector('.document-row')) {
                        modalDocumentList.innerHTML = '<div class="text-muted document-empty-state">No documents uploaded yet.</div>';
                    }
                    storedDocs = storedDocs.filter((d) => d.document_id !== String(documentId));
                    needsReload = true;
                    if (deletedPath && deletedPath === currentPreviewPath) hideDocumentPreview();
                    refreshDocumentsUi();
                } else {
                    alert(data.message || 'Failed to delete document.');
                }
            })
            .catch(() => alert('Something went wrong deleting the document.'));
    }

    function showConfirmDeleteModal(documentId, fileName, deletedPath) {
        const modalEl = document.getElementById('confirmDeleteDocModal');
        if (!modalEl) {
            if (confirm('Delete this document? This cannot be undone.')) {
                performDeleteDocument(documentId, deletedPath);
            }
            return;
        }

        const nameEl = document.getElementById('confirmDeleteDocName');
        if (nameEl) nameEl.textContent = fileName || 'this document';

        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        const confirmBtn = document.getElementById('confirmDeleteDocBtn');
        const freshBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(freshBtn, confirmBtn);
        freshBtn.addEventListener('click', () => {
            bsModal.hide();
            performDeleteDocument(documentId, deletedPath);
        });
    }

    if (modalDocumentList) {
        modalDocumentList.addEventListener('click', (e) => {
            const deleteBtn = e.target.closest('.document-delete-btn');
            if (deleteBtn) {
                const row = deleteBtn.closest('.document-row');
                const nameEl = row.querySelector('.document-name');
                showConfirmDeleteModal(
                    row.dataset.documentId,
                    nameEl ? nameEl.textContent.trim() : '',
                    row.dataset.filePath
                );
                return;
            }

            const changeTypeLink = e.target.closest('.stored-change-type');
            if (changeTypeLink) {
                const doc = storedDocs.find((d) => d.document_id === changeTypeLink.closest('.document-row').dataset.documentId);
                if (doc) {
                    doc.needs_manual_type = true;
                    refreshStoredRows();
                }
                return;
            }

            const changeBtn = e.target.closest('.document-change-btn');
            if (changeBtn) {
                activeRowForReplace = changeBtn.closest('.document-row');
                const input = document.getElementById('replaceDocumentInput');
                if (input) input.click();
                return;
            }

            const viewBtn = e.target.closest('.document-view-btn');
            if (viewBtn) previewFromRow(viewBtn.closest('.document-row'));
        });
    }

    if (documentList) {
        documentList.addEventListener('click', (e) => {
            const viewBtn = e.target.closest('.document-view-btn');
            if (viewBtn) previewFromRow(viewBtn.closest('.document-row'));
        });
    }

    const replaceDocumentInput = document.getElementById('replaceDocumentInput');
    if (replaceDocumentInput) {
        replaceDocumentInput.addEventListener('change', () => {
            if (!replaceDocumentInput.files.length || !activeRowForReplace) return;

            const file = replaceDocumentInput.files[0];
            if (!isPdfFile(file)) {
                alert('Only PDF files are allowed.');
                replaceDocumentInput.value = '';
                activeRowForReplace = null;
                return;
            }

            const documentId = activeRowForReplace.dataset.documentId;
            const replacedPath = activeRowForReplace.dataset.filePath;
            const formDataRep = new FormData();
            formDataRep.append('document_id', documentId);
            formDataRep.append('document', file);

            setStatus('Uploading replacement... please wait.', 'loading');
            toggleSaveDisabled(true);

            fetch(ACTIONS_URL + '?action=replace_document', {
                method: 'POST',
                body: formDataRep,
            })
                .then(parseJsonResponse)
                .then((data) => {
                    if (data.success) {
                        getRowsForDocument(documentId).forEach((row) => {
                            const nameEl = row.querySelector('.document-name');
                            if (nameEl) nameEl.textContent = data.file_name;

                            const downloadLink = row.querySelector('a[title="Download file"]');
                            if (downloadLink) downloadLink.href = data.file_path;

                            row.dataset.filePath = data.file_path;
                        });
                        const doc = storedDocs.find((d) => d.document_id === String(documentId));
                        if (doc) {
                            doc.file_name = data.file_name;
                            doc.file_path = data.file_path;
                            doc.requirement_code = data.requirement_code || null;
                        }
                        needsReload = true;
                        if (replacedPath && replacedPath === currentPreviewPath) hideDocumentPreview();
                        setStatus(data.warning ? 'Document replaced. ' + data.warning : 'Document replaced.', data.warning ? 'warning' : 'success');
                        refreshDocumentsUi();
                    } else {
                        setStatus(data.message || 'Failed to replace document.', 'error');
                    }
                })
                .catch((err) => setStatus(err.message || 'Something went wrong replacing the document.', 'error'))
                .finally(() => {
                    toggleSaveDisabled(false);
                    replaceDocumentInput.value = '';
                    activeRowForReplace = null;
                });
        });
    }

    refreshDocumentsUi();
});