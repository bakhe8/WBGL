# WBGL Server Toggle
# يقوم بتبديل حالة السيرفر - تشغيل أو إيقاف

$ErrorActionPreference = "SilentlyContinue"
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidFile = Join-Path $projectPath "server.pid"
$portFile = Join-Path $projectPath "server.port"

function Test-PortAvailable {
    param([int]$Port)

    try {
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
        $listener.Start()
        $listener.Stop()
        return $true
    } catch {
        return $false
    }
}

function Get-ServerPort {
    $envPortRaw = $env:WBGL_PORT

    if ($envPortRaw) {
        [int]$envPort = 0
        if (-not [int]::TryParse($envPortRaw, [ref]$envPort) -or $envPort -lt 1024 -or $envPort -gt 65535) {
            Write-Host "✗ قيمة WBGL_PORT غير صالحة: $envPortRaw" -ForegroundColor Red
            Write-Host "  استخدم قيمة رقمية بين 1024 و 65535" -ForegroundColor Yellow
            exit 1
        }

        if (-not (Test-PortAvailable -Port $envPort)) {
            Write-Host "✗ المنفذ المحدد في WBGL_PORT مشغول: $envPort" -ForegroundColor Red
            Write-Host "  غيّر WBGL_PORT أو احذف المتغير لاختيار منفذ تلقائي" -ForegroundColor Yellow
            exit 1
        }

        return $envPort
    }

    for ($port = 8000; $port -le 8100; $port++) {
        if (Test-PortAvailable -Port $port) {
            return $port
        }
    }

    Write-Host "✗ لا يوجد منفذ متاح ضمن النطاق 8000-8100" -ForegroundColor Red
    Write-Host "  أغلق خدمة تستخدم المنافذ أو حدّد WBGL_PORT يدويًا" -ForegroundColor Yellow
    exit 1
}

# التحقق من حالة السيرفر
if (Test-Path $pidFile) {
    $processId = Get-Content $pidFile
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    
    if ($process) {
        # السيرفر يعمل - سنقوم بإيقافه
        Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
        Write-Host "   إيقاف السيرفر" -ForegroundColor Yellow
        Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
        Write-Host ""
        
        Stop-Process -Id $processId -Force
        Remove-Item $pidFile -Force
        if (Test-Path $portFile) {
            Remove-Item $portFile -Force
        }
        
        Write-Host "✓ تم إيقاف السيرفر (PID: $processId)" -ForegroundColor Green
        Write-Host ""
        Start-Sleep -Seconds 2
        exit 0
    }

    Remove-Item $pidFile -Force
}

# السيرفر متوقف - سنقوم بتشغيله
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host "   تشغيل السيرفر" -ForegroundColor Green
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host ""

# إنشاء مجلد logs إذا لم يكن موجوداً
$logsDir = Join-Path $projectPath "storage\logs"
if (-not (Test-Path $logsDir)) {
    New-Item -ItemType Directory -Path $logsDir -Force | Out-Null
}

# تحديد مسار PHP (محلي أو من النظام)
$phpPath = Join-Path $projectPath "php\php.exe"
if (-not (Test-Path $phpPath)) {
    # استخدام PHP من النظام
    $phpPath = "php"
}

$serverPort = Get-ServerPort
$serverUrl = "http://localhost:$serverPort"

# تشغيل السيرفر في الخلفية
$processInfo = New-Object System.Diagnostics.ProcessStartInfo
$processInfo.FileName = $phpPath
$processInfo.Arguments = "-S localhost:$serverPort server.php"
$processInfo.WorkingDirectory = $projectPath
$processInfo.UseShellExecute = $false
$processInfo.CreateNoWindow = $true
$processInfo.RedirectStandardOutput = $true
$processInfo.RedirectStandardError = $true

$process = New-Object System.Diagnostics.Process
$process.StartInfo = $processInfo

# بدء العملية
$started = $process.Start()

if ($started) {
    # حفظ PID
    $process.Id | Out-File -FilePath $pidFile -Encoding UTF8
    $serverPort | Out-File -FilePath $portFile -Encoding UTF8
    
    Write-Host "✓ تم تشغيل السيرفر بنجاح!" -ForegroundColor Green
    Write-Host "  - PID: $($process.Id)" -ForegroundColor Gray
    Write-Host "  - المنفذ: $serverPort" -ForegroundColor Gray
    Write-Host "  - العنوان: $serverUrl" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "لإيقاف السيرفر: قم بتشغيل هذا الملف مرة أخرى" -ForegroundColor Yellow
    Write-Host ""
    
    # فتح المتصفح
    Start-Sleep -Milliseconds 500
    Start-Process $serverUrl
    
    Start-Sleep -Seconds 2
} else {
    Write-Host "✗ فشل في تشغيل السيرفر" -ForegroundColor Red
    Start-Sleep -Seconds 3
    exit 1
}
