<?php

namespace Tests\Support;

/**
 * Parses PowerShell scripts without running them. The Windows server package in
 * tools/windows only ever runs on a yard PC, so CI can check syntax and nothing more.
 * GitHub's Ubuntu runners ship PowerShell 7 as `pwsh`; a Windows laptop may only have
 * Windows PowerShell 5.1 as `powershell`.
 */
final class PowerShell
{
    public static function binary(): ?string
    {
        foreach (['pwsh', 'powershell'] as $candidate) {
            $probe = PHP_OS_FAMILY === 'Windows'
                ? "where {$candidate} 2>NUL"
                : "command -v {$candidate} 2>/dev/null";
            $output = [];
            exec($probe, $output, $code);
            if ($code === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string> parser error messages; empty when the script parses
     */
    public static function parseErrors(string $binary, string $path): array
    {
        $literal = "'".str_replace("'", "''", $path)."'";
        $script = '$e = $null; '
            ."[System.Management.Automation.Language.Parser]::ParseFile({$literal}, [ref]\$null, [ref]\$e) | Out-Null; "
            .'$e | ForEach-Object { $_.Message }; '
            .'exit ([int]($e.Count -gt 0))';

        // -EncodedCommand sidesteps cmd.exe and sh quoting rules entirely.
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $output = [];
        exec(escapeshellarg($binary).' -NoProfile -NonInteractive -EncodedCommand '.$encoded.' 2>&1', $output, $code);

        if ($code === 0) {
            return [];
        }

        return $output === [] ? ["{$binary} exited with {$code}"] : array_values($output);
    }
}
