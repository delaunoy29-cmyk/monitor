// Arranca el servidor de verdad, configurado solo con variables de entorno, como en la nube.
const test = require("node:test");
const assert = require("node:assert");
const { spawn } = require("child_process");
const fs = require("fs");
const os = require("os");
const path = require("path");

const PUERTO = 3900 + Math.floor(Math.random() * 90);
const BASE = `http://127.0.0.1:${PUERTO}`;
const TOKEN = "token-de-prueba-0123456789";
const auth = { Authorization: "Basic " + Buffer.from("granja:clave-panel").toString("base64") };
const paquete = (temp, hum) => {
  const v = Math.round(temp * 10) & 0xffff;
  return Buffer.from([0x01, 0x75, 90, 0x03, 0x67, v & 0xff, v >> 8, 0x04, 0x68, hum * 2]).toString("base64");
};

function arrancar(env) {
  const datos = fs.mkdtempSync(path.join(os.tmpdir(), "monitor-"));
  const hijo = spawn(process.execPath, [path.join(__dirname, "..", "server.js")], {
    env: { PATH: process.env.PATH, CONFIG: path.join(datos, "no-hay.json"), DATOS: datos, ...env },
    stdio: ["ignore", "pipe", "pipe"],
  });
  let salida = "";
  hijo.stdout.on("data", (d) => (salida += d));
  hijo.stderr.on("data", (d) => (salida += d));
  const termino = new Promise((ok) => hijo.on("exit", (codigo) => ok(codigo)));
  return { hijo, termino, salida: () => salida, datos };
}

async function esperarListo(base) {
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(`${base}/salud`)).ok) return; } catch {}
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error("el servidor no arrancó");
}

test("si CONFIG apunta a un archivo que no existe, no arranca", async () => {
  const { termino, salida } = arrancar({ MODO_PRUEBA: "true", PORT: String(PUERTO) });
  assert.strictEqual(await termino, 1);
  assert.match(salida(), /No existe/);
});

test("en modo real sin token no arranca", async () => {
  const datos = fs.mkdtempSync(path.join(os.tmpdir(), "monitor-"));
  const hijo = spawn(process.execPath, [path.join(__dirname, "..", "server.js")], {
    env: { PATH: process.env.PATH, CONFIG_JSON: fs.readFileSync(path.join(__dirname, "..", "config.example.json"), "utf8"), MODO_PRUEBA: "false", DATOS: datos, PORT: String(PUERTO) },
  });
  let salida = "";
  hijo.stderr.on("data", (d) => (salida += d));
  const codigo = await new Promise((ok) => hijo.on("exit", ok));
  assert.strictEqual(codigo, 1);
  assert.match(salida, /TOKEN_GATEWAY/);
});

test("recibe datos del gateway por HTTP y los muestra en el panel", async (t) => {
  const s = arrancar({
    CONFIG_JSON: JSON.stringify({ limites: { calorAviso: 28, calorCritico: 31, frio: 14, humedadAlta: 80, humedadMinutos: 30, sinDatosMinutos: 20 } }),
    MODO_PRUEBA: "true", PORT: String(PUERTO), TOKEN_GATEWAY: TOKEN, PANEL_CLAVE: "clave-panel", PANEL_USUARIO: "granja",
    GALPONES: "Galpón 1", SENSORES: "24E1240000000001:1:Inicio;24E1240000000002:1:Fondo",
    CONTACTO_1_NOMBRE: "Ana", CONTACTO_1_WHATSAPP: "+56911111111",
  });
  t.after(() => s.hijo.kill("SIGTERM"));
  await esperarListo(BASE);

  const salud = await (await fetch(`${BASE}/salud`)).json();
  assert.strictEqual(salud.sensores, 2);
  assert.strictEqual(salud.ultimoUplink, null);

  const uplink = (q, cuerpo, headers = {}) =>
    fetch(`${BASE}/uplink${q}`, { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: cuerpo });

  assert.strictEqual((await uplink("?token=malo", "{}")).status, 401);
  assert.strictEqual((await uplink(`?token=${TOKEN}`, "esto no es json")).status, 400);

  const r = await uplink(`?token=${TOKEN}`, JSON.stringify({ devEUI: "24e1240000000001", fPort: 85, data: paquete(31.5, 60) }));
  assert.deepStrictEqual(await r.json(), { ok: true });
  // El token también puede ir en la cabecera X-Token.
  const r2 = await uplink("", JSON.stringify({ devEUI: "24E1240000000002", object: { temperature: 24, humidity: 62 } }), { "X-Token": TOKEN });
  assert.strictEqual(r2.status, 200);
  const r3 = await uplink(`?token=${TOKEN}`, JSON.stringify({ devEUI: "FFFFFFFFFFFFFFFF", data: paquete(20, 50) }));
  assert.deepStrictEqual(await r3.json(), { ok: true, ignorado: true });

  assert.strictEqual((await fetch(`${BASE}/api/estado`)).status, 401);
  const estado = await (await fetch(`${BASE}/api/estado`, { headers: auth })).json();
  const [g1] = estado.galpones;
  assert.strictEqual(g1.nombre, "Galpón 1");
  assert.deepStrictEqual(g1.sensores.map((x) => [x.nombre, x.temp, x.nivel]), [["Inicio", 31.5, "critico"], ["Fondo", 24, "ok"]]);
  assert.match(s.salida(), /\[PRUEBA\] WhatsApp a Ana: 🚨 Galpón 1 – Inicio: 31,5 °C/);
  assert.ok(fs.readFileSync(path.join(s.datos, "lecturas.jsonl"), "utf8").includes("24E1240000000001"));
});

test("el panel se puede instalar como app en el iPhone y mantiene la sesión", async (t) => {
  const puerto = PUERTO + 100;
  const base = `http://127.0.0.1:${puerto}`;
  const s = arrancar({
    CONFIG_JSON: JSON.stringify({ limites: { calorAviso: 28, calorCritico: 31, frio: 14, humedadAlta: 80, humedadMinutos: 30, sinDatosMinutos: 20 } }),
    MODO_PRUEBA: "true", PORT: String(puerto), TOKEN_GATEWAY: TOKEN, PANEL_CLAVE: "clave-panel", PANEL_USUARIO: "granja",
    SENSORES: "24E1240000000001:1:Inicio",
  });
  t.after(() => s.hijo.kill("SIGTERM"));
  await esperarListo(base);

  // Lo que Safari necesita para "Añadir a pantalla de inicio" está disponible sin sesión.
  const manifest = await fetch(`${base}/manifest.webmanifest`);
  assert.strictEqual(manifest.status, 200);
  assert.strictEqual((await manifest.json()).display, "standalone");
  for (const ruta of ["/sw.js", "/icono-180.png", "/icono-512.png", "/apple-touch-icon.png"]) {
    assert.strictEqual((await fetch(base + ruta)).status, 200, ruta);
  }

  // Sin sesión, el panel lleva a la página de entrada y la API responde 401.
  const sinSesion = await fetch(`${base}/`, { redirect: "manual" });
  assert.strictEqual(sinSesion.status, 303);
  assert.strictEqual(sinSesion.headers.get("location"), "entrar");
  assert.match(await (await fetch(`${base}/entrar`)).text(), /name="clave"/);
  assert.strictEqual((await fetch(`${base}/api/estado`)).status, 401);

  const entrar = (usuario, clave) => fetch(`${base}/entrar`, {
    method: "POST", redirect: "manual",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ usuario, clave }),
  });
  const mal = await entrar("granja", "otra");
  assert.strictEqual(mal.status, 401);
  assert.match(await mal.text(), /incorrectos/);

  const bien = await entrar("granja", "clave-panel");
  assert.strictEqual(bien.status, 303);
  const cookie = bien.headers.get("set-cookie");
  assert.match(cookie, /^sesion=[0-9a-f]{64};.*Max-Age=\d+;.*HttpOnly/);
  const conSesion = { Cookie: cookie.split(";")[0] };
  assert.strictEqual((await fetch(`${base}/api/estado`, { headers: conSesion })).status, 200);
  const panel = await (await fetch(`${base}/`, { headers: conSesion })).text();
  assert.match(panel, /rel="manifest"/);
  assert.match(panel, /apple-touch-icon/);

  // Una cookie inventada no sirve, y usuario:clave en la cabecera sigue funcionando.
  assert.strictEqual((await fetch(`${base}/api/estado`, { headers: { Cookie: "sesion=" + "0".repeat(64) } })).status, 401);
  assert.strictEqual((await fetch(`${base}/api/estado`, { headers: auth })).status, 200);

  const salir = await fetch(`${base}/salir`, { redirect: "manual", headers: conSesion });
  assert.match(salir.headers.get("set-cookie"), /Max-Age=0/);
});
