import test from 'node:test';
import assert from 'node:assert/strict';
import { formatInstitutionalMoney as money } from '../../resources/js/Support/institutionalMoney.js';

test('centavo strings preserve both digits and grouped units', () => {
    assert.equal(money('0'), 'MXN 0.00');
    assert.equal(money('1'), 'MXN 0.01');
    assert.equal(money('100001'), 'MXN 1,000.01');
});
test('aggregates above PHP and JavaScript integer ranges remain exact', () => {
    assert.equal(money('12000000000000000001'), 'MXN 120,000,000,000,000,000.01');
});
test('negative centavos and alternate currency stay explicit', () => {
    assert.equal(money('-1'), 'MXN -0.01');
    assert.equal(money('101', 'USD'), 'USD 1.01');
});
test('missing values floats and untrusted numeric types are never presented as zero', () => {
    for (const input of [undefined, null, 100, 1.5, '1.5', '', '01', 'NaN']) assert.equal(money(input), 'Importe no disponible');
});
