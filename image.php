<?php
    require_once __DIR__ . '/lib/frogfind.php';
    ff_block_crawlers();

    $url = "";

    //get the image url
    if (ff_param('i') !== '') {
        $url = ff_param('i');
    } else {
        exit();
    }

    //only show images FrogFind linked to recently
    if (!ff_link_is_signed('image', $url)) {
        ff_small_page(403, 'FrogFind Image Viewer', '<p>This image link has expired, sorry :(</p><p><a href="/">Back to FrogFind</a></p>');
    }

    //image needs to start with http
    if (!preg_match('#^https?://#i', $url)) {
        echo("Image failed :(");
        exit();
    }

    //the back link goes to the page you came from, if it's a normal web address
    $back_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if (!preg_match('#^https?://#i', $back_url)) {
        $back_url = '/';
    }

    //private: the back link depends on the visitor, so a shared cache mustn't keep it
    header('Cache-Control: private, max-age=3600');
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">


 <html>
 <head>
     <title>FrogFind Image Viewer</title>
     <meta name="robots" content="noindex,nofollow">
 </head>
 <body>
    <small><a href="<?php echo ff_href($back_url) ?>">< Back to previous page</a></small>
    <p><small><b>Viewing image:</b> <?php echo ff_h($url) ?></small></p>
    <img src="<?php echo ff_image_src($url) ?>">
    <br><br>
    <small><a href="<?php echo ff_href($back_url) ?>">< Back to previous page</a></small>
 </body>
 </html>