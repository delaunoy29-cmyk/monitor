const test = require("node:test");
const assert = require("node:assert");
const path = require("path");
const { cargarConfig } = require("../lib/config");
const { crearMotor } = require("../lib/alertas");

// Simula el paso del tiempo y registra lo que el motor enviaría.
function escenario() {
  const realNow = Date.now;
  let ahora = Date.parse("2026-10-08T12:00:00Z");
  Date.now = () => ahora;
  const { config } = cargarConfig({ CONFIG: path.join(__dirname, "..", "config.example.json") });
  const enviados = [], llamadas = [];
  const almacen = { ultima: new Map(), guardarAlerta: (a) => enviados.push(a) };
  const motor = crearMotor(config, almacen, { whatsapp() {}, llamar: (t) => llamadas.push(t) });
  const S = config.sensores;
  const leer = (s, l) => { l.t = ahora; almacen.ultima.set(s.devEUI.toUpperCase(), l); motor.evaluar(s, l); };
  const normal = (s) => leer(s, s.tipo === "luz" ? { di: 1 } : { temp: 24, hum: 60 });
  return {
    S, motor, leer, normal, llamadas,
    min: (m) => (ahora += m * 60000),
    tomar: () => enviados.splice(0).map((a) => `${a.nivel} ${a.texto}`),
    fin: () => { Date.now = realNow; },
  };
}

test("calor: aviso, crítico con histéresis, recordatorio y normalización", (t) => {
  const e = escenario();
  t.after(e.fin);
  e.S.forEach(e.normal);
  assert.deepStrictEqual(e.tomar(), []);

  e.leer(e.S[0], { temp: 28.5, hum: 70 });
  assert.match(e.tomar()[0], /^aviso ⚠️ Galpón 1 – Inicio: 28,5 °C/);

  e.leer(e.S[0], { temp: 31.2, hum: 70 });
  assert.match(e.tomar()[0], /^critico 🚨 Galpón 1 – Inicio: 31,2 °C/);
  assert.strictEqual(e.llamadas.length, 1);

  e.leer(e.S[0], { temp: 30.8, hum: 70 }); // dentro de la histéresis
  assert.deepStrictEqual(e.tomar(), []);

  e.min(16);
  e.S.slice(1).forEach(e.normal);
  e.leer(e.S[0], { temp: 30.9, hum: 70 });
  e.motor.revisar();
  assert.match(e.tomar()[0], /^critico 🔁 Sigue activa hace 16 min/);

  e.leer(e.S[0], { temp: 27.2, hum: 70 });
  assert.match(e.tomar()[0], /^ok ✅ Galpón 1 – Inicio: temperatura normal, 27,2 °C/);
});

test("corte de luz llama y avisa al volver", (t) => {
  const e = escenario();
  t.after(e.fin);
  const luz = e.S.find((s) => s.tipo === "luz");
  e.S.forEach(e.normal);
  e.leer(luz, { di: 0 });
  assert.match(e.tomar()[0], /^critico 🚨 CORTE DE LUZ – Tablero general/);
  assert.strictEqual(e.llamadas.length, 1);
  e.leer(luz, { di: 1 });
  assert.match(e.tomar()[0], /^ok ✅ Volvió la luz/);
});

test("sensores mudos van en un solo mensaje y la granja caída es crítica", (t) => {
  const e = escenario();
  t.after(e.fin);
  e.S.forEach(e.normal);
  e.min(21);
  e.S.slice(0, 6).forEach(e.normal);
  e.motor.revisar();
  const m = e.tomar();
  assert.strictEqual(m.length, 1);
  assert.match(m[0], /Sin datos hace más de 20 min: Galpón 4 – Inicio, Galpón 4 – Fondo, Tablero general/);

  e.min(21);
  e.motor.revisar();
  assert.match(e.tomar()[0], /^critico 🚨 No llegan datos de NINGÚN sensor/);

  e.S.forEach(e.normal);
  assert.ok(e.tomar().some((x) => x.includes("Se recuperó la conexión")));
});
