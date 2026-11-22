# Protótipo clicável (estático). Imagem sem PHP, sem Node e sem root.
# Contexto de build: a RAIZ do repositório (veja docker-compose.yml desta pasta).
FROM nginxinc/nginx-unprivileged:1.27-alpine

COPY prototipo-ux/infra/nginx.conf /etc/nginx/conf.d/default.conf
# Só o que o navegador precisa: telas e assets. Testes, gerador e infra ficam de fora (.dockerignore).
COPY prototipo-ux/*.html /usr/share/nginx/html/
COPY prototipo-ux/assets /usr/share/nginx/html/assets

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1:8080/saude || exit 1
