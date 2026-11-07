#!/bin/sh
# Renders mosquitto.conf with where the app is and the secret it shares with
# the broker (MERIDIAN_MQTT_AUTH_SECRET, the same in the app's .env), then
# runs Mosquitto.
set -eu

case "${MERIDIAN_MQTT_AUTH_SECRET:-}" in
    '' | *[!A-Za-z0-9_-]*)
        echo "Set MERIDIAN_MQTT_AUTH_SECRET (letters, digits, - and _) here and in the app's .env." >&2
        exit 1
        ;;
esac

sed -e "s|@AUTH_HOST@|${MERIDIAN_AUTH_HOST:-nginx}|" \
    -e "s|@AUTH_PORT@|${MERIDIAN_AUTH_PORT:-80}|" \
    -e "s|@AUTH_SECRET@|${MERIDIAN_MQTT_AUTH_SECRET}|" \
    /meridian/mosquitto.conf > /tmp/mosquitto.conf

mkdir -p /mosquitto/data
chown -R mosquitto:mosquitto /mosquitto/data

exec /usr/sbin/mosquitto -c /tmp/mosquitto.conf
