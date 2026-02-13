$procs = Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine -like '*agent\main.py*' }
if ($procs) {
    $procs | ForEach-Object {
        try {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
            Write-Output ("Stopped PID {0}" -f $_.ProcessId)
        } catch {
            Write-Output ("Failed to stop PID {0}: {1}" -f $_.ProcessId, $_)
        }
    }
} else {
    Write-Output 'No agent process found.'
}
