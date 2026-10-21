// Guarda las lecturas en un archivo (una línea JSON por lectura) y mantiene
// en memoria los últimos días para el panel.

const fs = require("fs");
const path = require("path");

function crearAlmacen(carpeta, diasHistorial = 7) {
  fs.mkdirSync(carpeta, { recursive: true });
  const archLecturas = path.join(carpeta, "lecturas.jsonl");
  const archAlertas = path.join(carpeta, "alertas.jsonl");
  const ventana = diasHistorial * 24 * 3600 * 1000;

  const historial = new Map(); // eui -> [{t, temp, hum, bat, di}]
  const ultima = new Map();    // eui -> última lectura
  let registro = [];           // últimas alertas enviadas

  function cargar(arch, fn) {
    if (!fs.existsSync(arch)) return;
    for (const linea of fs.readFileSync(arch, "utf8").split("\n")) {
      if (!linea) continue;
      try { fn(JSON.parse(linea)); } catch {}
    }
  }

  const limite = Date.now() - ventana;
  cargar(archLecturas, (l) => {
    if (l.t < limite) return;
    if (!historial.has(l.eui)) historial.set(l.eui, []);
    historial.get(l.eui).push(l);
    ultima.set(l.eui, l);
  });
  cargar(archAlertas, (a) => registro.push(a));
  registro = registro.slice(-200);

  function guardarLectura(l) {
    if (!historial.has(l.eui)) historial.set(l.eui, []);
    historial.get(l.eui).push(l);
    ultima.set(l.eui, l);
    fs.appendFileSync(archLecturas, JSON.stringify(l) + "\n");
  }

  function guardarAlerta(a) {
    registro.push(a);
    if (registro.length > 200) registro = registro.slice(-200);
    fs.appendFileSync(archAlertas, JSON.stringify(a) + "\n");
  }

  // Una vez al día: quita de memoria y del archivo lo que pasó de la ventana.
  function compactar() {
    const corte = Date.now() - ventana;
    const lineas = [];
    for (const [eui, arr] of historial) {
      const resto = arr.filter((l) => l.t >= corte);
      historial.set(eui, resto);
      for (const l of resto) lineas.push(JSON.stringify(l));
    }
    lineas.sort((a, b) => JSON.parse(a).t - JSON.parse(b).t);
    fs.writeFileSync(archLecturas, lineas.join("\n") + (lineas.length ? "\n" : ""));
    fs.writeFileSync(archAlertas, registro.map((a) => JSON.stringify(a)).join("\n") + (registro.length ? "\n" : ""));
  }

  function serie(eui, desde) {
    return (historial.get(eui) || []).filter((l) => l.t >= desde);
  }

  return { guardarLectura, guardarAlerta, compactar, serie, ultima, registro: () => registro };
}

module.exports = { crearAlmacen };
