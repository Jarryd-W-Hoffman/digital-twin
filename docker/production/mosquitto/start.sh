#!/bin/sh
# Waits for Caddy's certificate for MERIDIAN_DOMAIN, renders mosquitto.conf,
# and runs Mosquitto. When Caddy renews the certificate, Mosquitto is stopped
# so the container restarts with the new one (devices reconnect by themselves).
set -eu

case "${MERIDIAN_MQTT_AUTH_SECRET:-}" in
    '' | *[!A-Za-z0-9_-]*)
        echo "Set MERIDIAN_MQTT_AUTH_SECRET (letters, digits, - and _) in docker/production/.env." >&2
        exit 1
        ;;
esac

domain="${MERIDIAN_DOMAIN:?Set MERIDIAN_DOMAIN}"
find_cert() {
    find /caddy-data/caddy/certificates -path "*/${domain}/${domain}.crt" 2>/dev/null | head -n 1
}

cert=$(find_cert)
while [ -z "$cert" ]; do
    echo "Waiting for Caddy's certificate for ${domain}..."
    sleep 10
    cert=$(find_cert)
done
key="${cert%.crt}.key"

mkdir -p /mosquitto/certs /mosquitto/data
cp "$cert" /mosquitto/certs/cert.pem
cp "$key" /mosquitto/certs/key.pem
chown -R mosquitto:mosquitto /mosquitto/certs /mosquitto/data
chmod 600 /mosquitto/certs/key.pem
issued=$(sha256sum "$cert" | cut -d' ' -f1)

sed -e "s|@CERT@|/mosquitto/certs/cert.pem|" \
    -e "s|@KEY@|/mosquitto/certs/key.pem|" \
    -e "s|@AUTH_SECRET@|${MERIDIAN_MQTT_AUTH_SECRET}|" \
    /meridian/mosquitto.conf > /tmp/mosquitto.conf

/usr/sbin/mosquitto -c /tmp/mosquitto.conf &
broker=$!

checks=0
while kill -0 "$broker" 2>/dev/null; do
    sleep 60
    checks=$((checks + 1))
    if [ "$checks" -ge 60 ]; then
        checks=0
        if [ "$(sha256sum "$cert" | cut -d' ' -f1)" != "$issued" ]; then
            echo "The certificate was renewed; restarting to use it."
            kill "$broker"
            wait "$broker" || true
            exit 0
        fi
    fi
done
wait "$broker"
