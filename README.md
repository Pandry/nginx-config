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

# Sample `docker-compose.yml` file with Let's Encrypt

```
version: '3.7'
services:
  nginx:
    image: nginx
    ports:
      - 80:80/tcp
      - 443:443/tcp
    volumes:
      - /etc/nginx:/etc/nginx:ro
      - /var/log/nginx:/var/log/nginx:rw
      - /var/www:/var/www:ro
      - /etc/ssl/:/etc/ssl:ro
      - /etc/letsencrypt/archive:/etc/archive:ro
    logging:
      options:
        max-size: "10m"
      driver: json-file
    networks:
      - nginx
    command: "/bin/sh -c 'while :; do sleep 1h & wait $${!}; nginx -s reload; done & nginx -g \"daemon off;\"'"

  certbot:   
    image: certbot/certbot
    volumes:
      - /etc/nginx:/etc/nginx:ro
      - /etc/letsencrypt:/etc/letsencrypt
      - /var/lib/letsencrypt:/var/lib/letsencrypt
      - /etc/ssl/:/etc/letsencrypt/live
      - /var/www/httpschallenges:/var/www/httpschallenges
      - /var/log/letsencrypt:/var/log/letsencrypt
    networks:
      - nginx
    logging:
      options:
        max-size: "1m"
        max-file: "10"
      driver: json-file
    entrypoint: 
      - /bin/sh
      - -c 
      - |
        trap exit TERM; while :; do 
          for CONFIG_FILE in /etc/nginx/sites/*.vh
          do
            DOMAINS=$$(grep -h '[^$$#]\s*server_name' $$CONFIG_FILE | sed -r 's/[[:blank:]]*server_name[[:blank:]]+(.*;[[:blank:]]*#[[:blank:]]*NL)?//' | sed '/^[[:blank:]]*$$/d' | sed -r 's/[[:blank:]]+/ /g' | rev | cut -c 2- | rev)
            if [ $$(echo $$DOMAINS | wc -w)  -gt 0 ]
            then
              echo -e "[\033[0;32mOK!\033[0m] [$$DOMAINS] Geting HTTPS certificate(s) for $$CONFIG_FILE"
              certbot certonly --non-interactive --webroot --agree-tos -m admin@test.com $$(echo $$DOMAINS | xargs -n 1 echo -w /var/www/httpschallenges -d | tr '\n' ' ')
            fi
          done
          #certbot certonly --non-interactive --webroot --agree-tos -m admin@test.com $$(grep -h '[^$$#]\s*server_name' /etc/nginx/sites/*.vh | sed -r 's/[[:blank:]]*server_name[[:blank:]]+(.*;[[:blank:]]*#[[:blank:]]*NL)?//' | sed '/^[[:blank:]]*$$/d' | sed -r 's/[[:blank:]]+/ /g' | rev | cut -c 2- | rev | xargs -n 1 echo -w /var/www/httpschallenges -d | tr '\n' ' ')
          chown -R 101:101 /etc/letsencrypt/archive/*/*.pem 2> /dev/null || echo "No cert found!"
          sleep 12h & wait $${!}; 
        done;

networks:
  nginx:
    name: web
```

## Monitoring with prometheus
Since nginx exposes only metrics for the pay stuff, we need to rely on 3rd party modules (the awesome [nginx-module-vts](https://github.com/vozlt/nginx-module-vts) module!)  
And since we (I) don't really want to tamper too much with the base image, I've compiled the module as dynamic, with this Dockerfile (copied from the [hermanbanken's gist](https://gist.github.com/hermanbanken/96f0ff298c162a522ddbba44cad31081)):

Copy the following to in a `Dockerfile.vts`  
```Dockerfile
FROM nginx:alpine AS builder
RUN wget "http://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz" -O nginx.tar.gz
apk add --no-cache --virtual .build-deps \
  gcc \
  libc-dev \
  make \
  openssl-dev \
  pcre-dev \
  zlib-dev \
  linux-headers \
  curl \
  gnupg \
  libxslt-dev \
  gd-dev \
  geoip-dev \
  git 

RUN git clone https://github.com/vozlt/nginx-module-vts.git && \
    mkdir -p /usr/src && \
    CONFARGS=$(nginx -V 2>&1 | sed -n -e 's/^.*arguments: //p') \
    tar -zxC /usr/src -f nginx.tar.gz && \
    cd /usr/src/nginx-$NGINX_VERSION && \
    ./configure --with-compat $CONFARGS --add-dynamic-module=/nginx-module-vts && \
    make && make install
```

Now we need to compile & build the image and copy the file
```bash
IMAGE=builder-nginx-alpine-vts
docker build Dockerfile.vts -t $IMAGE
id=$(docker create $IMAGE)
docker cp $id:/usr/local/nginx/modules/ngx_http_vhost_traffic_status_module.so .
docker rm -v $id
```

And now you have in your working dir the `ngx_http_vhost_traffic_status_module.so`  
You can now put it a volume:

```yaml
services:
  nginx:
    image: nginx:alpine
    container_name: nginx
    # ...
    volumes:
      - ./ngx_http_vhost_traffic_status_module.so:/usr/local/nginx/modules/ngx_http_vhost_traffic_status_module.so
```

and configure it in the `/etc/nginx/nginx.conf` file:  

```
load_module /usr/local/nginx/modules/ngx_http_vhost_traffic_status_module.so;
# ...
http {
        vhost_traffic_status_zone;
        # ...
        server {
            listen 8828;
            server_name mynginx.stat.page.internal.lan.log.name.lol;
            #location /status {
            #    stub_status;
            #}
            location /metrics {
                vhost_traffic_status_display;
                vhost_traffic_status_display_format prometheus;
            }
        }
        # ...
```
