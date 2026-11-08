document.addEventListener('DOMContentLoaded', function () {
    var dataEl = document.getElementById('accessPageData');
    var DATA = {};
    try { DATA = JSON.parse(dataEl.dataset.json || '{}'); } catch (e) { DATA = {}; }
    var people = Array.isArray(DATA.people) ? DATA.people : [];
    var csrf = DATA.csrf || '';
    var busy = false;

    var rows = document.getElementById('accessRows');
    var flash = document.getElementById('accessFlash');
    var countEl = document.getElementById('accessCount');
    var tabs = document.querySelectorAll('.filter-tab[data-filter]');
    var roleTabs = document.querySelectorAll('.filter-tab[data-role-filter]');
    var filter = 'all';
    var roleFilter = 'all';

    var addModalEl = document.getElementById('giveAccessModal');
    var addModal = bootstrap.Modal.getOrCreateInstance(addModalEl);
    var addForm = document.getElementById('giveAccessForm');
    var nameInput = document.getElementById('accessName');
    var emailInput = document.getElementById('accessEmail');
    var roleSelect = document.getElementById('accessRole');
    var addError = document.getElementById('giveAccessError');
    var addBtn = document.getElementById('giveAccessBtn');

    var confirmEl = document.getElementById('accessConfirmModal');
    var confirmModal = bootstrap.Modal.getOrCreateInstance(confirmEl);

    var EMAIL_VALID = /^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/;
    var NAME_INVALID = /[^\p{L} .'\-]/gu;
    var NAME_VALID = /^\p{L}[\p{L} .'\-]*$/u;
    var NAME_MESSAGE = "Name can only contain letters (including ñ), spaces, hyphens (-), periods (.) and apostrophes (').";
    var EMAIL_MESSAGE = 'Please enter a valid email address (e.g. name@gmail.com).';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    var flashTimer = null;

    function showFlash(message, kind) {
        clearTimeout(flashTimer);
        flash.hidden = !message;
        flash.innerHTML = '';
        if (!message) return;
        if (kind !== 'error' && kind !== 'busy') {
            flashTimer = setTimeout(function () { showFlash(''); }, 5000);
        }
        flash.className = 'um-flash ' + (kind === 'error' ? 'um-flash-error' : kind === 'busy' ? 'um-flash-busy' : 'um-flash-success');
        var icon = kind === 'error' ? 'bi-x-circle-fill' : kind === 'busy' ? 'bi-hourglass-split' : 'bi-check-circle-fill';
        flash.appendChild(el('i', 'bi ' + icon));
        flash.appendChild(el('span', '', message));
    }

    function removedText(ms) {
        var seconds = Math.max(0, Math.floor((Date.now() - ms) / 1000));
        if (seconds < 60) return 'Access removed just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return 'Access removed ' + minutes + (minutes === 1 ? ' min ago' : ' mins ago');
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return 'Access removed ' + hours + (hours === 1 ? ' hr ago' : ' hrs ago');
        var days = Math.floor(hours / 24);
        if (days < 7) return 'Access removed ' + days + (days === 1 ? ' day ago' : ' days ago');
        return 'Access removed on ' + new Date(ms).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function refreshRemovedNotes() {
        rows.querySelectorAll('[data-removed-at]').forEach(function (note) {
            var ms = Number(note.dataset.removedAt);
            if (ms) note.textContent = removedText(ms);
        });
    }

    function button(className, html, id) {
        var b = el('button', className);
        b.type = 'button';
        b.dataset.id = id;
        b.innerHTML = html;
        return b;
    }

    function render() {
        rows.innerHTML = '';
        var shown = people.filter(function (p) {
            return (filter === 'all' || p.status === filter) && (roleFilter === 'all' || p.role === roleFilter);
        });
        shown.forEach(function (p) {
            var active = p.status === 'Active';
            var tr = el('tr');

            var nameCell = el('td');
            var nameWrap = el('div', 'um-name');
            nameWrap.appendChild(el('span', 'um-avatar um-avatar-' + (p.avatar || 0), p.initials || ''));
            nameWrap.appendChild(el('span', '', p.name));
            nameCell.appendChild(nameWrap);
            tr.appendChild(nameCell);

            tr.appendChild(el('td', 'um-email', p.email));
            tr.appendChild(el('td', 'um-role', p.role));

            var statusCell = el('td');
            statusCell.appendChild(el('span', 'um-status ' + (active ? 'um-status-active' : 'um-status-inactive'), active ? 'Active' : 'Inactive'));
            tr.appendChild(statusCell);

            var actions = el('td');
            var wrap = el('div', 'um-actions');
            if (active) {
                wrap.appendChild(button('reset-btn access-reset-btn', '<i class="bi bi-key"></i> Reset password', p.id));
                wrap.appendChild(button('deactivate-btn access-remove-btn', '<i class="bi bi-person-dash"></i> Remove access', p.id));
            } else {
                if (p.removed_at) {
                    var note = el('span', 'um-status-note', removedText(p.removed_at));
                    note.dataset.removedAt = String(p.removed_at);
                    wrap.appendChild(note);
                }
                wrap.appendChild(button('activate-btn access-activate-btn', '<i class="bi bi-person-check"></i> Restore access', p.id));
            }
            actions.appendChild(wrap);
            tr.appendChild(actions);
            rows.appendChild(tr);
        });
        if (!shown.length) {
            var empty = el('tr');
            var cell = el('td', 'um-empty', filter === 'all' && roleFilter === 'all' ? 'No accounts yet. Click ADD USER to add one.' : 'No accounts match the selected filters.');
            cell.colSpan = 5;
            empty.appendChild(cell);
            rows.appendChild(empty);
        }
        countEl.textContent = 'Showing ' + shown.length + ' account' + (shown.length === 1 ? '' : 's');
    }

    async function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf_token', csrf);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        var res = await fetch('access.php', { method: 'POST', body: body });
        var raw = await res.text();
        var data;
        try { data = JSON.parse(raw); } catch (e) {
            throw new Error(/signin\.php|<html/i.test(raw) ? 'Your session may have expired. Please sign in again.' : 'The server returned an unexpected response (HTTP ' + res.status + ').');
        }
        if (data.redirect) {
            window.location.href = data.redirect;
            throw new Error(data.message || 'Please sign in again.');
        }
        if (Array.isArray(data.people)) {
            people = data.people;
            render();
        }
        return data;
    }

    function findPerson(id) {
        return people.find(function (p) { return String(p.id) === String(id); });
    }

    function setFieldError(input, feedbackId, message) {
        input.classList.toggle('is-invalid', !!message);
        document.getElementById(feedbackId).textContent = message || '';
    }

    function setAddError(message) {
        addError.hidden = !message;
        addError.textContent = message || '';
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            filter = tab.dataset.filter;
            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            render();
        });
    });
    roleTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            roleFilter = tab.dataset.roleFilter;
            roleTabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            render();
        });
    });
    nameInput.addEventListener('input', function () {
        var cleaned = nameInput.value.replace(NAME_INVALID, '').replace(/^[ .'\-]+/, '');
        if (cleaned !== nameInput.value) nameInput.value = cleaned;
        if (nameInput.classList.contains('is-invalid')) setFieldError(nameInput, 'accessNameFeedback', '');
    });
    emailInput.addEventListener('input', function () {
        if (emailInput.classList.contains('is-invalid')) setFieldError(emailInput, 'accessEmailFeedback', '');
    });

    addModalEl.addEventListener('show.bs.modal', function () {
        addForm.reset();
        setFieldError(nameInput, 'accessNameFeedback', '');
        setFieldError(emailInput, 'accessEmailFeedback', '');
        setAddError('');
    });
    addModalEl.addEventListener('shown.bs.modal', function () { nameInput.focus(); });
    addModalEl.addEventListener('hide.bs.modal', function (e) { if (busy) e.preventDefault(); });

    addForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (busy) return;
        var name = nameInput.value.trim().replace(/\s+/g, ' ');
        var email = emailInput.value.trim();
        var nameOk = name !== '' && NAME_VALID.test(name);
        var emailOk = EMAIL_VALID.test(email);
        setFieldError(nameInput, 'accessNameFeedback', nameOk ? '' : (name === '' ? 'Please enter a name.' : NAME_MESSAGE));
        setFieldError(emailInput, 'accessEmailFeedback', emailOk ? '' : (email === '' ? 'Please enter an email address.' : EMAIL_MESSAGE));
        if (!nameOk || !emailOk) {
            (nameOk ? emailInput : nameInput).focus();
            return;
        }
        busy = true;
        addBtn.disabled = true;
        addBtn.textContent = 'Sending...';
        setAddError('');
        try {
            var data = await post('add', { name: name, email: email, role: roleSelect.value });
            if (data.success) {
                busy = false;
                addModal.hide();
                showFlash('');
            } else if (data.field === 'email') {
                setFieldError(emailInput, 'accessEmailFeedback', data.message);
            } else if (data.field === 'name') {
                setFieldError(nameInput, 'accessNameFeedback', data.message);
            } else {
                setAddError(data.message || 'The account was not created.');
            }
        } catch (err) {
            setAddError(err.message || 'The account was not created.');
        } finally {
            busy = false;
            addBtn.disabled = false;
            addBtn.innerHTML = '<i class="bi bi-check-lg"></i> Create account';
        }
    });

    var pendingConfirm = null;
    var pendingCancel = null;

    function askConfirm(options) {
        document.getElementById('accessConfirmIcon').className = 'bi ' + options.icon;
        document.getElementById('accessConfirmTitle').textContent = options.title;
        document.getElementById('accessConfirmText').textContent = options.text;
        document.getElementById('accessConfirmBtn').textContent = options.button;
        pendingConfirm = options.onConfirm;
        pendingCancel = options.onCancel || null;
        confirmModal.show();
    }

    document.getElementById('accessConfirmBtn').addEventListener('click', function () {
        var run = pendingConfirm;
        pendingConfirm = null;
        pendingCancel = null;
        confirmModal.hide();
        if (run) run();
    });
    confirmEl.addEventListener('hidden.bs.modal', function () {
        if (pendingCancel) pendingCancel();
        pendingConfirm = null;
        pendingCancel = null;
    });

    async function runAction(action, fields, busyText) {
        busy = true;
        rows.querySelectorAll('button').forEach(function (c) { c.disabled = true; });
        showFlash(busyText, 'busy');
        try {
            var data = await post(action, fields);
            showFlash(data.message || '', data.success ? 'success' : 'error');
        } catch (err) {
            showFlash(err.message || 'Something went wrong. Please try again.', 'error');
            render();
        } finally {
            busy = false;
        }
    }

    rows.addEventListener('click', function (e) {
        if (busy) return;
        var btn = e.target.closest('.access-reset-btn, .access-remove-btn, .access-activate-btn');
        if (!btn) return;
        var person = findPerson(btn.dataset.id);
        if (!person) return;
        if (btn.classList.contains('access-reset-btn')) {
            askConfirm({
                icon: 'bi-key',
                title: 'Reset password?',
                text: 'A new temporary password will be emailed to ' + person.name + ' (' + person.email + '). They must set a new password on their next sign-in.',
                button: 'Reset',
                onConfirm: function () { runAction('reset', { id: person.id }, 'Resetting the password and sending the email... please wait.'); },
            });
        } else if (btn.classList.contains('access-activate-btn')) {
            askConfirm({
                icon: 'bi-person-check',
                title: 'Restore access?',
                text: person.name + ' (' + person.email + ') will be able to sign in again as ' + person.role + '.',
                button: 'Restore access',
                onConfirm: function () { runAction('activate', { id: person.id }, 'Restoring access...'); },
            });
        } else {
            askConfirm({
                icon: 'bi-person-dash',
                title: 'Remove access?',
                text: person.name + ' (' + person.email + ') will be signed out and can no longer sign in.',
                button: 'Remove access',
                onConfirm: function () { runAction('remove', { id: person.id }, 'Removing access...'); },
            });
        }
    });

    render();
    setInterval(refreshRemovedNotes, 30000);
});