// Envío de WhatsApp (CallMeBot) y llamadas (CallMeBot por Telegram o Twilio).
// Los envíos se hacen en cola, uno tras otro, para respetar los límites de CallMeBot.

const ESPERA_MS = 4000;
let cola = Promise.resolve();

function encolar(tarea) {
  cola = cola
    .then(tarea)
    .catch((e) => console.error("[notificar]", e.message))
    .then(() => new Promise((r) => setTimeout(r, ESPERA_MS)));
  return cola;
}

async function pedir(url, opciones = {}, intentos = 3) {
  for (let n = 1; n <= intentos; n++) {
    try {
      const r = await fetch(url, { ...opciones, signal: AbortSignal.timeout(20000) });
      const texto = await r.text();
      if (r.ok) return texto;
      throw new Error(`HTTP ${r.status}: ${texto.slice(0, 200)}`);
    } catch (e) {
      if (n === intentos) throw e;
      await new Promise((r) => setTimeout(r, 3000 * n));
    }
  }
}

function crearNotificador(config) {
  const prueba = !!config.modoPrueba;
  const contactos = config.contactos || [];
  const ll = config.llamadas || {};

  function whatsapp(texto) {
    for (const c of contactos) {
      if (!c.whatsapp || (!c.apikey && !prueba)) continue;
      encolar(async () => {
        if (prueba) return console.log(`[PRUEBA] WhatsApp a ${c.nombre}: ${texto}`);
        const url =
          "https://api.callmebot.com/whatsapp.php?phone=" + encodeURIComponent(c.whatsapp.replace(/\s/g, "")) +
          "&text=" + encodeURIComponent(texto) +
          "&apikey=" + encodeURIComponent(c.apikey);
        await pedir(url);
        console.log(`[whatsapp] enviado a ${c.nombre}`);
      });
    }
  }

  // Varias alertas críticas seguidas (p. ej. los 2 sensores de un galpón) generan una sola llamada.
  let ultimaLlamada = 0;
  function llamar(texto) {
    if (!ll.proveedor || ll.proveedor === "ninguno") return;
    if (Date.now() - ultimaLlamada < (ll.minutosEntreLlamadas ?? 5) * 60000) return;
    ultimaLlamada = Date.now();
    for (const c of contactos) {
      encolar(async () => {
        if (ll.proveedor === "callmebot") {
          if (!c.telegram && !prueba) return;
          if (prueba) return console.log(`[PRUEBA] Llamada Telegram a ${c.nombre}: ${texto}`);
          const url =
            "https://api.callmebot.com/start.php?user=" + encodeURIComponent(c.telegram) +
            "&text=" + encodeURIComponent(texto) +
            "&lang=" + encodeURIComponent(ll.idioma || "es-ES-Standard-A") +
            "&rpt=" + (ll.repetir || 2);
          await pedir(url);
          console.log(`[llamada] Telegram a ${c.nombre}`);
        } else if (ll.proveedor === "twilio") {
          const t = ll.twilio || {};
          const numero = c.telefono || c.whatsapp;
          if (!numero || !t.accountSid) return;
          if (prueba) return console.log(`[PRUEBA] Llamada Twilio a ${c.nombre}: ${texto}`);
          const voz = `<Response>${`<Say language="es-MX">${escaparXml(texto)}</Say><Pause length="1"/>`.repeat(ll.repetir || 2)}</Response>`;
          const cuerpo = new URLSearchParams({ To: numero, From: t.desde, Twiml: voz });
          await pedir(`https://api.twilio.com/2010-04-01/Accounts/${t.accountSid}/Calls.json`, {
            method: "POST",
            headers: {
              Authorization: "Basic " + Buffer.from(`${t.accountSid}:${t.authToken}`).toString("base64"),
              "Content-Type": "application/x-www-form-urlencoded",
            },
            body: cuerpo,
          });
          console.log(`[llamada] Twilio a ${c.nombre}`);
        }
      });
    }
  }

  return { whatsapp, llamar };
}

function escaparXml(s) {
  return s.replace(/[<>&"']/g, (c) => ({ "<": "&lt;", ">": "&gt;", "&": "&amp;", '"': "&quot;", "'": "&apos;" }[c]));
}

module.exports = { crearNotificador };
