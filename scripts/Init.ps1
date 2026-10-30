. "$PSScriptRoot\Common.ps1"
$envPath = Join-Path $Script:ProjectRoot '.env'
if (Test-Path -LiteralPath $envPath) {
    Write-Output 'Existing .env preserved. Passwords and APP_KEY were not changed.'
    exit 0
}
$local = Join-Path $Script:ProjectRoot '.local'
New-Item -ItemType Directory -Force -Path $local | Out-Null
$bytes = New-Object byte[] 32
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
$appKey = 'base64:' + [Convert]::ToBase64String($bytes)
$owner = New-PrivateHex
$admin = New-PrivateHex
$member = New-PrivateHex
$second = New-PrivateHex
$content = @(
    'APP_NAME=AccessPolicy', 'APP_ENV=local', 'APP_DEBUG=false', 'APP_URL=http://127.0.0.1:8102',
    "APP_KEY=$appKey", 'DB_CONNECTION=pgsql', 'DB_HOST=postgres', 'DB_PORT=5432',
    'DB_DATABASE=accesspolicy', 'DB_USERNAME=accesspolicy', "DB_PASSWORD=$(New-PrivateHex)",
    "DEMO_OWNER_PASSWORD=$owner", "DEMO_ADMIN_PASSWORD=$admin", "DEMO_MEMBER_PASSWORD=$member",
    "DEMO_SECOND_OWNER_PASSWORD=$second", 'CACHE_STORE=database', 'SESSION_DRIVER=array', 'LOG_CHANNEL=stderr'
) -join "`n"
[IO.File]::WriteAllText($envPath, $content + "`n", (New-Object Text.UTF8Encoding($false)))
$credentials = "Private local demo accounts. Do not publish.`nowner@accesspolicy.test : $owner`nadmin@accesspolicy.test : $admin`nmember@accesspolicy.test : $member`nsecond-owner@accesspolicy.test : $second`n"
[IO.File]::WriteAllText((Join-Path $local 'LOCAL-CREDENTIALS.txt'), $credentials, (New-Object Text.UTF8Encoding($false)))
Write-Output 'Created private .env and .local/LOCAL-CREDENTIALS.txt. No credentials were printed.'
