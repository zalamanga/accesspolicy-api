param([switch]$NoBuild)
. "$PSScriptRoot\Common.ps1"
Push-Location $Script:ProjectRoot
try {
    & "$PSScriptRoot\Init.ps1"
    if (-not $NoBuild) { Invoke-Docker @('compose', '-f', 'compose.yml', '-p', 'accesspolicy', 'build', '--progress', 'plain', 'api') }
    Invoke-Docker @('compose', '-f', 'compose.yml', '-p', 'accesspolicy', 'up', '-d', '--wait', '--wait-timeout', '180')
    Write-Output 'API: http://127.0.0.1:8102 | Contract: http://127.0.0.1:8102/openapi.json'
} finally { Pop-Location }
