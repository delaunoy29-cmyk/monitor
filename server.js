// Monitor avícola: recibe las lecturas del gateway Milesight, avisa por
// WhatsApp/llamada y sirve el panel web. Node 18+; la única dependencia (mqtt)
// solo se usa si se activa la entrada por MQTT.

const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const { leerUplink } = require("./lib/decoder");
const { crearAlmacen } = require("./lib/almacen");
const { crearNotificador } = require("./lib/notificar");
const { crearMotor } = require("./lib/alertas");
const { cargarConfig } = require("./lib/config");
const { conectarMqtt } = require("./lib/mqtt");

let config, origen, problemas;
try {
  ({ config, origen, problemas } = cargarConfig());
} catch (e) {
  console.error(`Error en la configuración: ${e.message}`);
  process.exit(1);
}
console.log(`Configuración leída de: ${origen}`);
if (problemas.length) {
  for (const p of problemas) console.error(`  ✗ ${p}`);
  if (!config.modoPrueba) {
    console.error("No se puede arrancar en modo real con estos problemas. Corrígelos o usa MODO_PRUEBA=true.");
    process.exit(1);
  }
  console.error("  (en modo prueba se arranca igual)");
}
const sensores = new Map((config.sensores || []).map((s) => [s.devEUI.toUpperCase(), s]));

const almacen = crearAlmacen(process.env.DATOS || path.join(__dirname, "datos"));
const notificador = crearNotificador(config);
const motor = crearMotor(config, almacen, notificador);

setInterval(motor.revisar, 60 * 1000);
setInterval(almacen.compactar, 24 * 3600 * 1000);

const panelHtml = fs.readFileSync(path.join(__dirname, "public", "index.html"));

function iguales(a, b) {
  const x = Buffer.from(String(a)), y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

function autorizado(req) {
  const p = config.panel;
  if (!p?.clave) return true;
  const m = /^Basic (.+)$/.exec(req.headers.authorization || "");
  if (!m) return false;
  const [u, ...resto] = Buffer.from(m[1], "base64").toString().split(":");
  return iguales(u, p.usuario || "") && iguales(resto.join(":"), p.clave);
}

function json(res, codigo, datos) {
  res.writeHead(codigo, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" });
  res.end(JSON.stringify(datos));
}

function leerCuerpo(req) {
  return new Promise((ok, mal) => {
    let datos = "";
    req.on("data", (c) => {
      datos += c;
      if (datos.length > 1e6) { mal(new Error("demasiado grande")); req.destroy(); }
    });
    req.on("end", () => ok(datos));
    req.on("error", mal);
  });
}

function estado() {
  const ahora = Date.now();
  const alertas = motor.lista();
  const nGalpones = config.galpones?.length || Math.max(0, ...[...sensores.values()].map((s) => s.galpon || 0));
  const grupos = [];
  for (let g = 1; g <= nGalpones; g++) grupos.push({ id: g, nombre: config.galpones?.[g - 1] || `Galpón ${g}`, sensores: [] });
  const general = { id: 0, nombre: "General", sensores: [] };
  for (const s of sensores.values()) {
    const eui = s.devEUI.toUpperCase();
    const u = almacen.ultima.get(eui);
    const propias = alertas.filter((a) => a.eui === eui);
    const nivel = propias.some((a) => a.nivel === "critico") ? "critico" : propias.length ? "aviso" : "ok";
    const fila = { eui, nombre: s.nombre, tipo: s.tipo || "th", ultimo: u?.t ?? null, temp: u?.temp, hum: u?.hum, bat: u?.bat, di: u?.di, nivel };
    (grupos[(s.galpon || 0) - 1] || general).sensores.push(fila);
  }
  if (general.sensores.length) grupos.push(general);
  return {
    ahora, galpones: grupos, alertas, limites: config.limites,
    valorConLuz: config.luz?.valorConLuz ?? 1,
    registro: almacen.registro().slice(-30).reverse(),
  };
}

function historial(galpon, horas) {
  const desde = Date.now() - horas * 3600 * 1000;
  const paso = horas <= 24 ? 5 * 60000 : 30 * 60000; // promedia en tramos para aligerar
  const series = [];
  for (const s of sensores.values()) {
    if ((s.galpon || 0) !== galpon || (s.tipo || "th") !== "th") continue;
    const tramos = new Map();
    for (const l of almacen.serie(s.devEUI.toUpperCase(), desde)) {
      const k = Math.floor(l.t / paso) * paso;
      const a = tramos.get(k) || { t: k + paso / 2, temp: 0, nt: 0, hum: 0, nh: 0 };
      if (l.temp !== undefined) { a.temp += l.temp; a.nt++; }
      if (l.hum !== undefined) { a.hum += l.hum; a.nh++; }
      tramos.set(k, a);
    }
    series.push({
      nombre: s.nombre,
      puntos: [...tramos.values()].map((a) => ({
        t: a.t,
        temp: a.nt ? +(a.temp / a.nt).toFixed(2) : null,
        hum: a.nh ? +(a.hum / a.nh).toFixed(1) : null,
      })),
    });
  }
  return { desde, hasta: Date.now(), series };
}

// Procesa un mensaje del gateway, venga por HTTP o por MQTT.
let ultimoUplink = null;
function procesarUplink(cuerpo, via) {
  const l = leerUplink(cuerpo);
  if (!l) return { codigo: 400, respuesta: { error: "mensaje sin devEUI" } };
  ultimoUplink = Date.now();
  const s = sensores.get(l.eui);
  if (!s) {
    console.log(`[${via}] sensor no registrado: ${l.eui}`, l);
    return { codigo: 200, respuesta: { ok: true, ignorado: true } };
  }
  const lectura = { t: Date.now(), eui: l.eui };
  for (const k of ["temp", "hum", "bat", "di"]) if (l[k] !== undefined) lectura[k] = l[k];
  almacen.guardarLectura(lectura);
  motor.evaluar(s, lectura);
  return { codigo: 200, respuesta: { ok: true } };
}

const servidor = http.createServer(async (req, res) => {
  const url = new URL(req.url, "http://x");
  try {
    // Entrada de datos del gateway (protegida con token)
    if (url.pathname === "/uplink" && req.method === "POST") {
      const token = url.searchParams.get("token") || req.headers["x-token"] || "";
      if (!config.tokenGateway || !iguales(token, config.tokenGateway)) return json(res, 401, { error: "token inválido" });
      let cuerpo;
      try {
        cuerpo = JSON.parse(await leerCuerpo(req));
      } catch {
        return json(res, 400, { error: "el cuerpo no es JSON" });
      }
      const { codigo, respuesta } = procesarUplink(cuerpo, "http");
      return json(res, codigo, respuesta);
    }

    // Para el monitor externo (UptimeRobot) y el chequeo de salud de la plataforma.
    if (url.pathname === "/salud") {
      return json(res, 200, { ok: true, modoPrueba: !!config.modoPrueba, sensores: sensores.size, ultimoUplink });
    }

    if (!autorizado(req)) {
      res.writeHead(401, { "WWW-Authenticate": 'Basic realm="Monitor avicola", charset="UTF-8"' });
      return res.end("Se necesita usuario y clave");
    }

    if (url.pathname === "/" || url.pathname === "/index.html") {
      res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
      return res.end(panelHtml);
    }
    if (url.pathname === "/api/estado") return json(res, 200, estado());
    if (url.pathname === "/api/historial") {
      const g = Number(url.searchParams.get("galpon") || 1);
      const h = Math.min(168, Math.max(1, Number(url.searchParams.get("horas") || 24)));
      return json(res, 200, historial(g, h));
    }
    if (url.pathname === "/api/prueba" && req.method === "POST") {
      const tipo = url.searchParams.get("tipo") === "llamada" ? "critico" : "aviso";
      motor.enviar(tipo, "🧪 Mensaje de prueba del monitor de la granja. Si lo recibes, las alertas funcionan.",
        "Esta es una llamada de prueba del monitor de la granja.");
      return json(res, 200, { ok: true });
    }
    json(res, 404, { error: "no encontrado" });
  } catch (e) {
    console.error("[servidor]", e);
    json(res, 500, { error: e.message });
  }
});

const mqtt = conectarMqtt(config.mqtt, (cuerpo) => procesarUplink(cuerpo, "mqtt"));

const puerto = process.env.PORT || config.puerto || 3000;
servidor.listen(puerto, () => {
  console.log(`Monitor avícola escuchando en el puerto ${puerto}${config.modoPrueba ? " (MODO PRUEBA: no envía mensajes reales)" : ""}`);
});

// Las plataformas en la nube detienen el contenedor con SIGTERM al desplegar una versión nueva.
for (const senal of ["SIGTERM", "SIGINT"]) {
  process.on(senal, () => {
    console.log(`Recibido ${senal}, cerrando.`);
    mqtt?.end();
    servidor.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 3000).unref();
  });
}
process.on("unhandledRejection", (e) => console.error("[sin capturar]", e));
