param(
  [int]$DebounceMs = 0,
  [int]$AggregateWindowMs = 1000
)

$ErrorActionPreference = 'Stop'
$cfg = Join-Path (Join-Path $PSScriptRoot '..') 'agent\config.yml'
if (!(Test-Path $cfg)) { throw "config.yml not found at $cfg" }

$content = Get-Content -Raw -Path $cfg
$content = $content -replace 'debounce_ms:\s*\d+', "debounce_ms: $DebounceMs"
$content = $content -replace 'aggregate_window_ms:\s*\d+', "aggregate_window_ms: $AggregateWindowMs"

$tmp = "$cfg.tmp"
Set-Content -Path $tmp -Value $content -Encoding UTF8
Move-Item -Force -Path $tmp -Destination $cfg
Write-Host "Updated debounce_ms=$DebounceMs aggregate_window_ms=$AggregateWindowMs"
