# NGINX configuration

Make sure the folder exist:
```
mkdir -p /var/www/sites/{example.com,app.example.com}/public
mkdir -p /var/www/httpschallenges/.well-known/acme-challenge
chown -R nginx: /var/www
```

In case SELinux is enabled (`getenforce` returns `Enforcing`), make sure the `/var/www(/.*)?` path is indicated as `httpd_sys_content_t` type:  
`semanage fcontext -l | grep "/var/www(/.*)?" | grep httpd_sys_content_t`

Otherwise flag the folder correctly:
```
semanage fcontext -a -t httpd_sys_content_t "/var/www(/.*)?"
```

After doing so, restore the privileges of the folder:
```
restorecon -Rv /var/www
```

And make sure that NGINX can act as a reverse proxy
```
setsebool -P httpd_can_network_connect 1
```

# Completing the installation process
To provide a "working by default" configuration, the configuration file does not provide a self-signed certificate (since it needs to be created).  
To complete the install process, as [written in the confiuration file](https://Github.com/Pandry/nginx-config/src/branch/master/nginx.conf#L66-L78), you need to execute this command:  
```
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -keyout /etc/ssl/nginx-selfsigned.key -out /etc/ssl/nginx-selfsigned.crt
```
Leaving blank every requested field.  
After  that, uncomment the code block from line 68 to 78, and you're good to go :)  

# PHP installation process

Add REMI PHP Repo
```
yum install https://rpms.remirepo.net/enterprise/remi-release-7.rpm -y
yum install yum-utils php74 php74-php-fpm -y
```

Then edit the file `/etc/opt/remi/php74/php-fpm.d/www.conf` and make sure the following lines are uncommented and assigned to the NGINX user:

```
sed -i.bak -e 's/apache/nginx/' -E -e 's/listen = .*/listen = \/run\/php7.4-fpm.sock/' /etc/opt/remi/php74/php-fpm.d/www.conf
```

Or, the hard way

```
user = nginx
group = nginx

;replace the version with the correct PHP version !!
listen = /run/php7.4-fpm.sock

listen.owner = nginx
listen.group = nginx
```

Then start NGINX and PHP, enabling them:
```
systemctl start nginx php73-php-fpm mariadb
systemctl enable nginx php73-php-fpm mariadb
```

# Certbot installation

```
yum install epel-release -y
yum install certbot -y
certbot certonly --webroot --agree-tos --email admin@domain.com -w /var/www/httpschallenges/.well-known/acme-challenge -d service.com

```
