. "$PSScriptRoot\Common.ps1"
Push-Location $Script:ProjectRoot
$savedTest = $env:TEST_DB_PASSWORD
$saved = @{}
$testStackTouched = $false
try {
    & "$PSScriptRoot\Init.ps1"
    if (Get-NetTCPConnection -LocalPort 5459 -State Listen -ErrorAction SilentlyContinue) { throw 'Port 5459 is already in use. No service was stopped.' }
    $values = Get-PrivateEnv
    foreach ($key in @('APP_KEY', 'DEMO_OWNER_PASSWORD', 'DEMO_ADMIN_PASSWORD', 'DEMO_MEMBER_PASSWORD', 'DEMO_SECOND_OWNER_PASSWORD')) {
        $saved[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $values[$key], 'Process')
    }
    $env:TEST_DB_PASSWORD = New-PrivateHex
    Invoke-Docker @('compose', '-f', 'compose.test.yml', '-p', 'accesspolicy-test', 'build', '--progress', 'plain', 'tests')
    $testStackTouched = $true
    Invoke-Docker @('compose', '-f', 'compose.test.yml', '-p', 'accesspolicy-test', 'up', '-d', '--wait', 'postgres')
    Invoke-Docker @('compose', '-f', 'compose.test.yml', '-p', 'accesspolicy-test', 'run', '--rm', '--no-deps', 'tests')
} finally {
    try { if ($testStackTouched) { Invoke-Docker @('compose', '-f', 'compose.test.yml', '-p', 'accesspolicy-test', 'down') } } finally {
        foreach ($key in $saved.Keys) { [Environment]::SetEnvironmentVariable($key, $saved[$key], 'Process') }
        $env:TEST_DB_PASSWORD = $savedTest
        Pop-Location
    }
}
