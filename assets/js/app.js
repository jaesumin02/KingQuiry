document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const targetInput = document.getElementById(button.dataset.togglePassword);
            if (!(targetInput instanceof HTMLInputElement)) {
                return;
            }

            const shouldShow = targetInput.type === 'password';
            targetInput.type = shouldShow ? 'text' : 'password';
            button.textContent = shouldShow ? 'Hide' : 'Show';
            button.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', String(shouldShow));
        });
    });

    const imageInput = document.getElementById('property-image');
    const imagePreview = document.getElementById('property-image-preview');
    if (!(imageInput instanceof HTMLInputElement) || !(imagePreview instanceof HTMLImageElement)) {
        return;
    }

    let previewUrl = '';
    imageInput.addEventListener('change', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);

        const file = imageInput.files?.[0];
        if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            imagePreview.removeAttribute('src');
            imagePreview.classList.add('d-none');
            return;
        }

        previewUrl = URL.createObjectURL(file);
        imagePreview.src = previewUrl;
        imagePreview.classList.remove('d-none');
    });

    window.addEventListener('pagehide', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
    });
});
