# Run by setup's make deploy in the release image, on the release copy (after composer install --no-dev).
# Assets for prod: AssetMapper serves compiled files only.
APP_ENV=prod php bin/console importmap:install
APP_ENV=prod php bin/console asset-map:compile
