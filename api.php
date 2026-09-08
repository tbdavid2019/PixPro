<?php
ob_start();
session_start();

require_once 'vendor/autoload.php';
require_once 'config/database.php';
require_once 'config/upload.php';
require_once 'config/cors.php';
require_once 'config/security.php';

// 初始化
$db = Database::getInstance();
$pdo = $db->getConnection();
$config = Database::getConfig($pdo);

// ============================================
// 工具函数
// ============================================

/**
 * 从数据库获取配置值
 */
function getConfigValue($pdo, $key, $default = 0) {
    $stmt = $pdo->prepare("SELECT value FROM configs WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false || !isset($row['value']) || $row['value'] === '') {
        return $default;
    }

    return (int)$row['value'];
}

/**
 * 检查域名是否被允许
 */
function isDomainAllowed($host) {
    global $config;

    return isHostAllowedByConfiguredDomains($host, $config['site_domain'] ?? '');
}

/**
 * 记录日志
 */
function logMessage($message) {
    file_put_contents('上传日志.txt', "[" . date('Y-m-d H:i:s') . "] $message" . PHP_EOL, FILE_APPEND);
}

/**
 * 返回JSON响应并退出
 */
function respondAndExit($response) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function formatSizeLimit($bytes) {
    if ($bytes < 1024 * 1024) {
        return max(1, round($bytes / 1024)) . 'KB';
    }

    $mb = $bytes / (1024 * 1024);
    return $mb < 10 ? number_format($mb, 1) . 'MB' : number_format($mb, 0) . 'MB';
}

// ============================================
// 上传限制
// ============================================

/**
 * 检查上传次数限制
 */
function isUploadAllowed($maxUploadsPerDay) {
    if ($maxUploadsPerDay <= 0) return true;
    
    $uploadDir = 'i/.upload_limits/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $clientIp = getClientIp();
    $currentDate = date('Y-m-d');
    $limitFile = $uploadDir . md5($clientIp) . '.json';
    
    // 读取或初始化记录
    $uploadData = file_exists($limitFile) 
        ? json_decode(file_get_contents($limitFile), true) ?: []
        : [];
    
    // 新的一天重置计数
    if (!isset($uploadData['date']) || $uploadData['date'] !== $currentDate) {
        $uploadData = ['date' => $currentDate, 'count' => 0, 'ip' => $clientIp];
    }
    
    // 检查限制
    if ($uploadData['count'] >= $maxUploadsPerDay) {
        return "上传次数已达今日限制（{$maxUploadsPerDay}次），请明天再试";
    }
    
    // 更新计数
    $uploadData['count']++;
    $uploadData['last_upload'] = date('Y-m-d H:i:s');
    file_put_contents($limitFile, json_encode($uploadData, JSON_PRETTY_PRINT));
    
    // 10%概率清理过期文件
    try {
        if (random_int(1, 10) === 1) {
            foreach (glob($uploadDir . '*.json') as $file) {
                $data = json_decode(@file_get_contents($file), true);
                if (isset($data['date']) && $data['date'] !== $currentDate) {
                    @unlink($file);
                }
            }
        }
    } catch (Exception $e) {}
    
    return true;
}

// ============================================
// 验证函数
// ============================================

/**
 * 验证Token和请求来源
 */
function validateToken() {
    global $pdo, $config;
    
    // 1. 获取 Token
    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $reqHeaders = apache_request_headers();
        $authHeader = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? '';
    }
    if (empty($authHeader) && function_exists('getallheaders')) {
        $reqHeaders = getallheaders();
        $authHeader = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    } else {
        $token = trim($_POST['token'] ?? $_GET['token'] ?? '');
    }
    
    // 2. 验证 Token (優先級最高)
    if (!empty($token)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE token = ?");
        $stmt->execute([$token]);
        if ($stmt->fetch()) return;
    }
    
    // 3. 验证 Session (針對官方網頁上傳)
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        return;
    }
    
    // 4. 如果開啟了登入限制，則此時應拒絕（因為 Token 與 Session 都失效）
    $loginRestriction = isset($config['login_restriction']) && filter_var($config['login_restriction'], FILTER_VALIDATE_BOOLEAN);
    if ($loginRestriction) {
        respondAndExit(['result' => 'error', 'code' => 403, 'message' => '登入保護已開啟，請提供有效的 Token 或先登入']);
    }

    // 5. 驗證網域 (僅作為公開模式下的基本過濾)
    $refererHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    $currentHost = parse_url(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!empty($refererHost) && !empty($currentHost) && strcasecmp($refererHost, $currentHost) === 0) {
        return;
    }
    if (isDomainAllowed($refererHost)) return;
    
    respondAndExit(['result' => 'error', 'code' => 403, 'message' => '身分驗證失敗：無效的 Token、尚未登入或網域未授權']);
}

/**
 * 设置CORS响应头
 */
function setCorsHeaders() {
    global $config;
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowOrigin = resolveCorsAllowOrigin($origin, $config['site_domain'] ?? '');

    header("Access-Control-Allow-Origin: $allowOrigin");
    if ($allowOrigin !== '*' && $allowOrigin !== 'null') {
        header("Access-Control-Allow-Credentials: true");
    }
    
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

function isPublicActionAllowed($action) {
    global $config;

    $publicActions = ['upload', 'upload_url', 'stats'];
    if (!in_array($action, $publicActions, true)) {
        return false;
    }

    $loginRestriction = isset($config['login_restriction']) && filter_var($config['login_restriction'], FILTER_VALIDATE_BOOLEAN);
    return !$loginRestriction;
}

// ============================================
// 主流程
// ============================================

try {
    setCorsHeaders();

    // 取得 Action
    $action = $_GET['action'] ?? $_POST['action'] ?? 'upload';

    // OPTIONS 請求直接退出
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }

    // 驗證登入限制與操作權限
    if (!isPublicActionAllowed($action)) {
        validateToken();
    }

    // 分流處理
    switch ($action) {
        case 'upload':
            handleUnifiedUpload($pdo, $config);
            break;

        case 'list':
            handleUnifiedList($pdo, $_GET['type'] ?? 'all');
            break;

        case 'search':
            handleUnifiedSearch($pdo, $_GET['q'] ?? $_GET['query'] ?? '');
            break;

        case 'stats':
            handleGetStats($pdo);
            break;

        case 'delete':
            handleDeleteAsset($pdo, (int)($_POST['id'] ?? $_GET['id'] ?? 0));
            break;

        case 'upload_url':
            handleUploadFromUrl($pdo, $config);
            break;

        default:
            respondAndExit(['result' => 'error', 'code' => 400, 'message' => '未知的操作: ' . $action]);
    }
    
} catch (Exception $e) {
    logMessage('錯誤: ' . $e->getMessage());
    respondAndExit(['result' => 'error', 'code' => 500, 'message' => '伺服器錯誤: ' . $e->getMessage()]);
}

/**
 * 遠端 URL 上傳處理器
 */
function handleUploadFromUrl($pdo, $config) {
    $url = $_POST['url'] ?? $_GET['url'] ?? '';
    if (empty($url)) {
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => 'URL 不能為空']);
    }

    // SSRF 安全檢驗
    $urlCheck = validateSafeRemoteUrl($url);
    if (!$urlCheck['valid']) {
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => '無效或受限的遠端 URL: ' . $urlCheck['error']]);
    }
    $url = $urlCheck['url'];

    // 1. 安全檢查：獲取 Headers
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_NOBODY, true); // 僅獲取 Header
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    applySafeCurlOptions($ch);
    $response = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    if ($contentLength < 0) {
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH);
    }
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => '遠端伺服器回應錯誤 (HTTP ' . $httpCode . ')']);
    }

    // 若有重新導向，再次驗證最終 URL 避免被跳轉至內部網路
    if (!empty($effectiveUrl) && $effectiveUrl !== $url) {
        $redirCheck = validateSafeRemoteUrl($effectiveUrl);
        if (!$redirCheck['valid']) {
            respondAndExit(['result' => 'error', 'code' => 400, 'message' => '重新導向至不安全的位址: ' . $redirCheck['error']]);
        }
        $url = $redirCheck['url'];
    }

    // 驗證大小 (限制 100MB 避免伺服器爆掉)
    $maxFileSize = getConfigValue($pdo, 'max_file_size', 100 * 1024 * 1024);
    if ($contentLength > 0 && $contentLength > $maxFileSize) {
        respondAndExit(['result' => 'error', 'code' => 413, 'message' => '遠端檔案太大']);
    }

    // 2. 下載檔案
    $tempDir = 'storage/temp';
    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
    
    $parsedPath = parse_url($url, PHP_URL_PATH) ?? '';
    $extension = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION)) ?: 'tmp';
    $tempFile = $tempDir . '/' . bin2hex(random_bytes(8)) . '.' . $extension;

    $fp = fopen($tempFile, 'w+');
    if (!$fp) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '無法建立本機暫存檔案']);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    applySafeCurlOptions($ch);
    $downloadOk = curl_exec($ch);
    $downloadEffectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    fclose($fp);

    // 下載完成後再次檢查重定向網址安全
    if (!empty($downloadEffectiveUrl) && $downloadEffectiveUrl !== $url) {
        $finalCheck = validateSafeRemoteUrl($downloadEffectiveUrl);
        if (!$finalCheck['valid']) {
            @unlink($tempFile);
            respondAndExit(['result' => 'error', 'code' => 400, 'message' => '下載重新導向至不安全位址']);
        }
    }

    if (!$downloadOk || !file_exists($tempFile) || filesize($tempFile) == 0) {
        @unlink($tempFile);
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '遠端檔案下載失敗']);
    }

    // 3. 模擬 $_FILES 結構並調用現有邏輯
    $file = [
        'name' => basename(parse_url($url, PHP_URL_PATH)) ?: 'downloaded_asset.' . $extension,
        'type' => $contentType,
        'tmp_name' => $tempFile,
        'error' => 0,
        'size' => filesize($tempFile),
        'is_remote' => true // 標記為遠端抓取，方便內部處理
    ];

    require_once __DIR__ . '/config/detector.php';
    $detection = AssetDetector::detect($tempFile, $file['name']);

    if (AssetDetector::isDangerous($detection)) {
        @unlink($tempFile);
        respondAndExit([
            'result' => 'error',
            'code' => 403,
            'message' => '安全防護：遠端資源包含禁止的程式碼或可執行檔 (' . htmlspecialchars($detection['label'] ?? 'unknown') . ')'
        ]);
    }

    if (($detection['label'] ?? '') === 'empty' || filesize($tempFile) === 0) {
        @unlink($tempFile);
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => '遠端檔案為空檔案']);
    }

    list($mimeType, $ext) = detectMimeType($file);
    processAsset($file, $pdo, $config, $mimeType);
}

/**
 * 處理資產 (通用上傳邏輯)
 */
function processAsset($file, $pdo, $config, $mimeType) {
    // 針對 URL 下載的檔案，將其從臨時目錄「移動」到正式流程
    $_SESSION['use_rename'] = true;
    
    try {
        require_once __DIR__ . '/config/detector.php';
        $detection = AssetDetector::detect($file['tmp_name'], $file['name']);
        if (AssetDetector::isDangerous($detection)) {
            respondAndExit([
                'result' => 'error',
                'code' => 403,
                'message' => '安全防護：禁止上傳可執行檔或腳本程式 (' . htmlspecialchars($detection['label'] ?? 'unknown') . ')'
            ]);
        }

        $targetGroup = AssetDetector::routeAsset($detection);

        if ($targetGroup === 'image') {
            handleUploadedFile($file, $_POST['token'] ?? '', $_SERVER['HTTP_REFERER'] ?? '', $_POST['password'] ?? '');
        } elseif ($targetGroup === 'video') {
            require_once 'config/video_logic.php';
            $videoData = handleVideoUpload($file, $pdo, $_POST['title'] ?? '', $_POST['description'] ?? '', $_POST['password'] ?? '');
            respondAndExit(['result' => 'success', 'code' => 200, 'data' => $videoData]);
        } elseif ($targetGroup === 'audio') {
            require_once 'config/audio_logic.php';
            $audioData = handleAudioUpload($file, $pdo, $_POST['title'] ?? '', $_POST['description'] ?? '', $_POST['password'] ?? '');
            respondAndExit(['result' => 'success', 'code' => 200, 'data' => $audioData]);
        } else {
            require_once 'api_file.php';
            handleFileUpload($file, $pdo, $config);
        }
    } finally {
        unset($_SESSION['use_rename']);
        if (file_exists($file['tmp_name'])) @unlink($file['tmp_name']);
    }
}


/**
 * 統一上傳處理器
 */
function handleUnifiedUpload($pdo, $config) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES)) {
        respondAndExit(['result' => 'error', 'code' => 204, 'message' => '無文件上傳']);
    }

    // 驗證上傳限制
    $maxUploadsPerDay = getConfigValue($pdo, 'max_uploads_per_day');
    $uploadCheck = isUploadAllowed($maxUploadsPerDay);
    if ($uploadCheck !== true) {
        respondAndExit(['result' => 'error', 'code' => 429, 'message' => $uploadCheck]);
    }

    // 驗證檔案大小
    $maxFileSize = getConfigValue($pdo, 'max_file_size', 100 * 1024 * 1024);
    foreach ($_FILES as $file) {
        if ($file['size'] > $maxFileSize) {
            respondAndExit([
                'result' => 'error',
                'code' => 413,
                'message' => '文件大小超過限制，最大允許 ' . formatSizeLimit($maxFileSize)
            ]);
        }
    }

    // 根據檔案內容自動分流
    require_once __DIR__ . '/config/detector.php';
    foreach ($_FILES as $file) {
        $detection = AssetDetector::detect($file['tmp_name'], $file['name']);

        // 1. 安全防護：阻擋可執行檔或腳本 (PHP, Shell, ELF, PEbin 等)
        if (AssetDetector::isDangerous($detection)) {
            respondAndExit([
                'result' => 'error',
                'code' => 403,
                'message' => '安全防護：禁止上傳可執行檔或腳本程式 (' . htmlspecialchars($detection['label'] ?? 'unknown') . ')'
            ]);
        }

        // 2. 空檔案阻擋
        if (($detection['label'] ?? '') === 'empty' || ($file['size'] ?? 0) === 0) {
            respondAndExit([
                'result' => 'error',
                'code' => 400,
                'message' => '上傳的檔案為空檔案'
            ]);
        }

        // 3. 依檢測結果路由分流
        $targetGroup = AssetDetector::routeAsset($detection);
        
        if ($targetGroup === 'image') {
            // 圖片處理
            handleUploadedFile($file, $_POST['token'] ?? '', $_SERVER['HTTP_REFERER'] ?? '', $_POST['password'] ?? '');
        } elseif ($targetGroup === 'video') {
            // 影片處理
            require_once 'config/video_logic.php';
            $videoData = handleVideoUpload($file, $pdo, $_POST['title'] ?? '', $_POST['description'] ?? '', $_POST['password'] ?? '');
            respondAndExit(['result' => 'success', 'code' => 200, 'data' => $videoData]);
        } elseif ($targetGroup === 'audio') {
            // 音訊處理
            require_once 'config/audio_logic.php';
            $audioData = handleAudioUpload($file, $pdo, $_POST['title'] ?? '', $_POST['description'] ?? '', $_POST['password'] ?? '');
            respondAndExit(['result' => 'success', 'code' => 200, 'data' => $audioData]);
        } else {
            // 文件處理
            require_once 'api_file.php';
            handleFileUpload($file, $pdo, $config);
        }
    }
}

/**
 * 統一列表處理器
 */
function handleUnifiedList($pdo, $type) {
    global $config;

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = "1=1";
    $params = [];
    if ($type === 'image') {
        $where = "is_video = 0 AND is_audio = 0 AND is_file = 0";
    } elseif ($type === 'video') {
        $where = "is_video = 1";
    } elseif ($type === 'audio') {
        $where = "is_audio = 1";
    } elseif ($type === 'file') {
        $where = "is_file = 1";
    }

    $stmt = $pdo->prepare("SELECT * FROM images WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as &$asset) {
        $asset['url'] = getAssetPublicUrl($asset, $config);
        $asset['share_url'] = buildAssetShareUrl($asset, $config);
    }


    $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM images WHERE $where")->fetchColumn();
    $totalPages = ceil($totalCount / $limit);

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'data' => $assets,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => (int)$totalPages,
            'total_count' => $totalCount
        ]
    ]);
}

/**
 * 統一搜尋處理器
 */
function handleUnifiedSearch($pdo, $query) {
    global $config;

    if (empty($query)) {
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => '搜尋內容不能為空']);
    }

    $type = $_GET['type'] ?? 'all';
    $where = "(path LIKE ? OR url LIKE ? OR title LIKE ?)";
    $params = ["%$query%", "%$query%", "%$query%"];

    if ($type === 'image') {
        $where .= " AND is_video = 0 AND is_audio = 0 AND is_file = 0";
    } elseif ($type === 'video') {
        $where .= " AND is_video = 1";
    } elseif ($type === 'audio') {
        $where .= " AND is_audio = 1";
    } elseif ($type === 'file') {
        $where .= " AND is_file = 1";
    }

    $stmt = $pdo->prepare("SELECT * FROM images WHERE $where ORDER BY created_at DESC LIMIT 50");
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as &$asset) {
        $asset['url'] = getAssetPublicUrl($asset, $config);
        $asset['share_url'] = buildAssetShareUrl($asset, $config);
    }


    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'data' => $assets,
        'query' => $query
    ]);
}

/**
 * 獲取統計數據
 */
function handleGetStats($pdo) {
    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM images")->fetchColumn(),
        'image' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_video = 0 AND is_audio = 0 AND is_file = 0")->fetchColumn(),
        'video' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_video = 1")->fetchColumn(),
        'audio' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_audio = 1")->fetchColumn(),
        'file' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_file = 1")->fetchColumn(),
    ];

    respondAndExit([
        'result' => 'success',
        'code' => 200,
        'data' => $stats
    ]);
}

/**
 * 刪除資產
 */
function handleDeleteAsset($pdo, $id) {
    if ($id <= 0) respondAndExit(['result' => 'error', 'message' => '無效的 ID']);
    
    // 這裡可以引入 config/delete.php 的邏輯，或者直接實作
    require_once 'config/delete.php';
    $result = deleteAsset($pdo, $id); // 假設有這個函數
    
    if ($result) {
        respondAndExit(['result' => 'success', 'message' => '刪除成功']);
    } else {
        respondAndExit(['result' => 'error', 'message' => '刪除失敗']);
    }
}

?>
