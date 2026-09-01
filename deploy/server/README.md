# Large social uploads

Cerqle accepts social-video files up to 500 MB. The request envelope is 520 MB
to leave room for multipart metadata.

1. Install `php-social-uploads.ini` for the PHP-FPM version serving Cerqle.
2. Include `nginx-social-uploads.conf` in the Cerqle Nginx server block.
3. Restart PHP-FPM, run `nginx -t`, and reload Nginx.
4. Confirm the FPM values (CLI PHP may use a different ini):
   `upload_max_filesize=500M`, `post_max_size=520M`.
5. Apply `docker/supervisor/cerqle.conf` so large publishing jobs have enough
   time and provider-processing confirmation jobs can use their own retry count.

Uploaded social media is still subject to the client's plan storage quota.
