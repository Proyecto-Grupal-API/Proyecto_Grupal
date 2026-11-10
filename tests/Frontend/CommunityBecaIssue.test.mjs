import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import { ref } from 'vue';
import { formatInstitutionalMoney } from '../../resources/js/Support/institutionalMoney.js';

const source = readFileSync(new URL('../../resources/js/Components/CommunityBecaIssue.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'community-beca-test' }).content
    .replace(/^import .*;$/gm, '')
    .replace('export default', 'return');
const createComponent = new Function('ref', 'axios', 'formatInstitutionalMoney', 'route', script);

function mount(http) {
    const component = createComponent(ref, http, formatInstitutionalMoney, (name, id) => name + (id ? '/' + id : ''));
    return component.setup({}, { expose() {} });
}

test('a request cannot be reviewed or issued before the session factor is confirmed', async () => {
    const calls = [];
    const state = mount({
        post: async (...args) => { calls.push(args); },
        get: async (...args) => { calls.push(args); },
    });
    state.reference.value = 'beca:aaaaaaaaaaaaaaaaaaaaaaaa';
    await state.submit();
    state.factorCode.value = '123456';
    await state.confirmFactor();
    assert.equal(state.factorConfirmed.value, true);
    assert.equal(state.factorCode.value, '');
    assert.deepEqual(calls, [['security.two-factor.confirm', { code: '123456' }]]);
});

test('a rejected factor clears the code and prevents financial requests', async () => {
    const state = mount({
        post: async () => { throw { response: { status: 422, data: { errors: { code: ['Código rechazado'] } } } }; },
        get: async () => { assert.fail('The financial API must not be contacted'); },
    });
    state.factorCode.value = '123456';
    await state.confirmFactor();
    assert.equal(state.factorConfirmed.value, false);
    assert.equal(state.factorCode.value, '');
    assert.equal(state.error.value, 'Código rechazado');
    await state.submit();
});

test('loss of an issuance response clears the review and lets the same reference recover its receipt', async () => {
    const reference = 'aaaaaaaaaaaaaaaaaaaaaaaa';
    let issuanceCalls = 0;
    let issued = false;
    const state = mount({
        post: async (path, payload) => {
            if (path === 'security.two-factor.confirm') return { data: { ok: true } };
            issuanceCalls++;
            assert.deepEqual(payload, { budget_version: '4', review_token: 'review-token', confirm: true });
            issued = true;
            throw new Error('Lost response');
        },
        get: async () => ({ data: { data: issued ? { receipt: { bonus_id: 'bonus-1' } }
            : { award: {}, can_issue: true, budget_version: '4', review_token: 'review-token' } } }),
    });
    state.reference.value = 'beca:' + reference;
    state.factorCode.value = '123456';
    await state.confirmFactor();
    await state.submit();
    state.confirmed.value = true;
    await state.submit(true);
    assert.equal(state.review.value, null);
    assert.equal(state.confirmed.value, false);
    await state.submit();
    assert.equal(state.review.value.receipt.bonus_id, 'bonus-1');
    await state.submit(true);
    assert.equal(issuanceCalls, 1);
});

test('a denial after review requires a new factor and removes the old issuance token', async () => {
    const state = mount({
        post: async () => ({ data: { ok: true } }),
        get: async () => { throw { response: { status: 403, data: { message: 'La autorización venció.' } } }; },
    });
    state.factorCode.value = '123456';
    await state.confirmFactor();
    state.reference.value = 'aaaaaaaaaaaaaaaaaaaaaaaa';
    await state.submit();
    assert.equal(state.factorConfirmed.value, false);
    assert.equal(state.review.value, null);
    assert.equal(state.error.value, 'La autorización venció.');
});
