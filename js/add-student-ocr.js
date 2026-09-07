document.addEventListener('DOMContentLoaded', function () {
    const ACTIONS_URL = 'student_directory.php';

    let faceModelsLoaded = false;
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
            faceModelsLoaded = true;
        })();
        return faceModelsPromise;
    }

    loadFaceModels();

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

        const replaceBtnLabel = document.getElementById('replacePhotoBtn');
        if (replaceBtnLabel) {
            replaceBtnLabel.innerHTML = '<i class="bi bi-arrow-repeat"></i> Replace Photo';
        }
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

    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            const file = uploadInput.files[0];
            if (!file) return;
            uploadAndExtract(file);
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
                    if (data.file_path) {
                        addDocument({
                            file_name: data.file_name || file.name,
                            file_path: data.file_path,
                            image_path: data.image_path || '',
                            ocr_text: '',
                            drive_link: data.drive_link || '',
                            drive_file_id: data.drive_file_id || '',
                        });
                    }
                    if (data.image_path) {
                        extractFaceFromScan(data.image_path);
                    }
                    return;
                }

                const docIndex = addDocument({
                    file_name: data.file_name,
                    file_path: data.file_path,
                    image_path: data.image_path || '',
                    ocr_text: data.ocr_text,
                    drive_link: data.drive_link || '',
                    drive_file_id: data.drive_file_id || '',
                });

                uploadedDocuments[docIndex].appliedFields = populateFields(data.extracted);
                setStatus('Please review both tabs before saving.', 'success');

                if (data.image_path) {
                    extractFaceFromScan(data.image_path);
                }
            })
            .catch((err) => {
                toggleSaveDisabled(false);
                setStatus('Upload failed: ' + err.message, 'error');
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
            viewBtn.title = 'View local file';
            viewBtn.addEventListener('click', function () {
                window.open(doc.file_path, '_blank');
            });
        }

        if (doc.drive_link) {
            const driveBtn = document.createElement('button');
            driveBtn.type = 'button';
            driveBtn.className = 'btn btn-sm btn-outline-secondary';
            driveBtn.title = 'View on Google Drive';
            driveBtn.innerHTML = '<i class="bi bi-google"></i>';
            driveBtn.addEventListener('click', function () {
                window.open(doc.drive_link, '_blank');
            });
            const changeBtnRef = node.querySelector('.document-change-btn');
            if (changeBtnRef) changeBtnRef.parentNode.insertBefore(driveBtn, changeBtnRef);
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

        if (doc.file_path || doc.image_path) {
            const formData = new FormData();
            if (doc.file_path) formData.append('file_path', doc.file_path);
            if (doc.image_path && doc.image_path !== doc.file_path) {
                formData.append('image_path', doc.image_path);
            }
            fetch(ACTIONS_URL + '?action=delete_document', {
                method: 'POST',
                body: formData,
            }).catch(function (err) {
                console.error('Failed to delete file:', err);
            });
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
});