import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-aq-sidebar-toggle]');
    if (toggle || event.target.closest('.aq-overlay')) {
        document.body.classList.toggle('aq-sidebar-open');
    }
});
