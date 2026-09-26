document.addEventListener('DOMContentLoaded', function () {
    const ACTIONS_URL = 'student_directory.php';
    let studentPhotoSet = false;
    let currentPhotoDocIndex = null;
    let recordSaved = false;

    const previewPanel = document.getElementById('documentPreviewPanel');
    const previewFrame = document.getElementById('documentPreviewFrame');
    const previewTitle = document.getElementById('documentPreviewTitle');
    const previewCloseBtn = document.getElementById('documentPreviewCloseBtn');
    const previewExpandBtn = document.getElementById('documentPreviewExpandBtn');
    const previewResizeHandle = document.getElementById('documentPreviewResizeHandle');
    let currentPreviewFilePath = '';

    if (previewResizeHandle && previewPanel) {
        let dragging = false;

        previewResizeHandle.addEventListener('mousedown', function (e) {
            dragging = true;
            previewPanel.classList.add('is-resizing');
            document.body.classList.add('is-resizing-preview');
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            const newWidth = window.innerWidth - e.clientX;
            const clamped = Math.min(Math.max(newWidth, 320), Math.min(900, window.innerWidth - 300));
            document.documentElement.style.setProperty('--doc-preview-width', clamped + 'px');
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            previewPanel.classList.remove('is-resizing');
            document.body.classList.remove('is-resizing-preview');
            document.body.style.userSelect = '';
        });
    }

    if (previewExpandBtn) {
        previewExpandBtn.addEventListener('click', function () {
            if (currentPreviewFilePath) {
                window.open(currentPreviewFilePath, '_blank');
            }
        });
    }

    function openDocumentPreview(filePath, fileName) {
        if (!previewPanel || !previewFrame) {
            window.open(filePath, '_blank');
            return;
        }
        currentPreviewFilePath = filePath;
        previewFrame.src = filePath + (filePath.indexOf('#') === -1 ? '#view=FitH' : '');
        if (previewTitle) previewTitle.textContent = fileName || 'Document Preview';
        previewPanel.classList.add('is-open');
        previewPanel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('has-document-preview');
    }

    function closeDocumentPreview() {
        if (!previewPanel || !previewFrame) return;
        previewPanel.classList.remove('is-open');
        previewPanel.setAttribute('aria-hidden', 'true');
        previewFrame.src = 'about:blank';
        currentPreviewFilePath = '';
        document.body.classList.remove('has-document-preview');
    }

    if (previewCloseBtn) {
        previewCloseBtn.addEventListener('click', closeDocumentPreview);
    }

    const addRecordsModalElForPreview = document.getElementById('addRecordsModal');
    if (addRecordsModalElForPreview) {
        addRecordsModalElForPreview.addEventListener('hidden.bs.modal', closeDocumentPreview);
    }

    function setStudentPhoto(dataUrl) {
        const container = document.getElementById('photoContainer');
        container.innerHTML = `<img src="${dataUrl}" class="photo-img" alt="Student photo">`;

        let hidden = document.getElementById('photoData');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'photoData';
            hidden.name = 'photo';
            document.getElementById('addStudentForm').appendChild(hidden);
        }
        hidden.value = dataUrl;
        studentPhotoSet = true;

        const replaceBtnLabel = document.getElementById('replacePhotoBtn');
        if (replaceBtnLabel) {
            replaceBtnLabel.innerHTML = '<i class="bi bi-arrow-repeat"></i> Replace Photo';
        }
    }

    function resetStudentPhoto() {
        const container = document.getElementById('photoContainer');
        container.innerHTML = `<div class="photo-placeholder">
                        <i class="bi bi-person-circle"></i>
                        <span>Student Photo</span>
                    </div>`;

        const hidden = document.getElementById('photoData');
        if (hidden) hidden.value = '';
        studentPhotoSet = false;
        currentPhotoDocIndex = null;

        const replaceBtnLabel = document.getElementById('replacePhotoBtn');
        if (replaceBtnLabel) {
            replaceBtnLabel.innerHTML = '<i class="bi bi-upload"></i> Upload Photo';
        }
    }

    const form               = document.getElementById('addStudentForm');
    const uploadInput        = document.getElementById('uploadFileInput');
    const uploadFileBtn      = document.getElementById('uploadFileBtn');
    const scanBtn            = document.querySelector('.scan-document-btn');
    const saveBtn            = document.getElementById('saveStudentBtn');
    const ocrStatus          = document.getElementById('ocrStatus');
    const documentsDataInput = document.getElementById('documentsData');
    const documentList       = document.getElementById('documentList');
    const documentEmptyState = document.getElementById('documentEmptyState');
    const documentRowTemplate = document.getElementById('documentRowTemplate');

    const studentNumberInput = document.getElementById('student_number');
    const numberFeedback     = document.getElementById('studentNumberFeedback');

    let uploadedDocuments = [];
    let numberIsDuplicate = false;

    if (!form) return;

    if (uploadFileBtn) {
        uploadFileBtn.addEventListener('click', function () {
            uploadInput.click();
        });
    }

    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            uploadInput.click();
        });
    }

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
                pendingReplaceIndex = null;
                return;
            }

            if (pendingReplaceIndex !== null) {
                const indexToReplace = pendingReplaceIndex;
                pendingReplaceIndex = null;
                replaceDocument(indexToReplace, file);
            } else {
                uploadAndExtract(file);
            }
            uploadInput.value = '';
        });
    }

    function uploadAndExtract(file) {
        setStatus('Uploading... please wait.', 'loading');
        toggleSaveDisabled(true);

        const formData = new FormData();
        formData.append('document', file);

        fetch(ACTIONS_URL + '?action=ocr_extract', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                toggleSaveDisabled(false);

                if (!data.success) {
                    setStatus(data.message || 'OCR extraction failed.', 'error');
                    let docIndex = null;
                    if (data.file_path) {
                        docIndex = addDocument({
                            file_name: data.file_name || file.name,
                            file_path: data.file_path,
                            image_path: data.image_path || '',
                            photo_path: data.photo_path || '',
                            doc_type: data.doc_type || null,
                            ocr_text: '',
                        });
                    }
                    if (data.photo_path && !studentPhotoSet) {
                        setStudentPhoto(data.photo_path);
                        currentPhotoDocIndex = docIndex;
                    }
                    return;
                }

                const docType = data.doc_type || null;

                const docIndex = addDocument({
                    file_name: data.file_name,
                    file_path: data.file_path,
                    image_path: data.image_path || '',
                    photo_path: data.photo_path || '',
                    doc_type: docType,
                    ocr_text: data.ocr_text,
                });

                uploadedDocuments[docIndex].appliedFields = populateFields(data.extracted);
                setStatus('Please review both tabs before saving.', 'success');

                if (data.photo_path && !studentPhotoSet) {
                    setStudentPhoto(data.photo_path);
                    currentPhotoDocIndex = docIndex;
                }
            })
            .catch((err) => {
                toggleSaveDisabled(false);
                setStatus('Upload failed: ' + err.message, 'error');
            });
    }

    function replaceDocument(index, file) {
        const oldDoc = uploadedDocuments[index];
        if (!oldDoc) return;

        setStatus('Uploading replacement... please wait.', 'loading');
        toggleSaveDisabled(true);

        const formData = new FormData();
        formData.append('document', file);

        fetch(ACTIONS_URL + '?action=ocr_extract', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                toggleSaveDisabled(false);

                if (!data.success && !data.file_path) {
                    setStatus(data.message || 'OCR extraction failed.', 'error');
                    return;
                }

                const docType = data.doc_type || null;
                deleteFileSet(oldDoc);

                if (oldDoc.appliedFields && oldDoc.appliedFields.length) {
                    oldDoc.appliedFields.forEach(function (name) {
                        const el = form.elements[name];
                        if (el) el.value = '';
                    });
                    if (oldDoc.appliedFields.indexOf('student_number') !== -1) {
                        numberIsDuplicate = false;
                        if (numberFeedback) numberFeedback.textContent = '';
                        if (studentNumberInput) studentNumberInput.classList.remove('is-invalid');
                    }
                }

                const wasStudentPhotoSource = (currentPhotoDocIndex === index);

                const newDoc = {
                    file_name: data.file_name || file.name,
                    file_path: data.file_path,
                    image_path: data.image_path || '',
                    photo_path: data.photo_path || '',
                    doc_type: docType,
                    ocr_text: data.ocr_text || '',
                };
                newDoc.appliedFields = data.success ? populateFields(data.extracted) : [];

                uploadedDocuments[index] = newDoc;
                syncDocumentsHiddenField();
                rerenderDocumentList();

                if (newDoc.photo_path && (wasStudentPhotoSource || !studentPhotoSet)) {
                    setStudentPhoto(newDoc.photo_path);
                    currentPhotoDocIndex = index;
                } else if (wasStudentPhotoSource && !newDoc.photo_path) {
                    resetStudentPhoto();
                }

                setStatus(
                    data.success ? 'Document replaced. Please review both tabs before saving.' : (data.message || 'Replaced, but OCR could not read the new file.'),
                    data.success ? 'success' : 'error'
                );
            })
            .catch((err) => {
                toggleSaveDisabled(false);
                setStatus('Replace failed: ' + err.message, 'error');
            });
    }

    function populateFields(extracted) {
        const applied = [];
        if (!extracted) return applied;
        Object.keys(extracted).forEach((key) => {
            const value = extracted[key];
            if (!value) return; 
            const el = form.elements[key];
            if (el && !el.value) {
                el.value = value;
                applied.push(key);
            }
        });

        if (extracted.student_number) {
            checkStudentNumber(extracted.student_number);
        }
        return applied;
    }

    function addDocument(doc) {
        uploadedDocuments.push(doc);
        syncDocumentsHiddenField();
        renderDocumentRow(doc, uploadedDocuments.length - 1);
        if (documentEmptyState) documentEmptyState.style.display = 'none';
        return uploadedDocuments.length - 1;
    }

    function renderDocumentRow(doc, index) {
        if (!documentRowTemplate || !documentList) return;
        const node = documentRowTemplate.content.cloneNode(true);
        const row = node.querySelector('.document-row');
        node.querySelector('.document-row-title').textContent = 'Uploaded Document';
        node.querySelector('.document-row-filename').textContent = doc.file_name;

        const viewBtn = node.querySelector('.document-view-btn');
        if (viewBtn) {
            viewBtn.title = 'Preview document';
            viewBtn.addEventListener('click', function () {
                openDocumentPreview(doc.file_path, doc.file_name);
            });
        }

        const changeBtn = node.querySelector('.document-change-btn');
        if (changeBtn) {
            changeBtn.addEventListener('click', function () {
                pendingReplaceIndex = index;
                uploadInput.click();
            });
        }

        const deleteBtn = node.querySelector('.document-delete-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                deleteDocument(index);
            });
        }

        if (row) row.dataset.index = index;
        documentList.appendChild(node);
    }

    function deleteDocument(index) {
        const doc = uploadedDocuments[index];
        if (!doc) return;

        setStatus('', '');

        if (doc.file_path || doc.image_path || doc.photo_path) {
            const formData = new FormData();
            if (doc.file_path) formData.append('file_path', doc.file_path);
            if (doc.image_path && doc.image_path !== doc.file_path) {
                formData.append('image_path', doc.image_path);
            }
            if (doc.photo_path) formData.append('photo_path', doc.photo_path);
            fetch(ACTIONS_URL + '?action=delete_document', {
                method: 'POST',
                body: formData,
            }).catch(function (err) {
                console.error('Failed to delete file:', err);
            });
        }

        if (index === currentPhotoDocIndex) {
            resetStudentPhoto();
        } else if (currentPhotoDocIndex !== null && currentPhotoDocIndex > index) {
            currentPhotoDocIndex -= 1;
        }

        if (doc.appliedFields && doc.appliedFields.length) {
            doc.appliedFields.forEach(function (name) {
                const el = form.elements[name];
                if (el) el.value = '';
            });

            if (doc.appliedFields.indexOf('student_number') !== -1) {
                numberIsDuplicate = false;
                if (numberFeedback) numberFeedback.textContent = '';
                if (studentNumberInput) studentNumberInput.classList.remove('is-invalid');
            }
        }

        uploadedDocuments.splice(index, 1);
        syncDocumentsHiddenField();
        rerenderDocumentList();
    }

    function rerenderDocumentList() {
        if (!documentList) return;
        documentList.innerHTML = '';
        uploadedDocuments.forEach(function (doc, i) {
            renderDocumentRow(doc, i);
        });
        if (documentEmptyState) {
            documentEmptyState.style.display = uploadedDocuments.length === 0 ? '' : 'none';
        }
    }

    let pendingReplaceIndex = null;

    function syncDocumentsHiddenField() {
        if (documentsDataInput) {
            documentsDataInput.value = JSON.stringify(uploadedDocuments);
        }
    }

    if (studentNumberInput) {
        studentNumberInput.addEventListener('blur', function () {
            const value = studentNumberInput.value.trim();
            if (value) checkStudentNumber(value);
        });
    }

    function checkStudentNumber(value) {
        fetch(ACTIONS_URL + '?action=check_student_number&student_number=' + encodeURIComponent(value))
            .then((res) => res.json())
            .then((data) => {
                if (!data.success) return;
                numberIsDuplicate = !!data.exists;
                if (numberFeedback) {
                    numberFeedback.textContent = numberIsDuplicate
                        ? 'This student number already exists.'
                        : '';
                }
                studentNumberInput.classList.toggle('is-invalid', numberIsDuplicate);
            });
    }

    let pendingUploads = 0;

    function toggleSaveDisabled(disabled) {
        if (disabled) {
            pendingUploads++;
        } else {
            pendingUploads = Math.max(0, pendingUploads - 1);
        }
        if (saveBtn) saveBtn.disabled = pendingUploads > 0;
    }

    function setStatus(message, type) {
        if (!ocrStatus) return;
        ocrStatus.textContent = message;
        ocrStatus.className = 'ocr-status ocr-status-' + type;
    }

    const REQUIRED_FIELDS = [
        { name: 'student_number', label: 'Student Number' },
        { name: 'name',           label: 'Name' },
        { name: 'course',         label: 'Course' },
        { name: 'year_section',   label: 'Year & Section' },
    ];

    function clearRequiredFieldErrors() {
        REQUIRED_FIELDS.forEach(function (f) {
            const el = form.elements[f.name];
            if (el) el.classList.remove('is-invalid');
        });
    }

    function collectAndValidateRequiredFields() {
        const values = {};
        const missing = [];

        REQUIRED_FIELDS.forEach(function (f) {
            const el = form.elements[f.name];
            const value = (el && el.value ? el.value : '').trim();
            values[f.name] = value;

            if (el) el.classList.toggle('is-invalid', !value);
            if (!value) missing.push(f.label);
        });

        return { values: values, missing: missing };
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function (e) {
            e.preventDefault();

            const result = collectAndValidateRequiredFields();
            const values = result.values;
            const missing = result.missing;

            if (missing.length > 0) {
                setStatus('Please fill in: ' + missing.join(', ') + '.', 'error');
                const studentInfoTab = document.getElementById('student-info-tab');
                if (studentInfoTab) bootstrap.Tab.getOrCreateInstance(studentInfoTab).show();
                return;
            }

            if (numberIsDuplicate) {
                setStatus('Cannot save: student number already exists.', 'error');
                return;
            }

            clearRequiredFieldErrors();

            showConfirmModal({
                studentNumber: values.student_number,
                name: values.name,
                course: values.course,
                yearSection: values.year_section,
                docCount: uploadedDocuments.length,
            });
        });
    }

    REQUIRED_FIELDS.forEach(function (f) {
        const el = form.elements[f.name];
        if (el) {
            el.addEventListener('input', function () {
                if (el.value.trim()) el.classList.remove('is-invalid');
            });
        }
    });

    function showConfirmModal(summary) {
        const modalEl = document.getElementById('confirmSaveModal');
        if (!modalEl) {
            if (window.confirm('Save this student record?')) submitStudent();
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
        freshBtn.addEventListener('click', function () {
            bsModal.hide();
            submitStudent();
        });
    }

    function submitStudent() {
        setStatus('Saving...', 'loading');
        toggleSaveDisabled(true);

        const formData = new FormData(form);
        formData.set('documents', JSON.stringify(uploadedDocuments));

        fetch(ACTIONS_URL + '?action=add_student', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                toggleSaveDisabled(false);
                if (!data.success) {
                    setStatus(data.message || 'Failed to save student record.', 'error');
                    if (data.duplicate) {
                        numberIsDuplicate = true;
                        studentNumberInput.classList.add('is-invalid');
                    }
                    return;
                }
                setStatus('Saved!', 'success');
                recordSaved = true;
                window.location.reload();
            })
            .catch((err) => {
                toggleSaveDisabled(false);
                setStatus('Save failed: ' + err.message, 'error');
            });
    }

    const replacePhotoBtn   = document.getElementById('replacePhotoBtn');
    const replacePhotoInput = document.getElementById('replacePhotoInput');

    if (replacePhotoBtn && replacePhotoInput) {
        replacePhotoBtn.addEventListener('click', function () {
            replacePhotoInput.click();
        });

        replacePhotoInput.addEventListener('change', function () {
            const file = replacePhotoInput.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (e) {
                setStudentPhoto(e.target.result);
            };
            reader.readAsDataURL(file);
            replacePhotoInput.value = '';
        });
    }

    function deleteFileSet(doc) {
        if (!doc || (!doc.file_path && !doc.image_path && !doc.photo_path)) return;
        const formData = new FormData();
        if (doc.file_path) formData.append('file_path', doc.file_path);
        if (doc.image_path && doc.image_path !== doc.file_path) {
            formData.append('image_path', doc.image_path);
        }
        if (doc.photo_path) formData.append('photo_path', doc.photo_path);
        fetch(ACTIONS_URL + '?action=delete_document', {
            method: 'POST',
            body: formData,
        }).catch(function (err) {
            console.error('Failed to delete file:', err);
        });
    }

    const addRecordsModalEl = document.getElementById('addRecordsModal');
    if (addRecordsModalEl) {
        addRecordsModalEl.addEventListener('hidden.bs.modal', function () {
            if (!recordSaved) {
                uploadedDocuments.forEach(deleteFileSet);
                uploadedDocuments = [];
                syncDocumentsHiddenField();
                rerenderDocumentList();
                resetStudentPhoto();
                form.reset();
                clearRequiredFieldErrors();
                numberIsDuplicate = false;
                if (numberFeedback) numberFeedback.textContent = '';
                setStatus('', '');
            }
            recordSaved = false;
        });
    }
});