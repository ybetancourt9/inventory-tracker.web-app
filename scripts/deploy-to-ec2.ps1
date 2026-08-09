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
    '/opt/inventory-tracker/docker-compose.aws.yml'  = Encode "$Root/docker-compose.aws.yml"
    '/opt/inventory-tracker/Caddyfile'               = Encode "$Root/deploy/aws/Caddyfile"
    '/usr/local/bin/fetch-env'                       = Encode "$Root/deploy/aws/fetch-env.sh"
    '/etc/systemd/system/inventory-tracker.service'  = Encode "$Root/deploy/aws/inventory-tracker.service"
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
    "sleep 20",
    # Plain docker ps, not compose ps: the compose file interpolates secrets
    # that only exist for the lifetime of the unit's own start.
    "docker ps --format '{{.Names}}  {{.Status}}'",
    "curl -fsS -o /dev/null -w 'health: %{http_code}\n' http://127.0.0.1/api/health"
)

$payload = @{ commands = $commands } | ConvertTo-Json -Depth 5 -Compress

# Written to a file rather than passed inline: PowerShell strips the quotes out
# of JSON on its way to a native command. UTF8Encoding($false) because the CLI
# rejects a byte order mark.
$payloadFile = Join-Path ([IO.Path]::GetTempPath()) 'inventory-tracker-deploy.json'
[IO.File]::WriteAllText($payloadFile, $payload, (New-Object Text.UTF8Encoding($false)))

try {
    $commandId = aws ssm send-command --profile $AwsProfile --region $Region `
        --instance-ids $InstanceId `
        --document-name AWS-RunShellScript `
        --parameters "file://$payloadFile" `
        --query 'Command.CommandId' --output text
} finally {
    Remove-Item $payloadFile -ErrorAction SilentlyContinue
}

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
