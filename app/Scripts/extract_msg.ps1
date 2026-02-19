param (
    [string]$msgPath,
    [string]$outputDir
)

try {
    # Validate input
    if (-not (Test-Path $msgPath)) {
        Write-Output "Error: MSG file not found: $msgPath"
        exit 1
    }

    if (-not (Test-Path $outputDir)) {
        New-Item -ItemType Directory -Force -Path $outputDir | Out-Null
    }

    # Initialize Outlook COM
    $outlook = New-Object -ComObject Outlook.Application
    # $namespace = $outlook.GetNamespace("MAPI") # Not needed
    $msg = $outlook.Session.OpenSharedItem($msgPath)

    # Extract Body
    $body = $msg.Body
    
    # Extract Subject
    $subject = $msg.Subject

    # Extract Attachments
    $attachments = @()
    foreach ($att in $msg.Attachments) {
        $cleanName = $att.FileName -replace '[^a-zA-Z0-9\._\-]', '_'
        $savePath = Join-Path $outputDir $cleanName
        $att.SaveAsFile($savePath)
        $attachments += @{
            "original_name" = $att.FileName
            "saved_path"    = $savePath
            "size"          = $att.Size
        }
    }

    # Prepare Result
    $result = @{
        "status"      = "success"
        "subject"     = $subject
        "body"        = $body
        "attachments" = $attachments
    }

    # Output JSON
    $json = $result | ConvertTo-Json -Depth 5 -Compress
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
    Write-Output $json

}
catch {
    $errorMsg = $_.Exception.Message
    $result = @{
        "status"  = "error"
        "message" = $errorMsg
    }
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
    Write-Output ($result | ConvertTo-Json -Compress)
    exit 1
}
finally {
    # Cleanup COM objects
    # Cleanup COM objects
    if ($msg) { 
        try { $msg.Close(0) } catch {} 
        [System.Runtime.Interopservices.Marshal]::ReleaseComObject($msg) | Out-Null
    }
    if ($outlook) {
        [System.Runtime.Interopservices.Marshal]::ReleaseComObject($outlook) | Out-Null
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
