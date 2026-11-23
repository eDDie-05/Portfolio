param(
    [Parameter(Mandatory=$true)][ValidatePattern('^https://')][string]$HrmsUrl,
    [Parameter(Mandatory=$true)][ValidatePattern('^[A-Za-z0-9._-]+$')][string]$ComputerCode,
    [string]$PublishPath = (Join-Path $PSScriptRoot 'publish')
)

$ErrorActionPreference = 'Stop'
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this installer from an elevated PowerShell session.' }

$serviceName = 'BravadoAttendanceAgent'
$serviceAccount = "NT SERVICE\$serviceName"
$exeName = 'Bravado.AttendanceAgent.exe'
$sourceExe = Join-Path $PublishPath $exeName
if (-not (Test-Path $sourceExe)) { throw "Published agent was not found at $sourceExe. Publish the project for win-x64 first." }

$secureToken = Read-Host 'Paste the one-time HRMS computer token' -AsSecureString
$tokenPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
try {
    $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer)
    $protectedToken = [Convert]::ToBase64String([Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($token), $null, [Security.Cryptography.DataProtectionScope]::LocalMachine))
}
finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)
    $token = $null
    $secureToken.Dispose()
}

$programDir = Join-Path $env:ProgramFiles 'Bravado\AttendanceAgent'
$dataDir = Join-Path $env:ProgramData 'Bravado\AttendanceAgent'
$spoolDir = Join-Path $dataDir 'spool'
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existing) { Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue; & sc.exe delete $serviceName | Out-Null; Start-Sleep -Seconds 2 }
New-Item -ItemType Directory -Force -Path $programDir, $spoolDir | Out-Null
Copy-Item -Path (Join-Path $PublishPath '*') -Destination $programDir -Recurse -Force

$config = [ordered]@{ hrmsUrl = $HrmsUrl.TrimEnd('/'); computerCode = $ComputerCode; tokenDpapi = $protectedToken; spoolPath = (Join-Path $spoolDir 'queue.jsonl') }
$configPath = Join-Path $dataDir 'agent.json'
$config | ConvertTo-Json -Depth 3 | Set-Content -Path $configPath -Encoding UTF8
$protectedToken = $null

& icacls.exe $programDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)RX' 'BUILTIN\Administrators:(OI)(CI)F' "${serviceAccount}:(OI)(CI)RX" /T | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not secure the program directory permissions.' }
& icacls.exe $dataDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'BUILTIN\Administrators:(OI)(CI)F' "${serviceAccount}:(OI)(CI)R" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not secure the agent configuration permissions.' }
& icacls.exe $spoolDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'BUILTIN\Administrators:(OI)(CI)F' "${serviceAccount}:(OI)(CI)M" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not secure the event queue permissions.' }

$binaryPath = '"' + (Join-Path $programDir $exeName) + '"'
& sc.exe create $serviceName binPath= $binaryPath start= delayed-auto obj= $serviceAccount | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Windows could not register the attendance service.' }
& sc.exe description $serviceName 'Reports interactive Windows sign-in and sign-out events to Bravado HRMS.' | Out-Null

try { Add-LocalGroupMember -Group 'Event Log Readers' -Member $serviceAccount -ErrorAction Stop }
catch { Write-Warning "Could not add the service to Event Log Readers. Confirm Security log read access before starting: $($_.Exception.Message)" }

try {
    Start-Service -Name $serviceName
    Write-Host "Installed and started $serviceName for $ComputerCode. The token is DPAPI protected in $configPath."
    Write-Host 'Confirm Security log read access and agent health in HRMS before relying on endpoint events.'
}
catch { throw "Service was installed but did not start. Check Windows Event Viewer and the service account permissions. $($_.Exception.Message)" }
