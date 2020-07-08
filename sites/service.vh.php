server {
    listen           *:80;

    #listen          *:443 ssl http2;
    # HSTS (ngx_http_headers_module is required) (15768000 seconds = 6 months)
    # decommentare solo se HTTPS in uso
    #add_header Strict-Transport-Security max-age=15768000;
    #ssl_certificate /etc/letsencrypt/live/www.service.com/fullchain.pem;
    #ssl_certificate_key /etc/letsencrypt/live/www.service.com/privkey.pem;

    # Indirizzo del sito
    server_name     www.service.com;

    root /var/www/sites/www.service.com/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        include conf/php/7.2;
    }
 
    include conf/common.conf;
}