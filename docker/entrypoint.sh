#!/bin/bash
# Arranca php-fpm y nginx en el mismo contenedor (sin root). Si cualquiera de los dos muere, el contenedor sale para que se reinicie.
# SIGTERM/SIGINT => parada ordenada (SIGQUIT): terminan las peticiones en curso, por ejemplo un envío a SUNAT.
set -u

mkdir -p /tmp/nginx

# Configuración y certificado válidos antes de aceptar tráfico (falla rápido con un mensaje claro).
php /app/bin/startup.php || exit 1

php-fpm --nodaemonize --fpm-config /usr/local/etc/php-fpm.conf &
fpm_pid=$!
# -e stderr: el registro de errores compilado por defecto (/var/log/nginx) no es escribible sin root.
nginx -e stderr -g 'daemon off;' -c /etc/nginx/nginx.conf &
nginx_pid=$!

stop() {
    kill -QUIT "$fpm_pid" "$nginx_pid" 2>/dev/null
}
trap stop TERM INT

wait -n "$fpm_pid" "$nginx_pid"
status=$?
stop
wait
exit "$status"
