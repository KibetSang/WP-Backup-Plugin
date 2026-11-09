#!/bin/sh
# Builds dist/bastion-backup.zip for WordPress: the plugin only, no tests, docs sources or development tools.
# Runs the tests first and refuses to package if any fail or any PHP file has a syntax error.
set -eu
cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/^define( 'BASTION_BACKUP_VERSION', '\(.*\)' );/\1/p" bastion-backup.php)
for f in $(find . -name '*.php' -not -path './vendor/*' -not -path './dist/*'); do
	php -l "$f" >/dev/null || { echo "Syntax error in $f"; exit 1; }
done
if [ "${SKIP_TESTS:-}" != "1" ]; then
	php bin/phpunit -c phpunit.xml.dist >/dev/null || { echo "Tests failed: not packaging."; exit 1; }
fi
php bin/make-pot.php >/dev/null
rm -rf dist/bastion-backup dist/bastion-backup.zip
mkdir -p dist/bastion-backup
cp -R bastion-backup.php uninstall.php readme.txt LICENSE core includes installer assets languages dist/bastion-backup/
( cd dist && zip -qrX bastion-backup.zip bastion-backup )
rm -rf dist/bastion-backup
# Nothing that should stay behind may be inside.
if unzip -l dist/bastion-backup.zip | grep -E '/(tests|bin|docs|vendor)/|\.git|PROMPT\.md|phpunit|composer\.json|\.env' ; then
	echo "Development files found in the zip: refusing it."; rm -f dist/bastion-backup.zip; exit 1
fi
echo "Built dist/bastion-backup.zip (version $VERSION, $(du -h dist/bastion-backup.zip | cut -f1))"
