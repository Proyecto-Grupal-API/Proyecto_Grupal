// Plain state permits isolated tests; pages supply reactive state.
export function useIdempotentAttempt(state = { pending: false, retryable: false }, makeKey = () => crypto.randomUUID()) {
    let snapshot = null;
    let uncertain = false;
    const copy = value => JSON.parse(JSON.stringify(value));
    return {
        state,
        protectNavigation(router, browser, warn) {
            const block = event => {
                if (!state.pending) return;
                event.preventDefault();
                warn();
            };
            // Covers Inertia Link, shared layout links, router.get and reload.
            const removeBefore = router.on('before', block);
            const beforeUnload = event => {
                if (!state.pending) return;
                event.preventDefault();
                event.returnValue = true; // Legacy browser support; text is browser-controlled.
            };
            const navigate = event => {
                // Native history traversal does not emit Inertia's before event.
                // Never claim cancellation for unsupported/non-cancelable events.
                if (event.cancelable) block(event);
            };
            browser.addEventListener('beforeunload', beforeUnload);
            browser.navigation?.addEventListener('navigate', navigate);
            // A prior Inertia visit could otherwise finish after the Axios request starts.
            // This cancels client visits, not financial Axios transport or server work.
            router.cancelAll();
            return () => {
                removeBefore();
                browser.removeEventListener('beforeunload', beforeUnload);
                browser.navigation?.removeEventListener('navigate', navigate);
            };
        },
        async send(build, transport) {
            if (snapshot && !state.retryable) throw new Error('El resultado está pendiente de verificar. Esta operación no permite un reintento seguro.');
            if (!snapshot) {
                // Replay must be explicitly backed by a service contract. Read-only
                // requests are repeatable but do not replay a stored backend result.
                const { retryable = false, readOnly = false, ...request } = build();
                snapshot = copy({ ...request, headers: { ...request.headers, 'Idempotency-Key': makeKey() } });
                state.retryable = retryable || readOnly;
                state.readOnly = readOnly;
                state.pending = true;
            }
            try {
                // Form and transport mutations cannot alter the retained request.
                const response = await transport(copy(snapshot));
                snapshot = null;
                uncertain = false;
                state.pending = false;
                return response;
            } catch (error) {
                // Validation precedes execution in these controllers. A later 422
                // cannot rule out execution of an earlier request with a lost response.
                if (!uncertain && error.response?.status === 422) {
                    snapshot = null;
                    state.pending = false;
                } else {
                    // 409 includes key collisions and in-progress reconciliation;
                    // 503 alone does not establish whether a write was committed.
                    // Even a read-only dependency failure retains the same inputs
                    // for an explicit retry; no error schedules a resend.
                    uncertain = true;
                }
                throw error;
            }
        },
    };
}
