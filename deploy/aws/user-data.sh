#!/bin/bash
# First-boot provisioning for the application host (Amazon Linux 2023).
#
# Installs Docker, drops the helper scripts in place, and enables the unit that
# runs the stack. The AWS CLI is already present on AL2023.

set -euxo pipefail

COMPOSE_VERSION=v2.32.4
APP_DIR=/opt/inventory-tracker

dnf install -y docker
systemctl enable --now docker

install -d /usr/libexec/docker/cli-plugins
curl -fsSL \
    "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64" \
    -o /usr/libexec/docker/cli-plugins/docker-compose
chmod +x /usr/libexec/docker/cli-plugins/docker-compose

install -d "$APP_DIR"

cat > /usr/local/bin/ecr-login <<'EOF'
#!/bin/bash
set -euo pipefail
REGION=us-east-1
REGISTRY=100995727620.dkr.ecr.${REGION}.amazonaws.com
aws ecr get-login-password --region "$REGION" \
    | docker login --username AWS --password-stdin "$REGISTRY"
EOF
chmod 755 /usr/local/bin/ecr-login

# fetch-env, docker-compose.aws.yml and the unit file are copied up by
# scripts/deploy-to-ec2.ps1; this only enables what runs them.
systemctl daemon-reload
