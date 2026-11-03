const test = require("node:test");
const assert = require("node:assert");
const path = require("path");
const { cargarConfig, leerSensores } = require("../lib/config");

const NO_EXISTE = { CONFIG: path.join(__dirname, "no-existe.json") };

test("sin archivo ni variables usa los valores por defecto, sin sensores", () => {
  const { config, origen, problemas } = cargarConfig({});
  // Si hay un config.json local, este test no aplica.
  if (origen !== "variables de entorno") return;
  assert.strictEqual(config.limites.calorAviso, 28);
  assert.deepStrictEqual(config.sensores, []);
  assert.ok(problemas.some((p) => p.includes("TOKEN_GATEWAY")));
});

test("CONFIG apuntando a un archivo que no existe es un error", () => {
  assert.throws(() => cargarConfig(NO_EXISTE), /No existe/);
});

test("formato corto de SENSORES", () => {
  const s = leerSensores("24e1240000000001:1:Inicio; 24E1240000000002:1:Fondo\n24E1240000000009:0:Tablero general:luz");
  assert.deepStrictEqual(s, [
    { devEUI: "24E1240000000001", galpon: 1, nombre: "Inicio", tipo: "th" },
    { devEUI: "24E1240000000002", galpon: 1, nombre: "Fondo", tipo: "th" },
    { devEUI: "24E1240000000009", galpon: 0, nombre: "Tablero general", tipo: "luz" },
  ]);
});

test("SENSORES con DevEUI mal escrito da un error claro", () => {
  assert.throws(() => leerSensores("24E12400001:1:Inicio"), /DevEUI inválido/);
});

test("las variables de entorno mandan sobre CONFIG_JSON", () => {
  const base = { modoPrueba: true, tokenGateway: "x", limites: { calorAviso: 28, calorCritico: 31, frio: 14, humedadAlta: 80, humedadMinutos: 30, sinDatosMinutos: 20 } };
  const { config, problemas } = cargarConfig({
    CONFIG_JSON: JSON.stringify(base),
    MODO_PRUEBA: "false",
    TOKEN_GATEWAY: "un-token-bastante-largo-123",
    PANEL_CLAVE: "secreta",
    CALOR_AVISO: "27,5",
    CALOR_CRITICO: "30",
    SENSORES: "24E1240000000001:1:Inicio",
    CONTACTO_1_NOMBRE: "Ana", CONTACTO_1_WHATSAPP: "+56911111111", CONTACTO_1_APIKEY: "123456",
    CONTACTO_3_WHATSAPP: "+56933333333",
    LLAMADAS_PROVEEDOR: "twilio", TWILIO_ACCOUNT_SID: "AC1",
    MQTT_URL: "mqtts://broker:8883",
  });
  assert.strictEqual(config.modoPrueba, false);
  assert.strictEqual(config.limites.calorAviso, 27.5);
  assert.strictEqual(config.limites.calorCritico, 30);
  assert.strictEqual(config.limites.frio, 14);
  assert.deepStrictEqual(config.contactos.map((c) => c.nombre), ["Ana", "Persona 3"]);
  assert.strictEqual(config.llamadas.twilio.accountSid, "AC1");
  assert.strictEqual(config.mqtt.url, "mqtts://broker:8883");
  assert.deepStrictEqual(problemas, []);
});

test("umbral no numérico o aviso >= crítico se rechazan", () => {
  const base = { CONFIG_JSON: JSON.stringify({ limites: { calorAviso: 28, calorCritico: 31, frio: 14, humedadAlta: 80, humedadMinutos: 30, sinDatosMinutos: 20 } }) };
  assert.throws(() => cargarConfig({ ...base, CALOR_AVISO: "mucho" }), /CALOR_AVISO debe ser un número/);
  assert.throws(() => cargarConfig({ ...base, CALOR_AVISO: "32" }), /menor que CALOR_CRITICO/);
});

test("token y clave de ejemplo se marcan como problema", () => {
  const { problemas } = cargarConfig({ CONFIG: path.join(__dirname, "..", "config.example.json") });
  assert.ok(problemas.some((p) => p.startsWith("TOKEN_GATEWAY")));
  assert.ok(problemas.some((p) => p.startsWith("PANEL_CLAVE")));
  assert.ok(problemas.some((p) => p.includes("CallMeBot")));
});
