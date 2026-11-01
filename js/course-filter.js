document.addEventListener('DOMContentLoaded', function () {
    ['courseFilterSelect', 'archiveCourseFilter'].forEach(function (id) {
        var select = document.getElementById(id);
        if (select) buildCourseMenu(select);
    });

    function buildCourseMenu(select) {
        var wrap = select.parentElement;
        wrap.classList.add('course-filter');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = select.className + ' course-filter-button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        var label = document.createElement('span');
        label.className = 'course-filter-label';
        button.appendChild(label);

        var menu = document.createElement('div');
        menu.className = 'course-filter-menu';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;

        var items = [];
        Array.prototype.forEach.call(select.options, function (option) {
            var item = document.createElement('div');
            item.className = 'course-filter-option';
            item.setAttribute('role', 'option');
            item.tabIndex = -1;
            item.dataset.value = option.value;
            var text = option.textContent.trim();
            var split = text.indexOf(' - ');
            if (option.value === '') {
                item.classList.add('course-filter-all');
                item.appendChild(document.createTextNode('All courses'));
            } else if (split > 0) {
                var code = document.createElement('span');
                code.className = 'course-filter-code';
                code.textContent = text.slice(0, split);
                var name = document.createElement('span');
                name.className = 'course-filter-name';
                name.textContent = text.slice(split + 3);
                item.appendChild(code);
                item.appendChild(name);
            } else {
                var only = document.createElement('span');
                only.className = 'course-filter-code';
                only.textContent = text;
                item.appendChild(only);
            }
            if (option.selected) {
                item.classList.add('is-selected');
                item.setAttribute('aria-selected', 'true');
            }
            item.addEventListener('click', function () { choose(item); });
            menu.appendChild(item);
            items.push(item);
        });

        var current = select.options[select.selectedIndex];
        label.textContent = current ? current.textContent.trim() : '';
        if (current && current.value) button.title = current.textContent.trim();

        select.classList.add('course-filter-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        wrap.insertBefore(button, select);
        wrap.appendChild(menu);

        function place() {
            var rect = button.getBoundingClientRect();
            var top = rect.bottom + 6;
            menu.style.top = top + 'px';
            menu.style.right = Math.max(16, document.documentElement.clientWidth - rect.right) + 'px';
            menu.style.maxHeight = Math.max(160, Math.min(360, window.innerHeight - top - 16)) + 'px';
        }

        function open() {
            place();
            menu.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            var selected = menu.querySelector('.is-selected') || items[0];
            if (selected) {
                selected.focus();
                selected.scrollIntoView({ block: 'nearest' });
            }
        }

        function close(focusButton) {
            menu.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            if (focusButton) button.focus();
        }

        function choose(item) {
            close(true);
            if (select.value === item.dataset.value) return;
            select.value = item.dataset.value;
            var chosen = select.options[select.selectedIndex];
            label.textContent = chosen ? chosen.textContent.trim() : '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        button.addEventListener('click', function () {
            if (menu.hidden) {
                open();
            } else {
                close(false);
            }
        });

        button.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                open();
            }
        });

        menu.addEventListener('keydown', function (e) {
            var index = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                items[Math.min(items.length - 1, index + 1)].focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                items[Math.max(0, index - 1)].focus();
            } else if (e.key === 'Home') {
                e.preventDefault();
                items[0].focus();
            } else if (e.key === 'End') {
                e.preventDefault();
                items[items.length - 1].focus();
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (index !== -1) choose(items[index]);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close(true);
            } else if (e.key === 'Tab') {
                close(false);
            }
        });

        document.addEventListener('click', function (e) {
            if (!menu.hidden && !wrap.contains(e.target)) close(false);
        });

        window.addEventListener('resize', function () {
            if (!menu.hidden) place();
        });
        window.addEventListener('scroll', function (e) {
            if (!menu.hidden && !menu.contains(e.target)) place();
        }, true);
    }
});
