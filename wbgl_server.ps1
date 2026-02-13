param(
    [ValidateSet("start", "stop", "restart", "toggle")]
    [string]$Action = "toggle",
    [int]$Port = 0,
    [switch]$OpenBrowser,
    [switch]$Silent
)

$ErrorActionPreference = "SilentlyContinue"

$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidFile = Join-Path $projectPath "server.pid"
$portFile = Join-Path $projectPath "server.port"
$logsDir = Join-Path $projectPath "storage\logs"

function Write-Info {
    param(
        [string]$Message,
        [string]$Color = "Gray"
    )

    if (-not $Silent) {
        Write-Host $Message -ForegroundColor $Color
    }
}

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

function Test-PortAvailable {
    param([int]$TargetPort)

    try {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $TargetPort)
        $listener.Start()
        $listener.Stop()
        return $true
    }
    catch {
        return $false
    }
}

function Get-TargetPort {
    if ($Port -gt 0) {
        return $Port
    }

    $envPortRaw = $env:WBGL_PORT
    if ($envPortRaw) {
        [int]$envPort = 0
        if ([int]::TryParse($envPortRaw, [ref]$envPort) -and $envPort -ge 1024 -and $envPort -le 65535) {
            return $envPort
        }
        Write-Info "✗ قيمة WBGL_PORT غير صالحة: $envPortRaw" "Red"
        exit 1
    }

    for ($candidatePort = 8000; $candidatePort -le 8100; $candidatePort++) {
        if (Test-PortAvailable -TargetPort $candidatePort) {
            return $candidatePort
        }
    }

    Write-Info "✗ لا يوجد منفذ متاح ضمن النطاق 8000-8100" "Red"
    exit 1
}

function Get-PidsOnPort {
    param([int]$TargetPort)

    $owningPids = @()

    try {
        $owningPids = Get-NetTCPConnection -LocalPort $TargetPort -ErrorAction Stop |
            Select-Object -ExpandProperty OwningProcess -Unique
    }
    catch {
        $netstatLines = netstat -ano | Select-String ":$TargetPort"
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

    return $owningPids
}

function Stop-ProcessById {
    param([int]$ProcessId)

    if (-not $ProcessId) {
        return
    }

    $proc = Get-Process -Id $ProcessId -ErrorAction SilentlyContinue
    if ($proc) {
        Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
    }
}

function Stop-FromPidFile {
    if (-not (Test-Path $pidFile)) {
        return
    }

    $processIdFromFile = Get-Content $pidFile | Select-Object -First 1
    if ($processIdFromFile -and ($processIdFromFile -as [int])) {
        Stop-ProcessById -ProcessId ([int]$processIdFromFile)
    }

    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
}

function Stop-ByPort {
    param([int]$TargetPort)

    if ($TargetPort -le 0) {
        return
    }

    $pids = Get-PidsOnPort -TargetPort $TargetPort
    foreach ($owningProcessId in $pids) {
        Stop-ProcessById -ProcessId $owningProcessId
    }
}

function Stop-Server {
    param([int]$TargetPort)

    Stop-FromPidFile
    Stop-ByPort -TargetPort $TargetPort

    if (Test-Path $pidFile) {
        Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path $portFile) {
        Remove-Item $portFile -Force -ErrorAction SilentlyContinue
    }
}

function Start-Server {
    param([int]$TargetPort)

    if (-not (Test-Path $logsDir)) {
        New-Item -ItemType Directory -Path $logsDir -Force | Out-Null
    }

    $phpPath = Resolve-PhpPath -ProjectPath $projectPath
    if (-not $phpPath) {
        Write-Info "✗ لم يتم العثور على PHP" "Red"
        exit 1
    }

    if (-not (Test-PortAvailable -TargetPort $TargetPort)) {
        Write-Info "✗ المنفذ مشغول: $TargetPort" "Red"
        exit 1
    }

    $stdoutLog = Join-Path $logsDir "server-$TargetPort.out.log"
    $stderrLog = Join-Path $logsDir "server-$TargetPort.err.log"

    $process = Start-Process -FilePath $phpPath -ArgumentList "-S localhost:$TargetPort server.php" -WorkingDirectory $projectPath -WindowStyle Hidden -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog -PassThru

    if (-not $process) {
        Write-Info "✗ فشل في تشغيل السيرفر" "Red"
        exit 1
    }

    Start-Sleep -Milliseconds 700
    if ($process.HasExited) {
        Write-Info "✗ السيرفر توقف مباشرة بعد الإقلاع" "Red"
        exit 1
    }

    $process.Id | Out-File -FilePath $pidFile -Encoding UTF8
    $TargetPort | Out-File -FilePath $portFile -Encoding UTF8

    $serverUrl = "http://localhost:$TargetPort"
    Write-Info "✓ تم تشغيل السيرفر بنجاح" "Green"
    Write-Info "  - PID: $($process.Id)" "Gray"
    Write-Info "  - PORT: $TargetPort" "Gray"
    Write-Info "  - URL: $serverUrl" "Cyan"

    if ($OpenBrowser -and -not $Silent) {
        Start-Process $serverUrl
    }
}

if ($Action -eq "toggle") {
    if (Test-Path $pidFile) {
        $pidValue = Get-Content $pidFile | Select-Object -First 1
        if ($pidValue -and ($pidValue -as [int]) -and (Get-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue)) {
            Stop-Server -TargetPort 0
            Write-Info "✓ تم إيقاف السيرفر" "Green"
            exit 0
        }
    }

    $selectedPort = Get-TargetPort
    Start-Server -TargetPort $selectedPort
    exit 0
}

if ($Action -eq "stop") {
    $selectedPort = Get-TargetPort
    Stop-Server -TargetPort $selectedPort
    Write-Info "✓ تم إيقاف السيرفر" "Green"
    exit 0
}

if ($Action -eq "restart") {
    $selectedPort = Get-TargetPort
    Stop-Server -TargetPort $selectedPort
    Start-Server -TargetPort $selectedPort
    exit 0
}

if ($Action -eq "start") {
    $selectedPort = Get-TargetPort
    Start-Server -TargetPort $selectedPort
    exit 0
}
