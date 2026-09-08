<?php
/**
 * Asset Content-Type & Security Detector for 888box
 *
 * Utilizes Google Magika AI-powered content-type detection with graceful fallback
 * to PHP native fileinfo (finfo) and extension heuristics.
 */

class AssetDetector {
    /**
     * Cached availability of Magika binary
     */
    private static ?bool $magikaAvailable = null;
    private static ?string $magikaPath = null;

    /**
     * Dangerous content-type labels (RCE / executable risks)
     */
    private static array $dangerousLabels = [
        'php', 'javascript', 'js', 'python', 'shell', 'bash', 'batch',
        'powershell', 'perl', 'ruby', 'elf', 'pebin', 'mach-o', 'wasm',
        'symlink', 'java', 'class', 'jar', 'apk', 'crx'
    ];

    /**
     * Check if Magika CLI is installed and executable
     */
    public static function isMagikaAvailable(): bool {
        if (self::$magikaAvailable !== null) {
            return self::$magikaAvailable;
        }

        $possiblePaths = [
            '/usr/local/bin/magika',
            '/usr/bin/magika',
            '/opt/homebrew/bin/magika'
        ];

        foreach ($possiblePaths as $path) {
            if (@is_executable($path)) {
                self::$magikaPath = $path;
                self::$magikaAvailable = true;
                return true;
            }
        }

        $which = trim((string)@shell_exec('which magika 2>/dev/null'));
        if (!empty($which) && @is_executable($which)) {
            self::$magikaPath = $which;
            self::$magikaAvailable = true;
            return true;
        }

        self::$magikaAvailable = false;
        self::$magikaPath = null;
        return false;
    }

    /**
     * Detect asset details from a file path
     *
     * @param string $filePath Path to temporary or stored file
     * @param string $clientFileName Original client file name (optional)
     * @return array [
     *   'label' => string,
     *   'group' => string,
     *   'mime' => string,
     *   'score' => float,
     *   'ext' => string,
     *   'engine' => 'magika'|'finfo'|'size'|'none',
     *   'is_text' => bool,
     *   'description' => string
     * ]
     */
    public static function detect(string $filePath, string $clientFileName = ''): array {
        $clientExt = strtolower(pathinfo($clientFileName, PATHINFO_EXTENSION));

        if (!file_exists($filePath)) {
            return [
                'label' => 'unknown',
                'group' => 'unknown',
                'mime' => 'application/octet-stream',
                'score' => 0.0,
                'ext' => $clientExt,
                'engine' => 'none',
                'is_text' => false,
                'description' => 'File not found'
            ];
        }

        // 1. Check for empty file
        if (filesize($filePath) === 0) {
            return [
                'label' => 'empty',
                'group' => 'empty',
                'mime' => 'inode/x-empty',
                'score' => 1.0,
                'ext' => $clientExt,
                'engine' => 'size',
                'is_text' => false,
                'description' => 'Empty file'
            ];
        }

        // 2. Try Google Magika deep learning detection
        if (self::isMagikaAvailable() && self::$magikaPath) {
            $cmd = escapeshellcmd(self::$magikaPath) . ' --json ' . escapeshellarg($filePath) . ' 2>/dev/null';
            $output = @shell_exec($cmd);
            if (!empty($output)) {
                $data = json_decode($output, true);
                if (is_array($data) && !empty($data[0]['result']['value'])) {
                    $val = $data[0]['result']['value'];
                    $out = $val['output'] ?? $val['dl'] ?? [];
                    $label = $out['label'] ?? 'unknown';
                    $group = $out['group'] ?? 'unknown';
                    $mime = $out['mime_type'] ?? 'application/octet-stream';
                    $score = (float)($val['score'] ?? 1.0);
                    $exts = $out['extensions'] ?? [];
                    $isText = (bool)($out['is_text'] ?? false);
                    $description = $out['description'] ?? '';

                    $bestExt = !empty($exts[0]) ? strtolower($exts[0]) : $clientExt;

                    return [
                        'label' => $label,
                        'group' => $group,
                        'mime' => $mime,
                        'score' => $score,
                        'ext' => $bestExt,
                        'engine' => 'magika',
                        'is_text' => $isText,
                        'description' => $description
                    ];
                }
            }
        }

        // 3. Fallback: PHP native fileinfo
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $filePath) ?: 'application/octet-stream';
                @finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath) ?: 'application/octet-stream';
        }

        $parts = explode('/', $mime);
        $group = $parts[0] ?? 'unknown';
        $sub = $parts[1] ?? 'unknown';

        // Check if MIME indicates a dangerous script
        $dangerousMimes = [
            'text/x-php' => 'php',
            'application/x-php' => 'php',
            'application/x-httpd-php' => 'php',
            'text/x-shellscript' => 'shell',
            'application/x-sh' => 'shell',
            'application/x-executable' => 'elf',
            'application/x-msdownload' => 'pebin',
            'application/x-dosexec' => 'pebin'
        ];
        if (isset($dangerousMimes[$mime])) {
            $sub = $dangerousMimes[$mime];
            $group = ($sub === 'php' || $sub === 'shell') ? 'code' : 'executable';
        }

        return [
            'label' => $sub,
            'group' => $group,
            'mime' => $mime,
            'score' => 0.5,
            'ext' => $clientExt,
            'engine' => 'finfo',
            'is_text' => (strpos($mime, 'text/') === 0),
            'description' => $mime
        ];
    }

    /**
     * Check if the detected asset is dangerous (e.g. PHP script, executable, shell)
     */
    public static function isDangerous(array $detection): bool {
        $label = strtolower($detection['label'] ?? '');
        $group = strtolower($detection['group'] ?? '');
        $mime = strtolower($detection['mime'] ?? '');

        if (in_array($label, self::$dangerousLabels, true)) {
            return true;
        }

        if ($group === 'executable') {
            return true;
        }

        $dangerousMimes = [
            'application/x-php', 'text/x-php', 'application/x-httpd-php',
            'application/x-sh', 'text/x-sh', 'application/x-shellscript',
            'application/x-executable', 'application/x-msdownload',
            'application/x-dosexec', 'application/x-sharedlib'
        ];
        if (in_array($mime, $dangerousMimes, true)) {
            return true;
        }

        return false;
    }

    /**
     * Determine upload routing: 'image' | 'video' | 'audio' | 'file'
     */
    public static function routeAsset(array $detection): string {
        $group = strtolower($detection['group'] ?? '');
        $label = strtolower($detection['label'] ?? '');
        $mime = strtolower($detection['mime'] ?? '');

        if ($group === 'image' || str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($group === 'video' || str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($group === 'audio' || str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        // Explicit label mappings
        $imageLabels = ['jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff', 'avif', 'heic'];
        if (in_array($label, $imageLabels, true)) {
            return 'image';
        }

        $videoLabels = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'mpeg', 'm4v'];
        if (in_array($label, $videoLabels, true)) {
            return 'video';
        }

        $audioLabels = ['mp3', 'wav', 'aac', 'ogg', 'flac', 'm4a', 'wma', 'opus', 'aiff'];
        if (in_array($label, $audioLabels, true)) {
            return 'audio';
        }

        return 'file';
    }
}
