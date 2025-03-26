<?php
/**
 * 设备适配图片输出系统
 * 功能：根据设备类型自动输出最佳方向的图片
 * 特点：高性能缓存、智能回退、详细日志
 */

// ==================== 配置部分 ====================
$config = [
	'image_dir' => 'images/',          // 图片目录(结尾带斜杠)
    'cache_dir' => 'cache/',           // 缓存目录(结尾带斜杠)
    'cache_ttl' => 86400,              // 缓存有效期(秒，默认1天)
    'strict_mode' => false,            // true:严格模式/false:宽松模式(无匹配图片时回退)
    'enable_logging' => true,          // 是否记录日志
    'log_file' => 'logs/image_system.log', // 日志文件路径
];

// ==================== 初始化 ====================
// 确保目录存在
if (!file_exists($config['cache_dir']) && !mkdir($config['cache_dir'], 0755, true)) {
    die('Error: Cannot create cache directory');
}
if ($config['enable_logging'] && !file_exists(dirname($config['log_file']))) {
    mkdir(dirname($config['log_file']), 0755, true);
}

// ==================== 核心函数 ====================

/**
 * 记录日志
 */
function log_message($message, $level = 'INFO') {
    global $config;
    if (!$config['enable_logging']) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp][$level] $message" . PHP_EOL;
    file_put_contents($config['log_file'], $log, FILE_APPEND);
}

/**
 * 增强版设备检测
 */
function detectDeviceType() {
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    
    // 移动设备关键词
    $mobileKeywords = [
        'mobile', 'android', 'iphone', 'ipod', 'ipad', 'blackberry',
        'webos', 'opera mini', 'windows phone', 'iemobile', 'tablet'
    ];
    
    // 平板设备单独处理
    $isTablet = strpos($userAgent, 'tablet') !== false 
        || (strpos($userAgent, 'ipad') !== false);
    
    foreach ($mobileKeywords as $keyword) {
        if (strpos($userAgent, $keyword) !== false) {
            return $isTablet ? 'tablet' : 'mobile';
        }
    }
    
    return 'desktop';
}

/**
 * 获取图片文件列表
 */
function getImageFiles($dir) {
    $images = [];
    if (!is_dir($dir)) {
        log_message("Image directory not found: $dir", 'ERROR');
        return $images;
    }
    
    $files = scandir($dir);
    foreach ($files as $file) {
        $path = $dir . $file;
        if (is_file($path) && preg_match('/\.(jpg|jpeg|png|gif|webp|avif)$/i', $file)) {
            $images[] = $path;
        }
    }
    
    log_message(sprintf('Found %d images in directory', count($images)));
    return $images;
}

/**
 * 带缓存的图片信息获取
 */
function getImageInfoWithCache() {
    global $config;
    $cacheFile = $config['cache_dir'] . 'image_info.cache';
    
    // 尝试读取有效缓存
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        $cacheValid = false;
        
        if ($cacheData && isset($cacheData['expire']) && $cacheData['expire'] > time()) {
            // 验证缓存中的图片是否仍然存在
            $allExists = true;
            foreach ($cacheData['images'] as $path => $info) {
                if (!file_exists($path) || filemtime($path) != $info['mtime']) {
                    $allExists = false;
                    break;
                }
            }
            
            if ($allExists) {
                log_message('Using valid image cache');
                return $cacheData['images'];
            }
        }
    }
    
    // 重建缓存
    log_message('Rebuilding image cache');
    $images = getImageFiles($config['image_dir']);
    $imageInfo = [];
    
    foreach ($images as $path) {
        try {
            $size = getimagesize($path);
            if ($size === false) continue;
            
            $imageInfo[$path] = [
                'width' => $size[0],
                'height' => $size[1],
                'orientation' => ($size[1] > $size[0]) ? 'portrait' : 'landscape',
                'mtime' => filemtime($path),
                'aspect_ratio' => $size[0] ? round($size[1]/$size[0], 2) : 0
            ];
        } catch (Exception $e) {
            log_message("Error processing image $path: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // 保存缓存
    $cacheData = [
        'created' => time(),
        'expire' => time() + $config['cache_ttl'],
        'images' => $imageInfo
    ];
    file_put_contents($cacheFile, json_encode($cacheData));
    
    return $imageInfo;
}

/**
 * 智能图片筛选
 */
function filterImages($imageInfo, $deviceType) {
    global $config;
    
    $portraits = [];
    $landscapes = [];
    
    foreach ($imageInfo as $path => $info) {
        if ($info['orientation'] === 'portrait') {
            $portraits[$path] = $info['aspect_ratio'];
        } else {
            $landscapes[$path] = $info['aspect_ratio'];
        }
    }
    
    log_message(sprintf(
        'Device: %s, Portraits: %d, Landscapes: %d',
        $deviceType,
        count($portraits),
        count($landscapes)
    ));
    
    // 设备偏好设置
    $preferred = [
        'mobile' => $portraits,
        'tablet' => $landscapes, // 平板通常更适合横屏
        'desktop' => $landscapes
    ][$deviceType] ?? [];
    
    // 严格模式：只返回偏好图片
    if ($config['strict_mode']) {
        return array_keys($preferred);
    }
    
    // 宽松模式：优先返回偏好图片，没有则返回所有
    return !empty($preferred) ? array_keys($preferred) : array_keys($imageInfo);
}

// ==================== 主程序 ====================

try {
    // 1. 检测设备类型
    $deviceType = detectDeviceType();
    log_message("Detected device: $deviceType");
    
    // 2. 获取图片信息(带缓存)
    $imageInfo = getImageInfoWithCache();
    if (empty($imageInfo)) {
        throw new Exception('No valid images found');
    }
    
    // 3. 筛选图片
    $filtered = filterImages($imageInfo, $deviceType);
    if (empty($filtered)) {
        throw new Exception('No matching images found for current device');
    }
    
    // 4. 随机选择一张图片
    $selected = $filtered[array_rand($filtered)];
    $info = $imageInfo[$selected];
    
    // 5. 输出图片
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif'
    ];
    
    $ext = strtolower(pathinfo($selected, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimeTypes[$ext] ?? 'image/jpeg'));
    header('X-Image-Info: ' . json_encode([
        'width' => $info['width'],
        'height' => $info['height'],
        'orientation' => $info['orientation'],
        'aspect_ratio' => $info['aspect_ratio'],
        'selected_for' => $deviceType
    ]));
    
    readfile($selected);
    log_message("Served image: $selected ({$info['orientation']})");
    
} catch (Exception $e) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Image Service Error: ' . $e->getMessage();
    log_message('Error: ' . $e->getMessage(), 'ERROR');
}

// 手动刷新缓存
if (isset($_GET['refresh_cache']) && $_GET['refresh_cache'] === '1') {
    $cacheFile = $config['cache_dir'] . 'image_info.cache';
    if (file_exists($cacheFile)) unlink($cacheFile);
    header('Location: ' . str_replace('?refresh_cache=1', '', $_SERVER['REQUEST_URI']));
    exit;
}
?>
