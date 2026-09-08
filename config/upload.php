<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/detector.php';

use OSS\OssClient;
use OSS\Core\OssException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Upyun\Upyun;
use Upyun\Config;

// ============================================
// 工具函数
// ============================================

/**
 * 获取客户端IP地址
 */
function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    return trim(explode(',', $ip)[0]);
}

/**
 * 正規化 URL base，確保有協議且不帶尾斜線
 */
function normalizeUrlBase($baseUrl, $defaultScheme = 'https://') {
    if (empty($baseUrl)) {
        return '';
    }

    if (!preg_match('/^https?:\/\//i', $baseUrl)) {
        $baseUrl = $defaultScheme . ltrim($baseUrl, '/');
    }

    return rtrim($baseUrl, '/');
}

/**
 * 根據存儲配置生成實際來源 URL
 */
function generateFileUrl($storage, $config, $filePath, $s3Result = null) {
    $cleanPath = ltrim($filePath, '/');

    if ($storage === 'local') {
        $domain = !empty($config['local_cdn_domain'])
            ? normalizeUrlBase($config['local_cdn_domain'])
            : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $url = $domain . '/' . $cleanPath;
    } elseif ($storage === 'oss') {
        $domain = !empty($config['oss_cdn_domain'])
            ? normalizeUrlBase($config['oss_cdn_domain'])
            : normalizeUrlBase($config['oss_endpoint'] ?? '');
        $url = $domain ? ($domain . '/' . $cleanPath) : '';
    } elseif ($storage === 's3') {
        if (!empty($config['s3_cdn_domain'])) {
            $url = normalizeUrlBase($config['s3_cdn_domain']) . '/' . $cleanPath;
        } elseif (isset($s3Result['ObjectURL']) && !empty($s3Result['ObjectURL'])) {
            $url = (string)$s3Result['ObjectURL'];
        } else {
            $domain = normalizeUrlBase($config['s3_endpoint'] ?? '');
            $url = $domain ? ($domain . '/' . $cleanPath) : '';
        }
    } elseif ($storage === 'upyun') {
        $domain = normalizeUrlBase($config['upyun_cdn_domain'] ?? '');
        $url = $domain ? ($domain . '/' . $cleanPath) : '';
    } else {
        $url = '';
    }

    // 处理 url_prefix
    if (!empty($config['url_prefix'])) {
        $urlWithoutProtocol = preg_replace('/^https?:\/\//', '', $url);
        return $config['url_prefix'] . '/' . $urlWithoutProtocol;
    }

    return $url;
}

/**
 * 生成對外顯示用網址，優先使用本站遮罩 URL
 */
function generatePublicFileUrl($storage, $config, $filePath, $originUrl = '', $isPasswordProtected = false) {
    return getAssetPublicUrl([
        'url' => $originUrl,
        'path' => $filePath,
        'storage' => $storage,
        'password' => $isPasswordProtected ? '1' : ''
    ], $config);
}

/**
 * 判斷資料庫中的 URL 是否誤存成目前站台的遮罩網址
 */
function isMaskedStorageUrl($url, $filePath) {
    if (empty($url) || empty($filePath) || empty($_SERVER['HTTP_HOST'])) {
        return false;
    }

    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    $path = $parsed['path'] ?? '';

    return $host === $_SERVER['HTTP_HOST'] && $path === '/' . ltrim($filePath, '/');
}

/**
 * 解析資產實際來源 URL，兼容早期誤存的遮罩網址
 */
function resolveAssetOriginUrl($asset, $config) {
    $storage = $asset['storage'] ?? 'local';
    $path = $asset['path'] ?? '';
    $url = $asset['url'] ?? '';

    if ($storage === 'local') {
        return $url;
    }

    if (empty($url) || isMaskedStorageUrl($url, $path)) {
        return generateFileUrl($storage, $config, $path);
    }

    return $url;
}

/**
 * 生成上传响应数据
 */
function generateUploadResponse($fileUrl, $filePath, $finalFilePath, $size, $width, $height, $message = '', $isError = false, $assetId = null, $config = null) {
    $shareUrl = buildAssetShareUrl($assetId, $config);

    respondAndExit($isError ? [
        'result' => 'error',
        'code' => 500,
        'message' => $message
    ] : [
        'result' => 'success',
        'code' => 200,
        'status' => true,
        'name' => basename($finalFilePath),
        'data' => [
            'id' => $assetId,
            'url' => $fileUrl,
            'share_url' => $shareUrl ?: $fileUrl,
            'name' => basename($finalFilePath),
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'path' => $filePath
        ],
        'url' => $fileUrl,
        'share_url' => $shareUrl ?: $fileUrl
    ]);
}

// ============================================
// 图片处理函数
// ============================================

/**
 * 修正JPEG图片方向
 */
function correctJpegOrientation($filepath, $quality) {
    $exif = @exif_read_data($filepath);
    if (!$exif || !isset($exif['Orientation'])) return;

    $image = imagecreatefromjpeg($filepath);
    switch ($exif['Orientation']) {
        case 3: $image = imagerotate($image, 180, 0); break;
        case 6: $image = imagerotate($image, -90, 0); break;
        case 8: $image = imagerotate($image, 90, 0); break;
    }
    imagejpeg($image, $filepath, $quality);
    imagedestroy($image);
}

/**
 * 转换图片为WebP格式
 */
function convertImageToWebp($source, $destination, $quality = 60) {
    if (!file_exists($source)) {
        logMessage("错误: 源文件不存在: $source");
        return false;
    }
    
    $maxWidth = 2500;
    $maxHeight = 1600;
    $info = getimagesize($source);
    $mimeType = $info['mime'];

    try {
        if ($mimeType === 'image/png') {
            if (!class_exists('Imagick')) return 'imagick_not_installed';
            
            $image = new Imagick($source);
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor('transparent');
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $image->resizeImage(round($width * $ratio), round($height * $ratio), Imagick::FILTER_LANCZOS, 1);
            }

            $result = $image->writeImage($destination);
            $image->clear();
            $image->destroy();
            return $result;
        } else if ($mimeType === 'image/jpeg') {
            if (!extension_loaded('gd')) return 'gd_not_installed';
            
            $gdInfo = gd_info();
            if (!isset($gdInfo['WebP Support']) || !$gdInfo['WebP Support']) return 'gd_no_webp_support';
            
            $image = imagecreatefromjpeg($source);
            if (!$image) return 'gd_create_failed';
            
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $newWidth = round($width * $ratio);
                $newHeight = round($height * $ratio);
                $newImage = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $newImage;
            }

            $result = imagewebp($image, $destination, $quality);
            imagedestroy($image);
            gc_collect_cycles();
            return $result;
        }
        return 'unsupported_mime_type';
    } catch (Exception $e) {
        logMessage('图片转换失败: ' . $e->getMessage());
        return false;
    }
}

/**
 * 处理图片压缩和格式转换
 */
function processImageCompression($fileMimeType, $newFilePath, $newFilePathWithoutExt, $quality, $outputFormat) {
    $finalFilePath = $newFilePath;
    
    // 对于非SVG和非WebP的图片，根据输出格式决定是否转换
    if (!in_array($fileMimeType, ['image/svg+xml', 'image/webp']) && $outputFormat === 'webp') {
        $convertResult = convertImageToWebp($newFilePath, $newFilePathWithoutExt . '.webp', $quality);
        
        if ($convertResult === true) {
            $webpPath = $newFilePathWithoutExt . '.webp';
            if (file_exists($webpPath) && filesize($webpPath) > 0) {
                $finalFilePath = $webpPath;
                unlink($newFilePath);
            }
        } else if (is_string($convertResult)) {
            $errorMessages = [
                'imagick_not_installed' => 'Imagick扩展未安装，无法处理PNG图片',
                'gd_not_installed' => 'GD扩展未安装',
                'gd_no_webp_support' => 'GD扩展不支持webp格式',
                'gd_create_failed' => '无法创建图像资源',
                'unsupported_mime_type' => '不支持的图片格式'
            ];
            respondAndExit(['result' => 'error', 'code' => 500, 'message' => $errorMessages[$convertResult] ?? '图片转换失败']);
        }
    }
    
    // 如果输出格式不是webp，或者需要保持原格式
    if ($outputFormat !== 'webp' && $outputFormat !== 'original') {
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $outputFormat) {
            $newFinalFilePath = $newFilePathWithoutExt . '.' . $outputFormat;
            rename($finalFilePath, $newFinalFilePath);
            $finalFilePath = $newFinalFilePath;
        }
    } else if ($outputFormat === 'original' && $fileMimeType !== 'image/svg+xml') {
        // 保持原格式
        $currentExt = strtolower(pathinfo($finalFilePath, PATHINFO_EXTENSION));
        $originalExt = strtolower(pathinfo($newFilePath, PATHINFO_EXTENSION));
        if ($currentExt !== $originalExt) {
            $newFinalFilePath = $newFilePathWithoutExt . '.' . $originalExt;
            if (file_exists($finalFilePath)) {
                rename($finalFilePath, $newFinalFilePath);
                $finalFilePath = $newFinalFilePath;
            }
        }
    }
    
    return $finalFilePath;
}

/**
 * 获取图片尺寸信息
 */
function getImageDimensions($finalFilePath, $fileMimeType) {
    if ($fileMimeType === 'image/svg+xml') return ['width' => 100, 'height' => 100];
    
    try {
        if (class_exists('Imagick')) {
            $image = new Imagick($finalFilePath);
            $dimensions = ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            $image->destroy();
            return $dimensions;
        }
        
        $dimensions = getimagesize($finalFilePath);
        if ($dimensions) return ['width' => $dimensions[0], 'height' => $dimensions[1]];
        
        $image = imagecreatefromstring(file_get_contents($finalFilePath));
        if ($image) {
            $dimensions = ['width' => imagesx($image), 'height' => imagesy($image)];
            imagedestroy($image);
            return $dimensions;
        }
    } catch (Exception $e) {
        logMessage('获取图片尺寸失败: ' . $e->getMessage());
    }
    
    return ['width' => 0, 'height' => 0];
}

// ============================================
// 文件验证函数
// ============================================

/**
 * 检测并修正文件MIME类型 (結合 Magika AI 與本地檔案內容辨識)
 */
function detectMimeType($file) {
    if (isset($file['tmp_name']) && file_exists($file['tmp_name'])) {
        $detection = AssetDetector::detect($file['tmp_name'], $file['name'] ?? '');
        $ext = $detection['ext'] ?: strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        return [$detection['mime'], $ext, $detection];
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    
    // 根据扩展名判断MIME类型（回退備用）
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'flac' => 'audio/flac'
    ];
    
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    return [$mimeType, $extension, []];
}

/**
 * 验证文件类型和有效性
 */
function validateFile($file) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/octet-stream'];
    list($mimeType, $extension, $detection) = detectMimeType($file);
    
    // 1. 安全防護：阻擋可執行檔或腳本 (PHP, Shell, ELF, PEbin 等)
    if (!empty($detection) && AssetDetector::isDangerous($detection)) {
        respondAndExit(['result' => 'error', 'code' => 403, 'message' => '安全防護：禁止上傳可執行檔或腳本程式 (' . htmlspecialchars($detection['label'] ?? 'unknown') . ')']);
    }

    // 2. 空檔案阻擋
    if (!empty($detection) && ($detection['label'] ?? '') === 'empty') {
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => '上傳的檔案為空檔案']);
    }

    // 3. 若為 Magika 檢測，驗證是否為圖片群組或合法圖片 label
    if (!empty($detection) && ($detection['engine'] ?? '') === 'magika') {
        $allowedImageLabels = ['jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'tiff', 'avif', 'heic'];
        if (($detection['group'] ?? '') !== 'image' && !in_array($detection['label'] ?? '', $allowedImageLabels, true)) {
            respondAndExit(['result' => 'error', 'code' => 406, 'message' => '檔案內容不是有效的圖片格式 (' . htmlspecialchars($detection['label'] ?? 'unknown') . ')']);
        }
    }

    if (!in_array($mimeType, $allowedTypes)) {
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '不支持的文件类型']);
    }
    
    if ($mimeType !== 'image/svg+xml') {
        $isValidImage = ($mimeType === 'application/octet-stream') 
            ? imagecreatefromstring(file_get_contents($file['tmp_name'])) !== false
            : getimagesize($file['tmp_name']) !== false;
            
        if (!$isValidImage) {
            respondAndExit(['result' => 'error', 'code' => 406, 'message' => '文件不是有效的图片']);
        }
    }
    
    return [$mimeType, $extension];
}

// ============================================
// 主处理函数
// ============================================

/**
 * 处理上传的文件
 */
function handleUploadedFile($file, $token, $referer, $password = '') {
    global $pdo;
    
    $config = Database::getConfig($pdo);
    $storage = $config['storage'];
    $user_id = $_SESSION['user_id'] ?? NULL;
    $quality = intval($_POST['quality'] ?? 60);
    
    list($mimeType, $extension) = validateFile($file);
    
    $datePath = 'storage/i/' . date('Y/m/d');
    if (!is_dir($datePath) && !mkdir($datePath, 0755, true)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '无法创建上传目录']);
    }
    
    try {
        $randomFileName = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    $ext = $extension ?: (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$mimeType] ?? 'jpg');
    $newFilePath = $datePath . '/' . $randomFileName . '.' . $ext;
    
    // 根據是否為遠端抓取決定移動方式
    $moveFunction = isset($_SESSION['use_rename']) ? 'rename' : 'move_uploaded_file';
    if (!$moveFunction($file['tmp_name'], $newFilePath)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '文件儲存失敗']);
    }

    
    ini_set('memory_limit', '1024M');
    set_time_limit(300);
    
    $finalFilePath = processImageCompression($mimeType, $newFilePath, $datePath . '/' . $randomFileName, $quality, $config['output_format'] ?? 'webp');
    if ($mimeType === 'image/jpeg') correctJpegOrientation($finalFilePath, $quality);
    
    $dimensions = getImageDimensions($finalFilePath, $mimeType);
    $fileSize = filesize($finalFilePath);
    $filePath = $datePath . '/' . basename($finalFilePath);
    
    try {
        $result = StorageHelper::upload($storage, $config, $finalFilePath, $filePath, [
            'content_type' => mime_content_type($finalFilePath) ?: $mimeType,
            'content_disposition' => 'inline; filename="' . addcslashes(basename($filePath), '"\\') . '"'
        ]);
        
        if ($storage !== 'local') {
            if (file_exists($finalFilePath)) unlink($finalFilePath);
            if ($finalFilePath !== $newFilePath && file_exists($newFilePath)) unlink($newFilePath);
        }
        
        $fileUrl = generateFileUrl($storage, $config, $filePath, $result);
        $publicFileUrl = generatePublicFileUrl($storage, $config, $filePath, $fileUrl, !empty($password));
        $storagePath = ($storage === 'local') ? $finalFilePath : $filePath;
        
        $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : NULL;
        $shareToken = generateShareToken();
        $stmt = $pdo->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id, password, mime_type, is_video, is_file, share_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fileUrl, $storagePath, $storage, $fileSize, getClientIp(), $user_id, $hashedPassword, $mimeType, 0, 0, $shareToken]);
        $assetId = $pdo->lastInsertId();
        
        // 记录上传成功日志
        $clientIp = getClientIp();
        logMessage("上传成功 | IP: {$clientIp} | 存储: {$storage} | URL: {$fileUrl}");

        generateUploadResponse($publicFileUrl, $storagePath, $finalFilePath, $fileSize, $dimensions['width'], $dimensions['height'], '', false, $shareToken, $config);
    } catch (Exception $e) {
        // 记录上传失败日志
        $clientIp = getClientIp();
        $errorMsg = $e->getMessage();
        logMessage("上传失败 | IP: {$clientIp} | 存储: {$storage} | 错误: {$errorMsg}");
        
        generateUploadResponse('', '', '', 0, 0, 0, "文件上传到{$storage}失败: " . $errorMsg, true);
    }
}
