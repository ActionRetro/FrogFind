<?php
require_once __DIR__ . '/lib/frogfind.php';
ff_block_crawlers();

$show_results = FALSE;
$final_result_html = "<hr>";
$error_text = "";
$query = "";

if(trim(ff_param('q')) !== '') { // if there's a search query, show the results for it
    $query = mb_substr(trim(ff_param('q')), 0, 200);
    $show_results = TRUE;

    // Same search in the last hour? Use the saved results.
    $results = json_decode((string) ff_cache_get('search', mb_strtolower($query)), true);
    if (!is_array($results)) {
        $results = ddg_search($query, $error_text);
        if (is_array($results)) {
            ff_cache_put('search', mb_strtolower($query), json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    if (is_array($results) && count($results) == 0) {
        $error_text .= "No results found. Try different words?<br>";
    }
    foreach ((array) $results as $result) {
        $final_result_html .= "<br><a href='" . ff_read_link($result['url']) . "' rel='nofollow'><font size='4'><b>" . $result['title'] . "</b></font><br><font color='#008000' size='2'>"
                            . $result['display_url'] . "</font></a><br>" . $result['snippet'] . "<br><br><hr>";
    }
}

// Ask DuckDuckGo. Returns a list of results, or NULL (with $error_text set)
// if the search didn't work.
function ddg_search($query, &$error_text) {
    $unavailable = "Search is temporarily unavailable, sorry :( Please try again in a few minutes.<br>";

    // DuckDuckGo turned us away a moment ago: don't keep knocking.
    $blocked_flag = FF_CACHE_DIR . '/ddg-blocked';
    if (@filemtime($blocked_flag) > time() - 120) {
        $error_text .= $unavailable;
        return NULL;
    }

    ff_busy_valve();
    // No User-Agent here, same as the original file_get_contents() call.
    $response = ff_fetch("https://html.duckduckgo.com/html?q=" . urlencode($query), FF_MAX_HTML_BYTES, FF_MAX_HTML_BYTES, '');
    $results_html = $response['body'];

    // DuckDuckGo's "are you a bot?" page comes back as a 202 (or a 403) with an anomaly box.
    if ($response['status'] == 202 || $response['status'] == 403 || $response['status'] == 429
        || strpos($results_html, 'anomaly-modal') !== false || strpos($results_html, 'challenge-form') !== false) {
        @touch($blocked_flag);
        $error_text .= $unavailable;
        return NULL;
    }
    if (!$response['ok']) {
        $error_text .= "Failed to get results, sorry :( <br>";
        return NULL;
    }

    $simple_results=$results_html;
    $simple_results = str_replace( 'strong>', 'b>', $simple_results ); //change <strong> to <b>
    $simple_results = str_replace( 'em>', 'i>', $simple_results ); //change <em> to <i>
    $simple_results = clean_str($simple_results);

    $result_blocks = explode('<h2 class="result__title">', $simple_results);
    $total_results = count($result_blocks)-1;
    $results = [];

    for ($x = 1; $x <= $total_results; $x++) {
        if(strpos($result_blocks[$x], '<a class="badge--ad">')===false) { //only return non ads
            // result link, which DuckDuckGo wraps in its own redirect
            $result_link = explode('class="result__a" href="', $result_blocks[$x])[1] ?? '';
            $result_topline = explode('">', $result_link);
            $result_url = ddg_target_url(html_entity_decode($result_topline[0]));
            if ($result_url == '') continue;
            // result title
            $result_title = str_replace("</a>","",explode("\n", $result_topline[1] ?? ''));
            // result display url
            $result_display_url = explode('class="result__url"', $result_blocks[$x])[1] ?? '';
            $result_display_url = trim(explode("\n", $result_display_url)[1] ?? '');
            // result snippet
            $result_snippet = explode('class="result__snippet"', $result_blocks[$x])[1] ?? '';
            $result_snippet = explode('">', $result_snippet)[1] ?? '';
            $result_snippet = explode('</a>', $result_snippet)[0];

            $results[] = ['url' => $result_url, 'title' => $result_title[0], 'display_url' => $result_display_url, 'snippet' => $result_snippet];
        }
    }

    // No results and no "No results" message means the page changed under us.
    if (count($results) == 0 && strpos($results_html, 'no-results') === false) {
        $error_text .= $unavailable;
        return NULL;
    }
    return $results;
}

// "//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com&rut=..." -> "https://example.com"
function ddg_target_url($link) {
    $query_string = parse_url($link, PHP_URL_QUERY);
    if ($query_string) {
        parse_str($query_string, $params);
        if (isset($params['uddg']) && is_string($params['uddg'])) $link = $params['uddg'];
    }
    return preg_match('#^https?://#i', $link) ? $link : '';
}

//replace chars that old machines probably can't handle
function clean_str($str) {
    $str = str_replace( "‘", "'", $str );
    $str = str_replace( "’", "'", $str );
    $str = str_replace( "“", '"', $str );
    $str = str_replace( "”", '"', $str );
    $str = str_replace( "–", '-', $str );
    $str = str_replace( "&#x27;", "'", $str );

    return $str;
}

if ($show_results && !is_array($results)) {
    header('Cache-Control: no-store'); // don't let anyone keep a copy of an error
} else {
    ff_cache_headers(3600);
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">

<html>
<head>
	<title>FrogFind!</title>
<?php if($show_results) { ?>
	<meta name="robots" content="noindex,nofollow">
<?php } ?>
</head>
<body>

<?php if($show_results) { // there's a search query in q, so show search results ?>

    <form action="/" method="get">
    <a href="/"><font size=6 color="#008000">Frog</font><font size=6 color="#000000">Find!</font></a> Leap again: <input type="text" size="30" name="q" value="<?php echo ff_h($query) ?>">
    <input type="submit" value="Ribbbit!">
    </form>
    <hr>
    <br>
    <center>Search Results for <b><?php echo ff_h($query) ?></b></center>
    <br>
    <?php if($error_text) { echo "<p><font color='red'>" . $error_text . "</font></p>"; } ?>
    <?php echo $final_result_html ?>

<?php } else { // no search query, so show new search ?>
    <br><br><center><h1><font size=7><font color="#008000">Frog</font>Find!</font></h1></center>
    <center><h3>The Search Engine for Vintage Computers</h3></center>
    <br><br>
    <center>
    <form action="/" method="get">
    Leap to: <input type="text" size="30" name="q"><br>
    <input type="submit" value="Ribbbit!">
    </center>
    <br><br><br>
    <small><center>Built by <b><a href="https://youtube.com/ActionRetro">Action Retro</a></b> on YouTube | <a href="about.php">Why build such a thing?</a></center><br>
    <small><center>Powered by DuckDuckGo</center></small>
</form>
</form>

<?php } ?>

</body>
</html>