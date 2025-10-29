# Use the official PHP image with Apache
FROM php:8.2-apache

# Install necessary PHP extensions for MySQL and others
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite (useful for clean URLs)
RUN a2enmod rewrite

# Copy all files from your repository into the web root
COPY . /var/www/html/

# Set proper file permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 for Render
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
