# Local test instance: osTicket 1.18.4 on PHP 8.3 / Apache.
# For development only, never for production.
FROM php:8.3-apache

ARG OSTICKET_VERSION=1.18.4
ARG OSTICKET_SHA256=1688c2f77b800788368beae3760d9c86db6eea21c88392da004ee81a3961825c

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libzip-dev \
      msmtp curl ca-certificates \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli gd intl zip opcache \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Source tarball of the release tag, pinned by checksum.
RUN curl -fsSL -o /tmp/ost.tar.gz \
      "https://github.com/osTicket/osTicket/archive/refs/tags/v${OSTICKET_VERSION}.tar.gz" \
 && echo "${OSTICKET_SHA256}  /tmp/ost.tar.gz" | sha256sum -c - \
 && tar xzf /tmp/ost.tar.gz -C /tmp \
 && rm -rf /var/www/html \
 && mv "/tmp/osTicket-${OSTICKET_VERSION}" /var/www/html \
 && cp /var/www/html/include/ost-sampleconfig.php /var/www/html/include/ost-config.php \
 && mkdir -p /var/www/html/assets/custom/css \
 && chown -R www-data:www-data /var/www/html \
 && rm /tmp/ost.tar.gz

# Outgoing mail goes to the Mailpit container, so tests can see what a
# status change sends to customers.
RUN printf 'sendmail_path = "/usr/bin/msmtp -t --host=mail --port=1025 --from=helpdesk@example.com"\n' \
      > /usr/local/etc/php/conf.d/mail.ini \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
