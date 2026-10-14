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
    const requirementChecklist = document.getElementById('requirementChecklist');

    const studentNumberInput = document.getElementById('student_number');
    const numberFeedback     = document.getElementById('studentNumberFeedback');

    let uploadedDocuments = [];
    let numberIsDuplicate = false;
    let pendingReplaceIndex = null;

    const uploadedSources = new Map();

    const PAGE_DATA = (function () {
        const el = document.getElementById('documentPageData');
        try { return el && el.dataset.json ? JSON.parse(el.dataset.json) : {}; } catch (e) { console.error('Bad documentPageData', e); return {}; }
    })();
    const REQUIREMENTS = Array.isArray(PAGE_DATA.requirements) ? PAGE_DATA.requirements : [];
    const REQUIREMENT_GROUPS = PAGE_DATA.groups || {};
    const requirementByCode = {};
    REQUIREMENTS.forEach(function (r) { requirementByCode[r.code] = r; });

    const ADMISSION_TRACKS = PAGE_DATA.tracks || {};
    const trackSelect = document.getElementById('admission_track');
    let trackTouched = false;

    function currentTrack() {
        return trackSelect && trackSelect.value ? trackSelect.value : 'shs_current';
    }

    function isRequiredForTrack(r) {
        return Array.isArray(r.required_for) && r.required_for.indexOf(currentTrack()) !== -1;
    }

    function suggestTrackFromDocuments() {
        if (!trackSelect || trackTouched) return;
        const groups = uploadedDocuments.map(function (d) {
            const r = requirementByCode[d.requirement_code];
            return r ? r.group : null;
        });
        let suggested = 'shs_current';
        if (groups.indexOf('als') !== -1) suggested = 'als';
        else if (groups.indexOf('past_graduate') !== -1) suggested = 'shs_past';
        if (trackSelect.value !== suggested && ADMISSION_TRACKS[suggested]) {
            trackSelect.value = suggested;
        }
    }

    if (trackSelect) {
        trackSelect.addEventListener('change', function () {
            trackTouched = true;
            rerenderDocumentList();
        });
    }

    function requirementLabel(code) {
        return code && requirementByCode[code] ? requirementByCode[code].label : 'Unclassified';
    }

    function findDocByRequirement(code, exceptIndex) {
        if (!code) return -1;
        return uploadedDocuments.findIndex(function (d, i) {
            return i !== exceptIndex && d.requirement_code === code;
        });
    }

    function findDocByHash(hash, exceptIndex) {
        if (!hash) return -1;
        return uploadedDocuments.findIndex(function (d, i) {
            return i !== exceptIndex && d.file_hash === hash;
        });
    }

    function duplicateReason(doc, exceptIndex) {
        const sameFile = findDocByHash(doc.file_hash, exceptIndex);
        if (sameFile !== -1) {
            return requirementLabel(doc.requirement_code) + ' was already uploaded';
        }
        const sameReq = findDocByRequirement(doc.requirement_code, exceptIndex);
        if (sameReq !== -1) {
            return requirementLabel(doc.requirement_code) + ' is already attached; use its Replace button to change it';
        }
        return null;
    }

    function forgetSourceIfUnused(sourceHash) {
        if (!sourceHash) return;
        const stillUsed = uploadedDocuments.some(function (d) { return d.source_hash === sourceHash; });
        if (!stillUsed) uploadedSources.delete(sourceHash);
    }

    async function sha256OfFile(file) {
        if (!window.crypto || !window.crypto.subtle) return null;
        try {
            const digest = await window.crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            return Array.from(new Uint8Array(digest)).map(function (b) {
                return b.toString(16).padStart(2, '0');
            }).join('');
        } catch (err) {
            return null;
        }
    }

    if (!form) return;

    function formatYearSection(value, deleting) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 2);
        if (digits.length === 2) return digits[0] + '-' + digits[1];
        if (digits.length === 1) return deleting ? digits : digits + '-';
        return '';
    }

    const yearSectionInput = form.elements['year_section'];
    if (yearSectionInput) {
        yearSectionInput.addEventListener('input', function (e) {
            const deleting = !!(e.inputType && e.inputType.indexOf('delete') === 0);
            const formatted = formatYearSection(yearSectionInput.value, deleting);
            if (formatted !== yearSectionInput.value) yearSectionInput.value = formatted;
            updateGraduationDisplay();
        });
    }

    const PROGRAM_YEARS = 4;
    const graduationDisplay = document.getElementById('graduation_sy_display');
    const graduationNote = document.getElementById('graduationNote');

    function currentSchoolYearEnd() {
        const now = new Date();
        const afterJuly = now.getMonth() > 6;
        return now.getFullYear() + (afterJuly ? 1 : 0);
    }

    function entryYearOf(studentNumber) {
        const match = String(studentNumber || '').match(/^\s*((?:19|20)\d{2})/);
        return match ? parseInt(match[1], 10) : null;
    }

    function clampLevel(level) {
        return Math.max(1, Math.min(PROGRAM_YEARS, level));
    }

    function autoGraduationInfo() {
        const entry = entryYearOf(studentNumberInput ? studentNumberInput.value : '');
        if (entry === null) return null;
        const endYear = entry + PROGRAM_YEARS;
        const current = currentSchoolYearEnd();
        return {
            endYear: endYear,
            level: clampLevel(PROGRAM_YEARS - (endYear - current)),
            graduated: endYear < current,
        };
    }

    function enteredLevel() {
        const match = String(yearSectionInput ? yearSectionInput.value : '').match(/^(\d)/);
        return match ? parseInt(match[1], 10) : null;
    }

    function graduationInfo() {
        const auto = autoGraduationInfo();
        if (!auto) return null;
        const level = enteredLevel();
        if (level === null || level === auto.level) return auto;
        const current = currentSchoolYearEnd();
        return { endYear: current + (PROGRAM_YEARS - clampLevel(level)), level: clampLevel(level), graduated: false };
    }

    function schoolYearLabel(endYear) {
        return 'SY ' + (endYear - 1) + '-' + endYear;
    }

    function updateGraduationDisplay() {
        if (!graduationDisplay) return;
        const info = graduationInfo();
        graduationDisplay.value = info ? schoolYearLabel(info.endYear) : '';
        if (graduationNote) {
            graduationNote.textContent = info && info.graduated
                ? 'This student has already graduated. The record will be moved to Archive as GRADUATE after saving.'
                : '';
        }
    }

    function syncYearFromStudentNumber() {
        const auto = autoGraduationInfo();
        if (auto && yearSectionInput) {
            const sectionMatch = String(yearSectionInput.value || '').match(/^\d-(\d)$/);
            yearSectionInput.value = auto.level + '-' + (sectionMatch ? sectionMatch[1] : '');
            yearSectionInput.classList.remove('is-invalid');
        }
        updateGraduationDisplay();
    }

    if (studentNumberInput) {
        studentNumberInput.addEventListener('input', function () {
            if (!studentNumberInput.value.trim()) {
                numberIsDuplicate = false;
                if (numberFeedback) numberFeedback.textContent = '';
                studentNumberInput.classList.remove('is-invalid');
            }
            if (entryYearOf(studentNumberInput.value) !== null || !studentNumberInput.value) {
                syncYearFromStudentNumber();
            }
        });
    }

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

    function sendOcrRequest(file) {
        const formData = new FormData();
        formData.append('document', file);
        return fetch(ACTIONS_URL + '?action=ocr_extract', {
            method: 'POST',
            body: formData,
        }).then(function (res) { return res.json(); });
    }

    async function uploadAndExtract(file) {
        const localHash = await sha256OfFile(file);
        if (localHash && uploadedSources.has(localHash)) {
            setStatus('"' + file.name + '" was already uploaded.', 'error');
            return;
        }

        setStatus('Uploading and classifying... please wait.', 'loading');
        toggleSaveDisabled(true);

        let data;
        try {
            data = await sendOcrRequest(file);
        } catch (err) {
            toggleSaveDisabled(false);
            setStatus('Upload failed: ' + err.message, 'error');
            return;
        }
        toggleSaveDisabled(false);

        if (!data.success) {
            setStatus(data.message || 'AI extraction failed.', 'error');
            return;
        }

        if (uploadedSources.has(data.source_hash)) {
            (data.documents || []).forEach(deleteFileSet);
            setStatus('"' + file.name + '" was already uploaded.', 'error');
            return;
        }

        const accepted = [];
        const rejected = [];
        (data.documents || []).forEach(function (doc) {
            doc.classified_by = data.classified_by || '';
            doc.source_hash = data.source_hash;
            doc.manual = false;
            doc.needs_manual_type = !doc.requirement_code;
            const reason = duplicateReason(doc, -1);
            if (reason) {
                deleteFileSet(doc);
                rejected.push(reason);
                return;
            }
            accepted.push(addDocument(doc));
        });

        if (accepted.length) {
            uploadedSources.set(data.source_hash, file.name);

            const first = uploadedDocuments[accepted[0]];
            first.appliedFields = populateFields(data.extracted);

            if (!studentPhotoSet) {
                const photoIndex = accepted.find(function (i) { return uploadedDocuments[i].photo_path; });
                if (photoIndex !== undefined) {
                    setStudentPhoto(uploadedDocuments[photoIndex].photo_path);
                    currentPhotoDocIndex = photoIndex;
                }
            }
            syncDocumentsHiddenField();
        }

        const parts = [];
        const detected = accepted.filter(function (i) { return uploadedDocuments[i].requirement_code; });
        const unknown = accepted.length - detected.length;
        if (detected.length) {
            parts.push((detected.length === 1 ? 'Document detected: ' : 'Documents detected: ')
                + detected.map(function (i) { return requirementLabel(uploadedDocuments[i].requirement_code); }).join(', ') + '.');
        }
        if (unknown) {
            parts.push(unknown === 1
                ? 'The document type could not be identified; please choose it in the Documents tab.'
                : unknown + ' documents could not be identified; please choose their types in the Documents tab.');
        }
        if (data.skipped_pages && data.skipped_pages.length) {
            parts.push('Skipped info pages: ' + data.skipped_pages.map(function (s) { return 'p' + s.page; }).join(', ') + '.');
        }
        if (rejected.length) {
            parts.push('Not added (duplicate): ' + rejected.join('; ') + '.');
        }
        (data.warnings || []).forEach(function (w) { parts.push(w); });
        if (accepted.length) {
            parts.push('Please review both tabs before saving.');
        }

        const type = !accepted.length ? 'error'
            : (rejected.length || (data.warnings && data.warnings.length) || accepted.some(function (i) {
                const c = uploadedDocuments[i].confidence;
                return c === 'low' || c === 'none';
            })) ? 'warning' : 'success';
        setStatus(parts.join(' '), type);
    }

    async function replaceDocument(index, file) {
        const oldDoc = uploadedDocuments[index];
        if (!oldDoc) return;

        const localHash = await sha256OfFile(file);
        if (localHash && uploadedSources.has(localHash) && localHash !== oldDoc.source_hash) {
            setStatus('"' + file.name + '" was already uploaded.', 'error');
            return;
        }

        setStatus('Uploading replacement... please wait.', 'loading');
        toggleSaveDisabled(true);

        let data;
        try {
            data = await sendOcrRequest(file);
        } catch (err) {
            toggleSaveDisabled(false);
            setStatus('Replace failed: ' + err.message, 'error');
            return;
        }
        toggleSaveDisabled(false);

        if (!data.success || !data.documents || !data.documents.length) {
            setStatus(data.message || 'AI extraction failed.', 'error');
            return;
        }

        const docs = data.documents;
        let pick = docs.findIndex(function (d) { return d.requirement_code && d.requirement_code === oldDoc.requirement_code; });
        if (pick === -1) pick = 0;
        const newDoc = docs[pick];
        docs.forEach(function (d, i) { if (i !== pick) deleteFileSet(d); });

        newDoc.source_hash = data.source_hash;
        newDoc.classified_by = data.classified_by || '';
        newDoc.manual = false;
        newDoc.needs_manual_type = !newDoc.requirement_code;

        if (oldDoc.requirement_code && newDoc.requirement_code !== oldDoc.requirement_code) {
            deleteFileSet(newDoc);
            setStatus('Cannot replace: the new file is not the same type of document.', 'error');
            return;
        }

        const reason = duplicateReason(newDoc, index);
        if (reason) {
            deleteFileSet(newDoc);
            setStatus('Replacement not used: ' + reason + '.', 'error');
            return;
        }

        deleteFileSet(oldDoc);
        clearAppliedFields(oldDoc);

        const wasStudentPhotoSource = (currentPhotoDocIndex === index);

        uploadedDocuments[index] = newDoc;
        newDoc.appliedFields = populateFields(data.extracted);
        forgetSourceIfUnused(oldDoc.source_hash);
        uploadedSources.set(data.source_hash, file.name);
        syncDocumentsHiddenField();
        rerenderDocumentList();

        if (newDoc.photo_path && (wasStudentPhotoSource || !studentPhotoSet)) {
            setStudentPhoto(newDoc.photo_path);
            currentPhotoDocIndex = index;
        } else if (wasStudentPhotoSource && !newDoc.photo_path) {
            resetStudentPhoto();
        }

        const parts = ['Document replaced.'];
        if (oldDoc.requirement_code !== newDoc.requirement_code) {
            parts.push('New file was classified as ' + requirementLabel(newDoc.requirement_code)
                + ' (was ' + requirementLabel(oldDoc.requirement_code) + ').');
        }
        if (docs.length > 1) {
            parts.push('The PDF contained ' + docs.length + ' documents; only this one was used. Upload the others separately.');
        }
        (data.warnings || []).forEach(function (w) { parts.push(w); });
        parts.push('Please review both tabs before saving.');
        setStatus(parts.join(' '), parts.length > 2 ? 'warning' : 'success');
    }

    function clearAppliedFields(doc) {
        if (!doc.appliedFields || !doc.appliedFields.length) return;
        doc.appliedFields.forEach(function (name) {
            const el = form.elements[name];
            if (el) el.value = '';
        });
        if (doc.appliedFields.indexOf('student_number') !== -1) {
            numberIsDuplicate = false;
            if (numberFeedback) numberFeedback.textContent = '';
            if (studentNumberInput) studentNumberInput.classList.remove('is-invalid');
        }
        updateGraduationDisplay();
    }

    function populateFields(extracted) {
        const applied = [];
        if (!extracted) return applied;
        Object.keys(extracted).forEach((key) => {
            const value = extracted[key];
            if (!value) return;
            const el = form.elements[key];
            if (!el) return;
            if (key === 'year_section') {
                const found = String(value).match(/(\d)\s*-\s*(\d)/);
                const current = String(el.value || '').trim();
                if (!found || /^\d-\d$/.test(current)) return;
                const currentYear = (current.match(/^(\d)/) || [])[1];
                el.value = (currentYear || found[1]) + '-' + found[2];
                el.classList.remove('is-invalid');
                applied.push(key);
                return;
            }
            if (!el.value) {
                el.value = value;
                applied.push(key);
            }
        });

        if (applied.indexOf('student_number') !== -1 && studentNumberInput) {
            studentNumberInput.value = maskStudentNumber(studentNumberInput.value, false);
            checkStudentNumber(studentNumberInput.value);
        } else if (extracted.student_number) {
            checkStudentNumber(extracted.student_number);
        }
        if (applied.indexOf('student_number') !== -1) {
            syncYearFromStudentNumber();
        } else {
            updateGraduationDisplay();
        }
        return applied;
    }

    function addDocument(doc) {
        uploadedDocuments.push(doc);
        suggestTrackFromDocuments();
        syncDocumentsHiddenField();
        rerenderDocumentList();
        return uploadedDocuments.length - 1;
    }

    function buildTypeSelect(select, doc, index) {
        select.innerHTML = '';

        const other = document.createElement('option');
        other.value = '';
        other.textContent = '— Other / unclassified -';
        select.appendChild(other);

        Object.keys(REQUIREMENT_GROUPS).forEach(function (groupKey) {
            const reqs = REQUIREMENTS.filter(function (r) { return r.group === groupKey; });
            if (!reqs.length) return;
            const optgroup = document.createElement('optgroup');
            optgroup.label = REQUIREMENT_GROUPS[groupKey];
            reqs.forEach(function (r) {
                const opt = document.createElement('option');
                opt.value = r.code;
                const takenBy = findDocByRequirement(r.code, index);
                opt.textContent = r.label + (takenBy !== -1 ? ' (already attached)' : '');
                opt.disabled = takenBy !== -1;
                optgroup.appendChild(opt);
            });
            select.appendChild(optgroup);
        });

        select.value = doc.requirement_code || '';

        select.addEventListener('change', function () {
            const code = select.value || null;
            if (code && findDocByRequirement(code, index) !== -1) {
                setStatus(requirementLabel(code) + ' is already attached. Delete or change that file first.', 'error');
                select.value = doc.requirement_code || '';
                return;
            }
            doc.requirement_code = code;
            doc.requirement_label = requirementLabel(code);
            doc.manual = true;
            syncDocumentsHiddenField();
            rerenderDocumentList();
        });
    }

    function renderDocumentRow(doc, index) {
        if (!documentRowTemplate || !documentList) return;
        const node = documentRowTemplate.content.cloneNode(true);
        const row = node.querySelector('.document-row');

        const titleEl = node.querySelector('.document-row-title');
        titleEl.textContent = doc.requirement_code ? requirementLabel(doc.requirement_code) : 'Unclassified - choose a type below';
        if (!doc.requirement_code) titleEl.classList.add('text-danger');

        const pagesText = doc.pages && doc.pages.length ? ' · ' + (doc.pages.length === 1 ? '1 page' : doc.pages.length + ' pages') : '';
        node.querySelector('.document-row-filename').textContent = doc.file_name + pagesText;


        const typeSelect = node.querySelector('.document-type-select');
        if (typeSelect) {
            if (doc.needs_manual_type) {
                buildTypeSelect(typeSelect, doc, index);
            } else {
                const changeLink = document.createElement('button');
                changeLink.type = 'button';
                changeLink.className = 'btn btn-link change-type-link';
                changeLink.textContent = 'Change type';
                changeLink.addEventListener('click', function () {
                    doc.needs_manual_type = true;
                    rerenderDocumentList();
                });
                typeSelect.replaceWith(changeLink);
            }
        }

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


    function completionBadge(complete, title) {
        const badge = document.createElement('span');
        badge.className = 'req-completion ' + (complete ? 'req-completion-complete' : 'req-completion-incomplete');
        if (title) badge.title = title;
        const icon = document.createElement('i');
        icon.className = 'bi ' + (complete ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill');
        badge.appendChild(icon);
        badge.appendChild(document.createTextNode(' ' + (complete ? 'Complete' : 'Incomplete')));
        return badge;
    }

    function renderRequirementChecklist() {
        if (!requirementChecklist) return;
        requirementChecklist.innerHTML = '';
        if (!REQUIREMENTS.length || uploadedDocuments.length === 0) return;

        const required = REQUIREMENTS.filter(isRequiredForTrack);
        const attachedRequired = required.filter(function (r) { return findDocByRequirement(r.code, -1) !== -1; });

        const heading = document.createElement('div');
        heading.className = 'requirement-checklist-heading';
        const missingNow = missingRequiredLabels();
        heading.appendChild(completionBadge(missingNow.length === 0,
            missingNow.length ? 'Missing: ' + missingNow.join(', ') : 'All required documents attached'));
        heading.appendChild(document.createTextNode((ADMISSION_TRACKS[currentTrack()] || 'Requirements') + ': '
            + attachedRequired.length + ' of ' + required.length + ' required documents attached'));
        requirementChecklist.appendChild(heading);

    }

    function missingRequiredLabels() {
        return REQUIREMENTS
            .filter(function (r) { return isRequiredForTrack(r) && findDocByRequirement(r.code, -1) === -1; })
            .map(function (r) { return r.label; });
    }

    function deleteDocument(index) {
        const doc = uploadedDocuments[index];
        if (!doc) return;

        setStatus('', '');
        deleteFileSet(doc);

        if (index === currentPhotoDocIndex) {
            resetStudentPhoto();
        } else if (currentPhotoDocIndex !== null && currentPhotoDocIndex > index) {
            currentPhotoDocIndex -= 1;
        }

        clearAppliedFields(doc);

        uploadedDocuments.splice(index, 1);
        forgetSourceIfUnused(doc.source_hash);
        syncDocumentsHiddenField();
        rerenderDocumentList();
    }

    function rerenderDocumentList() {
        renderRequirementChecklist();
        if (!documentList) return;
        documentList.innerHTML = '';
        for (let i = uploadedDocuments.length - 1; i >= 0; i--) {
            renderDocumentRow(uploadedDocuments[i], i);
        }
        if (documentEmptyState) {
            documentEmptyState.style.display = uploadedDocuments.length === 0 ? '' : 'none';
        }
    }

    function syncDocumentsHiddenField() {
        if (documentsDataInput) {
            documentsDataInput.value = JSON.stringify(uploadedDocuments);
        }
    }

    rerenderDocumentList();

    const STUDENT_NUMBER_FORMAT = /^\d{4}-\d{5}-BN-\d$/;
    const STUDENT_NUMBER_MESSAGE = 'Invalid student number format. Use 0000-00000-BN-0 (e.g. 2021-00123-BN-0).';
    let numberFormatInvalid = false;

    function validateStudentNumberFormat() {
        if (!studentNumberInput) return true;
        const value = studentNumberInput.value.trim().toUpperCase();
        numberFormatInvalid = value !== '' && !STUDENT_NUMBER_FORMAT.test(value);
        if (numberFormatInvalid) {
            if (numberFeedback) numberFeedback.textContent = STUDENT_NUMBER_MESSAGE;
            studentNumberInput.classList.add('is-invalid');
        } else if (!numberIsDuplicate) {
            if (numberFeedback && numberFeedback.textContent === STUDENT_NUMBER_MESSAGE) numberFeedback.textContent = '';
            studentNumberInput.classList.remove('is-invalid');
        }
        return !numberFormatInvalid;
    }

    function maskStudentNumber(value, deleting) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 10);
        let out = digits.slice(0, 4);
        if (digits.length > 4 || (digits.length === 4 && !deleting)) out += '-';
        if (digits.length > 4) out += digits.slice(4, 9);
        if (digits.length > 9 || (digits.length === 9 && !deleting)) out += '-BN-';
        if (digits.length > 9) out += digits.slice(9, 10);
        return out;
    }

    if (studentNumberInput) {
        studentNumberInput.addEventListener('beforeinput', function (e) {
            if (e.data && /[^0-9]/.test(e.data) && e.inputType === 'insertText') {
                e.preventDefault();
            }
        });
        studentNumberInput.addEventListener('input', function (e) {
            const deleting = !!(e.inputType && e.inputType.indexOf('delete') === 0);
            const masked = maskStudentNumber(studentNumberInput.value, deleting);
            if (masked !== studentNumberInput.value) {
                studentNumberInput.value = masked;
                studentNumberInput.setSelectionRange(masked.length, masked.length);
            }
        });
        studentNumberInput.addEventListener('blur', function () {
            const value = studentNumberInput.value.trim();
            if (value && validateStudentNumberFormat()) checkStudentNumber(value);
        });
        studentNumberInput.addEventListener('input', function () {
            if (numberFormatInvalid && STUDENT_NUMBER_FORMAT.test(studentNumberInput.value.trim().toUpperCase())) {
                validateStudentNumberFormat();
            }
        });
    }

    function checkStudentNumber(value) {
        if (!STUDENT_NUMBER_FORMAT.test(String(value).trim().toUpperCase())) {
            validateStudentNumberFormat();
            return;
        }
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
        { name: 'last_name',      label: 'Last Name' },
        { name: 'first_name',     label: 'First Name' },
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

            if (!validateStudentNumberFormat()) {
                setStatus(STUDENT_NUMBER_MESSAGE, 'error');
                const studentInfoTab = document.getElementById('student-info-tab');
                if (studentInfoTab) bootstrap.Tab.getOrCreateInstance(studentInfoTab).show();
                studentNumberInput.focus();
                return;
            }

            if (uploadedDocuments.length === 0) {
                setStatus('Please upload at least one document before saving the student record.', 'error');
                const documentsTab = document.getElementById('documents-tab');
                if (documentsTab) bootstrap.Tab.getOrCreateInstance(documentsTab).show();
                return;
            }

            if (numberIsDuplicate) {
                setStatus('Cannot save: student number already exists.', 'error');
                return;
            }

            clearRequiredFieldErrors();

            showConfirmModal({
                studentNumber: values.student_number,
                name: values.last_name + ', ' + (values.first_name + ' ' + ((form.elements['middle_name'] || {}).value || '')).trim(),
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
        const gradEl = document.getElementById('confirmSummaryGraduation');
        if (gradEl) {
            const info = graduationInfo();
            gradEl.textContent = !info ? '—'
                : schoolYearLabel(info.endYear)
                    + (info.graduated ? ' (already ended: this student will be moved to Archive as GRADUATE after saving)' : '');
            gradEl.classList.toggle('text-danger', !!(info && info.graduated));
        }
        const trackEl = document.getElementById('confirmSummaryTrack');
        if (trackEl) trackEl.textContent = ADMISSION_TRACKS[currentTrack()] || '—';
        const missingEl = document.getElementById('confirmSummaryMissing');
        if (missingEl) {
            const missing = missingRequiredLabels();
            missingEl.textContent = missing.length ? missing.join(', ') : 'None';
            missingEl.classList.toggle('text-danger', missing.length > 0);
        }
        const completionEl = document.getElementById('confirmSummaryCompletion');
        if (completionEl) {
            const missingNow = missingRequiredLabels();
            completionEl.innerHTML = '';
            completionEl.appendChild(completionBadge(missingNow.length === 0));
        }

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
                    if (data.invalid_student_number) {
                        validateStudentNumberFormat();
                    }
                    if (data.duplicate_document) {
                        const documentsTab = document.getElementById('documents-tab');
                        if (documentsTab) bootstrap.Tab.getOrCreateInstance(documentsTab).show();
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
                uploadedSources.clear();
                pendingReplaceIndex = null;
                trackTouched = false;
                syncDocumentsHiddenField();
                rerenderDocumentList();
                resetStudentPhoto();
                form.reset();
                rerenderDocumentList();
                clearRequiredFieldErrors();
                numberIsDuplicate = false;
                if (numberFeedback) numberFeedback.textContent = '';
                updateGraduationDisplay();
                setStatus('', '');
            }
            recordSaved = false;
        });
    }
});