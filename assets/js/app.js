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

    // Handle AJAX password change in Manage Account modal
    const modalPasswordForm = document.getElementById('modalChangePasswordForm');
    if (modalPasswordForm) {
        modalPasswordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = document.getElementById('manageAccountAlert');
            const submitBtn = document.getElementById('modalSubmitPasswordBtn');
            const originalBtnText = submitBtn ? submitBtn.textContent : 'Save Password';

            if (alertBox) {
                alertBox.classList.add('d-none');
                alertBox.classList.remove('alert-danger', 'alert-success');
                alertBox.textContent = '';
            }

            const formData = new FormData(modalPasswordForm);
            const currentPass = (formData.get('current_password') || '').toString();
            const newPass = (formData.get('new_password') || '').toString();
            const confirmPass = (formData.get('confirm_password') || '').toString();

            if (!currentPass || !newPass || !confirmPass) {
                if (alertBox) {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = 'Please fill in all password fields.';
                    alertBox.classList.remove('d-none');
                }
                return;
            }

            if (newPass.length < 8) {
                if (alertBox) {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = 'New password must be at least 8 characters long.';
                    alertBox.classList.remove('d-none');
                }
                return;
            }

            if (newPass !== confirmPass) {
                if (alertBox) {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = 'New password and confirmation password do not match.';
                    alertBox.classList.remove('d-none');
                }
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            try {
                const response = await fetch('change_password.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    if (alertBox) {
                        alertBox.className = 'alert alert-success';
                        alertBox.textContent = data.message || 'Password updated successfully!';
                        alertBox.classList.remove('d-none');
                    }
                    modalPasswordForm.reset();
                } else {
                    if (alertBox) {
                        alertBox.className = 'alert alert-danger';
                        alertBox.textContent = data.error || 'Failed to update password.';
                        alertBox.classList.remove('d-none');
                    }
                }
            } catch (err) {
                if (alertBox) {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = 'An unexpected network error occurred. Please try again.';
                    alertBox.classList.remove('d-none');
                }
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            }
        });

        const manageModal = document.getElementById('manageAccountModal');
        if (manageModal) {
            manageModal.addEventListener('hidden.bs.modal', () => {
                const alertBox = document.getElementById('manageAccountAlert');
                if (alertBox) {
                    alertBox.className = 'alert d-none';
                    alertBox.textContent = '';
                }
                modalPasswordForm.reset();
            });
        }
    }
});

/**
 * Copies contact phone number to clipboard and updates button state.
 */
function copyContactPhoneNumber(number, btn) {
    const cleanNumber = number || '+63 917 123 4567';
    const showSuccess = () => {
        const label = btn.querySelector('.copy-label');
        if (!label) return;
        const originalText = label.textContent;
        label.textContent = 'Copied!';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        setTimeout(() => {
            label.textContent = originalText;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(cleanNumber).then(showSuccess).catch(() => {
            fallbackCopy(cleanNumber, showSuccess);
        });
    } else {
        fallbackCopy(cleanNumber, showSuccess);
    }
}

function fallbackCopy(text, callback) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
        document.execCommand('copy');
        callback();
    } catch (e) {
        console.error('Failed to copy text', e);
    }
    document.body.removeChild(textarea);
}
