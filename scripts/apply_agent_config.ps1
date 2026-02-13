# Apply recommended agent configuration
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File scripts/apply_agent_config.ps1

$ErrorActionPreference = 'Stop'

$agentDir = Join-Path $PSScriptRoot '..' | Resolve-Path
$agentConfigPath = Join-Path $agentDir 'agent' | Join-Path -ChildPath 'config.yml'
$agentDirPath = Split-Path $agentConfigPath -Parent

if (!(Test-Path $agentDirPath)) {
    New-Item -ItemType Directory -Force -Path $agentDirPath | Out-Null
}

$yml = @'
watch:
  path: .
  recursive: true

ignore:
  paths:
    - agent/events.log
    - agent/events.jsonl
    - agent/status.json
    - agent/status.json.tmp
    - agent/stop_agent.ps1
    - agent/commands
  globs:
    - ".git/**"
    - "agent/commands/**"

features:
  console_log: true
  text_log: true
  jsonl_log: true
  status: true
  event_types: ["created", "modified", "deleted"]
  debounce_ms: 150
  aggregate_window_ms: 2000

logging:
  level: "INFO"
  file: agent/events.log

jsonl:
  file: agent/events.jsonl

status:
  file: agent/status.json
  interval_sec: 5.0

commands:
  enabled: true
  inbox: agent/commands/inbox
  outbox: agent/commands/outbox
  poll_interval_ms: 500
'@

# Write atomically
$tempPath = "$agentConfigPath.tmp"
$yml | Out-File -FilePath $tempPath -Encoding UTF8 -Force
Move-Item -Force -Path $tempPath -Destination $agentConfigPath

Write-Host "Applied agent config to $agentConfigPath"
