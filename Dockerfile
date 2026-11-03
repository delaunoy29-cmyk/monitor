# Imagen para Render, Railway, Fly.io o cualquier servidor con Docker.
FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production \
    DATOS=/data \
    PORT=3000

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY server.js simular.js config.example.json ./
COPY lib ./lib
COPY public ./public

# Historial de lecturas y alertas. Montar aquí un disco/volumen persistente.
RUN mkdir -p /data
VOLUME ["/data"]

EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD wget -qO- "http://127.0.0.1:${PORT}/salud" > /dev/null || exit 1

CMD ["node", "server.js"]
