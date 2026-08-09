#!/bin/bash
# Writes the secret environment variables the stack needs.
#
# The output goes to /run, which is tmpfs: it lives in memory, never reaches the
# EBS volume, and disappears on reboot. Writing it anywhere on disk would put
# plaintext credentials into every snapshot of this instance.
#
# Credentials come from the instance role, so there is no access key here.

set -euo pipefail

PREFIX=/inventory-tracker/prod
OUT=/run/inventory-tracker.env

umask 077

TMP="$(mktemp /run/inventory-tracker.env.XXXXXX)"

emit() {
    local param="$1" var="$2" value
    value="$(aws ssm get-parameter --name "$param" --with-decryption \
        --query Parameter.Value --output text)"

    if [ -z "$value" ] || [ "$value" = "None" ]; then
        echo "fetch-env: $param is empty or missing" >&2
        exit 1
    fi

    printf '%s=%s\n' "$var" "$value" >> "$TMP"
}

emit "$PREFIX/db/app-password" DB_PASSWORD
emit "$PREFIX/jwt-secret"      JWT_SECRET

chmod 600 "$TMP"
mv "$TMP" "$OUT"
