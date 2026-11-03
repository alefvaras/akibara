const test = require('node:test');
const assert = require('node:assert/strict');
const { montar, nodos, texto } = require('./support/arnes.js');

function fixture(overrides = {}, fail = false) {
    const data = Object.assign({ logged_in: true, balance: 10500, intent: 0, effective: 0, min: 100, max: 5000, min_cart: 50000, missing: 0, redeem_value: 1, unavailable: '', earn_now: 0 }, overrides);
    const app = montar({ cuenta: data, errorCanje: fail ? 'El carrito cambió: revisa el importe.' : '' });
    const refresh = () => nodos(app.pintarCanje()).map(n => ({ type: n.t, props: n.p, node: n }));
    return { data, calls: app.solicitudesCanje, focused: () => app.campoCanje.enfocado,
        button: label => refresh().find(x => x.type === 'button' && texto(x.node) === label),
        input: () => refresh().find(x => x.type === 'input'), fullText: () => texto(app.pintarCanje()) };
}

test('máximo sólo rellena, muestra pesos y permite confirmar explícitamente', async () => {
    const f = fixture();
    assert.equal(f.button('Confirmar canje').props.disabled, true);
    f.button('Usar el máximo disponible').props.onClick();
    assert.deepEqual(f.calls, []);
    assert.equal(f.focused(), true);
    assert.equal(f.input().props.value, '5000');
    assert.match(f.fullText(), /5\.000 puntos: \$5\.000/);
    await f.button('Confirmar canje').props.onClick();
    assert.deepEqual(f.calls, [5000]);
});

test('max0 con saldo o tope bajo mínimo no ofrece formulario ni canje activo', () => {
    for (const balance of [50, 10500]) {
        const f = fixture({ balance, max: 0, intent: 100, effective: 0, unavailable: 'El mínimo no se alcanza.' });
        assert.equal(f.input(), undefined);
        assert.match(f.fullText(), /no se aplica/);
        assert.doesNotMatch(f.fullText(), /Canje aplicado:/);
        assert.ok(f.button('Quitar canje'));
    }
});

test('importe inválido no se aplica ni recorta silenciosamente', () => {
    const f = fixture();
    for (const value of ['50', '5001', '-1', '100.5', '']) {
        f.input().props.onChange({ target: { value } });
        assert.equal(f.button('Confirmar canje').props.disabled, true);
        f.button('Confirmar canje').props.onClick();
    }
    assert.deepEqual(f.calls, []);
});

test('errores del servidor se explican y se puede volver a intentar', async () => {
    const f = fixture({}, true);
    f.button('Usar el máximo disponible').props.onClick();
    await f.button('Confirmar canje').props.onClick();
    assert.match(f.fullText(), /El carrito cambió/);
    assert.equal(f.button('Confirmar canje').props.disabled, false);
});

test('canje efectivo usa el valor configurable; quitar sigue enviando cero', async () => {
    const f = fixture({ intent: 5000, effective: 300, redeem_value: 2, max: 300, balance: 300 });
    assert.match(f.fullText(), /Canje aplicado: 300 puntos = \$600/);
    await f.button('Quitar canje').props.onClick();
    assert.deepEqual(f.calls, [0]);
});

test('switch_points ofrece botón nativo de 1 clic para conmutar de pack a puntos cuando convienen más', async () => {
    const f = fixture({
        balance: 4000,
        max: 0,
        switch_points: 2000,
        redeem_value: 1,
        unavailable: '💡 Tienes $2.000 en Puntos Akibara disponibles (superan los -$1.500 de tu descuento de pack actual).'
    });
    const btn = f.button('Usar mis $2.000 en puntos en lugar del descuento de pack');
    assert.ok(btn, 'debe renderizar el botón de conmutación pack -> puntos');
    assert.equal(btn.props.disabled, false);
    await btn.props.onClick();
    assert.deepEqual(f.calls, [2000]);
});
