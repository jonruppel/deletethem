#!/bin/bash

# Clean up and create local zip directory
rm -rf /Users/jonruppel/Sites/deletethem/zips && mkdir -p /Users/jonruppel/Sites/deletethem/zips

# Copy files to zip directory
cp -r api zips/api/
cp -r assets zips/assets/
cp index.html zips/index.html

# Create Apache configuration file
cat > zips/deletethem.conf << 'EOL'
# Handle subdirectory access at /deletethem
Alias /deletethem /var/www/html/deletethem

# Handle HTTP subdomain access and redirect to HTTPS
<VirtualHost *:80>
    ServerName deletethem.oneover.com
    
    # Redirect all HTTP traffic to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

# Handle HTTPS subdomain access
<VirtualHost *:443>
    ServerName deletethem.oneover.com
    DocumentRoot /var/www/html/deletethem

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/deletethem.oneover.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/deletethem.oneover.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>

# Shared directory configuration
<Directory /var/www/html/deletethem>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted

    # Enable URL rewriting
    <IfModule mod_rewrite.c>
        RewriteEngine On
        
        # If accessing via subdomain, use root path
        RewriteCond %{HTTP_HOST} ^deletethem\.oneover\.com$ [NC]
        RewriteRule ^(.*)$ $1 [L]

        # If accessing via subdirectory, use /deletethem base
        RewriteCond %{HTTP_HOST} !^deletethem\.oneover\.com$ [NC]
        RewriteBase /deletethem/
        
        # If the request is not for a file or directory
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        # Rewrite to index.html (static SPA)
        RewriteRule ^ index.html [L]
    </IfModule>
</Directory>

# Logging
LogLevel warn
ErrorLog /var/log/httpd/deletethem-error.log
CustomLog /var/log/httpd/deletethem-access.log combined
EOL

# Create zip file
zip -r zips.zip zips/*

# Copy zip file to server
scp /Users/jonruppel/Sites/deletethem/zips.zip jonruppel@208.109.241.221:/home/jonruppel/

# SSH into server and deploy
ssh jonruppel@208.109.241.221 '
    # Create necessary directories if they dont exist
    sudo mkdir -p /var/www/html/deletethem/{api,assets}
    
    # Clean existing directories
    sudo rm -rf /var/www/html/deletethem/api/*
    sudo rm -rf /var/www/html/deletethem/assets/*
    sudo rm -f /var/www/html/deletethem/index.html

    # Extract and move files
    cd /var/www/html/deletethem
    sudo unzip -o ~/zips.zip
    sudo mv zips/api/* api/
    sudo mv zips/assets/* assets/
    sudo mv zips/index.html .

    # Set up Apache configuration
    sudo mv -f zips/deletethem.conf /etc/httpd/conf.d/
    sudo rm -rf zips
    sudo rm -f ~/zips.zip

    # Set permissions
    sudo chown -R apache:apache /var/www/html/deletethem
    sudo chmod -R 755 /var/www/html/deletethem

    # Check if SSL certificates exist
    if ! sudo test -f /etc/letsencrypt/live/deletethem.oneover.com/fullchain.pem || ! sudo test -f /etc/letsencrypt/live/deletethem.oneover.com/privkey.pem; then
        echo "SSL certificate for deletethem.oneover.com not found. Please ensure it is properly set up."
        exit 1
    fi
    # deletethem.com is configured in a separate vhost; no additional cert check here

    # Verify Apache configuration and restart
    echo "Testing Apache configuration..."
    if sudo apachectl configtest; then
        echo "Configuration test passed. Restarting Apache..."
        sudo systemctl restart httpd
        echo "Apache restart attempted."
    else
        echo "Apache configuration test failed."
        exit 1
    fi
'

# Clean up local zip files
rm -rf /Users/jonruppel/Sites/deletethem/zips
rm -f /Users/jonruppel/Sites/deletethem/zips.zip
