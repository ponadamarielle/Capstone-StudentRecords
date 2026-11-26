document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('accountSettingsModal');
    if (!modal) return;
    const tabs = [...modal.querySelectorAll('[data-section]')];
    const panels = [...modal.querySelectorAll('[data-panel]')];
    const form = document.getElementById('changeProfileForm');
    const save = document.getElementById('asSave');
    const cancel = document.getElementById('asCancel');
    const name = document.getElementById('asName');
    const message = document.getElementById('asMessage');
    function blocked() {
        return ['asSave', 'cpSaveBtn', 'ceSaveBtn'].some(id => {
            const el = document.getElementById(id);
            return el.disabled || el.classList.contains('d-none');
        });
    }
    function select(section) {
        tabs.forEach(tab => {
            const active = tab.dataset.section === section;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', String(active));
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.panel !== section; });
    }
    tabs.forEach(tab => tab.addEventListener('click', () => {
        if (!blocked()) select(tab.dataset.section);
    }));
    modal.addEventListener('hide.bs.modal', e => { if (save.disabled) e.preventDefault(); });
    modal.addEventListener('hidden.bs.modal', () => {
        select('profile');
        form.reset();
        message.textContent = '';
        message.classList.remove('is-ok');
    });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (save.disabled) return;
        save.disabled = cancel.disabled = true;
        message.textContent = '';
        message.classList.remove('is-ok');
        try {
            const response = await fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin'});
            if (response.status === 401) { window.location = '../signin.php'; return; }
            const data = await response.json();
            message.textContent = data.message;
            if (data.success) {
                name.value = name.defaultValue = data.name;
                document.getElementById('asDisplayName').textContent = data.name;
                document.querySelectorAll('.navbar-username').forEach(el => el.textContent = data.name);
                document.querySelectorAll('.admin-avatar').forEach(el => el.textContent = data.initials);
                message.classList.add('is-ok');
            }
        } catch (error) {
            message.textContent = 'Your name could not be saved. Please try again.';
        } finally { save.disabled = cancel.disabled = false; }
    });
});
