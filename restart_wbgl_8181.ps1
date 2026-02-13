$ErrorActionPreference = "SilentlyContinue"

$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$port = 8181
$pidFile = Join-Path $projectPath "server.pid"
$portFile = Join-Path $projectPath "server.port"

function Resolve-PhpPath {
    param([string]$ProjectPath)

    $localPhp = Join-Path $ProjectPath "php\php.exe"
    if (Test-Path $localPhp) {
        return $localPhp
    }

    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd) {
        return $phpCmd.Source
    }

    return $null
}

function Stop-ServerFromPidFile {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        return
    }

    $processIdFromFile = Get-Content $Path | Select-Object -First 1
    if ($processIdFromFile -and ($processIdFromFile -as [int])) {
        $proc = Get-Process -Id ([int]$processIdFromFile) -ErrorAction SilentlyContinue
        if ($proc) {
            Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
        }
    }

    Remove-Item $Path -Force -ErrorAction SilentlyContinue
}

function Stop-ServerOnPort {
    param([int]$Port)

    $owningPids = @()

    try {
        $owningPids = Get-NetTCPConnection -LocalPort $Port -ErrorAction Stop |
            Select-Object -ExpandProperty OwningProcess -Unique
    } catch {
        $netstatLines = netstat -ano | Select-String ":$Port"
        foreach ($line in $netstatLines) {
            $parts = ($line.ToString() -replace '^\s+', '') -split '\s+'
            if ($parts.Length -ge 5) {
                $candidate = $parts[$parts.Length - 1]
                if ($candidate -as [int]) {
                    $owningPids += [int]$candidate
                }
            }
        }
        $owningPids = $owningPids | Select-Object -Unique
    }

    foreach ($owningProcessId in $owningPids) {
        $proc = Get-Process -Id $owningProcessId -ErrorAction SilentlyContinue
        if ($proc) {
            Stop-Process -Id $owningProcessId -Force -ErrorAction SilentlyContinue
        }
    }
}

Stop-ServerFromPidFile -Path $pidFile
Stop-ServerOnPort -Port $port

if (Test-Path $portFile) {
    Remove-Item $portFile -Force -ErrorAction SilentlyContinue
}

$logsDir = Join-Path $projectPath "storage\logs"
if (-not (Test-Path $logsDir)) {
    New-Item -ItemType Directory -Path $logsDir -Force | Out-Null
}

$phpPath = Resolve-PhpPath -ProjectPath $projectPath
if (-not $phpPath) {
    exit 1
}

$stdoutLog = Join-Path $logsDir "server-8181.out.log"
$stderrLog = Join-Path $logsDir "server-8181.err.log"

$process = Start-Process -FilePath $phpPath -ArgumentList "-S localhost:$port server.php" -WorkingDirectory $projectPath -WindowStyle Hidden -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog -PassThru

if (-not $process) {
    exit 1
}

Start-Sleep -Milliseconds 700

if ($process.HasExited) {
    exit 1
}

$process.Id | Out-File -FilePath $pidFile -Encoding UTF8
$port | Out-File -FilePath $portFile -Encoding UTF8
