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

    restrictInput('edit_name', /[^A-Za-z\s.,]/g);
    ['edit_course', 'edit_sex'].forEach((id) => {
        restrictInput(id, /[^A-Za-z\s]/g);
    });

    restrictInput('edit_student_number', /[^0-9A-Za-z\-]/g);
    restrictInput('edit_year_section', /[^0-9\-]/g);
    restrictInput('edit_mobile_number', /[^0-9]/g);

    const uploadInput         = document.getElementById('uploadFileInput');
    const uploadFileBtn       = document.getElementById('uploadFileBtn');
    const scanBtn             = document.querySelector('.scan-document-btn');
    const saveBtn             = document.getElementById('saveDocumentBtn');
    const ocrStatus           = document.getElementById('ocrStatus');
    const stagedFilePath      = document.getElementById('stagedFilePath');
    const stagedFileName      = document.getElementById('stagedFileName');
    const scannedPreview      = document.getElementById('scannedFilePreview');
    const addDocumentFeedback = document.getElementById('addDocumentFeedback');

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

    function hasUnsavedChanges() {
        const fieldsChanged = Object.keys(ocrFieldMap).some((key) => {
            const input = document.getElementById(ocrFieldMap[key]);
            return input && input.value !== originalFieldValues[key];
        });
        const photoChanged = !!(replacePhotoInput && replacePhotoInput.files && replacePhotoInput.files.length > 0)
            || !!(stagedPhotoPath && stagedPhotoPath.value);
        const newDocumentStaged = !!(stagedFilePath && stagedFilePath.value);

        return fieldsChanged || photoChanged || newDocumentStaged;
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

    let stagedImagePath = null;
    let stagedAppliedFields = [];

    const originalPhotoSrc = (document.getElementById('modalStudentPhoto') || {}).src || '';

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

    function renderStagedEmptyState() {
        if (scannedPreview) {
            const stagedPath = scannedPreview.dataset.filePath;
            scannedPreview.innerHTML = '<div class="text-muted document-empty-state">No new file attached yet.</div>';
            delete scannedPreview.dataset.filePath;
            if (stagedPath && stagedPath === currentPreviewPath) hideDocumentPreview();
        }
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

    function renderStagedDocumentRow(fileName, filePath) {
        if (!scannedPreview) return;
        const safeName = escapeHtml(fileName);
        scannedPreview.innerHTML = `
            <div class="document-row">
                <span class="document-name">${safeName}</span>
                <div class="document-actions">
                    ${filePath ? `
                    <button type="button" class="btn btn-sm btn-outline-secondary staged-view-btn" title="Preview file">
                        <i class="bi bi-eye"></i>
                    </button>` : ''}
                    <button type="button" class="btn btn-sm btn-outline-secondary staged-replace-btn" title="Replace scanned document">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger staged-delete-btn" title="Remove scanned document">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
        scannedPreview.dataset.filePath = filePath || '';
    }

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

    function discardStagedDocument() {
        stagedAppliedFields.forEach((fieldId) => {
            const input = document.getElementById(fieldId);
            if (input) {
                input.value = '';
                input.classList.remove('ocr-filled');
            }
        });
        stagedAppliedFields = [];

        cleanupStagedFile(stagedFilePath.value, stagedImagePath);
        releaseStagedPhoto(true);

        stagedFilePath.value = '';
        stagedFileName.value = '';
        stagedImagePath = null;
        renderStagedEmptyState();
        setStatus('', '');
        updateSaveButtonState();
    }

    function resetModalAfterClose() {
        if (isSaving || recordSaved) return;

        if (stagedFilePath.value || stagedImagePath) {
            discardStagedDocument();
        } else {
            releaseStagedPhoto(true);
        }

        Object.keys(ocrFieldMap).forEach((key) => {
            const input = document.getElementById(ocrFieldMap[key]);
            if (!input) return;
            input.value = originalFieldValues[key];
            input.classList.remove('ocr-filled', 'is-invalid');
        });

        if (replacePhotoInput) replacePhotoInput.value = '';
        const photoImg = document.getElementById('modalStudentPhoto');
        if (photoImg && originalPhotoSrc) photoImg.src = originalPhotoSrc;
        const photoFb = document.getElementById('photoFeedback');
        if (photoFb) photoFb.textContent = '';

        if (addDocumentFeedback) addDocumentFeedback.textContent = '';
        setStatus('', '');

        const infoTab = document.getElementById('edit-info-tab');
        if (infoTab) bootstrap.Tab.getOrCreateInstance(infoTab).show();

        updateSaveButtonState();
    }

    if (addRecordsModalEl) {
        addRecordsModalEl.addEventListener('hidden.bs.modal', resetModalAfterClose);
        addRecordsModalEl.addEventListener('show.bs.modal', updateSaveButtonState);
    }

    window.addEventListener('pagehide', () => {
        if (recordSaved || isSaving) return;

        const data = new FormData();
        if (stagedFilePath.value) data.append('file_path', stagedFilePath.value);
        if (stagedImagePath)      data.append('image_path', stagedImagePath);
        if (stagedPhotoPath && stagedPhotoPath.value) data.append('photo_path', stagedPhotoPath.value);

        if (data.has('file_path') || data.has('image_path') || data.has('photo_path')) {
            navigator.sendBeacon(ACTIONS_URL + '?action=delete_staged_document', data);
        }
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

    function uploadAndExtract(file) {
        setStatus('Uploading... please wait.', 'loading');
        toggleSaveDisabled(true);
        if (addDocumentFeedback) addDocumentFeedback.textContent = '';

        if (stagedFilePath.value || stagedImagePath) {
            if (stagedFilePath.value === currentPreviewPath) hideDocumentPreview();
            stagedAppliedFields.forEach((fieldId) => {
                const input = document.getElementById(fieldId);
                if (input) {
                    input.value = '';
                    input.classList.remove('ocr-filled');
                }
            });
            stagedAppliedFields = [];
            cleanupStagedFile(stagedFilePath.value, stagedImagePath);
            releaseStagedPhoto(true);
        }

        const formData = new FormData();
        formData.append('document', file);

        fetch(ACTIONS_URL + '?action=ocr_extract', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                toggleSaveDisabled(false);

                if (addRecordsModalEl && !addRecordsModalEl.classList.contains('show')) {
                    cleanupStagedFile(data.file_path, data.image_path, data.photo_path);
                    return;
                }

                if (data.file_path) {
                    stagedFilePath.value = data.file_path;
                    stagedFileName.value = data.file_name || file.name;
                    stagedImagePath = data.image_path || null;
                    renderStagedDocumentRow(data.file_name || file.name, data.file_path);
                    updateSaveButtonState();
                }

                if (!data.success) {
                    setStatus(data.message || 'OCR extraction failed. You can still save the file as-is.', 'error');
                    stagedAppliedFields = [];
                } else {
                    stagedAppliedFields = populateEmptyFields(data.extracted);
                    setStatus('Please review both tabs before saving.', 'success');
                }

                if (data.photo_path) {
                    setServerPhoto(data.photo_path);
                } else if (data.image_path) {
                    extractFaceFromScan(data.image_path);
                }
            })
            .catch((err) => {
                toggleSaveDisabled(false);
                setStatus('Upload failed: ' + err.message, 'error');
            });
    }

    if (scannedPreview) {
        scannedPreview.addEventListener('click', (e) => {
            if (e.target.closest('.staged-replace-btn')) {
                triggerScan();
            } else if (e.target.closest('.staged-delete-btn')) {
                discardStagedDocument();
            } else if (e.target.closest('.staged-view-btn')) {
                openDocumentPreview(scannedPreview.dataset.filePath, stagedFileName.value);
            }
        });
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

    const documentList      = document.getElementById('documentList');
    const modalDocumentList = document.getElementById('modalDocumentList');

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

        const existingDocsCount = modalDocumentList
            ? modalDocumentList.querySelectorAll('.document-row').length
            : 0;
        const docCount = existingDocsCount + (stagedFilePath.value ? 1 : 0);

        showConfirmModal({
            studentNumber: values.student_number,
            name: values.name,
            course: values.course,
            yearSection: values.year_section,
            docCount,
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

            if (stagedFilePath.value) {
                const docFormData = new FormData();
                docFormData.append('student_id', form.elements['student_id'].value);
                docFormData.append('document_file_path', stagedFilePath.value);
                docFormData.append('document_file_name', stagedFileName.value);

                const docRes = await fetch(ACTIONS_URL + '?action=add_document', {
                    method: 'POST',
                    body: docFormData,
                });
                const docData = await parseJsonResponse(docRes);

                if (!docData.success) {
                    failSave(docData.message || 'Student info was updated, but the document failed to save.');
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

    function showEmptyStateIfNeeded(container) {
        if (container && !container.querySelector('.document-row')) {
            container.insertAdjacentHTML(
                'beforeend',
                '<div class="text-muted document-empty-state">No documents uploaded yet.</div>'
            );
        }
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
                    getRowsForDocument(documentId).forEach((r) => {
                        const parent = r.parentElement;
                        r.remove();
                        showEmptyStateIfNeeded(parent);
                    });
                    if (deletedPath && deletedPath === currentPreviewPath) hideDocumentPreview();
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

            fetch(ACTIONS_URL + '?action=replace_document', {
                method: 'POST',
                body: formDataRep,
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data.success) {
                        getRowsForDocument(documentId).forEach((row) => {
                            const nameEl = row.querySelector('.document-name');
                            if (nameEl) nameEl.textContent = data.file_name;

                            const downloadLink = row.querySelector('a[title="Download file"]');
                            if (downloadLink) downloadLink.href = data.file_path;

                            row.dataset.filePath = data.file_path;
                        });
                        if (replacedPath && replacedPath === currentPreviewPath) hideDocumentPreview();
                    } else {
                        alert(data.message || 'Failed to replace document.');
                    }
                })
                .catch(() => alert('Something went wrong replacing the document.'))
                .finally(() => {
                    replaceDocumentInput.value = '';
                    activeRowForReplace = null;
                });
        });
    }
});