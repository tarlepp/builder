FROM php:7-apache

VOLUME /opt/grafana

WORKDIR /var/www

RUN apt-get update && \
    apt-get install -y wget git libzip-dev

RUN a2enmod rewrite

RUN docker-php-ext-install opcache && \
    docker-php-ext-install zip

RUN wget -O /opt/composer https://getcomposer.org/composer.phar && \
    chmod +x /opt/composer

ADD ./composer.json /var/www/composer.json
ADD ./composer.lock /var/www/composer.lock
ADD ./src /var/www/src

RUN /opt/composer install --prefer-dist --classmap-authoritative --no-dev

ADD ./bin /var/www/bin
ADD ./html /var/www/html
