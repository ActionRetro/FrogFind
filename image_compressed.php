<?php
require_once __DIR__ . '/lib/frogfind.php';
ff_block_crawlers();

$url = "";
$raw_image = NULL;

//get the image url
if (ff_param('i') !== '') {
    $url = ff_param('i');
} else {
    exit();
}

//only shrink images FrogFind linked to recently
if (!ff_link_is_signed('image', $url)) {
    http_response_code(403);
    exit();
}

//an image will start with http, anything else is sus
if (!preg_match('#^https?://#i', $url)) {
    exit();
}

//shrunk this one in the last week? send the saved copy
$jpeg = ff_cache_get('image', $url);

if ($jpeg === NULL) {
    ff_busy_valve();
    $response = ff_fetch($url, FF_MAX_DOWNLOAD_BYTES, FF_MAX_DOWNLOAD_BYTES);
    if (!$response['ok']) {
        http_response_code(502);
        exit();
    }

    //we can only do jpg and png here. Check the file itself, not the name, and
    //check the size before decoding: a small file can unpack into a huge bitmap
    $info = @getimagesizefromstring($response['body']);
    if (!$info || ($info[2] !== IMAGETYPE_JPEG && $info[2] !== IMAGETYPE_PNG)) {
        http_response_code(415);
        exit();
    }
    $raw_imagex = $info[0];
    $raw_imagey = $info[1];
    if ($raw_imagex < 1 || $raw_imagey < 1 || $raw_imagex > FF_IMAGE_MAX_SIDE || $raw_imagey > FF_IMAGE_MAX_SIDE) {
        http_response_code(413);
        exit();
    }

    $raw_image = @imagecreatefromstring($response['body']);
    if (!$raw_image) {
        http_response_code(415);
        exit();
    }

    if ($raw_imagex >= $raw_imagey) {
        $dest_imagex = 300;
        $dest_imagey = max(1, intval(($raw_imagey / $raw_imagex) * $dest_imagex));
    } else {
        $dest_imagey = 200;
        $dest_imagex = max(1, intval(($raw_imagex / $raw_imagey) * $dest_imagey));
    }

    $dest_image = imagecreatetruecolor($dest_imagex, $dest_imagey);
    //white behind see-through PNGs, instead of black
    imagefill($dest_image, 0, 0, imagecolorallocate($dest_image, 255, 255, 255));

    imagecopyresampled($dest_image, $raw_image, 0, 0, 0, 0, $dest_imagex, $dest_imagey, $raw_imagex, $raw_imagey);

    //everything goes out as JPEG, which the oldest browsers can show (PNG came later)
    ob_start();
    imagejpeg($dest_image, NULL, 80); //80% quality
    $jpeg = ob_get_clean();
    ff_cache_put('image', $url, $jpeg);
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($jpeg));
header('X-Robots-Tag: noindex, nofollow');
ff_cache_headers(FF_CACHE_TTLS['image']);
echo $jpeg;
