# Builds both production images and pushes them to ECR.
#
# Built for linux/amd64 explicitly: the workstation may not match the instance
# architecture, and an image built for the wrong one fails at run time with an
# exec format error rather than at push time.

$ErrorActionPreference = 'Stop'

$AwsProfile = 'capstone'
$Region     = 'us-east-1'
$AccountId  = '100995727620'
$Registry   = "$AccountId.dkr.ecr.$Region.amazonaws.com"
$Root       = Split-Path $PSScriptRoot -Parent

$Tag = if ($args.Count -gt 0) { $args[0] } else { (git -C $Root rev-parse --short HEAD) }

Write-Host "Pushing tag: $Tag"

foreach ($repo in @('inventory-tracker-api', 'inventory-tracker-web')) {
    aws ecr describe-repositories --profile $AwsProfile --region $Region `
        --repository-names $repo 2>$null | Out-Null

    if ($LASTEXITCODE -ne 0) {
        Write-Host "Creating repository $repo"
        aws ecr create-repository --profile $AwsProfile --region $Region `
            --repository-name $repo `
            --image-scanning-configuration scanOnPush=true `
            --encryption-configuration encryptionType=AES256 | Out-Null
    }
}

aws ecr get-login-password --profile $AwsProfile --region $Region |
    docker login --username AWS --password-stdin $Registry

docker build --platform linux/amd64 --target prod `
    -t "$Registry/inventory-tracker-api:$Tag" `
    -t "$Registry/inventory-tracker-api:latest" "$Root/api"

docker build --platform linux/amd64 --target prod `
    -f "$Root/web/Dockerfile" `
    -t "$Registry/inventory-tracker-web:$Tag" `
    -t "$Registry/inventory-tracker-web:latest" "$Root"

foreach ($repo in @('inventory-tracker-api', 'inventory-tracker-web')) {
    docker push "$Registry/${repo}:$Tag"
    docker push "$Registry/${repo}:latest"
}

Write-Host ''
Write-Host "Pushed $Tag to $Registry"
