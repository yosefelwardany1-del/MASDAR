{{-- The script behind .app-ptabs (data-app-tabs): sections shown one at a time, deep-linkable by #id. --}}
@push('scripts')
<script>
    // Tabs without the jump: a click shows its section in place and keeps the address deep-linkable.
    (() => {
        const root = document.querySelector('[data-app-tabs]');
        if (! root) return;
        const panels = [...root.querySelectorAll('[data-app-tab-panel]')];
        const links = [...document.querySelectorAll('[data-app-tab-link]')];
        const panelFor = (hash) => {
            const target = hash && hash.length > 1 ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
            return panels.find((p) => target && (p === target || p.contains(target))) || panels.find((p) => p.id === root.dataset.default);
        };
        const show = (panel) => {
            root.classList.add('is-js');
            panels.forEach((p) => p.classList.toggle('is-active', p === panel));
            links.forEach((a) => {
                const on = a.hash === '#' + panel.id;
                a.classList.toggle('is-active', on && a.classList.contains('app-ptabs__link'));
                if (a.classList.contains('app-ptabs__link')) a.setAttribute('aria-current', on ? 'true' : 'false');
            });
        };
        links.forEach((a) => a.addEventListener('click', (event) => {
            const panel = panelFor(a.hash);
            if (! panel) return;
            event.preventDefault();
            show(panel);
            history.replaceState(null, '', '#' + panel.id);
            const nav = root.querySelector('.app-ptabs__nav');
            if (root.getBoundingClientRect().top < 0 || ! a.classList.contains('app-ptabs__link')) root.scrollIntoView({ block: 'start' });
            if (! a.classList.contains('app-ptabs__link')) nav.querySelector('a[href="#' + panel.id + '"]')?.focus();
        }));
        window.addEventListener('hashchange', () => show(panelFor(location.hash)));
        show(panelFor(location.hash));
    })();
</script>
@endpush
