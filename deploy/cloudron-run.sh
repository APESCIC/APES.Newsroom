#!/bin/bash
#
# ONE-TIME MANUAL SETUP. This file is a reference to copy to
# /app/data/run.sh via the Cloudron File Manager (or `cloudron push`).
# Cloudron's LAMP app runs /app/data/run.sh before the app starts on every
# boot/restart (docs.cloudron.io/packages/lamp#custom-startup-script).
#
# It does two things:
#   1. Runs the queue worker in the background, against whatever release
#      /app/data/current currently points to.
#   2. PHP session storage must live under /run, not the read-only
#      filesystem (docs.cloudron.io/packaging/cheat-sheet#php).
#
# The scheduler is NOT started here - it runs via Cloudron's own per-app
# Cron feature (dashboard -> app -> Cron), not this script. See
# docs/deployment.md for the exact entry to add there.

mkdir -p /run/php/sessions
chown www-data:www-data /run/php/sessions

if [ -L /app/data/current ]; then
    echo "Starting queue worker against $(readlink /app/data/current)"
    # Preserve CLOUDRON_* so the worker uses MySQL/Redis/SMTP, not .env sqlite.
    bash /app/data/current/deploy/cloudron-www-data.sh \
        /usr/bin/php /app/data/current/artisan queue:work \
        --queue=default,mail-test --sleep=3 --tries=3 --max-time=3600 &
else
    echo "No /app/data/current release yet - skipping queue worker start."
fi
