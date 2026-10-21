// Motor de alertas: decide cuándo avisar, repetir y dar por normalizada una alerta.

function crearMotor(config, almacen, notificador) {
  const L = config.limites;
  const rep = config.repetir || { avisoMin: 60, criticoMin: 15 };
  const valorConLuz = config.luz?.valorConLuz ?? 1;
  const zona = config.zonaHoraria || "America/Santiago";
  const activas = new Map(); // clave -> {nivel, desde, ultimoEnvio, texto, sensor}
  const humedadDesde = new Map();
  const inicio = Date.now();

  const nombreGalpon = (s) =>
    s.galpon ? config.galpones?.[s.galpon - 1] || `Galpón ${s.galpon}` : s.nombre;
  const etiqueta = (s) => (s.galpon ? `${nombreGalpon(s)} – ${s.nombre}` : s.nombre);
  const hora = (t = Date.now()) =>
    new Date(t).toLocaleTimeString("es-CL", { timeZone: zona, hour: "2-digit", minute: "2-digit", hour12: false });
  const f1 = (n) => n.toFixed(1).replace(".", ",");

  function enviar(nivel, texto, voz) {
    almacen.guardarAlerta({ t: Date.now(), nivel, texto });
    notificador.whatsapp(texto);
    if (nivel === "critico") notificador.llamar(voz || texto);
  }

  function activar(clave, nivel, texto, voz, sensor, silencioso = false) {
    const a = activas.get(clave);
    if (!a) {
      activas.set(clave, { nivel, desde: Date.now(), ultimoEnvio: Date.now(), texto, voz, sensor });
      if (!silencioso) enviar(nivel, texto, voz);
      return true;
    } else if (a.nivel === "aviso" && nivel === "critico") {
      Object.assign(a, { nivel, ultimoEnvio: Date.now(), texto, voz });
      enviar(nivel, texto, voz);
    } else {
      // Sigue activa: actualiza el texto y el nivel (puede bajar de crítico a aviso sin avisar).
      Object.assign(a, { nivel, texto, voz });
    }
  }

  function desactivar(clave, texto) {
    const a = activas.get(clave);
    if (!a) return;
    activas.delete(clave);
    const minutos = Math.round((Date.now() - a.desde) / 60000);
    enviar("ok", `${texto} (duró ${minutos} min)`);
  }

  function evaluar(sensor, l) {
    const id = sensor.devEUI.toUpperCase();
    desactivar(`${id}|sinDatos`, `✅ ${etiqueta(sensor)}: el sensor volvió a enviar datos.`);
    if (activas.has("gateway")) desactivar("gateway", "✅ Se recuperó la conexión con la granja.");

    if (sensor.tipo === "luz" && l.di !== undefined) {
      const clave = `${id}|luz`;
      if (l.di !== valorConLuz) {
        activar(clave, "critico",
          `🚨 CORTE DE LUZ – ${etiqueta(sensor)} (${hora()}). Revisar ventilación y generador.`,
          `Alerta. Corte de luz en ${etiqueta(sensor)}.`, sensor);
      } else {
        desactivar(clave, `✅ Volvió la luz – ${etiqueta(sensor)}.`);
      }
    }

    if (l.temp !== undefined) {
      const t = l.temp, h = L.histeresis ?? 0.5;
      const calor = activas.get(`${id}|calor`);
      const extra = l.hum !== undefined ? ` Humedad ${Math.round(l.hum)} %.` : "";
      let nivel = null;
      if (t >= L.calorCritico || (calor?.nivel === "critico" && t > L.calorCritico - h)) nivel = "critico";
      else if (t >= L.calorAviso || (calor && t > L.calorAviso - h)) nivel = "aviso";
      if (nivel === "critico") {
        activar(`${id}|calor`, nivel,
          `🚨 ${etiqueta(sensor)}: ${f1(t)} °C (${hora()}).${extra} Calor peligroso, revisar ventilación YA.`,
          `Alerta. ${nombreGalpon(sensor)} a ${Math.round(t)} grados. Revisar ventilación.`, sensor);
      } else if (nivel === "aviso") {
        activar(`${id}|calor`, nivel,
          `⚠️ ${etiqueta(sensor)}: ${f1(t)} °C (${hora()}).${extra} Temperatura alta.`, null, sensor);
      } else {
        desactivar(`${id}|calor`, `✅ ${etiqueta(sensor)}: temperatura normal, ${f1(t)} °C.`);
      }

      const frio = activas.has(`${id}|frio`);
      if (t <= L.frio || (frio && t < L.frio + h)) {
        activar(`${id}|frio`, "aviso", `⚠️ ${etiqueta(sensor)}: ${f1(t)} °C (${hora()}). Temperatura baja.`, null, sensor);
      } else {
        desactivar(`${id}|frio`, `✅ ${etiqueta(sensor)}: temperatura normal, ${f1(t)} °C.`);
      }
    }

    if (l.hum !== undefined) {
      const clave = `${id}|humedad`;
      if (l.hum >= L.humedadAlta) {
        if (!humedadDesde.has(id)) humedadDesde.set(id, Date.now());
        if (Date.now() - humedadDesde.get(id) >= L.humedadMinutos * 60000) {
          activar(clave, "aviso",
            `⚠️ ${etiqueta(sensor)}: humedad ${Math.round(l.hum)} % hace más de ${L.humedadMinutos} min. Revisar ventilación y bebederos.`,
            null, sensor);
        }
      } else if (l.hum < L.humedadAlta - 3) {
        humedadDesde.delete(id);
        desactivar(clave, `✅ ${etiqueta(sensor)}: humedad normal, ${Math.round(l.hum)} %.`);
      }
    }
  }

  // Se ejecuta cada minuto: sensores sin datos, caída general y recordatorios.
  function revisar() {
    const ahora = Date.now();
    const limite = L.sinDatosMinutos * 60000;
    const sensores = config.sensores || [];
    const ultimoDe = (s) => almacen.ultima.get(s.devEUI.toUpperCase())?.t ?? inicio;
    const mudos = sensores.filter((s) => ahora - Math.max(ultimoDe(s), inicio) > limite);

    if (sensores.length > 1 && mudos.length === sensores.length) {
      for (const s of sensores) activas.delete(`${s.devEUI.toUpperCase()}|sinDatos`);
      activar("gateway", "critico",
        `🚨 No llegan datos de NINGÚN sensor hace más de ${L.sinDatosMinutos} min. Puede ser corte de luz o de internet en la granja.`,
        "Alerta. No llegan datos de la granja. Puede haber un corte de luz o de internet.");
    } else {
      // Los sensores que se quedan mudos a la vez van en un solo mensaje.
      const nuevos = mudos.filter((s) =>
        activar(`${s.devEUI.toUpperCase()}|sinDatos`, "aviso",
          `⚠️ ${etiqueta(s)}: el sensor no envía datos. Revisar pila o señal.`, null, s, true));
      if (nuevos.length) {
        enviar("aviso", `⚠️ Sin datos hace más de ${L.sinDatosMinutos} min: ${nuevos.map(etiqueta).join(", ")}. Revisar pila o señal.`);
      }
    }

    for (const a of activas.values()) {
      const cada = (a.nivel === "critico" ? rep.criticoMin : rep.avisoMin) * 60000;
      if (cada > 0 && ahora - a.ultimoEnvio >= cada) {
        a.ultimoEnvio = ahora;
        const min = Math.round((ahora - a.desde) / 60000);
        enviar(a.nivel, `🔁 Sigue activa hace ${min} min: ${a.texto}`, a.voz);
      }
    }
  }

  function lista() {
    return [...activas.entries()].map(([clave, a]) => ({
      clave, nivel: a.nivel, desde: a.desde, texto: a.texto,
      eui: a.sensor?.devEUI?.toUpperCase(), galpon: a.sensor?.galpon,
    }));
  }

  return { evaluar, revisar, lista, enviar };
}

module.exports = { crearMotor };
