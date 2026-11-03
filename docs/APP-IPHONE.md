# El panel como app en el iPhone

El panel se instala en el iPhone como una app más, con su icono en la pantalla de inicio,
sin App Store, sin Mac y sin pagar la cuenta de desarrollador de Apple.

## Instalarla (una vez por teléfono)

1. Abre **Safari** (tiene que ser Safari) y entra a la dirección del panel, por ejemplo `https://granja.tudominio.cl`.
2. Escribe el usuario y la clave del panel (`PANEL_USUARIO` y `PANEL_CLAVE`).
3. Toca el botón **Compartir** (el cuadrado con la flecha hacia arriba).
4. Elige **Añadir a pantalla de inicio** y luego **Añadir**.
5. Aparece el icono **Galpones**. Ábrelo desde ahí: se ve a pantalla completa, sin la barra de Safari.

La primera vez que se abre desde el icono puede pedir usuario y clave otra vez (el iPhone guarda
la sesión de la app aparte de la de Safari). Después queda la sesión abierta.

En Android es igual con Chrome: menú ⋮ → **Instalar app** o **Añadir a pantalla de inicio**.

## Cómo funciona

- El panel necesita estar publicado con **https** (Render y Fly.io ya lo dan).
- `public/manifest.webmanifest`, los iconos `public/icono-*.png` y `public/sw.js` se sirven sin
  sesión, porque el iPhone los pide antes de entrar.
- La sesión es una cookie firmada con `PANEL_CLAVE` que dura 400 días. **Cerrar sesión** está
  arriba a la derecha. Si se cambia `PANEL_CLAVE`, todos los teléfonos tienen que entrar otra vez.
- Sin conexión, la app muestra la última versión del panel con el aviso «Sin conexión con el
  servidor». Los datos nunca se guardan en el teléfono.

## Lo que no hace

- Las **alertas** siguen llegando por WhatsApp y llamada, como antes. La app no envía
  notificaciones propias.
- No está en la App Store. Para eso haría falta una app nativa (archivo IPA), que solo se puede
  compilar en un Mac con Xcode y publicar con la cuenta de desarrollador de Apple (99 USD al año).
