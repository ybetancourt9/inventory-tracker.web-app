# Builds both production images and pushes them to ECR.
#
# Built for linux/amd64 explicitly: the workstation may not match the instance
# architecture, and an image built for the wrong one fails at run time with an
# exec format error rather than at push time.

# Deliberately not 'Stop': aws and docker both write progress to stderr, which
# PowerShell would turn into a terminating error. Exit codes are checked instead.
$ErrorActionPreference = 'Continue'

$AwsProfile = 'capstone'
$Region     = 'us-east-1'
$AccountId  = '100995727620'
$Registry   = "$AccountId.dkr.ecr.$Region.amazonaws.com"
$Root       = Split-Path $PSScriptRoot -Parent
$Repos      = @('inventory-tracker-api', 'inventory-tracker-web')

function Invoke-Step {
    param([string]$What, [scriptblock]$Do)

    & $Do
    if ($LASTEXITCODE -ne 0) { throw "$What failed with exit code $LASTEXITCODE" }
}

$Tag = if ($args.Count -gt 0) { $args[0] } else { (git -C $Root rev-parse --short HEAD) }

Write-Host "Pushing tag: $Tag"

# One listing rather than a describe per repository, because describing a
# missing repository is an error and would have to be swallowed.
$existing = @(aws ecr describe-repositories --profile $AwsProfile --region $Region `
        --query 'repositories[].repositoryName' --output text) -split '\s+'

foreach ($repo in $Repos) {
    if ($existing -notcontains $repo) {
        Write-Host "Creating repository $repo"
        Invoke-Step "create $repo" {
            aws ecr create-repository --profile $AwsProfile --region $Region `
                --repository-name $repo `
                --image-scanning-configuration scanOnPush=true `
                --encryption-configuration encryptionType=AES256 | Out-Null
        }
    }
}

# Piped inside cmd rather than PowerShell: PowerShell's pipeline mangles the
# token on its way to stdin and ECR answers 400. A native pipe also keeps the
# token off the command line, where --password would expose it.
Invoke-Step 'ecr login' {
    cmd /c "aws ecr get-login-password --profile $AwsProfile --region $Region | docker login --username AWS --password-stdin $Registry"
}

Invoke-Step 'build api' {
    docker build --platform linux/amd64 --target prod `
        -t "$Registry/inventory-tracker-api:$Tag" `
        -t "$Registry/inventory-tracker-api:latest" "$Root/api"
}

Invoke-Step 'build web' {
    docker build --platform linux/amd64 --target prod `
        -f "$Root/web/Dockerfile" `
        -t "$Registry/inventory-tracker-web:$Tag" `
        -t "$Registry/inventory-tracker-web:latest" "$Root"
}

foreach ($repo in $Repos) {
    Invoke-Step "push $repo" { docker push "$Registry/${repo}:$Tag" }
    Invoke-Step "push $repo latest" { docker push "$Registry/${repo}:latest" }
}

Write-Host ''
Write-Host "Pushed $Tag to $Registry"
