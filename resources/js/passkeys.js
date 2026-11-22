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
        ? event.target.closest('[data-recovery-codes-action]')
        : null;

    if (!(target instanceof HTMLButtonElement) || target.dataset.recoveryCodesAction !== 'regenerate') {
        return;
    }

    const container = target.closest('[data-recovery-codes]');
    const status = container?.querySelector('[data-recovery-codes-status]');
    const regenerateUrl = container instanceof HTMLElement ? container.dataset.regenerateUrl : null;

    if (!regenerateUrl) {
        return;
    }

    if (!window.confirm('Generate new recovery codes? All existing recovery codes will immediately stop working.')) {
        return;
    }

    const originalLabel = target.textContent ?? '';
    target.disabled = true;

    try {
        const response = await fetch(regenerateUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
        });

        if (!response.ok) {
            throw new Error(response.status === 423
                ? 'Complete a fresh MFA verification before regenerating recovery codes.'
                : 'Recovery codes could not be regenerated. Confirm your password and try again.');
        }

        window.location.reload();
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
