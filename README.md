# Magic Photo Gallery

This is an all-in-one image gallery which you simply put inside your images directory and open with a browser.
It requires and HTTPD server with PHP support.

Just simply use my [docker image](https://github.com/napalmz/httpd-base), as shown [here](#Deployment).

## Screenshots

![App Screenshot](https://via.placeholder.com/468x300?text=App+Screenshot+Here)

## Deployment

First, move to your image directory and download the app:

```
wget https://github.com/napalmz/Magic-Photo-Gallery/index.php
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
      - "/my/photo/directory:/var/www/site"
      - "/my/photo/directory/log:/var/log/apache2"
    ports:
      - 80:80
    restart: always
```
