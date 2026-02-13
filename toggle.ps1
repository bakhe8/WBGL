# BGL3 Server Toggle
# يقوم بتبديل حالة السيرفر - تشغيل أو إيقاف

$ErrorActionPreference = "SilentlyContinue"
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidFile = Join-Path $projectPath "server.pid"

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
        
        Write-Host "✓ تم إيقاف السيرفر (PID: $processId)" -ForegroundColor Green
        Write-Host ""
        Start-Sleep -Seconds 2
        exit 0
    }
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

# تشغيل السيرفر في الخلفية
$processInfo = New-Object System.Diagnostics.ProcessStartInfo
$processInfo.FileName = $phpPath
$processInfo.Arguments = "-S localhost:8000 server.php"
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
    
    Write-Host "✓ تم تشغيل السيرفر بنجاح!" -ForegroundColor Green
    Write-Host "  - PID: $($process.Id)" -ForegroundColor Gray
    Write-Host "  - العنوان: http://localhost:8000" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "لإيقاف السيرفر: قم بتشغيل هذا الملف مرة أخرى" -ForegroundColor Yellow
    Write-Host ""
    
    # فتح المتصفح
    Start-Sleep -Milliseconds 500
    Start-Process "http://localhost:8000"
    
    Start-Sleep -Seconds 2
} else {
    Write-Host "✗ فشل في تشغيل السيرفر" -ForegroundColor Red
    Start-Sleep -Seconds 3
    exit 1
}
