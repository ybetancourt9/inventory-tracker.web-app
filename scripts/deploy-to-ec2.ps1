# Copies the deployment files to the application host and restarts the stack.
#
# Everything goes over SSM Run Command, so the instance needs no SSH port, no
# key pair, and no inbound rule at all.

$ErrorActionPreference = 'Stop'

$AwsProfile = 'capstone'
$Region     = 'us-east-1'
$Root       = Split-Path $PSScriptRoot -Parent

$InstanceId = $env:INVENTORY_TRACKER_INSTANCE_ID
if (-not $InstanceId) {
    throw 'Set INVENTORY_TRACKER_INSTANCE_ID to the application host instance id.'
}

$Tag = if ($args.Count -gt 0) { $args[0] } else { (git -C $Root rev-parse --short HEAD) }

function Encode($path) {
    [Convert]::ToBase64String([IO.File]::ReadAllBytes($path))
}

# Base64 avoids every quoting problem that arises from pushing file contents
# through a JSON command document.
$files = @{
    '/opt/inventory-tracker/docker-compose.aws.yml'          = Encode "$Root/docker-compose.aws.yml"
    '/usr/local/bin/fetch-env'                               = Encode "$Root/deploy/aws/fetch-env.sh"
    '/etc/systemd/system/inventory-tracker.service'          = Encode "$Root/deploy/aws/inventory-tracker.service"
}

$commands = @(
    "set -euo pipefail",
    "install -d /opt/inventory-tracker"
)

foreach ($dest in $files.Keys) {
    $commands += "echo '$($files[$dest])' | base64 -d > '$dest'"
}

$commands += @(
    "chmod 755 /usr/local/bin/fetch-env",
    "chmod 644 /etc/systemd/system/inventory-tracker.service",
    "printf 'ECR_REGISTRY=100995727620.dkr.ecr.$Region.amazonaws.com\nIMAGE_TAG=$Tag\n' > /opt/inventory-tracker/.env",
    "systemctl daemon-reload",
    "systemctl enable inventory-tracker",
    "systemctl restart inventory-tracker",
    "sleep 15",
    "docker compose -f /opt/inventory-tracker/docker-compose.aws.yml ps"
)

$payload = @{ commands = $commands } | ConvertTo-Json -Depth 5 -Compress

$commandId = aws ssm send-command --profile $AwsProfile --region $Region `
    --instance-ids $InstanceId `
    --document-name AWS-RunShellScript `
    --parameters $payload `
    --query 'Command.CommandId' --output text

Write-Host "Command $commandId dispatched, waiting..."

do {
    Start-Sleep -Seconds 5
    $status = aws ssm get-command-invocation --profile $AwsProfile --region $Region `
        --command-id $commandId --instance-id $InstanceId `
        --query Status --output text
    Write-Host "  $status"
} while ($status -in @('Pending', 'InProgress', 'Delayed'))

aws ssm get-command-invocation --profile $AwsProfile --region $Region `
    --command-id $commandId --instance-id $InstanceId `
    --query '[StandardOutputContent,StandardErrorContent]' --output text

if ($status -ne 'Success') { throw "Deployment finished with status $status" }
