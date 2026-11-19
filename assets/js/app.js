document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const submitter = event.submitter;
    const message = (submitter instanceof HTMLElement && submitter.dataset.confirm)
        || form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
        ? event.target.closest('button[type="button"][data-confirm]')
        : null;
    if (!(button instanceof HTMLButtonElement) || window.confirm(button.dataset.confirm || 'Continue with this action?')) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
}, true);

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-portal-menu-toggle]').forEach((toggle) => {
        const sidebar = toggle.closest('.kq-admin-sidebar, .kq-portal-sidebar');
        const navigationId = toggle.getAttribute('aria-controls');
        const navigation = navigationId ? document.getElementById(navigationId) : null;
        if (!(sidebar instanceof HTMLElement) || !(navigation instanceof HTMLElement)) {
            return;
        }

        const menuLabel = toggle.getAttribute('data-menu-label') || 'Navigation menu';
        const setMenuOpen = (isOpen) => {
            sidebar.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', String(isOpen));
            toggle.setAttribute('aria-label', `${isOpen ? 'Close' : 'Open'} ${menuLabel}`);
        };

        toggle.addEventListener('click', () => {
            setMenuOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });
        navigation.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target.closest('a, button') : null;
            if (target instanceof HTMLAnchorElement && target.hash) {
                const sectionId = target.hash.slice(1);
                if (document.getElementById(sectionId)) {
                    navigation.querySelectorAll('a[aria-current="location"]').forEach((link) => {
                        link.removeAttribute('aria-current');
                    });
                    target.setAttribute('aria-current', 'location');
                }
            }
            if (target) {
                setMenuOpen(false);
            }
        });
        document.addEventListener('click', (event) => {
            if (sidebar.classList.contains('is-open') && event.target instanceof Node && !sidebar.contains(event.target)) {
                setMenuOpen(false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setMenuOpen(false);
                toggle.focus();
            }
        });
    });

    const liveClock = document.getElementById('kq-live-clock');
    const liveClockDate = document.getElementById('kq-live-clock-date');
    const liveClockTime = document.getElementById('kq-live-clock-time');
    const serverEpochMs = Number(liveClock?.dataset.serverEpochMs);
    if (liveClock && liveClockDate && liveClockTime && Number.isFinite(serverEpochMs)) {
        const clockStartedAt = performance.now();
        const dateFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric'
        });
        const timeFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
        const updateClock = () => {
            const now = new Date(serverEpochMs + performance.now() - clockStartedAt);
            liveClockDate.textContent = dateFormatter.format(now);
            liveClockTime.textContent = timeFormatter.format(now);
            liveClockDate.dateTime = now.toISOString();
            liveClockTime.dateTime = now.toISOString();
        };
        updateClock();
        window.setInterval(updateClock, 1000);
    }

    document.querySelectorAll('.admin-control-room .admin-nav').forEach((navigation) => {
        const revealTab = (tab) => {
            const navigationBounds = navigation.getBoundingClientRect();
            const tabBounds = tab.getBoundingClientRect();
            if (tabBounds.right > navigationBounds.right - 4) {
                navigation.scrollLeft += tabBounds.right - navigationBounds.right + 4;
            } else if (tabBounds.left < navigationBounds.left + 4) {
                navigation.scrollLeft -= navigationBounds.left - tabBounds.left + 4;
            }
        };

        const currentTab = navigation.querySelector('[aria-current="page"]');
        if (currentTab) revealTab(currentTab);

        navigation.addEventListener('focusin', (event) => {
            if (event.target instanceof HTMLElement && event.target.matches('.admin-nav-tab')) {
                revealTab(event.target);
            }
        });
    });

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

    document.querySelectorAll('.kq-human-check-input').forEach((checkbox) => {
        const check = checkbox;
        const checkCard = check.closest('.kq-human-check');
        const status = checkCard?.querySelector('.kq-human-check-status');
        if (!(check instanceof HTMLInputElement) || !(status instanceof HTMLElement)) {
            return;
        }

        const verifiedInput = checkCard.querySelector('input[name="human_verified"]');
        check.addEventListener('change', () => {
            if (!(verifiedInput instanceof HTMLInputElement)) {
                return;
            }

            if (!check.checked) {
                verifiedInput.value = '0';
                checkCard.classList.remove('is-checking', 'is-verified');
                status.textContent = 'Click to verify';
                return;
            }

            check.disabled = true;
            verifiedInput.value = '0';
            checkCard.classList.remove('is-verified');
            checkCard.classList.add('is-checking');
            status.textContent = 'Verifying…';
            window.setTimeout(() => {
                check.disabled = false;
                verifiedInput.value = '1';
                checkCard.classList.remove('is-checking');
                checkCard.classList.add('is-verified');
                status.textContent = 'Verified';
            }, 900);
        });
    });

    const locSelect = document.getElementById('property-location');
    const latInput = document.getElementById('property-latitude');
    const lngInput = document.getElementById('property-longitude');
    const coordsText = document.getElementById('pin-coords-text');
    const coordsBadge = document.getElementById('pin-coords-badge');
    const recenterBtn = document.getElementById('btn-recenter-municipality');
    const pickerMapContainer = document.getElementById('property-picker-map');

    if (pickerMapContainer instanceof HTMLElement) {
        if (typeof L === 'undefined') {
            if (coordsBadge) coordsBadge.textContent = 'Map could not load';
        } else if (
            locSelect instanceof HTMLSelectElement
            && latInput instanceof HTMLInputElement
            && lngInput instanceof HTMLInputElement
            && coordsText instanceof HTMLElement
            && coordsBadge instanceof HTMLElement
        ) {
            const pickerMap = L.map(pickerMapContainer, {
                scrollWheelZoom: true,
                zoomControl: true
            }).setView([13.7565, 121.0583], 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 18
            }).addTo(pickerMap);

            const pickerPinIcon = L.divIcon({
                className: 'kq-picker-pin',
                html: '<div class="kq-picker-pin-mark">★</div>',
                iconSize: [36, 36],
                iconAnchor: [18, 36],
                popupAnchor: [0, -36]
            });
            let pickerMarker = null;

            const setPin = (latitude, longitude, zoomTo = false) => {
                const fixedLat = Number(latitude).toFixed(6);
                const fixedLng = Number(longitude).toFixed(6);
                latInput.value = fixedLat;
                lngInput.value = fixedLng;
                coordsText.textContent = `${fixedLat}, ${fixedLng}`;
                coordsBadge.textContent = `Exact pin: ${fixedLat}, ${fixedLng}`;
                coordsBadge.className = 'badge text-bg-success text-white fw-bold px-2 py-1';

                if (!pickerMarker) {
                    pickerMarker = L.marker([latitude, longitude], {
                        icon: pickerPinIcon,
                        draggable: true
                    }).addTo(pickerMap);
                    pickerMarker.on('dragend', (event) => {
                        const position = event.target.getLatLng();
                        setPin(position.lat, position.lng);
                        pickerMarker.openPopup();
                    });
                } else {
                    pickerMarker.setLatLng([latitude, longitude]);
                }

                const popup = document.createElement('span');
                popup.textContent = `Exact property pin: ${fixedLat}, ${fixedLng}`;
                pickerMarker.bindPopup(popup);
                if (zoomTo) pickerMap.setView([latitude, longitude], 14, { animate: true });
            };

            const clearPin = () => {
                latInput.value = '';
                lngInput.value = '';
                coordsText.textContent = 'No exact pin selected';
                coordsBadge.textContent = 'Select municipality or click map to pin';
                coordsBadge.className = 'badge text-bg-light border text-dark fw-semibold';
                if (pickerMarker) {
                    pickerMap.removeLayer(pickerMarker);
                    pickerMarker = null;
                }
            };

            const centerOnMunicipality = (zoom = true) => {
                const option = locSelect.options[locSelect.selectedIndex];
                if (!option?.dataset.lat || !option.dataset.lng) return;
                pickerMap.setView(
                    [Number(option.dataset.lat), Number(option.dataset.lng)],
                    zoom ? 14 : 11,
                    { animate: zoom }
                );
            };

            pickerMap.on('click', (event) => setPin(event.latlng.lat, event.latlng.lng));
            locSelect.addEventListener('change', () => {
                clearPin();
                centerOnMunicipality(true);
            });
            recenterBtn?.addEventListener('click', () => centerOnMunicipality(true));
            const initialLatitude = latInput.value.trim();
            const initialLongitude = lngInput.value.trim();
            if (initialLatitude !== '' && initialLongitude !== '') {
                const latitude = Number(initialLatitude);
                const longitude = Number(initialLongitude);
                if (
                    Number.isFinite(latitude)
                    && latitude >= -90
                    && latitude <= 90
                    && Number.isFinite(longitude)
                    && longitude >= -180
                    && longitude <= 180
                ) {
                    setPin(latitude, longitude, true);
                } else {
                    clearPin();
                }
            } else if (initialLatitude !== '' || initialLongitude !== '') {
                clearPin();
            } else if (locSelect.value !== '') {
                centerOnMunicipality(false);
            }
            window.setTimeout(() => pickerMap.invalidateSize(), 250);
        }
    }

    const imageInput = document.getElementById('property-image');
    const imagePreview = document.getElementById('property-image-preview');
    if (imageInput instanceof HTMLInputElement && imagePreview instanceof HTMLImageElement) {
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
    }

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

    // ══════════ THEME TOGGLE SWITCHER ══════════
    const themeBtn = document.getElementById('kqThemeToggle');
    const updateThemeIcon = (theme) => {
        const iconSpan = document.getElementById('themeToggleIcon');
        const nextAction = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        if (iconSpan) {
            if (theme === 'dark') {
                iconSpan.innerHTML = '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
            } else {
                iconSpan.innerHTML = '<svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
            }
        }
        themeBtn?.setAttribute('title', nextAction);
        themeBtn?.setAttribute('aria-label', nextAction);
        themeBtn?.setAttribute('aria-pressed', String(theme === 'dark'));
    };

    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
    updateThemeIcon(currentTheme);

    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const activeTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
            const newTheme = activeTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            try {
                localStorage.setItem('kq_theme', newTheme);
            } catch (e) {}
            updateThemeIcon(newTheme);
        });
    }

    // ══════════ MODAL ISOLATION & VIEWPORT CENTERING ══════════
    // Guarantees modals escape parent CSS Grid, transform, or layout clipping
    document.addEventListener('show.bs.modal', (e) => {
        const modal = e.target;
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });
});

/**
 * Copies contact phone number to clipboard and updates button state.
 */
function copyContactPhoneNumber(number, btn) {
    const cleanNumber = number || '+63 965 803 0933';
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
