import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-app-sidebar-toggle]');
    if (toggle || event.target.closest('.app-overlay')) {
        document.body.classList.toggle('app-sidebar-open');
    }
});
