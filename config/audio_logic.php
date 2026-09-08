<?php
require_once __DIR__ . '/video_helper.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/video_logic.php'; // Reuse resolvePodcastSiteUrl
require_once __DIR__ . '/rss.php';

/**
 * Handle audio upload logic
 */
function handleAudioUpload($file, $pdo, $title = '', $description = '', $password = '') {
    $config = Database::getConfig($pdo);
    $storage = $config['storage'];
    $user_id = $_SESSION['user_id'] ?? NULL;
    
    if (empty($title)) {
        $title = pathinfo($file['name'], PATHINFO_FILENAME);
    }
    
    // 1. Validate file
    list($mimeType, $extension, $detection) = detectMimeType($file);
    if (!empty($detection) && AssetDetector::isDangerous($detection)) {
        respondAndExit(['result' => 'error', 'code' => 403, 'message' => '安全防護：禁止上傳可執行檔或腳本程式 (' . htmlspecialchars($detection['label'] ?? 'unknown') . ')']);
    }
    if (!empty($detection) && ($detection['label'] ?? '') === 'empty') {
        respondAndExit(['result' => 'error', 'code' => 400, 'message' => '上傳的檔案為空檔案']);
    }
    $allowedAudioTypes = ['audio/mpeg', 'audio/wav', 'audio/aac', 'audio/ogg', 'audio/mp4', 'audio/flac', 'audio/x-wav', 'audio/x-mpeg'];
    
    if (!in_array($mimeType, $allowedAudioTypes) && strpos($mimeType, 'audio/') !== 0) {
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '不支持的音訊格式: ' . $mimeType]);
    }
    
    // 2. Prepare paths
    $datePath = 'storage/i/' . date('Y/m/d');
    if (!is_dir($datePath) && !mkdir($datePath, 0755, true)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '無法創建上傳目錄']);
    }
    
    $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $audioFileName = $randomFileName . '.' . $extension;
    $localAudioPath = $datePath . '/' . $audioFileName;
    
    // 3. Move uploaded file
    $moveFunction = isset($_SESSION['use_rename']) ? 'rename' : 'move_uploaded_file';
    if (!$moveFunction($file['tmp_name'], $localAudioPath)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '音訊儲存失敗']);
    }
    
    // 4. Extract metadata using VideoHelper (which uses ffprobe internally)
    $metadata = VideoHelper::getVideoMetadata($localAudioPath);
    
    // 5. Store files using StorageHelper
    try {
        $audioRemotePath = $datePath . '/' . $audioFileName;
        
        $audioResult = StorageHelper::upload($storage, $config, $localAudioPath, $audioRemotePath, [
            'content_type' => $mimeType,
            'content_disposition' => 'inline; filename="' . addcslashes($audioFileName, '"\\') . '"'
        ]);
        $audioUrl = generateFileUrl($storage, $config, $audioRemotePath, $audioResult);
        $publicAudioUrl = generatePublicFileUrl($storage, $config, $audioRemotePath, $audioUrl, !empty($password));
        
        // Capture file size before potential unlink
        $audioSize = file_exists($localAudioPath) ? filesize($localAudioPath) : 0;
        
        // Cleanup local files if not using local storage
        if ($storage !== 'local') {
            if (file_exists($localAudioPath)) unlink($localAudioPath);
        }
        
        $audioData = [
            'url' => $publicAudioUrl,
            'path' => $audioRemotePath,
            'size' => $audioSize,
            'filename' => $audioFileName,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata ?: ['duration' => 0, 'bitrate' => 0],
            'timestamp' => time()
        ];
        
        // 6. Save to database
        $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : NULL;
        $shareToken = generateShareToken();
        $stmt = $pdo->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id, title, description, password, mime_type, is_video, is_file, is_audio, share_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1, ?)");
        $stmt->execute([$audioUrl, $audioRemotePath, $storage, $audioData['size'], getClientIp(), $user_id, $title, $description, $hashedPassword, $mimeType, $shareToken]);
        $audioData['id'] = $pdo->lastInsertId();
        $audioData['share_url'] = buildAssetShareUrl($shareToken, $config);
        
        // 7. Update RSS and JSON
        if (empty($password)) {
            rebuildAudioRSS($pdo, $config);
        }
        updateDailyAudioList($audioData, $config);
        
        return $audioData;
        
    } catch (Exception $e) {
        logMessage("音訊處理失敗: " . $e->getMessage());
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '音訊處理失敗: ' . $e->getMessage()]);
    }
}

/**
 * Rebuild Audio Podcast RSS Feed from database
 */
function rebuildAudioRSS($pdo, $config) {
    $rssPath = getRssCachePath('audio');
    if (!is_dir('storage')) {
        if (!mkdir('storage', 0755, true)) {
            logMessage("無法建立 storage 目錄");
            return false;
        }
    }
    
    $lockFile = getRssLockPath('audio');
    $fp = fopen($lockFile, "w+");
    if (!$fp) {
        logMessage("無法建立鎖定檔案: $lockFile");
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        
        $xslt = $dom->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="/static/css/rss.xsl"');
        $dom->appendChild($xslt);
        
        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $dom->appendChild($rss);
        
        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);
        
        $channel->appendChild($dom->createElement('title', '888box Audio Podcast'));
        $channel->appendChild($dom->createElement('description', 'Automatically generated audio podcast from 888box uploads.'));
        $channel->appendChild($dom->createElement('link', resolvePodcastSiteUrl($config)));
        $channel->appendChild($dom->createElement('language', 'zh-tw'));
        
        // Fetch all audios from DB (Exclude password protected ones)
        $stmt = $pdo->prepare("SELECT * FROM images WHERE is_audio = 1 AND (password IS NULL OR password = '') ORDER BY id DESC");
        $stmt->execute();
        $audios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($audios as $audio) {
            $item = $dom->createElement('item');
            $item->appendChild($dom->createElement('title', htmlspecialchars($audio['title'] ?: $audio['path'])));
            $item->appendChild($dom->createElement('description', htmlspecialchars($audio['description'] ?: '')));
            $item->appendChild($dom->createElement('pubDate', date(DATE_RSS, strtotime($audio['created_at']))));
            
            $enclosure = $dom->createElement('enclosure');
            $publicUrl = getAssetPublicUrl($audio, $config);
            $enclosure->setAttribute('url', $publicUrl);
            $enclosure->setAttribute('length', $audio['size']);
            
            // Map mime-type
            $mime = $audio['mime_type'] ?: 'audio/mpeg';
            $enclosure->setAttribute('type', $mime);
            $item->appendChild($enclosure);
            
            $item->appendChild($dom->createElement('guid', $publicUrl));
            $channel->appendChild($item);
        }
        
        if ($dom->save($rssPath) === false) {
            logMessage("無法儲存 RSS 檔案: $rssPath");
        }

        cleanupLegacyPublicRssFiles();
        
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/**
 * Update Daily Audio List with flock
 */
function updateDailyAudioList($audioData, $config) {
    $date = date('Y-m-d');
    $dir = 'storage/' . $date;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $jsonPath = $dir . '/audios.json';
    $lockFile = $jsonPath . '.lock';
    
    $fp = fopen($lockFile, "w+");
    if (flock($fp, LOCK_EX)) {
        $list = [];
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            $list = json_decode($content, true) ?: [];
        }
        
        // Add new item at the beginning
        array_unshift($list, [
            'filename' => $audioData['filename'],
            'url' => $audioData['url'],
            'size' => $audioData['size'],
            'duration' => $audioData['metadata']['duration'] ?? 0,
            'bitrate' => $audioData['metadata']['bitrate'] ?? 0,
            'timestamp' => $audioData['timestamp'],
            'datetime' => date('Y-m-d H:i:s', $audioData['timestamp'])
        ]);
        
        file_put_contents($jsonPath, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
