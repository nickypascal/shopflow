(() => {
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const toggle = document.querySelector('[data-sidebar-toggle]');

    const closeSidebar = () => {
        sidebar?.classList.remove('open');
        overlay?.classList.remove('show');
    };

    toggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
        overlay?.classList.toggle('show');
    });
    overlay?.addEventListener('click', closeSidebar);

    document.querySelectorAll('[data-confirm]').forEach((element) => {
        element.addEventListener('click', (event) => {
            const message = element.getAttribute('data-confirm') || 'Lanjutkan tindakan ini?';
            if (!window.confirm(message)) event.preventDefault();
        });
    });

    const imageInput = document.querySelector('[data-image-input]');
    const preview = document.querySelector('[data-image-preview]');
    imageInput?.addEventListener('change', () => {
        const file = imageInput.files?.[0];
        if (!file || !preview) return;
        const reader = new FileReader();
        reader.addEventListener('load', () => { preview.src = String(reader.result); });
        reader.readAsDataURL(file);
    });
})();
