# Use the official PHP image with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install mysqli and pdo_mysql extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli pdo_mysql

# Copy all your project files into the container
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 🔹 Add host entry so 'host.docker.internal' works on Linux Docker engine
RUN echo "host.docker.internal host-gateway" >> /etc/hosts

# Expose port 80 for web traffic
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
