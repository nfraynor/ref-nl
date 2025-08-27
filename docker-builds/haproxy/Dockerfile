FROM haproxy:2.9-alpine

FROM haproxy:2.9-alpine
COPY haproxy.cfg /usr/local/etc/haproxy/haproxy.cfg
USER root
RUN mkdir -p /run/haproxy && chown -R haproxy:haproxy /run/haproxy
RUN mkdir -p /var/run/haproxy && chown -R haproxy:haproxy /run/haproxy