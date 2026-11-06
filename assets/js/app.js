document.addEventListener('DOMContentLoaded', () => {
    const toggleButtons = document.querySelectorAll('[data-toggle-password]');

    toggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-toggle-password');
            const targetInput = document.getElementById(targetId);

            if (!targetInput) {
                return;
            }

            const shouldShow = targetInput.type === 'password';
            targetInput.type = shouldShow ? 'text' : 'password';
            button.textContent = shouldShow ? 'Hide' : 'Show';
            button.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
        });
    });
});
