<?php

$url = "";
$filetype = "";
$raw_image = NULL;

//get the image url
if (isset( $_GET['i'] ) ) {
    $url = $_GET[ 'i' ];
} else {
    exit();
}

//an image will start with http, anything else is sus
if (substr( $url, 0, 4 ) != "http") {
    exit();
}

//we can only do jpg and png here
if (strpos($url, ".jpg") || strpos($url, ".jpeg") === true) {
    $filetype = "jpg";
    $raw_image = imagecreatefromjpeg($url);
} elseif (strpos($url, ".png") === true) {
    $filetype = "png";
    $raw_image = imagecreatefrompng($url);
} else {
    exit();
}

$raw_imagex = imagesx($raw_image);
$raw_imagey = imagesy($raw_image);

if ($raw_imagex >= $raw_imagey) {
	$dest_imagex = 300;
	$dest_imagey = intval(($raw_imagey / $raw_imagex) * $dest_imagex);
} else {
	$dest_imagey = 200;
	$dest_imagex = intval(($raw_imagex / $raw_imagey) * $dest_imagey);
}

$dest_image = imagecreatetruecolor($dest_imagex, $dest_imagey);

imagecopyresampled($dest_image, $raw_image, 0, 0, 0, 0, $dest_imagex, $dest_imagey, $raw_imagex, $raw_imagey);

header('Content-type: image/' . $filetype); 
if ($filetype = "jpg") {
    imagejpeg($dest_image,NULL,80); //80% quality
} elseif ($filetype = "png") {
    imagepng($dest_image,NULL,8); //80% compression
}

?>
