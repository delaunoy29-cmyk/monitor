const test = require("node:test");
const assert = require("node:assert");
const { decodificarMilesight, leerUplink } = require("../lib/decoder");

test("decodifica batería, temperatura y humedad del EM300-TH", () => {
  const d = decodificarMilesight([0x01, 0x75, 0x5c, 0x03, 0x67, 0x34, 0x01, 0x04, 0x68, 0x65]);
  assert.deepStrictEqual(d, { battery: 92, temperature: 30.8, humidity: 50.5 });
});

test("temperatura bajo cero", () => {
  assert.deepStrictEqual(decodificarMilesight([0x03, 0x67, 0xf6, 0xff]), { temperature: -1 });
});

test("entrada digital del EM300-DI", () => {
  assert.deepStrictEqual(decodificarMilesight([0x05, 0x00, 0x01]), { gpio: 1 });
});

test("uplink HTTP del UG63 sin codec (data en base64)", () => {
  // Así llega desde el Network Server integrado del UG63 con «Payload Codec: None».
  const cuerpo = {
    applicationID: "1", applicationName: "galpones", deviceName: "G1-Inicio",
    devEUI: "24e124136b000001", fPort: 85, fCnt: 12,
    data: Buffer.from([0x01, 0x75, 0x5c, 0x03, 0x67, 0x0e, 0x01, 0x04, 0x68, 0x82]).toString("base64"),
  };
  assert.deepStrictEqual(leerUplink(cuerpo), { eui: "24E124136B000001", temp: 27, hum: 65, bat: 92, di: undefined });
});

test("uplink ya decodificado por el gateway (object)", () => {
  const l = leerUplink({ devEUI: "24E124136B000002", object: { temperature: 25.3, humidity: 70, battery: 99 } });
  assert.strictEqual(l.temp, 25.3);
  assert.strictEqual(l.hum, 70);
});

test("formato ChirpStack v4 (deviceInfo.devEui)", () => {
  const l = leerUplink({ deviceInfo: { devEui: "24e124136b000009" }, object: { gpio: "off" } });
  assert.strictEqual(l.eui, "24E124136B000009");
  assert.strictEqual(l.di, 0);
});

test("mensaje sin devEUI se rechaza", () => {
  assert.strictEqual(leerUplink({ data: "AXVc" }), null);
});
