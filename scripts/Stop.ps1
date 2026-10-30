. "$PSScriptRoot\Common.ps1"
Push-Location $Script:ProjectRoot
try {
    Invoke-Docker @('compose', '-f', 'compose.yml', '-p', 'accesspolicy', 'down')
    Write-Output 'AccessPolicy stopped. PostgreSQL volume retained.'
} finally { Pop-Location }
