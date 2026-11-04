# Capturador del XBee del pivote. Se despliega aparte del monitor avícola (ver docs/PIVOTE-CAPTURA.md).
FROM node:22-alpine
WORKDIR /app
ENV DATOS=/data PORT=8080 PUERTO_TCP=9000
COPY captura.js ./
RUN mkdir -p /data
EXPOSE 8080 9000
CMD ["node", "captura.js"]
