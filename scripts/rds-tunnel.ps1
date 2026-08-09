# Opens an SSM port-forwarding tunnel to the RDS instance on 127.0.0.1:13306.
# The database has no public endpoint, so this is the only path in from a laptop.
# Leave this window open while working; Ctrl+C closes the tunnel.

$ErrorActionPreference = 'Stop'

$Profile    = 'capstone'
$Bastion    = 'i-0658bdf03a3c5716c'
$RdsHost    = 'inventory-tracker-db.cuhiemgc0osy.us-east-1.rds.amazonaws.com'
$LocalPort  = 13306

$env:Path += ';C:\Program Files\Amazon\SessionManagerPlugin\bin'

# The bastion is stopped when idle to avoid charges, so start it if needed.
$state = aws ec2 describe-instances --profile $Profile --instance-ids $Bastion `
    --query 'Reservations[0].Instances[0].State.Name' --output text

if ($state -ne 'running') {
    Write-Host "Bastion is $state, starting it..."
    aws ec2 start-instances --profile $Profile --instance-ids $Bastion | Out-Null
    aws ec2 wait instance-running --profile $Profile --instance-ids $Bastion

    # SSM registration lags the running state by a few seconds.
    do {
        Start-Sleep -Seconds 5
        $ping = aws ssm describe-instance-information --profile $Profile `
            --filters "Key=InstanceIds,Values=$Bastion" `
            --query 'InstanceInformationList[0].PingStatus' --output text
    } while ($ping -ne 'Online')
}

Write-Host "Tunnelling 127.0.0.1:$LocalPort -> ${RdsHost}:3306"

aws ssm start-session --profile $Profile --target $Bastion `
    --document-name AWS-StartPortForwardingSessionToRemoteHost `
    --parameters "host=$RdsHost,portNumber=3306,localPortNumber=$LocalPort"
