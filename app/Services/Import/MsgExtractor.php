<?php

namespace App\Services\Import;

class MsgExtractor
{
    private string $scriptPath;
    private string $tempDir;

    public function __construct()
    {
        // Path to the PowerShell script
        $this->scriptPath = __DIR__ . '/../../Scripts/extract_msg.ps1';
        // Temp directory for extracted attachments
        $this->tempDir = sys_get_temp_dir() . '/wbgl_msg_' . uniqid();
    }

    /**
     * Extracts content and attachments from a .msg file
     * 
     * @param string $msgFilePath Absolute path to the .msg file
     * @return array Result with 'subject', 'body', 'attachments' (paths)
     * @throws \Exception
     */
    public function extract(string $msgFilePath, string $outputDir = null): array
    {
        if (!file_exists($msgFilePath)) {
            throw new \Exception("MSG file not found: $msgFilePath");
        }
        $msgFilePath = realpath($msgFilePath);

        // Use custom output dir or create a unique temp one
        $targetDir = $outputDir ?? ($this->tempDir);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Escape paths for shell execution - CRITICAL for Windows paths with spaces
        $cmd = sprintf(
            'powershell.exe -ExecutionPolicy Bypass -File "%s" -msgPath "%s" -outputDir "%s"',
            $this->scriptPath,
            $msgFilePath,
            $targetDir
        );

        // Execute command
        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);

        // Parse Output (JSON)
        // Parse Output (JSON)
        $jsonStr = implode("\n", $output);
        
        // Remove valid UTF-8 BOM if present
        $jsonStr = preg_replace('/^\xEF\xBB\xBF/', '', $jsonStr);
        
        $result = json_decode($jsonStr, true);

        // Basic check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            // Log raw output for debugging
            $logDir = __DIR__ . '/../../../../storage/logs';
            if (!is_dir($logDir)) mkdir($logDir, 0777, true);
            file_put_contents($logDir . '/powershell_error_' . time() . '.log', "CMD: $cmd\n\nOUTPUT:\n$jsonStr");
            
            throw new \Exception("Failed to parse MSG output: $jsonError. See logs.");
        }

        if ($returnVar !== 0 || !isset($result['status']) || $result['status'] === 'error') {
            $error = $result['message'] ?? "Unknown PowerShell error";
             throw new \Exception("Failed to extract MSG: $error");
        }

        return $result;
    }
}
