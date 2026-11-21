import { Passkeys } from '@laravel/passkeys';

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

Passkeys.configure({
    fetch: {
        credentials: 'same-origin',
        headers: {
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            Accept: 'application/json',
        },
    },
});

document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element
        ? event.target.closest('[data-passkey-action]')
        : null;

    if (!(target instanceof HTMLButtonElement)) {
        return;
    }

    const action = target.dataset.passkeyAction;
    const status = document.querySelector('[data-passkey-status]');
    const originalLabel = target.textContent ?? '';

    target.disabled = true;

    try {
        if (action === 'verify') {
            const response = await Passkeys.verify({ remember: true });

            if (response && typeof response.redirect === 'string') {
                window.location.assign(response.redirect);
            } else {
                window.location.reload();
            }

            return;
        }

        if (action === 'register') {
            const nameInput = document.querySelector('[data-passkey-name]');
            const name = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';

            if (name.length === 0) {
                throw new Error('Enter a name for this passkey.');
            }

            await Passkeys.register({ name });

            if (status) {
                status.textContent = 'Passkey registered successfully.';
            }

            window.location.reload();
        }
    } catch (error) {
        if (status) {
            status.textContent = error instanceof Error
                ? error.message
                : 'The passkey operation could not be completed.';
        }
    } finally {
        target.disabled = false;
        target.textContent = originalLabel;
    }
});
