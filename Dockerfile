FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod headers \
 && mkdir -p /data && chown www-data:www-data /data
ENV ER_CONFIG_DIR=/data TZ=Asia/Bangkok
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html && rm -f /var/www/html/config.php
VOLUME /data
EXPOSE 80
HEALTHCHECK --interval=60s CMD php -r 'exit(@file_get_contents("http://127.0.0.1/install.php")===false?1:0);'
