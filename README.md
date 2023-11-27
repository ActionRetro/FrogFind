# FrogFind
Source for the FrogFind search engine for vintage computers

Love the frog. Be the frog.

## Selfhosted Usage
### Docker
**Installation**  

1. Install Docker and docker-compose
1. Create directory, in that directory create `docker-compose.yaml` file with content
    ```
    version: '3.7'
    services:

    web:
        image: ghcr.io/actionretro/frogfind:main
        ports:
        - 8080:8080
        restart: unless-stopped
    ```
1. Run `docker-compose up -d` in directory where you created `docker-compose.yaml`
1. After a minute (depends on your internet speed), you should be able to access FrogFind on port 8080

Alternatively, you can just use `docker run`.
```
docker run -p 8080:8080 ghcr.io/actionretro/frogfind:main
```

**Update**  

To update image, run in directory `docker-compose up -d --pull`. It will try to pull latest image.

### Just a regular plain server
* You need to have some kind of webserver - Apache2/nginx and PHP installed. You also need Composer installed.

1. Upload files from this repository to your server
2. On your server, in the webroot, run `composer install` to install required packages
3. Done, visit your website