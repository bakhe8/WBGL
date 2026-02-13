@echo off
REM Composer Helper Script - يستخدم PHP المحلي إذا كان موجوداً

setlocal

REM تحديد مسار PHP
set PHP_PATH=%~dp0php\php.exe

REM التحقق من وجود PHP محلي
if exist "%PHP_PATH%" (
    echo استخدام PHP المحلي...
    "%PHP_PATH%" "%~dp0composer.phar" %*
) else (
    echo استخدام PHP من النظام...
    php "%~dp0composer.phar" %*
)

endlocal
