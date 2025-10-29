# Use the official PHP image with Apache
FROM php:8.2-apache

# Set the working directory
WORKDIR /var/www/html

# Copy all project files into the container
COPY . /var/www/html/

# Install mysqli extension for MySQL
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Set file permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose the port Render (and browsers) will use
EXPOSE 80

# Start Apache server
CMD ["apache2-foreground"]
