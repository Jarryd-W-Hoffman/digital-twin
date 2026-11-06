#!/bin/sh
set -e

if [ -f /import/crontab ]; then
    cp /import/crontab /var/spool/cron/crontabs/www
    chmod 600 /var/spool/cron/crontabs/www
    chown root:root /var/spool/cron/crontabs/www
fi

exec supervisord -c /etc/supervisor/supervisord.conf -n