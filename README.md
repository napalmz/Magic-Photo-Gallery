# Magic Photo Gallery

This is an all-in-one image gallery which you simply put inside your images directory and open with a browser.
It requires and HTTPD server with PHP support.

Just simply use my [docker image](https://github.com/napalmz/httpd-base), as shown [here](#Deployment).

## Screenshots

![App Screenshot](https://via.placeholder.com/468x300?text=App+Screenshot+Here)

## Deployment

First, move to your image directory and download the app:

```
wget https://raw.githubusercontent.com/napalmz/Magic-Photo-Gallery/main/index.php
```

Then, spinup a docker container (using compose):
```compose
services:
  httpd-server-main:
    image: napalmzrpi/httpd-base:latest
    container_name: httpd-server-main
    environment:
      - TZ=Europe/Rome
    volumes:
      - "/my/app/directory/config:/var/www/site"        # Store PHP app and configurations
      - "/my/app/directory/log:/var/log/apache2"        # Store APACHE/PHP logs
      - "/my/photo/directory1:/var/www/site/Photo 1:ro" # Safely store pictures, it's READ ONLY
      - "/my/photo/directory2:/var/www/site/Photo 2:ro" # Safely store pictures, it's READ ONLY
      - "/my/photo/directory3:/var/www/site/Photo 3:ro" # Safely store pictures, it's READ ONLY
    ports:
      - 80:80
    restart: always
```
