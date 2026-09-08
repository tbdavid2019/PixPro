<?php
/**
 * 888box MCP Server & WebMCP Endpoint
 * 
 * Implements Model Context Protocol (MCP) over both stdio (CLI) and HTTP (WebMCP / Remote Agent).
 * Allows LLMs, Claude Desktop, Cursor, and browser AI agents (Chrome 146+ document.modelContext)
 * to interact with 888box directly as a tool provider.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/upload.php';
require_once __DIR__ . '/config/rss.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/security.php';

// 初始化数据库连接
$db = Database::getInstance();
$pdo = $db->getConnection();
$config = Database::getConfig($pdo);

/**
 * 獲取所有支援的 MCP Tool 定義
 */
function getMcpTools() {
    return [
        [
            'name' => 'upload_asset_by_url',
            'description' => 'Upload an image, video, audio, or document file from a remote URL to 888box',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'Direct URL of the asset to download and store'],
                    'title' => ['type' => 'string', 'description' => 'Optional title for the asset'],
                    'description' => ['type' => 'string', 'description' => 'Optional description for the asset'],
                    'password' => ['type' => 'string', 'description' => 'Optional password protection for the asset'],
                    'token' => ['type' => 'string', 'description' => 'API Token (optional if already logged in via browser session)']
                ],
                'required' => ['url']
            ]
        ],
        [
            'name' => 'list_assets',
            'description' => 'List recently uploaded assets (images, videos, audios, files) with pagination',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => ['all', 'image', 'video', 'audio', 'file'],
                        'description' => 'Type of assets to list (default: all)'
                    ],
                    'page' => ['type' => 'integer', 'description' => 'Page number (default: 1)'],
                    'limit' => ['type' => 'integer', 'description' => 'Items per page (default: 10, max: 50)'],
                    'token' => ['type' => 'string', 'description' => 'API Token (optional if logged in)']
                ]
            ]
        ],
        [
            'name' => 'search_assets',
            'description' => 'Search stored assets by keyword across title, path, and URL',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search keyword'],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['all', 'image', 'video', 'audio', 'file'],
                        'description' => 'Filter by asset type (default: all)'
                    ],
                    'token' => ['type' => 'string', 'description' => 'API Token (optional if logged in)']
                ],
                'required' => ['query']
            ]
        ],
        [
            'name' => 'get_stats',
            'description' => 'Get site-wide statistics about asset counts (total, images, videos, audio, files)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string', 'description' => 'API Token (optional)']
                ]
            ]
        ],
        [
            'name' => 'get_podcast_info',
            'description' => 'Get the current Podcast RSS feed URLs (video & audio) and subscription status',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string', 'description' => 'API Token (optional)']
                ]
            ]
        ],
        [
            'name' => 'rebuild_podcast_rss',
            'description' => 'Force a rebuild of the Podcast RSS feeds from the database (Admin only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => ['video', 'audio'],
                        'description' => 'Type of Podcast to rebuild (video/audio, default: video)'
                    ],
                    'token' => ['type' => 'string', 'description' => 'Admin API Token (optional if admin session is active)']
                ]
            ]
        ],
        [
            'name' => 'delete_asset',
            'description' => 'Delete an asset and its storage file by ID (Admin only)',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Asset ID to delete'],
                    'token' => ['type' => 'string', 'description' => 'Admin API Token (optional if admin session is active)']
                ],
                'required' => ['id']
            ]
        ]
    ];
}

/**
 * 驗證身分 (支援 Bearer Header, X-API-Key, Token 參數或 Session 登入態)
 */
function authenticateUser($pdo, $args = []) {
    // 1. 檢查 HTTP Header
    $headerToken = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $reqHeaders = apache_request_headers();
        $authHeader = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? '';
    }
    if (empty($authHeader) && function_exists('getallheaders')) {
        $reqHeaders = getallheaders();
        $authHeader = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? '';
    }

    if (!empty($authHeader) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
        $headerToken = trim($m[1]);
    } elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $headerToken = trim($_SERVER['HTTP_X_API_KEY']);
    }

    $token = !empty($args['token']) ? trim($args['token']) : $headerToken;

    // 2. 驗證 Token
    if (!empty($token)) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return ['id' => (int)$user['id'], 'username' => $user['username'], 'isAdmin' => true];
        }
    }

    // 3. 驗證 Session 登入態 (適用於 WebMCP 瀏覽器端請求)
    if (!empty($_SESSION['loggedin']) && !empty($_SESSION['user_id'])) {
        return [
            'id' => (int)$_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'admin',
            'isAdmin' => true
        ];
    }

    return null;
}

/**
 * 執行遠端 URL 下載與資產儲存
 */
function executeUploadFromUrl($pdo, $config, $url, $title = '', $description = '', $password = '', $userId = null) {
    if (empty($url)) {
        return ['success' => false, 'error' => '請提供有效的遠端 URL'];
    }

    // SSRF 安全檢驗
    $urlCheck = validateSafeRemoteUrl($url);
    if (!$urlCheck['valid']) {
        return ['success' => false, 'error' => '無效或受限的遠端 URL: ' . $urlCheck['error']];
    }
    $url = $urlCheck['url'];

    // 1. 安全檢查 Header
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, '888box-WebMCP-Ingestion/1.0');
    applySafeCurlOptions($ch);
    curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    if ($contentLength < 0) {
        $contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH);
    }
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        return ['success' => false, 'error' => "無法訪問遠端檔案 (HTTP $httpCode)"];
    }

    // 若有重新導向，再次驗證最終 URL 避免被跳轉至內部網路
    if (!empty($effectiveUrl) && $effectiveUrl !== $url) {
        $redirCheck = validateSafeRemoteUrl($effectiveUrl);
        if (!$redirCheck['valid']) {
            return ['success' => false, 'error' => '重新導向至受限的位址: ' . $redirCheck['error']];
        }
        $url = $redirCheck['url'];
    }

    $maxFileSize = (int)(Database::getConfig($pdo, 'max_file_size') ?: (100 * 1024 * 1024));
    if ($contentLength > 0 && $contentLength > $maxFileSize) {
        return ['success' => false, 'error' => '遠端檔案大小超過伺服器限制 (' . round($maxFileSize / (1024 * 1024)) . 'MB)'];
    }

    // 2. 下載到暫存目錄
    $tempDir = __DIR__ . '/storage/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $parsedPath = parse_url($url, PHP_URL_PATH) ?? '';
    $rawExt = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION));
    $tempFile = $tempDir . '/' . bin2hex(random_bytes(8)) . ($rawExt ? '.' . $rawExt : '.tmp');

    $fp = fopen($tempFile, 'w+');
    if (!$fp) {
        return ['success' => false, 'error' => '無法建立本機暫存檔案'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_USERAGENT, '888box-WebMCP-Ingestion/1.0');
    applySafeCurlOptions($ch);
    $downloadOk = curl_exec($ch);
    $downloadEffectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    fclose($fp);

    // 下載完成後再次檢查重定向網址安全
    if (!empty($downloadEffectiveUrl) && $downloadEffectiveUrl !== $url) {
        $finalCheck = validateSafeRemoteUrl($downloadEffectiveUrl);
        if (!$finalCheck['valid']) {
            if (file_exists($tempFile)) @unlink($tempFile);
            return ['success' => false, 'error' => '下載重新導向至受限位址'];
        }
    }

    if (!$downloadOk || !file_exists($tempFile) || filesize($tempFile) === 0) {
        if (file_exists($tempFile)) @unlink($tempFile);
        return ['success' => false, 'error' => '遠端檔案下載失敗或內容為空'];
    }

    $fileSize = filesize($tempFile);
    $fileName = basename($parsedPath) ?: ('asset_' . date('Ymd_His') . ($rawExt ? '.' . $rawExt : ''));
    if (empty($title)) {
        $title = pathinfo($fileName, PATHINFO_FILENAME);
    }

    $fakeFile = [
        'name' => $fileName,
        'type' => $contentType ?: 'application/octet-stream',
        'tmp_name' => $tempFile,
        'error' => 0,
        'size' => $fileSize
    ];

    list($mimeType, $ext, $detection) = detectMimeType($fakeFile);
    if (!empty($detection) && AssetDetector::isDangerous($detection)) {
        throw new Exception('安全防護：禁止上傳可執行檔或腳本程式 (' . ($detection['label'] ?? 'unknown') . ')');
    }
    if (!$rawExt && $ext) {
        $renamedTemp = $tempFile . '.' . $ext;
        if (rename($tempFile, $renamedTemp)) {
            $tempFile = $renamedTemp;
            $fakeFile['tmp_name'] = $tempFile;
        }
    }

    $_SESSION['use_rename'] = true;
    $prevUserId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        $_SESSION['user_id'] = $userId;
    }

    try {
        if (strpos($mimeType, 'video/') === 0) {
            require_once __DIR__ . '/config/video_logic.php';
            $videoData = handleVideoUpload($fakeFile, $pdo, $title, $description, $password);
            return [
                'success' => true,
                'type' => 'video',
                'id' => $videoData['id'] ?? null,
                'title' => $title,
                'url' => $videoData['url'] ?? '',
                'thumbnail_url' => $videoData['thumbnail_url'] ?? '',
                'share_url' => $videoData['share_url'] ?? ''
            ];
        } elseif (strpos($mimeType, 'audio/') === 0) {
            require_once __DIR__ . '/config/audio_logic.php';
            $audioData = handleAudioUpload($fakeFile, $pdo, $title, $description, $password);
            return [
                'success' => true,
                'type' => 'audio',
                'id' => $audioData['id'] ?? null,
                'title' => $title,
                'url' => $audioData['url'] ?? '',
                'share_url' => $audioData['share_url'] ?? ''
            ];
        } elseif (strpos($mimeType, 'image/') === 0) {
            $storage = $config['storage'] ?? 'local';
            $datePath = 'storage/i/' . date('Y/m/d');
            if (!is_dir($datePath)) mkdir($datePath, 0755, true);

            $randomNum = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $finalExt = $ext ?: 'jpg';
            $newFilePath = $datePath . '/' . $randomNum . '.' . $finalExt;

            if (!rename($tempFile, $newFilePath)) {
                return ['success' => false, 'error' => '圖片檔案移動失敗'];
            }

            $compressedPath = processImageCompression($mimeType, $newFilePath, $datePath . '/' . $randomNum, 80, $config['output_format'] ?? 'webp');
            $imgSize = filesize($compressedPath);
            $storagePath = ($storage === 'local') ? $compressedPath : ($datePath . '/' . basename($compressedPath));

            $result = StorageHelper::upload($storage, $config, $compressedPath, $storagePath, [
                'content_type' => mime_content_type($compressedPath) ?: $mimeType,
                'content_disposition' => 'inline; filename="' . addcslashes(basename($storagePath), '"\\') . '"'
            ]);

            if ($storage !== 'local') {
                if (file_exists($compressedPath)) @unlink($compressedPath);
                if ($compressedPath !== $newFilePath && file_exists($newFilePath)) @unlink($newFilePath);
            }

            $fileUrl = generateFileUrl($storage, $config, $storagePath, $result);
            $publicUrl = generatePublicFileUrl($storage, $config, $storagePath, $fileUrl, !empty($password));
            $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
            $shareToken = generateShareToken();

            $stmt = $pdo->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id, title, description, password, mime_type, is_video, is_audio, is_file, share_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)");
            $stmt->execute([$fileUrl, $storagePath, $storage, $imgSize, getClientIp(), $userId, $title, $description, $hashedPassword, $mimeType, $shareToken]);
            $assetId = $pdo->lastInsertId();

            return [
                'success' => true,
                'type' => 'image',
                'id' => (int)$assetId,
                'title' => $title,
                'url' => $publicUrl,
                'share_url' => buildAssetShareUrl($shareToken, $config)
            ];
        } else {
            // 一般文件
            $storage = $config['storage'] ?? 'local';
            $datePath = 'storage/i/' . date('Y/m/d');
            if (!is_dir($datePath)) mkdir($datePath, 0755, true);

            $randomNum = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $finalExt = $ext ?: 'bin';
            $destPath = $datePath . '/' . $randomNum . '.' . $finalExt;

            if (!rename($tempFile, $destPath)) {
                return ['success' => false, 'error' => '文件移動失敗'];
            }

            $docSize = filesize($destPath);
            $storagePath = ($storage === 'local') ? $destPath : ($datePath . '/' . basename($destPath));

            $result = StorageHelper::upload($storage, $config, $destPath, $storagePath, [
                'content_type' => $mimeType,
                'content_disposition' => 'attachment; filename="' . addcslashes($fileName, '"\\') . '"'
            ]);

            if ($storage !== 'local') {
                if (file_exists($destPath)) @unlink($destPath);
            }

            $fileUrl = generateFileUrl($storage, $config, $storagePath, $result);
            $publicUrl = generatePublicFileUrl($storage, $config, $storagePath, $fileUrl, !empty($password));
            $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
            $shareToken = generateShareToken();

            $stmt = $pdo->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id, title, description, password, mime_type, is_video, is_audio, is_file, share_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1, ?)");
            $stmt->execute([$fileUrl, $storagePath, $storage, $docSize, getClientIp(), $userId, $title, $description, $hashedPassword, $mimeType, $shareToken]);
            $assetId = $pdo->lastInsertId();

            return [
                'success' => true,
                'type' => 'file',
                'id' => (int)$assetId,
                'title' => $title,
                'url' => $publicUrl,
                'share_url' => buildAssetShareUrl($shareToken, $config)
            ];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => '處理上傳失敗: ' . $e->getMessage()];
    } finally {
        unset($_SESSION['use_rename']);
        if ($prevUserId !== null) {
            $_SESSION['user_id'] = $prevUserId;
        } else {
            unset($_SESSION['user_id']);
        }
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
}

/**
 * 處理單一 JSON-RPC 請求
 */
function handleRequest($request, $pdo, $config) {
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];
    $id = $request['id'] ?? null;

    switch ($method) {
        case 'initialize':
            return [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => (object)[],
                    'resources' => (object)[]
                ],
                'serverInfo' => [
                    'name' => '888box-mcp-server',
                    'version' => '1.1.0',
                    'description' => '888box Unified Asset Management & WebMCP Provider'
                ]
            ];

        case 'ping':
            return (object)[];

        case 'tools/list':
            return [
                'tools' => getMcpTools()
            ];

        case 'tools/call':
            $toolName = $params['name'] ?? '';
            $args = $params['arguments'] ?? [];

            $authUser = authenticateUser($pdo, $args);
            $loginRestriction = isset($config['login_restriction']) && filter_var($config['login_restriction'], FILTER_VALIDATE_BOOLEAN);

            // 當開啟登入限制時，任何 tool 調用皆需身分認證
            if ($loginRestriction && !$authUser) {
                return [
                    'content' => [
                        ['type' => 'text', 'text' => 'Error: Authentication required. Please provide a valid API token or login first.']
                    ],
                    'isError' => true
                ];
            }

            // 1. upload_asset_by_url
            if ($toolName === 'upload_asset_by_url') {
                $url = trim($args['url'] ?? '');
                $title = trim($args['title'] ?? '');
                $desc = trim($args['description'] ?? '');
                $pwd = trim($args['password'] ?? '');

                if (empty($url)) {
                    return ['content' => [['type' => 'text', 'text' => 'Error: URL is required']], 'isError' => true];
                }

                $res = executeUploadFromUrl($pdo, $config, $url, $title, $desc, $pwd, $authUser['id'] ?? null);
                if (!$res['success']) {
                    return ['content' => [['type' => 'text', 'text' => 'Upload failed: ' . ($res['error'] ?? 'Unknown error')]], 'isError' => true];
                }

                return [
                    'content' => [
                        ['type' => 'text', 'text' => "Asset uploaded successfully!\n" . json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
                    ]
                ];
            }

            // 2. list_assets
            if ($toolName === 'list_assets') {
                $type = $args['type'] ?? 'all';
                $page = max(1, (int)($args['page'] ?? 1));
                $limit = min(50, max(1, (int)($args['limit'] ?? 10)));
                $offset = ($page - 1) * $limit;

                $where = "1=1";
                if ($type === 'image') {
                    $where = "is_video = 0 AND is_audio = 0 AND is_file = 0";
                } elseif ($type === 'video') {
                    $where = "is_video = 1";
                } elseif ($type === 'audio') {
                    $where = "is_audio = 1";
                } elseif ($type === 'file') {
                    $where = "is_file = 1";
                }

                $stmt = $pdo->prepare("SELECT id, title, path, url, is_video, is_audio, is_file, size, mime_type, share_token, created_at FROM images WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
                $stmt->bindValue(1, $limit, PDO::PARAM_INT);
                $stmt->bindValue(2, $offset, PDO::PARAM_INT);
                $stmt->execute();
                $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($assets as &$asset) {
                    $asset['public_url'] = getAssetPublicUrl($asset, $config);
                    $asset['share_url'] = buildAssetShareUrl($asset, $config);
                }

                $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM images WHERE $where")->fetchColumn();

                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Assets ($type, page $page, total $totalCount):\n" . json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        ]
                    ]
                ];
            }

            // 3. search_assets
            if ($toolName === 'search_assets') {
                $query = trim($args['query'] ?? '');
                if ($query === '') {
                    return ['content' => [['type' => 'text', 'text' => 'Error: Query parameter is required']], 'isError' => true];
                }

                $type = $args['type'] ?? 'all';
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

                $stmt = $pdo->prepare("SELECT id, title, path, url, is_video, is_audio, is_file, size, mime_type, share_token, created_at FROM images WHERE $where ORDER BY created_at DESC LIMIT 20");
                $stmt->execute($params);
                $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($assets as &$asset) {
                    $asset['public_url'] = getAssetPublicUrl($asset, $config);
                    $asset['share_url'] = buildAssetShareUrl($asset, $config);
                }

                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Search results for '$query' ($type):\n" . json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        ]
                    ]
                ];
            }

            // 4. get_stats
            if ($toolName === 'get_stats') {
                $stats = [
                    'total' => (int)$pdo->query("SELECT COUNT(*) FROM images")->fetchColumn(),
                    'image' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_video = 0 AND is_audio = 0 AND is_file = 0")->fetchColumn(),
                    'video' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_video = 1")->fetchColumn(),
                    'audio' => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_audio = 1")->fetchColumn(),
                    'file'  => (int)$pdo->query("SELECT COUNT(*) FROM images WHERE is_file = 1")->fetchColumn(),
                    'storage_backend' => $config['storage'] ?? 'local'
                ];

                return [
                    'content' => [
                        ['type' => 'text', 'text' => "888box Statistics:\n" . json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]
                    ]
                ];
            }

            // 5. get_podcast_info
            if ($toolName === 'get_podcast_info') {
                $rssVideoUrl = buildRssUrl('video', $config, true);
                $rssAudioUrl = buildRssUrl('audio', $config, true);
                $rssMode = isRssTokenEnabled($config) ? 'token-protected' : 'public';
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Podcast Information:\nVideo RSS: $rssVideoUrl\nAudio RSS: $rssAudioUrl\nRSS Mode: $rssMode\nStorage Backend: " . ($config['storage'] ?? 'local')
                        ]
                    ]
                ];
            }

            // 6. rebuild_podcast_rss (Admin only)
            if ($toolName === 'rebuild_podcast_rss') {
                if (!$authUser || empty($authUser['isAdmin'])) {
                    return ['content' => [['type' => 'text', 'text' => 'Error: Admin authorization required to rebuild RSS feeds']], 'isError' => true];
                }

                $type = $args['type'] ?? 'video';
                if ($type === 'audio') {
                    require_once __DIR__ . '/config/audio_logic.php';
                    rebuildAudioRSS($pdo, $config);
                } else {
                    require_once __DIR__ . '/config/video_logic.php';
                    rebuildVideoRSS($pdo, $config);
                }

                return [
                    'content' => [
                        ['type' => 'text', 'text' => "Podcast " . ucfirst($type) . " RSS feed has been rebuilt successfully."]
                    ]
                ];
            }

            // 7. delete_asset (Admin only)
            if ($toolName === 'delete_asset') {
                if (!$authUser || empty($authUser['isAdmin'])) {
                    return ['content' => [['type' => 'text', 'text' => 'Error: Admin authorization required to delete assets']], 'isError' => true];
                }

                $id = (int)($args['id'] ?? 0);
                if ($id <= 0) {
                    return ['content' => [['type' => 'text', 'text' => 'Error: Valid Asset ID is required']], 'isError' => true];
                }

                require_once __DIR__ . '/config/delete.php';
                $success = deleteAsset($pdo, $id);

                return [
                    'content' => [
                        ['type' => 'text', 'text' => $success ? "Asset $id deleted successfully" : "Failed to delete asset $id"]
                    ],
                    'isError' => !$success
                ];
            }

            return ['content' => [['type' => 'text', 'text' => 'Unknown tool: ' . $toolName]], 'isError' => true];

        default:
            return ['error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
    }
}

// =============================================================================
// CLI 模式 (Stdio JSON-RPC 串流通訊)
// =============================================================================
if (PHP_SAPI === 'cli') {
    while ($line = fgets(STDIN)) {
        $request = json_decode($line, true);
        if (!$request) continue;

        ob_start();
        $result = handleRequest($request, $pdo, $config);
        $ob_content = ob_get_clean();

        $response = ['jsonrpc' => '2.0'];
        if (isset($result['error'])) {
            $response['error'] = $result['error'];
        } else {
            $response['result'] = $result;
        }

        if (isset($request['id'])) {
            $response['id'] = $request['id'];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit(0);
}

// =============================================================================
// HTTP 模式 (WebMCP / Remote JSON-RPC HTTP 端點)
// =============================================================================

// 設定 CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowOrigin = resolveCorsAllowOrigin($origin, $config['site_domain'] ?? '');
header("Access-Control-Allow-Origin: $allowOrigin");
if ($allowOrigin !== '*' && $allowOrigin !== 'null') {
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// OPTIONS Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// GET 請求：回傳 MCP 伺服器資訊與可用工具概覽
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . $host;

    echo json_encode([
        'name' => '888box MCP & WebMCP Server',
        'version' => '1.1.0',
        'description' => 'Model Context Protocol (MCP) and WebMCP endpoint for 888box asset platform.',
        'endpoint' => $baseUrl . '/mcp.php',
        'documentation' => $baseUrl . '/skill.php',
        'tools' => getMcpTools(),
        'capabilities' => [
            'stdio' => true,
            'http_jsonrpc' => true,
            'webmcp' => true
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// POST 請求：處理 JSON-RPC
$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);

if (!$request || !is_array($request)) {
    http_response_code(400);
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => ['code' => -32700, 'message' => 'Parse error: Invalid JSON payload'],
        'id' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 支援批次或單一請求
$isBatch = array_keys($request) === range(0, count($request) - 1);
$requests = $isBatch ? $request : [$request];
$responses = [];

foreach ($requests as $req) {
    if (!is_array($req)) {
        $responses[] = [
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => 'Invalid Request'],
            'id' => null
        ];
        continue;
    }

    ob_start();
    $result = handleRequest($req, $pdo, $config);
    ob_end_clean();

    $resp = ['jsonrpc' => '2.0'];
    if (isset($result['error'])) {
        $resp['error'] = $result['error'];
    } else {
        $resp['result'] = $result;
    }

    if (isset($req['id'])) {
        $resp['id'] = $req['id'];
    }

    $responses[] = $resp;
}

echo json_encode($isBatch ? $responses : $responses[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
