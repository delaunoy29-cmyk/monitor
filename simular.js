// Simula los sensores enviando datos como lo haría el gateway, para probar sin equipos.
// Uso: node simular.js [url] [token] [modo]
//   modo: normal (por defecto) | calor | sinluz | historia
const fs = require("fs");
const config = JSON.parse(fs.readFileSync(process.env.CONFIG || __dirname + "/config.json", "utf8"));
const url = process.argv[2] || `http://localhost:${config.puerto || 3000}`;
const token = process.argv[3] || config.tokenGateway;
const modo = process.argv[4] || "normal";

// Construye el paquete binario Milesight EM300 (batería, temperatura, humedad, entrada digital).
function paquete({ bat = 95, temp, hum, di }) {
  const b = [0x01, 0x75, bat];
  if (temp !== undefined) { const v = Math.round(temp * 10) & 0xffff; b.push(0x03, 0x67, v & 0xff, v >> 8); }
  if (hum !== undefined) b.push(0x04, 0x68, Math.round(hum * 2));
  if (di !== undefined) b.push(0x05, 0x00, di);
  return Buffer.from(b).toString("base64");
}

async function enviar(s, valores) {
  const r = await fetch(`${url}/uplink?token=${encodeURIComponent(token)}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ devEUI: s.devEUI, deviceName: s.nombre, fPort: 85, data: paquete(valores) }),
  });
  console.log(s.galpon, s.nombre, JSON.stringify(valores), "→", r.status, await r.text());
}

(async () => {
  for (const s of config.sensores) {
    if (s.tipo === "luz") { await enviar(s, { di: modo === "sinluz" ? 0 : 1 }); continue; }
    const base = 22 + s.galpon * 0.6 + Math.random();
    const temp = modo === "calor" && s.galpon === 3 ? 31.8 : base;
    await enviar(s, { temp: +temp.toFixed(1), hum: Math.round(60 + Math.random() * 10), bat: 90 + s.galpon });
  }
})();
