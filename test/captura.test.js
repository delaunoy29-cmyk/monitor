const test = require("node:test");
const assert = require("node:assert");
const net = require("net");
const dgram = require("dgram");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { iniciar, hex, texto } = require("../pivote/captura");

const espera = (ms) => new Promise((r) => setTimeout(r, ms));

test("muestra los bytes en hex y como texto", () => {
  const buf = Buffer.from([0x24, 0x47, 0x50, 0x00, 0xff, 0x0d, 0x0a]);
  assert.strictEqual(hex(buf), "24 47 50 00 FF 0D 0A");
  assert.strictEqual(texto(buf), "$GP··\\r\\n");
});

test("guarda lo que llega por TCP y UDP y lo muestra en la página", async () => {
  const datos = fs.mkdtempSync(path.join(os.tmpdir(), "pivote-"));
  const base = 4100 + Math.floor(Math.random() * 800);
  const srv = iniciar({ puertoTcp: base, puertoUdp: base, puertoHttp: base + 1, datos, clave: "secreta" });
  try {
    await espera(100);
    const sock = net.connect(base, "127.0.0.1");
    await new Promise((ok) => sock.on("connect", ok));
    sock.write(Buffer.from([0x01, 0x02, 0x41, 0x42]));
    await espera(100);
    sock.end();
    await espera(100);

    const u = dgram.createSocket("udp4");
    await new Promise((ok) => u.send(Buffer.from("HOLA"), base, "127.0.0.1", ok));
    u.close();
    await espera(150);

    const tipos = srv.reg.recientes.map((e) => e.tipo);
    assert.deepStrictEqual(tipos, ["conectado", "datos", "desconectado", "datos"]);
    assert.strictEqual(srv.reg.recientes[1].hex, "01 02 41 42");
    assert.strictEqual(srv.reg.recientes[3].texto, "HOLA");

    const archivo = fs.readdirSync(datos).find((f) => f.endsWith(".jsonl"));
    const lineas = fs.readFileSync(path.join(datos, archivo), "utf8").trim().split("\n");
    assert.strictEqual(lineas.length, 4);

    const sinClave = await fetch(`http://127.0.0.1:${base + 1}/`);
    assert.strictEqual(sinClave.status, 401);
    const auth = { Authorization: "Basic " + Buffer.from("pivote:secreta").toString("base64") };
    const html = await (await fetch(`http://127.0.0.1:${base + 1}/`, { headers: auth })).text();
    assert.match(html, /01 02 41 42/);
    const descarga = await fetch(`http://127.0.0.1:${base + 1}/descargar`, { headers: auth });
    assert.strictEqual(descarga.status, 200);
  } finally {
    await srv.cerrar();
  }
});
