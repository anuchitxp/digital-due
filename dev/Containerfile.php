# dev/Containerfile.php — PHP 8.3 + Microsoft SQL Server drivers (matches docs/07 staging install)
# Build: podman compose -f dev/compose.yaml build
FROM php:8.3-cli

ARG SQLSRV_VERSION=5.11.1
ARG ODBC_VERSION=18
ENV ACCEPT_EULA=Y

RUN apt-get update \
 && apt-get install -y --no-install-recommends gnupg2 curl ca-certificates unixodbc unixodbc-dev odbcinst \
 && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
      | gpg --dearmor -o /usr/share/keyrings/microsoft.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/microsoft.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
      > /etc/apt/sources.list.d/mssql.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends "msodbcsql${ODBC_VERSION}" \
 && pecl install "sqlsrv-${SQLSRV_VERSION}" "pdo_sqlsrv-${SQLSRV_VERSION}" \
 && docker-php-ext-enable sqlsrv pdo_sqlsrv \
 && { echo "default_charset=UTF-8"; \
      echo "session.gc_maxlifetime=900"; \
      echo "session.use_strict_mode=1"; \
      echo "display_errors=On"; \
      echo "memory_limit=256M"; } > /usr/local/etc/php/conf.d/due.ini \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
