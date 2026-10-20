<?php
require_once __DIR__ . '/lib/frogfind.php';
ff_block_crawlers();
require_once('vendor/autoload.php');

use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;

$article_url = trim(ff_param('a'));
$article = NULL; // title, content and images, fresh or from the cache
$error_text = "";
$status = 200;

// List of Content-Types that we know we can (try to) parse.
// Anything else will get piped through directly, if possible.
$compatible_content_types = [
    "text/html",
    "text/plain",
    "application/xhtml+xml"
];

if ($article_url === '') {
    $error_text = "What do you think you're doing... >:(";
} elseif (!ff_link_is_signed('read', $article_url) && !ff_box_token_is_valid(ff_param('t'))) {
    // Not a link FrogFind made in the last day, and not from the address box.
    $status = 403;
    $error_text = "This link has expired or didn't come from FrogFind. Press Go! to open it.";
} elseif (!preg_match('#^https?://#i', $article_url)) {
    $error_text = "That's not a web page :(";
} else {
    // Read this page in the last day? Use the saved copy.
    $article = json_decode((string) ff_cache_get('read', $article_url), true);
    if (!is_array($article)) {
        $article = NULL;
        ff_busy_valve();
        // The maximum allowed filesize for proxy download passthroughs is
        // FF_MAX_DOWNLOAD_BYTES (~8Mb). Pages to read are capped at FF_MAX_HTML_BYTES.
        $response = ff_fetch($article_url, FF_MAX_HTML_BYTES, FF_MAX_DOWNLOAD_BYTES);

        if ($response['too_big'] && !ff_is_html_type($response['media_type'])) {
            $error_text = "Failed to proxy file download, it's too large. :( <br>"
                        . "You can try downloading the file directly: " . ff_h($article_url);
        } elseif (!$response['ok']) {
            $error_text = "Failed to get the article, " . ff_h($response['error']) . " :( <br>";
        } elseif ($response['media_type'] !== '' && !in_array($response['media_type'], $compatible_content_types)) {
            // Attempt to handle downloads or other mime-types by passing them through.
            send_download($response);
        } else {
            $article = read_article($response, $error_text);
            if ($article) {
                ff_cache_put('read', $article_url, json_encode($article, JSON_INVALID_UTF8_SUBSTITUTE));
            }
        }
    }
}

function send_download($response) {
    $filename = basename((string) parse_url($response['url'], PHP_URL_PATH));
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', urldecode($filename));

    // If no filename can be deduced from the URL, set a placeholder filename
    if (trim($filename, '._') === '') {
        $filename = "download";
    }

    // Set the content headers based on the file we're proxying through.
    header('Content-Type: ' . preg_replace('#[^a-z0-9.+/-]#', '', $response['media_type']));
    header('Content-Length: ' . strlen($response['body']));
    // Set the content-disposition to encourage the browser to download the file.
    header('Content-Disposition: attachment; filename="'. $filename . '"');
    header('X-Robots-Tag: noindex, nofollow');
    ff_cache_headers(86400);
    echo $response['body'];
    exit();
}

// Run the page through Readability. Links stay pointed at the real web here;
// they get routed back through FrogFind (and signed) when the page is shown.
function read_article($response, &$error_text) {
    $configuration = new Configuration();
    $configuration
        ->setArticleByLine(false)
        ->setFixRelativeURLs(true)
        ->setOriginalURL($response['url']);

    $readability = new Readability($configuration);

    try {
        $readability->parse($response['body']);
    } catch (ParseException $e) {
        $error_text .= 'Sorry! ' . ff_h($e->getMessage()) . '<br>';
        return NULL;
    }

    $readable_article = strip_tags((string) $readability->getContent(), '<a><ol><ul><li><br><p><small><font><b><strong><i><em><blockquote><h1><h2><h3><h4><h5><h6>');
    $readable_article = str_replace( 'strong>', 'b>', $readable_article ); //change <strong> to <b>
    $readable_article = str_replace( 'em>', 'i>', $readable_article ); //change <em> to <i>
    $readable_article = clean_str($readable_article);

    $images = [];
    foreach ($readability->getImages() as $image_url) {
        //we can only do png and jpg
        $image_url = ff_absolute_url($response['url'], $image_url);
        $path = strtolower((string) parse_url($image_url, PHP_URL_PATH));
        if (preg_match('#^https?://#i', $image_url)
            && (strpos($path, ".jpg") !== false || strpos($path, ".jpeg") !== false || strpos($path, ".png") !== false)) {
            $images[] = $image_url;
        }
    }

    return [
        'title' => (string) $readability->getTitle(),
        'content' => $readable_article,
        'images' => array_values(array_unique($images)),
    ];
}

//replace chars that old machines probably can't handle
function clean_str($str) {
    $str = str_replace( "‘", "'", $str );
    $str = str_replace( "’", "'", $str );
    $str = str_replace( "“", '"', $str );
    $str = str_replace( "”", '"', $str );
    $str = str_replace( "–", '-', $str );

    return $str;
}

http_response_code($status);
if ($article) {
    // Half an hour, so a cached copy never hands out a stale address box token.
    ff_cache_headers(1800);
} else {
    header('Cache-Control: no-store');
}
$title = $article ? clean_str($article['title']) : "FrogFind!";
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">

 <html>
 <head>
     <title><?php echo ff_h($title);?></title>
     <meta name="robots" content="noindex,nofollow">
 </head>
 <body>
    <p>
        <form action="/read.php" method="get">
        <a href="/">Back to <b><font color="#008000">Frog</font><font color="000000">Find!</font></a></b> | Browsing URL: <input type="text" size="38" name="a" value="<?php echo ff_h($article_url) ?>">
        <input type="hidden" name="t" value="<?php echo ff_box_token() ?>">
        <input type="submit" value="Go!">
        </form>
    </p>
    <hr>
<?php if ($article) { ?>
    <h1><?php echo ff_h($title);?></h1>
    <p> <?php
        $img_num = 0;
        $imgline_html = "View page images:";
        foreach ($article['images'] as $image_url):
            $img_num++;
            $imgline_html .= " <a href='" . ff_image_page_link($image_url) . "' rel='nofollow'>[$img_num]</a> ";
        endforeach;
        if($img_num>0) {
            echo  $imgline_html ;
        }
    ?></small></p>
<?php } ?>
    <?php if($error_text) { echo "<p><font color='red'>" . $error_text . "</font></p>"; } ?>
<?php if ($article) { ?>
    <p><font size="4"><?php echo ff_proxy_links($article['content']);?></font></p>
<?php } ?>
 </body>
 </html>