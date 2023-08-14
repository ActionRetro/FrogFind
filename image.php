<?php

    $url = "";
    
    //get the image url
    if (isset( $_GET['i'] ) ) {
        $url = $_GET[ 'i' ];
    } else {
        exit();
    }

    //we can only do jpg and png here
    if (strpos($url, ".jpg") && strpos($url, ".jpeg") && strpos($url, ".png") != true ) {
        echo strpos($url, ".jpg");
        echo "Unsupported file type :(";
        exit();
    }

    //image needs to start with http
    if (substr( $url, 0, 4 ) != "http") {
        echo("Image failed :(");
        exit();
    }

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
 
 
 <html>
 <head>
     <title>FrogFind Image Viewer</title>
 </head>
 <body">
    <small><a href="<?php echo $_SERVER['HTTP_REFERER'] ?>">< Back to previous page</a></small>
    <p><small><b>Viewing image:</b> <?php echo $url ?></small></p>
    <img src="/image_compressed.php?i=<?php echo $url; ?>">
    <br><br>
    <small><a href="<?php echo $_SERVER['HTTP_REFERER'] ?>">< Back to previous page</a></small>
 </body>
 </html>