<?php
// 配置部分
$imageDir = 'images/';          // 图片目录路径
$cacheDir = 'cache/';           // 缓存目录路径
$cacheFile = $cacheDir . 'image_cache.json';  // 缓存文件路径
$cacheLifetime = 86400;         // 缓存有效期(秒)，默认1天

// 确保缓存目录存在
if (!file_exists($cacheDir) && !mkdir($cacheDir, 0755, true)) {
    die('无法创建缓存目录');
}

// 检测用户设备类型
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobileKeywords = [
        'mobile', 'android', 'iphone', 'ipod', 'blackberry', 
        'webos', 'opera mini', 'windows phone', 'iemobile'
    ];
    
    foreach ($mobileKeywords as $keyword) {
        if (stripos($userAgent, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

// 获取目录中所有图片文件
function getImageFiles($dir) {
    $images = [];
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            $filePath = $dir . $file;
            // 检查文件扩展名是否为图片格式且可读
            if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file) && is_readable($filePath)) {
                $images[] = $filePath;
            }
        }
    }
    return $images;
}

// 获取或创建图片信息缓存
function getImageInfoWithCache($imageDir, $cacheFile, $cacheLifetime) {
    // 如果缓存存在且未过期，直接读取
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheLifetime)) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if ($cachedData !== null) {
            // 验证缓存中的图片是否仍然存在且未修改
            $validCache = true;
            foreach ($cachedData as $filePath => $info) {
                if (!file_exists($filePath) || filemtime($filePath) > $info['mtime']) {
                    $validCache = false;
                    break;
                }
            }
            if ($validCache) {
                return $cachedData;
            }
        }
    }
    
    // 没有有效缓存，重新扫描图片
    $imageFiles = getImageFiles($imageDir);
    $imageInfo = [];
    
    foreach ($imageFiles as $filePath) {
        try {
            list($width, $height) = getimagesize($filePath);
            $imageInfo[$filePath] = [
                'width' => $width,
                'height' => $height,
                'orientation' => ($height > $width) ? 'portrait' : 'landscape',
                'mtime' => filemtime($filePath) // 记录文件修改时间
            ];
        } catch (Exception $e) {
            continue;
        }
    }
    
    // 保存到缓存文件
    file_put_contents($cacheFile, json_encode($imageInfo));
    
    return $imageInfo;
}

// 根据设备类型筛选图片
function filterImagesByOrientation($imageInfo, $isMobile) {
    $portraitImages = [];
    $landscapeImages = [];
    
    foreach ($imageInfo as $filePath => $info) {
        if ($info['orientation'] === 'portrait') {
            $portraitImages[] = $filePath;
        } else {
            $landscapeImages[] = $filePath;
        }
    }
    
    // 根据设备类型返回对应方向的图片
    // 如果没有对应方向的图片，则返回所有图片
    if ($isMobile) {
        return !empty($portraitImages) ? $portraitImages : array_keys($imageInfo);
    } else {
        return !empty($landscapeImages) ? $landscapeImages : array_keys($imageInfo);
    }
}

// 主程序
$isMobile = isMobileDevice();
$imageInfo = getImageInfoWithCache($imageDir, $cacheFile, $cacheLifetime);
$filteredImages = filterImagesByOrientation($imageInfo, $isMobile);

if (!empty($filteredImages)) {
    // 随机选择一张图片
    $randomImage = $filteredImages[array_rand($filteredImages)];
    
    // 根据图片类型设置正确的Content-Type
    $extension = strtolower(pathinfo($randomImage, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    
    $contentType = $mimeTypes[$extension] ?? 'image/jpeg';
    header('Content-Type: ' . $contentType);
    readfile($randomImage);
} else {
    // 没有找到图片的处理
    header('Content-Type: text/plain');
    echo 'No images found';
}

// 可选：添加手动刷新缓存的机制
if (isset($_GET['refresh_cache']) && $_GET['refresh_cache'] === '1') {
    unlink($cacheFile);
    header('Location: ' . str_replace('?refresh_cache=1', '', $_SERVER['REQUEST_URI']));
    exit;
}
?>
