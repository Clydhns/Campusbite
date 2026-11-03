(() => {
    'use strict';
    document.addEventListener('click', event => {
        const button=event.target.closest?.('[data-recovery-toggle]');
        if (!button) return;
        const input=document.getElementById(button.getAttribute('aria-controls'));
        if (!input || !['password','text'].includes(input.type)) return;
        const showing=input.type==='password';
        input.type=showing ? 'text' : 'password';
        button.setAttribute('aria-pressed',String(showing));
        button.setAttribute('aria-label',(showing ? 'Hide ' : 'Show ')+button.dataset.passwordLabel);
    });
})();
