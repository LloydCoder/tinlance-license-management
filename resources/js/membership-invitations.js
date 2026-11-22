const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const responseMessages = {
    401: 'Your session has expired. Sign in again, then retry.',
    403: 'This action is not permitted, or your MFA enrollment is no longer valid.',
    409: 'The invitation changed before this action completed. Refresh the workspace.',
    410: 'This invitation is no longer active. Refresh the workspace.',
    422: 'Some fields are invalid. Check the form and try again.',
    423: 'Confirm your password and complete a fresh MFA verification before changing invitations.',
    429: 'Too many attempts. Wait briefly before trying again.',
    503: 'The invitation service is temporarily unavailable. Try again later.',
};

async function postJson(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(responseMessages[response.status] ?? 'The request could not be completed. Refresh the workspace and try again.');
    }

    return response.status === 204 ? {} : response.json().catch(() => ({}));
}

document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-invitation-form]')) {
        return;
    }

    event.preventDefault();

    const endpoint = form.dataset.endpoint;
    const action = form.dataset.action;
    const status = form.querySelector('[data-form-status]');
    const submit = form.querySelector('button[type="submit"]');

    if (!endpoint || !['create', 'resend', 'revoke'].includes(action ?? '')) {
        if (status) status.textContent = 'This invitation action is not configured.';
        return;
    }

    if (action === 'revoke' && !window.confirm('Cancel this invitation? The pending invitation token will be invalidated.')) {
        return;
    }

    const formData = new FormData(form);
    formData.delete('_token');
    const payload = Object.fromEntries(formData.entries());
    const originalLabel = submit instanceof HTMLButtonElement ? submit.textContent : '';

    if (submit instanceof HTMLButtonElement) {
        submit.disabled = true;
        submit.textContent = 'Working…';
    }

    if (status) status.textContent = 'Submitting securely…';

    try {
        await postJson(endpoint, payload);
        if (status) status.textContent = 'The invitation change was accepted. Refreshing the workspace…';
        window.location.reload();
    } catch (error) {
        if (status) {
            status.textContent = error instanceof Error
                ? error.message
                : 'The invitation action could not be completed.';
        }
    } finally {
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = false;
            submit.textContent = originalLabel ?? '';
        }
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-step-up-totp]')) {
        return;
    }

    event.preventDefault();

    const codeInput = form.querySelector('input[name="code"]');
    const submit = form.querySelector('button[type="submit"]');
    const status = document.querySelector('[data-invitation-status]');

    if (!(codeInput instanceof HTMLInputElement)) return;

    if (submit instanceof HTMLButtonElement) submit.disabled = true;
    if (status) status.textContent = 'Verifying authenticator…';

    try {
        await postJson('/api/v1/security/step-up/totp', { code: codeInput.value.trim() });
        if (status) status.textContent = 'MFA verification succeeded. Refreshing workspace…';
        window.location.reload();
    } catch (error) {
        if (status) {
            status.textContent = error instanceof Error
                ? error.message
                : 'Authenticator verification could not be completed.';
        }
    } finally {
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
    }
});
