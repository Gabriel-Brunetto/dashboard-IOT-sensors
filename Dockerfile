FROM php:8.3-cli-alpine
WORKDIR /app
COPY servidor/ /app/
COPY /app/public/index.html /app/public/index.html
ENV PHP_CLI_SERVER_WORKERS=4 DATA_DIR=/data
VOLUME /data
EXPOSE 80
# o container roda o processo de fundo do no (heartbeat e eleicao) e o servidor web (api e pagina)
# o sh fica como processo 1 e repassa o sinal de parada aos dois, senao o container ignora o SIGTERM e o stop demora 10 s
CMD ["sh", "-c", "php /app/node.php & php -S 0.0.0.0:80 -t /app/public & trap 'kill $(jobs -p); exit 0' TERM INT; wait"]
