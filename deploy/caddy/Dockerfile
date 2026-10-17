# Önde çalışan Caddy: Let's Encrypt sertifikasını Cloudflare DNS doğrulamasıyla alır
# (sunucunun internetten erişilebilir olması gerekmez). Derleme bağlamı: deploy/caddy
FROM caddy:2-builder AS builder
# Derleme paralelliği sınırlı: az bellekli sunucuda Go derlemesi belleği tüketmesin.
ENV GOFLAGS=-p=2 GOMAXPROCS=2
RUN xcaddy build --with github.com/caddy-dns/cloudflare

FROM caddy:2
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
COPY Caddyfile /etc/caddy/Caddyfile
