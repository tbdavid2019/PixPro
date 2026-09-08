<?php

require_once __DIR__ . '/video_helper.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/rss.php';

function resolvePodcastSiteUrl($config) {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    $configuredDomains = array_filter(array_map('trim', explode(',', $config['site_domain'] ?? '')));
    foreach ($configuredDomains as $domain) {
        if ($domain === '*') {
            continue;
        }

        if (preg_match('/^https?:\/\//i', $domain)) {
            return rtrim($domain, '/');
        }

        return 'https://' . rtrim($domain, '/');
    }

    return 'http://localhost';
}

/**
 * Handle video upload logic
 */
function handleVideoUpload($file, $pdo, $title = '', $description = '', $password = '') {
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
    $allowedVideoTypes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
    
    if (!in_array($mimeType, $allowedVideoTypes)) {
        respondAndExit(['result' => 'error', 'code' => 406, 'message' => '不支持的影片格式: ' . $mimeType]);
    }
    
    // 2. Prepare paths
    $datePath = 'storage/i/' . date('Y/m/d');
    if (!is_dir($datePath) && !mkdir($datePath, 0755, true)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '無法創建上傳目錄']);
    }
    
    $randomFileName = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $videoFileName = $randomFileName . '.' . $extension;
    $localVideoPath = $datePath . '/' . $videoFileName;
    
    // 3. Move uploaded file
    $moveFunction = isset($_SESSION['use_rename']) ? 'rename' : 'move_uploaded_file';
    if (!$moveFunction($file['tmp_name'], $localVideoPath)) {
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '影片儲存失敗']);
    }

    
    // 4. Extract metadata and generate thumbnail
    $metadata = VideoHelper::getVideoMetadata($localVideoPath);
    $thumbFileName = $randomFileName . '_thumb.jpg';
    $localThumbPath = $datePath . '/' . $thumbFileName;
    
    $thumbSuccess = VideoHelper::generateThumbnail($localVideoPath, $localThumbPath);
    
    // 5. Store files using StorageHelper
    try {
        $videoRemotePath = $datePath . '/' . $videoFileName;
        $thumbRemotePath = $datePath . '/' . $thumbFileName;
        
        // Upload Video
        $videoResult = StorageHelper::upload($storage, $config, $localVideoPath, $videoRemotePath, [
            'content_type' => $mimeType,
            'content_disposition' => 'inline; filename="' . addcslashes($videoFileName, '"\\') . '"'
        ]);
        $videoUrl = generateFileUrl($storage, $config, $videoRemotePath, $videoResult);
        $publicVideoUrl = generatePublicFileUrl($storage, $config, $videoRemotePath, $videoUrl, !empty($password));
        
        // Upload Thumbnail if generated
        $thumbUrl = '';
        if ($thumbSuccess) {
            $thumbResult = StorageHelper::upload($storage, $config, $localThumbPath, $thumbRemotePath, [
                'content_type' => 'image/jpeg',
                'content_disposition' => 'inline; filename="' . addcslashes($thumbFileName, '"\\') . '"'
            ]);
            $thumbUrl = generateFileUrl($storage, $config, $thumbRemotePath, $thumbResult);
            $publicThumbUrl = empty($password)
                ? generatePublicFileUrl($storage, $config, $thumbRemotePath, $thumbUrl)
                : '';
        } else {
            $publicThumbUrl = '';
        }
        
        // Capture file size before potential unlink
        $videoSize = file_exists($localVideoPath) ? filesize($localVideoPath) : 0;
        
        // Cleanup local files if not using local storage
        if ($storage !== 'local') {
            if (file_exists($localVideoPath)) unlink($localVideoPath);
            if (file_exists($localThumbPath)) unlink($localThumbPath);
        }
        
        $videoData = [
            'url' => $publicVideoUrl,
            'thumbnail_url' => $publicThumbUrl,
            'path' => $videoRemotePath,
            'thumb_path' => $thumbRemotePath,
            'size' => $videoSize,
            'filename' => $videoFileName,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata ?: ['duration' => 0, 'width' => 0, 'height' => 0],
            'timestamp' => time()
        ];

        // 6. Save to database first, then rebuild RSS from DB as the source of truth
        $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : NULL;
        $shareToken = generateShareToken();
        $stmt = $pdo->prepare("INSERT INTO images (url, path, storage, size, upload_ip, user_id, title, description, password, mime_type, is_video, is_file, share_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$videoUrl, $videoRemotePath, $storage, $videoData['size'], getClientIp(), $user_id, $title, $description, $hashedPassword, $mimeType, 1, 0, $shareToken]);
        $videoData['id'] = $pdo->lastInsertId();
        $videoData['share_url'] = buildAssetShareUrl($shareToken, $config);

        // 7. Update RSS and JSON
        if (empty($password)) {
            rebuildVideoRSS($pdo, $config);
        }
        updateDailyList($videoData, $config);

        return $videoData;
        
    } catch (Exception $e) {
        logMessage("影片處理失敗: " . $e->getMessage());
        respondAndExit(['result' => 'error', 'code' => 500, 'message' => '影片處理失敗: ' . $e->getMessage()]);
    }
}

/**
 * Update Podcast RSS Feed with flock
 */
function updatePodcastRSS($videoData, $config) {
    $rssPath = getRssCachePath('video');
    if (!is_dir('storage')) {
        if (!mkdir('storage', 0755, true)) {
            logMessage("無法建立 storage 目錄");
            return false;
        }
    }
    
    $lockFile = getRssLockPath('video');
    $fp = fopen($lockFile, "w+");
    if (!$fp) {
        logMessage("無法建立鎖定檔案: $lockFile");
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;
        
        $xslt = $dom->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="/static/css/rss.xsl"');
        $dom->appendChild($xslt);
        
        if (file_exists($rssPath) && filesize($rssPath) > 0) {
            libxml_use_internal_errors(true);
            if (!$dom->load($rssPath)) {
                logMessage("XML 載入失敗，重建中... " . implode(", ", libxml_get_errors()));
                libxml_clear_errors();
                // 重新初始化
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->formatOutput = true;
                $dom->appendChild($xslt);
            }
        }
        
        $rss = $dom->getElementsByTagName('rss')->item(0);
        if (!$rss) {
            $rss = $dom->createElement('rss');
            $rss->setAttribute('version', '2.0');
            $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
            $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
            $dom->appendChild($rss);
        }
        
        $channel = $rss->getElementsByTagName('channel')->item(0);
        if (!$channel) {
            $channel = $dom->createElement('channel');
            $rss->appendChild($channel);
            
            $channel->appendChild($dom->createElement('title', '888box Video Podcast'));
            $channel->appendChild($dom->createElement('description', 'Automatically generated video podcast from 888box uploads.'));
            $channel->appendChild($dom->createElement('link', resolvePodcastSiteUrl($config)));
            $channel->appendChild($dom->createElement('language', 'zh-tw'));
        }
        
        // Create new item
        $item = $dom->createElement('item');
        $item->appendChild($dom->createElement('title', htmlspecialchars($videoData['title'])));
        $item->appendChild($dom->createElement('description', htmlspecialchars($videoData['description'])));
        $item->appendChild($dom->createElement('pubDate', date(DATE_RSS, $videoData['timestamp'])));
        
        $enclosure = $dom->createElement('enclosure');
        $enclosure->setAttribute('url', $videoData['url']);
        $enclosure->setAttribute('length', $videoData['size']);
        $enclosure->setAttribute('type', 'video/mp4'); 
        $item->appendChild($enclosure);
        
        if (!empty($videoData['thumbnail_url'])) {
            $itunesImage = $dom->createElement('itunes:image');
            $itunesImage->setAttribute('href', $videoData['thumbnail_url']);
            $item->appendChild($itunesImage);
        }
        
        if (isset($videoData['metadata']['duration'])) {
            $durationSec = round($videoData['metadata']['duration']);
            $item->appendChild($dom->createElement('itunes:duration', $durationSec));
        }
        
        $item->appendChild($dom->createElement('guid', $videoData['url']));
        
        // Insert at the beginning of channel
        $items = $channel->getElementsByTagName('item');
        if ($items->length > 0) {
            $channel->insertBefore($item, $items->item(0));
        } else {
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
 * Update Daily Video List with flock
 */
function updateDailyList($videoData, $config) {
    $date = date('Y-m-d');
    $dir = 'storage/' . $date;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $jsonPath = $dir . '/videos.json';
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
            'filename' => $videoData['filename'],
            'url' => $videoData['url'],
            'thumbnail_url' => $videoData['thumbnail_url'],
            'size' => $videoData['size'],
            'duration' => $videoData['metadata']['duration'] ?? 0,
            'resolution' => isset($videoData['metadata']['width']) ? ($videoData['metadata']['width'] . 'x' . $videoData['metadata']['height']) : 'unknown',
            'timestamp' => $videoData['timestamp'],
            'datetime' => date('Y-m-d H:i:s', $videoData['timestamp'])
        ]);
        
        file_put_contents($jsonPath, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/**
 * Rebuild Podcast RSS Feed from database
 */
function rebuildVideoRSS($pdo, $config) {
    $rssPath = getRssCachePath('video');
    if (!is_dir('storage')) {
        if (!mkdir('storage', 0755, true)) {
            logMessage("無法建立 storage 目錄");
            return false;
        }
    }
    
    $lockFile = getRssLockPath('video');
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
        
        $channel->appendChild($dom->createElement('title', '888box Video Podcast'));
        $channel->appendChild($dom->createElement('description', 'Automatically generated video podcast from 888box uploads.'));
        $channel->appendChild($dom->createElement('link', resolvePodcastSiteUrl($config)));
        $channel->appendChild($dom->createElement('language', 'zh-tw'));
        
        // Fetch all videos from DB (Exclude password protected ones)
        $stmt = $pdo->prepare("SELECT * FROM images WHERE (url LIKE '%.mp4' OR url LIKE '%.webm' OR url LIKE '%.mov' OR url LIKE '%.mkv') AND (password IS NULL OR password = '') ORDER BY id DESC");
        $stmt->execute();
        $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($videos as $video) {
            $item = $dom->createElement('item');
            $item->appendChild($dom->createElement('title', htmlspecialchars($video['title'] ?: $video['path'])));
            $item->appendChild($dom->createElement('description', htmlspecialchars($video['description'] ?: '')));
            $item->appendChild($dom->createElement('pubDate', date(DATE_RSS, strtotime($video['created_at']))));
            
            $enclosure = $dom->createElement('enclosure');
            $publicUrl = getAssetPublicUrl($video, $config);
            $enclosure->setAttribute('url', $publicUrl);
            $enclosure->setAttribute('length', $video['size']);
            // A basic check for mime type based on extension
            $type = 'video/mp4';
            if (strpos($publicUrl, '.webm') !== false) $type = 'video/webm';
            elseif (strpos($publicUrl, '.mov') !== false) $type = 'video/quicktime';
            $enclosure->setAttribute('type', $type);
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
