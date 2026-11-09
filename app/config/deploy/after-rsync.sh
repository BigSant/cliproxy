# Run by setup's make deploy in the target's php-fpm container (sh -e), once the new code is there.
# The previous release's compiled container is still in var/cache: a command run with it would build its services
# with the old constructors. So the hook starts without it; the deploy clears and warms the cache again afterwards.
rm -rf "var/cache/${APP_ENV:-prod}"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console doctrine:migrations:up-to-date
