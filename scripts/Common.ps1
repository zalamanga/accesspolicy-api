$ErrorActionPreference = 'Stop'
$Script:ProjectRoot = Split-Path -Parent $PSScriptRoot

function Invoke-Docker {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
    $saved = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & docker @Arguments
        if ($LASTEXITCODE -ne 0) { throw "Docker failed with exit code $LASTEXITCODE" }
    } finally { $ErrorActionPreference = $saved }
}

function Get-PrivateEnv {
    $values = @{}
    foreach ($line in [System.IO.File]::ReadAllLines((Join-Path $Script:ProjectRoot '.env'))) {
        if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') { $values[$matches[1]] = $matches[2] }
    }
    return $values
}

function New-PrivateHex {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}
