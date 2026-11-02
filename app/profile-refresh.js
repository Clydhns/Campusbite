(() => {
    const content = document.getElementById('profile-content');
    const user = document.body.dataset.profileUser;
    if (!content || !user) return;
    let pending = false;
    let noticeTimer;
    function updateSave() {
        const form = content.querySelector('#student-profile-form');
        if (!form) return;
        const baseline = JSON.parse(form.dataset.profileBaseline);
        const photo = form.querySelector('[data-profile-photo-input]');
        const removePhoto = form.querySelector('[data-profile-photo-remove]');
        const dirty = Object.entries(baseline).some(([name,value]) => form.elements.namedItem(name).value.trim() !== String(value ?? ''))
            || Boolean(photo?.files.length) || Boolean(removePhoto?.checked);
        form.querySelector('#profile-save').disabled = pending || !dirty;
        form.querySelector('#profile-save-state').textContent = pending ? 'Saving your changes...' : (dirty ? 'You have unsaved changes.' : 'Your details are up to date.');
    }
    function notice() {
        clearTimeout(noticeTimer);
        const box = content.querySelector('[data-profile-notice]');
        if (!box) return;
        box.focus({preventScroll:true});
        if (box.dataset.profileNotice === 'success') noticeTimer = setTimeout(() => { box.classList.add('is-leaving'); setTimeout(() => box.remove(),220); },5000);
    }
    content.addEventListener('input', updateSave);
    // Profile photo in Edit details: show the chosen picture in the centre. It is saved with Save changes.
    let previewUrl = '';
    content.addEventListener('change', event => {
        const input = event.target;
        if (input.matches('[data-profile-photo-remove]')) {
            content.querySelector('.profile-photo-edit')?.classList.toggle('is-removing', input.checked);
            updateSave();
            return;
        }
        if (!input.matches('[data-profile-photo-input]')) return;
        const file = input.files[0];
        if (!file) { updateSave(); return; }
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = URL.createObjectURL(file);
        let preview = content.querySelector('[data-profile-photo-preview]');
        if (preview.tagName !== 'IMG') {
            const image = document.createElement('img');
            image.className = 'profile-photo-preview';
            image.alt = '';
            image.dataset.profilePhotoPreview = '';
            preview.replaceWith(image);
            preview = image;
        }
        preview.src = previewUrl;
        content.querySelector('.profile-photo-change').textContent = 'Choose a different photo';
        const remove = content.querySelector('[data-profile-photo-remove]');
        if (remove) { remove.checked = false; content.querySelector('.profile-photo-edit').classList.remove('is-removing'); }
        updateSave();
    });

    // Ask before logging out. Without JavaScript the link logs out directly.
    content.addEventListener('click', event => {
        const dialog = content.querySelector('#logout-dialog');
        if (!dialog || typeof dialog.showModal !== 'function') return;
        if (event.target.closest('[data-logout-confirm]')) {
            event.preventDefault();
            dialog.showModal();
            dialog.querySelector('[data-logout-cancel]').focus();
        } else if (event.target.closest('[data-logout-cancel]') || event.target === dialog) {
            dialog.close();
        }
    });
    content.addEventListener('click', event => {
        const button = event.target.closest('[data-profile-password-toggle]');
        if (!button || pending) return;
        const input = content.querySelector('#current-password');
        const showing = input.type === 'password';
        input.type = showing ? 'text' : 'password';
        button.textContent = showing ? 'Hide' : 'Show';
        button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(showing));
    });
    content.addEventListener('submit', async event => {
        const form = event.target;
        if (form.id !== 'student-profile-form') return;
        event.preventDefault();
        if (pending || form.querySelector('#profile-save').disabled) return;
        const data = new FormData(form);
        pending = true;
        updateSave();
        const inputs = Array.from(form.querySelectorAll('input:not([type=hidden])'));
        inputs.forEach(input => { input.readOnly=true; });
        content.setAttribute('aria-busy','true');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(),15000);
        try {
            const response = await fetch('profile.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store',signal:controller.signal});
            if (!response.ok) throw new Error('Profile request failed');
            const page = new DOMParser().parseFromString(await response.text(),'text/html');
            if (page.body.dataset.profileUser !== user) { location.assign('login.php'); return; }
            const incoming = page.getElementById('profile-content');
            if (!incoming || !incoming.querySelector('#profile-details-title')) throw new Error('Incomplete profile response');
            // A successful save redirects back to the read-only view, so drop ?edit=details from the address.
            if (response.redirected) history.replaceState(null, '', 'profile.php#account-details');
            const top = window.scrollY;
            content.style.minHeight=content.getBoundingClientRect().height+'px';
            content.replaceChildren(...Array.from(incoming.childNodes));
            window.scrollTo(0,top);
            notice();
            requestAnimationFrame(() => { content.style.minHeight=''; });
        } catch (error) {
            content.querySelector('[data-profile-notice]')?.remove();
            const box=document.createElement('div');
            box.className='error-message'; box.dataset.profileNotice='error'; box.tabIndex=-1; box.setAttribute('role','alert');
            box.textContent='We could not confirm the save. Reload your profile to check the latest details before retrying.';
            const link=document.createElement('a'); link.href='profile.php'; link.textContent=' Reload profile'; box.append(link);
            content.prepend(box); notice();
        } finally {
            clearTimeout(timeout); pending=false;
            inputs.forEach(input => { input.readOnly=false; });
            content.setAttribute('aria-busy','false');
            updateSave();
        }
    });
    updateSave(); notice();
})();
