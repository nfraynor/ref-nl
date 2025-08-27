# MySQL 8 LTS-ish (Oracle image)
FROM mysql:8.4

# Healthcheck (the base image already has one, this is explicit/robust)
HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=10 \
  CMD mysqladmin ping -h 127.0.0.1 -uroot -p"super-strong-root-secret" || exit 1

# Add config
COPY config.cnf /etc/mysql/conf.d/my.cnf


EXPOSE 3306
