# syntax=docker/dockerfile:1

# The build context is the repository ROOT, not this directory.
#
# nginx needs `public/` on its OWN filesystem: `try_files $uri ...` resolves
# static files locally and only falls through to PHP-FPM for the rest. In
# development the directory arrives as a bind mount; a production image that
# mounts nothing has to carry it.

# The whole of public/ is taken from the application image rather than from the
# build context, and that is the difference between a reproducible image and one
# that depends on the machine it was built on. `public/bundles/` (the API-doc
# assets) and `public/build/` are both gitignored generated output: copying them
# from the context works on a developer box that happens to have them and
# quietly produces an nginx image without them on a clean clone.
#
# The application image generates both — the Encore stage emits build/, and
# `assets:install` emits bundles/ — so taking public/ from there is the single
# source of truth, and it removes the duplicate Encore build this file used to
# carry.
#
# The cost is an explicit build order: this image needs the application image to
# exist first, which `make prod-build` guarantees. Built out of order the COPY
# fails outright rather than silently producing a half-empty document root.
ARG APP_IMAGE=aihm-php:prod

FROM ${APP_IMAGE} AS app

FROM nginx:alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# index.php comes along and is harmless: its location block is `internal`, so a
# direct request for it is a 404 rather than a listing of the front controller.
COPY --from=app /var/www/html/public /var/www/html/public
