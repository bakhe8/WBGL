Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

projectPath = fso.GetParentFolderName(WScript.ScriptFullName)
cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File """ & projectPath & "\wbgl_server.ps1"" -Action restart -Port 8181 -OpenBrowser"

shell.Run cmd, 0, False
