# NIX Builder 0.1 — PHP 8.2 + Apache + outils pour Nix (installé au démarrage dans le volume /nix).
FROM php:8.2-apache-trixie

LABEL org.opencontainers.image.title="NIX Builder" \
      org.opencontainers.image.description="Images NixOS (OVA/VMDK) et projets Docker" \
      org.opencontainers.image.version="0.1.0"

# curl/xz : installeur Nix ; sudo : lancer Nix en www-data ; tar, cp --sparse : empaquetage OVA.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl xz-utils sudo unzip procps libzip-dev openssh-server apache2-utils \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip \
 && a2enmod rewrite headers \
 && rm -f /etc/ssh/ssh_host_* \
 && rm -rf /var/lib/apt/lists/*

# Clés d'hôte SSH dans un volume : l'empreinte ne change pas à chaque rebuild de l'image.
RUN { \
      echo 'PermitRootLogin yes'; \
      echo 'PasswordAuthentication yes'; \
      echo 'HostKey /etc/ssh/host_keys/ssh_host_ed25519_key'; \
      echo 'HostKey /etc/ssh/host_keys/ssh_host_rsa_key'; \
    } > /etc/ssh/sshd_config.d/nixvm.conf

RUN { \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 0'; \
      echo 'output_buffering = Off'; \
    } > /usr/local/etc/php/conf.d/nixvm.ini

COPY entrypoint.sh /usr/local/bin/nixvm-entrypoint
RUN chmod 755 /usr/local/bin/nixvm-entrypoint

WORKDIR /var/www/html
EXPOSE 80 22

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1

ENTRYPOINT ["nixvm-entrypoint"]
CMD ["apache2-foreground"]
