const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const responseMessages = {
    401: 'Your session has expired. Sign in again, then retry.',
    403: 'This action is not permitted, or your MFA enrollment is no longer valid.',
    409: 'The membership or invitation changed before this action completed. Refresh the workspace.',
    410: 'This invitation is no longer active. Refresh the workspace.',
    422: 'Some fields are invalid. Check the form and try again.',
    423: 'Confirm your password and complete a fresh MFA verification before changing invitations.',
    429: 'Too many attempts. Wait briefly before trying again.',
    503: 'The invitation was saved but email delivery failed. Refresh the workspace and retry delivery after mail transport is restored.',
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
        const error = new Error(responseMessages[response.status] ?? 'The request could not be completed. Refresh the workspace and try again.');
        error.status = response.status;
        throw error;
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

    if (!endpoint || !['create', 'resend', 'revoke', 'reinstate'].includes(action ?? '')) {
        if (status) status.textContent = 'This invitation action is not configured.';
        return;
    }

    if (action === 'revoke' && !window.confirm('Cancel this invitation? The pending invitation token will be invalidated.')) {
        return;
    }

    if (action === 'reinstate' && !window.confirm('Reinstate this membership? Previous roles will be removed and a fresh invitation will be sent. The user must accept it before access resumes.')) {
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
        if (status) {
            status.textContent = action === 'reinstate'
                ? 'The membership is invited again. A fresh invitation was sent; refreshing the workspace…'
                : 'The invitation change was accepted. Refreshing the workspace…';
        }
        window.location.reload();
    } catch (error) {
        if (action === 'reinstate' && error instanceof Error && error.status === 503) {
            if (status) status.textContent = 'The membership is invited, but email delivery failed. Refreshing to show the failed invitation and resend controls…';
            window.location.reload();
            return;
        }

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


document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-membership-form]')) {
        return;
    }

    event.preventDefault();

    const action = form.dataset.action;
    const status = form.querySelector('[data-form-status]');
    const submit = form.querySelector('button[type="submit"]');

    if (!['assign-role', 'revoke-role', 'revoke-member'].includes(action ?? '')) {
        if (status) status.textContent = 'This membership action is not configured.';
        return;
    }

    if (action === 'revoke-role' && !window.confirm('Remove this role from the member? The change will be recorded in the membership role history.')) {
        return;
    }

    if (action === 'revoke-member' && !window.confirm('Revoke this membership? The user will lose access to this tenant and the change will be recorded.')) {
        return;
    }

    const formData = new FormData(form);
    formData.delete('_token');
    const payload = Object.fromEntries(formData.entries());
    let endpoint = form.dataset.endpoint;

    if (action === 'assign-role') {
        const roleId = payload.role_id;
        delete payload.role_id;

        if (typeof roleId !== 'string' || roleId.length === 0 || !form.dataset.endpointTemplate) {
            if (status) status.textContent = 'Choose a valid role before submitting.';
            return;
        }

        endpoint = form.dataset.endpointTemplate.replace('{role}', encodeURIComponent(roleId));
    }

    if (!endpoint) {
        if (status) status.textContent = 'This membership action is not configured.';
        return;
    }

    const originalLabel = submit instanceof HTMLButtonElement ? submit.textContent : '';

    if (submit instanceof HTMLButtonElement) {
        submit.disabled = true;
        submit.textContent = 'Working…';
    }

    if (status) status.textContent = 'Submitting securely…';

    try {
        await postJson(endpoint, payload);
        if (status) {
            status.textContent = action === 'assign-role'
                ? 'Role assigned. Refreshing the workspace…'
                : action === 'revoke-role'
                    ? 'Role removed. Refreshing the workspace…'
                    : 'Membership revoked. Refreshing the workspace…';
        }
        window.location.reload();
    } catch (error) {
        if (status) {
            status.textContent = error instanceof Error
                ? error.message
                : 'The membership action could not be completed.';
        }
    } finally {
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = false;
            submit.textContent = originalLabel ?? '';
        }
    }
});
