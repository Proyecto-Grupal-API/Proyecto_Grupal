import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { useIdempotentAttempt } from '../../resources/js/Composables/useIdempotentAttempt.js';

const failure = (status, code) => Object.assign(new Error('Solicitud rechazada'), status ? { response: { status, data: { message: 'Solicitud rechazada', code } } } : {});
const request = value => ({ retryable: true, method: 'post', url: '/operation', data: { value, lines: [{ amount: 100 }] } });

for (const [status, code] of [[undefined], [500], [503], [409], [409, 'RECONCILIATION_IN_PROGRESS']]) {
    test('retains immutable request after ' + (code || status || 'network failure'), async () => {
        const attempt = useIdempotentAttempt();
        const original = request('original');
        let sent;
        await assert.rejects(attempt.send(() => original, async config => {
            sent = structuredClone(config);
            config.data.lines[0].amount = 999;
            throw failure(status, code);
        }));
        original.data.value = 'edited';
        await attempt.send(() => { throw Error('Must not rebuild'); }, async config => assert.deepEqual(config, sent));
        assert.equal(attempt.state.pending, false);
    });
}
test('initial 422 permits correction with a new key', async () => {
    let sequence = 0;
    const attempt = useIdempotentAttempt(undefined, () => String(++sequence));
    await assert.rejects(attempt.send(() => request('invalid'), async config => {
        assert.equal(config.headers['Idempotency-Key'], '1'); throw failure(422);
    }));
    assert.equal(attempt.state.pending, false);
    await attempt.send(() => request('corrected'), async config => {
        assert.equal(config.headers['Idempotency-Key'], '2'); assert.equal(config.data.value, 'corrected');
    });
});
test('422 after uncertainty cannot release an earlier attempt', async () => {
    const attempt = useIdempotentAttempt();
    let sent;
    await assert.rejects(attempt.send(() => request('original'), async config => { sent = config; throw failure(); }));
    await assert.rejects(attempt.send(() => request('edited'), async () => { throw failure(422); }));
    assert.equal(attempt.state.pending, true);
    await attempt.send(() => request('edited'), async config => assert.deepEqual(config, sent));
});
test('local validation does not create an attempt or call transport', async () => {
    const attempt = useIdempotentAttempt();
    await assert.rejects(attempt.send(() => { throw Error('Invalid amount'); }, () => assert.fail('Unexpected transport')));
    assert.equal(attempt.state.pending, false);
});
test('uncertain endpoints without replay support cannot be resent', async () => {
    const attempt = useIdempotentAttempt();
    await assert.rejects(attempt.send(() => ({ ...request('original'), retryable: false }), async () => { throw failure(); }));
    await assert.rejects(attempt.send(() => request('edited'), () => assert.fail('Unexpected resend')), /reintento seguro/);
    assert.equal(attempt.state.pending, true);
});
test('confirmed success permits a new key and changed payload', async () => {
    let sequence = 0;
    const attempt = useIdempotentAttempt(undefined, () => String(++sequence));
    for (const value of ['one', 'two']) await attempt.send(() => request(value), async config => {
        assert.equal(config.headers['Idempotency-Key'], String(sequence)); assert.equal(config.data.value, value);
    });
    assert.equal(sequence, 2);
});

// Execute the actual page submit handlers with lightweight Vue/Inertia/HTTP doubles.
// No mounting, Laravel process, or database connections are involved.
function page(name) {
    const source = readFileSync(new URL('../../resources/js/Pages/Financial/' + name + '.vue', import.meta.url), 'utf8');
    const script = source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*;$/gm, '');
    const ref = value => ({ value });
    const watchers = [];
    const unmountHooks = [];
    const watch = (source, callback) => {
        const getter = typeof source === 'function' ? source : () => source.value;
        watchers.push({ getter, callback, previous: getter() });
    };
    const reactive = value => new Proxy(value, { set(target, key, next) {
        target[key] = next;
        for (const watcher of watchers) {
            const current = watcher.getter();
            if (current !== watcher.previous) { watcher.previous = current; watcher.callback(current); }
        }
        return true;
    } });
    function events() {
        const listeners = new Map();
        return {
            addEventListener(name, callback) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(callback); },
            removeEventListener(name, callback) { listeners.get(name)?.delete(callback); },
            count(name) { return listeners.get(name)?.size ?? 0; },
            emit(name, properties = {}) {
                const event = { cancelable: true, defaultPrevented: false, preventDefault() { if (this.cancelable) this.defaultPrevented = true; }, ...properties };
                for (const callback of [...(listeners.get(name) ?? [])]) callback(event);
                return event;
            },
        };
    }
    const browser = { ...events(), navigation: events(), alerts: [], alert(message) { this.alerts.push(message); } };
    const visits = [];
    const routerEvents = events();
    const router = {
        cancellations: 0,
        cancelAll() { this.cancellations++; },
        on(name, callback) { routerEvents.addEventListener(name, callback); return () => routerEvents.removeEventListener(name, callback); },
        get(...args) { const event = routerEvents.emit('before'); if (!event.defaultPrevented) visits.push(args); },
        reload(...args) { this.get(...args); },
    };
    const computed = getter => ({ get value() { return getter(); } });
    const calls = [];
    let handler = async () => ({ data: { data: { status: 'COMPLETADA', cash_status: 'DISPONIBLE' } } });
    const axios = async config => { calls.push(structuredClone(config)); return handler(config); };
    axios.get = async () => ({ data: { data: [], meta: { pagination: { current_page: 1 } } } });
    const props = { permissions: { 'reconciliation.view': true }, businessDate: '2026-11-11' };
    const result = new Function('ref', 'reactive', 'computed', 'watch', 'onMounted', 'onBeforeUnmount', 'window', 'defineProps', 'axios', 'route', 'router', 'useIdempotentAttempt', script + '\nreturn { open, submit, form, dialog, busy, attempt, error, leaveWarning, selectWallet: typeof selectWallet === "undefined" ? null : selectWallet, detail: typeof detail === "undefined" ? null : detail };')(
        ref, reactive, computed, watch, () => {}, callback => unmountHooks.push(callback), browser, () => props, axios,
        (name, args) => name + JSON.stringify(args ?? {}), router, useIdempotentAttempt);
    result.dialog.value = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
    return { ...result, calls, browser, router, routerEvents, visits, unmount() { for (const hook of unmountHooks) hook(); watchers.length = 0; }, setHandler(value) { handler = value; } };
}
for (const name of ['Adjustments', 'Controls']) {
    function prepare(p) {
        if (name === 'Adjustments') { p.open('request-purchase', 'Compra'); Object.assign(p.form, { reason: 'Motivo', selectedId: 'purchase-id', walletAmount: '1.00', bonusAmounts: { bonus: '2.00' } }); }
        else { p.open('run'); p.form.wallet_ids = 'wallet-one,wallet-two'; }
    }
    test(name + ': real submit retains request across edits, close/reopen and double submit', async () => {
        const p = page(name); prepare(p);
        let reject;
        p.setHandler(() => new Promise((resolve, fail) => { reject = fail; }));
        const pending = p.submit();
        await p.submit();
        assert.equal(p.calls.length, 1);
        reject(failure()); await pending;
        p.dialog.value.close(); p.open('edit', { id: 'other-target' });
        Object.assign(p.form, { reason: 'changed', walletAmount: '999', bonusAmounts: { bonus: '999' }, wallet_ids: 'different', business_date: '2027-01-01' });
        p.setHandler(async () => ({ data: { data: {} } }));
        await p.submit();
        assert.equal(p.calls.length, 2);
        assert.deepEqual(p.calls[1], p.calls[0]);
        assert.equal(p.attempt.state.pending, false);
    });
    test(name + ': real submit displays 422 and allows corrected data with new key', async () => {
        const p = page(name); prepare(p);
        p.setHandler(async () => { throw failure(422); }); await p.submit();
        assert.equal(p.error.value, 'Solicitud rechazada');
        assert.equal(p.attempt.state.pending, false);
        Object.assign(p.form, { reason: 'corrected', business_date: '2026-11-12' });
        p.setHandler(async () => ({ data: { data: {} } })); await p.submit();
        assert.notEqual(p.calls[1].headers['Idempotency-Key'], p.calls[0].headers['Idempotency-Key']);
        assert.notDeepEqual(p.calls[1].data, p.calls[0].data);
    });
}

for (const [kind, action, retryable] of [
    ['request-refund', '', true], ['request-purchase', '', true],
    ['hold', 'capture', true], ['hold', 'release', true], ['reverse', '', true],
    ['refund', 'complete', true], ['purchase-refund', 'complete', true],
    ['refund', 'approve', false], ['refund', 'reject', false], ['refund', 'recover', false],
    ['purchase-refund', 'approve', false], ['purchase-refund', 'reject', false], ['policy', '', false],
]) {
    test('Adjustments replay contract: ' + kind + '/' + action, async () => {
        const p = page('Adjustments'); p.open(kind, 'Test', { id: 'target' }, action);
        Object.assign(p.form, { reason: 'Reason', amount: '1', walletAmount: '1', maxSeconds: '60' });
        p.setHandler(async () => { throw failure(); }); await p.submit();
        assert.equal(p.attempt.state.retryable, retryable);
        await p.submit();
        assert.equal(p.calls.length, retryable ? 2 : 1);
        if (retryable) assert.deepEqual(p.calls[0], p.calls[1]);
    });
}
for (const [mode, retryable] of [['create', false], ['edit', false], ['status', false], ['run', true], ['evaluate', true]]) {
    test('Controls retry classification: ' + mode, async () => {
        const p = page('Controls'); p.open(mode, { id: 'target', revision: 'revision' }); p.form.amount = '1';
        p.setHandler(async () => { throw failure(); }); await p.submit();
        assert.equal(p.attempt.state.retryable, retryable);
        await p.submit();
        assert.equal(p.calls.length, retryable ? 2 : 1);
        if (retryable) assert.deepEqual(p.calls[0], p.calls[1]);
    });
}

test('Controls resolve: uncertain review is not resent without backend deduplication', async () => {
    const p = page('Controls');
    p.detail.value = { row: { id: 'run-id' } };
    p.open('resolve', { id: 'difference-id' }); p.form.note = 'Reviewed';
    p.setHandler(async () => { throw failure(); });
    await p.submit(); await p.submit();
    assert.equal(p.calls.length, 1);
    assert.equal(p.attempt.state.retryable, false);
});
test('Controls: confirmed failed reconciliation is terminal and does not automatically rerun', async () => {
    const p = page('Controls'); p.open('run');
    p.setHandler(async () => ({ status: 201, data: { data: { status: 'FALLIDA', cash_status: 'NO_DISPONIBLE' } } }));
    await p.submit();
    assert.equal(p.calls.length, 1);
    assert.equal(p.attempt.state.pending, false);
    p.open('run'); await p.submit();
    assert.equal(p.calls.length, 2);
    assert.notEqual(p.calls[0].headers['Idempotency-Key'], p.calls[1].headers['Idempotency-Key']);
});

test('a header alone does not enable replay; unclassified operations fail closed', async () => {
    const attempt = useIdempotentAttempt();
    await assert.rejects(attempt.send(() => ({ method: 'post', url: '/unclassified', data: {}, headers: { 'Idempotency-Key': 'caller-key' } }), async () => { throw failure(); }));
    assert.equal(attempt.state.retryable, false);
    await assert.rejects(attempt.send(() => assert.fail('Unexpected rebuild'), () => assert.fail('Unexpected resend')), /reintento seguro/);
});
test('evaluate is read-only repetition, not backend idempotent replay', async () => {
    const p = page('Controls'); p.open('evaluate'); p.form.amount = '1';
    p.setHandler(async () => { throw failure(503, 'DEPENDENCY_UNAVAILABLE'); });
    await p.submit();
    assert.equal(p.attempt.state.readOnly, true);
    assert.equal(p.attempt.state.pending, true);
    assert.equal(p.error.value, 'Solicitud rechazada');
    p.form.amount = '500';
    p.setHandler(async config => {
        assert.equal('readOnly' in config, false);
        assert.equal('retryable' in config, false);
        return { data: { data: { decision: 'BLOQUEAR' } } };
    });
    await p.submit();
    assert.deepEqual(p.calls[0], p.calls[1]);
    assert.equal(p.detail.value.data.decision, 'BLOQUEAR');
    assert.equal(p.attempt.state.pending, false);
});
for (const name of ['Adjustments', 'Controls']) {
    for (const [status, code] of [[409], [409, 'RECONCILIATION_IN_PROGRESS'], [503], [503, 'DEPENDENCY_UNAVAILABLE']]) {
        test(name + ': ' + status + '/' + code + ' never releases or automatically resends an attempt', async () => {
            const p = page(name);
            if (name === 'Adjustments') { p.open('hold', 'Capture', { id: 'hold-id' }, 'capture'); p.form.reason = 'Reason'; }
            else p.open('run');
            p.setHandler(async () => { throw failure(status, code); });
            await p.submit();
            assert.equal(p.calls.length, 1);
            assert.equal(p.attempt.state.pending, true);
            assert.equal(p.error.value, 'Solicitud rechazada');
            p.dialog.value.close(); p.open('create');
            await p.submit();
            assert.deepEqual(p.calls[0], p.calls[1]);
        });
    }
    test(name + ': a later 422 after an uncertain write retains key, payload and error details', async () => {
        const p = page(name);
        if (name === 'Adjustments') { p.open('refund', 'Complete', { id: 'refund-id' }, 'complete'); p.form.reason = 'Reason'; }
        else p.open('run');
        p.setHandler(async () => { throw failure(); }); await p.submit();
        p.setHandler(async () => { const e = failure(422); e.response.data.errors = { reason: ['Validation detail'] }; throw e; });
        await p.submit();
        assert.equal(p.error.value, 'Validation detail');
        assert.equal(p.attempt.state.pending, true);
        assert.deepEqual(p.calls[0], p.calls[1]);
    });
}

for (const status of [409, 503, 403]) {
    test('non-replayable write stays blocked after HTTP ' + status, async () => {
        const p = page('Controls'); p.open('create'); p.form.amount = '1';
        p.setHandler(async () => { throw failure(status); });
        await p.submit();
        p.dialog.value.close(); p.open('create'); await p.submit();
        assert.equal(p.calls.length, 1);
        assert.equal(p.attempt.state.pending, true);
    });
}
test('initial 422 on a non-replayable write allows correction without reusing its key', async () => {
    const p = page('Controls'); p.open('create'); p.form.amount = '1';
    p.setHandler(async () => { throw failure(422); }); await p.submit();
    assert.equal(p.attempt.state.pending, false);
    p.form.name = 'Corrected';
    p.setHandler(async () => ({ data: { data: {} } })); await p.submit();
    assert.equal(p.calls.length, 2);
    assert.notEqual(p.calls[0].headers['Idempotency-Key'], p.calls[1].headers['Idempotency-Key']);
});
test('remount loses the in-memory attempt: reload safety is explicitly not provided', async () => {
    const before = page('Controls'); before.open('run');
    before.setHandler(async () => { throw failure(); }); await before.submit();
    const after = page('Controls'); after.open('run'); await after.submit();
    assert.equal(before.attempt.state.pending, true);
    assert.notEqual(before.calls[0].headers['Idempotency-Key'], after.calls[0].headers['Idempotency-Key']);
});

for (const name of ['Adjustments', 'Controls']) {
    function preparePending(p) {
        if (name === 'Adjustments') { p.open('hold', 'Capture', { id: 'hold-id' }, 'capture'); p.form.reason = 'Reason'; }
        else p.open('run');
    }
    test(name + ': Inertia links and layout navigation are blocked without discarding the request', async () => {
        const p = page(name); preparePending(p);
        p.setHandler(async () => { throw failure(); }); await p.submit();
        p.dialog.value.close();
        for (const destination of ['financial.dashboard', 'dashboard', 'profile.edit', 'logout']) {
            p.router.get(destination);
            assert.equal(p.visits.length, 0);
        }
        assert.match(p.leaveWarning.value, /pendiente/);
        assert.equal(p.dialog.value.open, true);
        assert.equal(p.attempt.state.pending, true);
        p.setHandler(async () => ({ data: { data: {} } })); await p.submit();
        assert.deepEqual(p.calls[0], p.calls[1]);
        assert.equal(p.routerEvents.count('before'), 0);
        assert.equal(p.browser.count('beforeunload'), 0);
        p.router.get('financial.dashboard');
        assert.ok(p.visits.length > 0);
    });
    test(name + ': navigation is guarded while the request is still in flight', async () => {
        const p = page(name); preparePending(p);
        let fail;
        p.setHandler(() => new Promise((resolve, reject) => { fail = reject; }));
        const submission = p.submit();
        p.router.get('dashboard');
        assert.equal(p.visits.length, 0);
        assert.equal(p.browser.count('beforeunload'), 1);
        assert.equal(p.router.cancellations, 1);
        fail(failure()); await submission;
        assert.equal(p.attempt.state.pending, true);
    });
    test(name + ': native unload requests a browser warning but does not clear pending state', async () => {
        const p = page(name); preparePending(p);
        p.setHandler(async () => { throw failure(); }); await p.submit();
        const event = p.browser.emit('beforeunload');
        assert.equal(event.defaultPrevented, true);
        assert.equal(event.returnValue, true);
        assert.equal(p.attempt.state.pending, true);
        assert.equal(p.calls.length, 1);
        // This asserts the handler contract, not that a real browser shows a dialog.
    });
    test(name + ': cancelable native history navigation is blocked when Navigation API exists', async () => {
        const p = page(name); preparePending(p);
        p.setHandler(async () => { throw failure(); }); await p.submit();
        for (const direction of ['back', 'forward']) {
            const event = p.browser.navigation.emit('navigate', { navigationType: 'traverse', direction });
            assert.equal(event.defaultPrevented, true);
        }
        assert.match(p.leaveWarning.value, /pendiente/);
        assert.equal(p.attempt.state.pending, true);
    });
    test(name + ': non-cancelable history and Vue unmount are acknowledged limits', async () => {
        const p = page(name); preparePending(p);
        p.setHandler(async () => { throw failure(); }); await p.submit();
        const event = p.browser.navigation.emit('navigate', { cancelable: false, navigationType: 'traverse' });
        assert.equal(event.defaultPrevented, false);
        p.unmount();
        assert.equal(p.browser.alerts.length, 1);
        assert.equal(p.attempt.state.pending, true); // cleanup never resets the retained request
        assert.equal(p.browser.count('beforeunload'), 0);
        assert.equal(p.browser.navigation.count('navigate'), 0);
        assert.equal(p.routerEvents.count('before'), 0);
        // No claim that alert can cancel unmount or survive actual document destruction.
    });
    test(name + ': guards are absent before a submission and removed after initial 422', async () => {
        const p = page(name);
        assert.equal(p.browser.count('beforeunload'), 0);
        p.router.get('dashboard'); assert.equal(p.visits.length, 1);
        preparePending(p);
        p.setHandler(async () => { throw failure(422); }); await p.submit();
        assert.equal(p.attempt.state.pending, false);
        assert.equal(p.browser.count('beforeunload'), 0);
        assert.equal(p.routerEvents.count('before'), 0);
        p.unmount(); assert.equal(p.browser.alerts.length, 0);
    });
    test(name + ': missing Navigation API still blocks Inertia and installs beforeunload', async () => {
        const p = page(name); delete p.browser.navigation; preparePending(p);
        p.setHandler(async () => { throw failure(); }); await p.submit();
        p.router.get('dashboard'); assert.equal(p.visits.length, 0);
        assert.equal(p.browser.emit('beforeunload').defaultPrevented, true);
        p.unmount(); assert.equal(p.browser.alerts.length, 1);
    });
}
test('Adjustments: changing wallet cannot discard a pending operation', async () => {
    const p = page('Adjustments'); p.open('refund', 'Complete', { id: 'refund-id' }, 'complete'); p.form.reason = 'Reason';
    p.setHandler(async () => { throw failure(); }); await p.submit();
    p.dialog.value.close(); p.selectWallet();
    assert.equal(p.visits.length, 0);
    assert.equal(p.dialog.value.open, true);
    p.setHandler(async () => ({ data: { data: {} } })); await p.submit();
    assert.deepEqual(p.calls[0], p.calls[1]);
});
