// Capturador de prueba para el XBee Cellular del pivote (iControl Remote).
// El XBee en modo transparente abre una conexión TCP (o manda UDP) a este servidor y
// reenvía tal cual todo lo que la placa le escribe por serial. Aquí se guarda cada
// trozo con la hora, en hexadecimal y como texto, para descifrar el protocolo.
// No responde nada: solo escucha. Sin dependencias, solo Node 18+.
const net = require("net");
const dgram = require("dgram");
const http = require("http");
const fs = require("fs");
const path = require("path");

const MAX_EN_MEMORIA = 500;

function hex(buf) {
  return buf.toString("hex").replace(/(..)/g, "$1 ").trim().toUpperCase();
}

// Texto legible: caracteres imprimibles tal cual, saltos de línea como \r \n, el resto como «·».
function texto(buf) {
  let s = "";
  for (const b of buf) {
    if (b === 0x0d) s += "\\r";
    else if (b === 0x0a) s += "\\n";
    else if (b >= 0x20 && b < 0x7f) s += String.fromCharCode(b);
    else s += "·";
  }
  return s;
}

function crearRegistro(dirDatos) {
  const recientes = [];
  if (dirDatos) fs.mkdirSync(dirDatos, { recursive: true });
  const archivoDelDia = (fecha) => path.join(dirDatos, `captura-${fecha.slice(0, 10)}.jsonl`);

  function anotar(evento) {
    const e = { hora: new Date().toISOString(), ...evento };
    recientes.push(e);
    if (recientes.length > MAX_EN_MEMORIA) recientes.shift();
    if (dirDatos) fs.appendFileSync(archivoDelDia(e.hora), JSON.stringify(e) + "\n");
    if (e.tipo === "datos") {
      console.log(`${e.hora} ${e.proto} ${e.origen} ${e.bytes} bytes\n  HEX:   ${e.hex}\n  TEXTO: ${e.texto}`);
    } else {
      console.log(`${e.hora} ${e.proto} ${e.origen} ${e.tipo}${e.detalle ? " (" + e.detalle + ")" : ""}`);
    }
    return e;
  }

  const datos = (proto, origen, buf) =>
    anotar({ tipo: "datos", proto, origen, bytes: buf.length, hex: hex(buf), texto: texto(buf) });

  return { anotar, datos, recientes, archivoDelDia };
}

function pagina(recientes) {
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);
  const filas = recientes
    .slice()
    .reverse()
    .map((e) =>
      e.tipo === "datos"
        ? `<tr><td>${esc(e.hora)}</td><td>${esc(e.proto)} ${esc(e.origen)}</td><td>${e.bytes}</td><td><code>${esc(e.hex)}</code><br><code class="t">${esc(e.texto)}</code></td></tr>`
        : `<tr class="ev"><td>${esc(e.hora)}</td><td>${esc(e.proto)} ${esc(e.origen)}</td><td></td><td>${esc(e.tipo)} ${esc(e.detalle || "")}</td></tr>`
    )
    .join("");
  return `<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="10">
<title>Captura del pivote</title>
<style>body{font-family:system-ui,sans-serif;margin:16px;background:#fff;color:#111}
table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #ddd;padding:6px;vertical-align:top;font-size:14px}
code{word-break:break-all;font-size:13px}.t{color:#06c}.ev td{color:#777}
@media (prefers-color-scheme:dark){body{background:#111;color:#eee}td{border-color:#333}.t{color:#6af}}</style></head>
<body><h1>Captura del pivote</h1>
<p>Se actualiza sola cada 10 segundos. Lo más nuevo arriba. <a href="/descargar">Descargar todo lo de hoy</a></p>
${filas ? `<table><tr><th>Hora (UTC)</th><th>Desde</th><th>Bytes</th><th>Contenido (hex y texto)</th></tr>${filas}</table>` : "<p>Todavía no llega nada.</p>"}
</body></html>`;
}

function iniciar({ puertoTcp = 9000, puertoUdp = puertoTcp, puertoHttp = 8080, datos, usuario = "pivote", clave } = {}) {
  const reg = crearRegistro(datos);

  const tcp = net.createServer((sock) => {
    const origen = `${sock.remoteAddress}:${sock.remotePort}`;
    reg.anotar({ tipo: "conectado", proto: "TCP", origen });
    sock.setKeepAlive(true, 60_000);
    sock.on("data", (buf) => reg.datos("TCP", origen, buf));
    sock.on("error", (err) => reg.anotar({ tipo: "error", proto: "TCP", origen, detalle: err.message }));
    sock.on("close", () => reg.anotar({ tipo: "desconectado", proto: "TCP", origen }));
  });
  tcp.listen(puertoTcp);

  let udp = null;
  if (puertoUdp) {
    udp = dgram.createSocket("udp4");
    udp.on("message", (buf, info) => reg.datos("UDP", `${info.address}:${info.port}`, buf));
    udp.on("error", (err) => console.error("UDP:", err.message));
    udp.bind(puertoUdp);
  }

  const web = http.createServer((req, res) => {
    const url = new URL(req.url, "http://x");
    if (url.pathname === "/salud") return res.end("ok");
    if (clave) {
      const esperado = "Basic " + Buffer.from(`${usuario}:${clave}`).toString("base64");
      if (req.headers.authorization !== esperado) {
        res.writeHead(401, { "WWW-Authenticate": 'Basic realm="pivote"' });
        return res.end("Falta usuario y clave");
      }
    }
    if (url.pathname === "/api") {
      res.writeHead(200, { "Content-Type": "application/json" });
      return res.end(JSON.stringify(reg.recientes));
    }
    if (url.pathname === "/descargar") {
      const archivo = datos && reg.archivoDelDia(new Date().toISOString());
      if (!archivo || !fs.existsSync(archivo)) {
        res.writeHead(404);
        return res.end("Hoy no hay datos guardados");
      }
      res.writeHead(200, {
        "Content-Type": "application/x-ndjson",
        "Content-Disposition": `attachment; filename="${path.basename(archivo)}"`,
      });
      return fs.createReadStream(archivo).pipe(res);
    }
    if (url.pathname === "/") {
      res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
      return res.end(pagina(reg.recientes));
    }
    res.writeHead(404);
    res.end();
  });
  web.listen(puertoHttp);

  const cerrar = () =>
    Promise.all([
      new Promise((ok) => tcp.close(ok)),
      new Promise((ok) => web.close(ok)),
      udp ? new Promise((ok) => udp.close(ok)) : null,
    ]);
  return { tcp, udp, web, reg, cerrar };
}

module.exports = { iniciar, hex, texto };

if (require.main === module) {
  const n = (v, def) => (v === undefined || v === "" ? def : Number(v));
  const opciones = {
    puertoTcp: n(process.env.PUERTO_TCP, 9000),
    puertoUdp: n(process.env.PUERTO_UDP, n(process.env.PUERTO_TCP, 9000)),
    puertoHttp: n(process.env.PORT, 8080),
    datos: process.env.DATOS || path.join(__dirname, "capturas"),
    usuario: process.env.PANEL_USUARIO || "pivote",
    clave: process.env.PANEL_CLAVE,
  };
  iniciar(opciones);
  console.log(
    `Escuchando el XBee en TCP ${opciones.puertoTcp}` +
      (opciones.puertoUdp ? ` y UDP ${opciones.puertoUdp}` : "") +
      `. Página para ver la captura en el puerto ${opciones.puertoHttp}. Archivos en ${opciones.datos}.`
  );
  if (!opciones.clave) console.log("Aviso: sin PANEL_CLAVE la página de captura queda abierta a cualquiera.");
}
