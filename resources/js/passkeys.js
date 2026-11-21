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


document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element
        ? event.target.closest('[data-recovery-codes-action]')
        : null;

    if (!(target instanceof HTMLButtonElement)) {
        return;
    }

    const container = target.closest('[data-recovery-codes]');
    const output = container?.querySelector('[data-recovery-codes-output]');
    const status = container?.querySelector('[data-recovery-codes-status]');
    const action = target.dataset.recoveryCodesAction;

    if (!(container instanceof HTMLElement) || !(output instanceof HTMLElement)) {
        return;
    }

    const viewUrl = container.dataset.viewUrl;
    const regenerateUrl = container.dataset.regenerateUrl;

    if (!viewUrl || !regenerateUrl || !['view', 'regenerate'].includes(action ?? '')) {
        return;
    }

    if (action === 'regenerate' && !window.confirm('Regenerate recovery codes? All existing codes will stop working.')) {
        return;
    }

    const originalLabel = target.textContent ?? '';
    target.disabled = true;

    try {
        const headers = {
            Accept: 'application/json',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        };

        if (action === 'regenerate') {
            const regenerateResponse = await fetch(regenerateUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers,
            });

            if (!regenerateResponse.ok) {
                throw new Error('Recovery codes could not be regenerated. Confirm your password and try again.');
            }
        }

        const response = await fetch(viewUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers,
        });

        if (!response.ok) {
            throw new Error('Recovery codes could not be retrieved. Confirm your password and try again.');
        }

        const codes = await response.json();

        if (!Array.isArray(codes) || !codes.every((code) => typeof code === 'string')) {
            throw new Error('The recovery-code response was invalid.');
        }

        output.replaceChildren();

        for (const code of codes) {
            const item = document.createElement('code');
            item.textContent = code;
            output.append(item);
        }

        output.hidden = false;

        if (status) {
            status.textContent = codes.length > 0
                ? 'Recovery codes are visible only in this page session. Store them securely before leaving.'
                : 'No recovery codes are currently available.';
        }
    } catch (error) {
        if (status) {
            status.textContent = error instanceof Error
                ? error.message
                : 'The recovery-code operation could not be completed.';
        }
    } finally {
        target.disabled = false;
        target.textContent = originalLabel;
    }
});
