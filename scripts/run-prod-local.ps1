# Runs the production images locally against RDS, to verify what will be
# deployed rather than a development approximation.
#
# Requires scripts/rds-tunnel.ps1 to be running in another window. Secrets are
# read from SSM Parameter Store into this process only; nothing is written to
# disk and no .env file is involved.

$ErrorActionPreference = 'Stop'

$AwsProfile = 'capstone'
$Root = Split-Path $PSScriptRoot -Parent

if (-not (Test-NetConnection -ComputerName 127.0.0.1 -Port 13306 -InformationLevel Quiet)) {
    throw 'Tunnel is not up. Run scripts/rds-tunnel.ps1 in another window first.'
}

function Get-Secret($name) {
    aws ssm get-parameter --profile $AwsProfile --name $name --with-decryption `
        --query Parameter.Value --output text
}

$env:DB_HOST     = 'host.docker.internal'
$env:DB_PORT     = '13306'
$env:DB_NAME     = 'inventory_tracker'
$env:DB_USER     = 'inventory_app'
$env:DB_PASSWORD = Get-Secret '/inventory-tracker/prod/db/app-password'

# The tunnel presents the database as localhost, which no RDS certificate
# names. Encryption still applies; only hostname matching is skipped.
$env:DB_SSL_VERIFY = 'false'

$env:JWT_SECRET = Get-Secret '/inventory-tracker/prod/jwt-secret'

docker compose -f "$Root/docker-compose.prod.yml" up --build -d

Write-Host ''
Write-Host 'Production stack on http://127.0.0.1:8081'
Write-Host 'Stop with: docker compose -f docker-compose.prod.yml down'
