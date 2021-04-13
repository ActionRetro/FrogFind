<?php

$loc = "US";

if( isset( $_GET['loc'] ) ) {
    $loc = $_GET["loc"];
}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">

<html>
<head>
	<title>68k.news: Choose Your Edition</title>
</head>
<body>
    <center><h1><b>68k.news:</b> <font color="#9400d3"><i>Headlines from the Future</i></font></h1></center>
    <hr>
    <center>
    <small>Basic HTML Google News for vintage computers. Built by <a href="https://youtube.com/ActionRetro" target="_blank"><b>Action Retro</b></a> on YouTube. Tested on Netscape 1.1 through 4 on a Mac SE/30.</small>
    <p><h2>CHOOSE YOUR EDITION:</h2></p>
    <p><a href='./index.php?section=nation&loc=US'>United States</a></p>
    <p><a href='./index.php?section=nation&loc=JP'>Japan</a></p>
    <p><a href='./index.php?section=nation&loc=UK'>United Kingdom</a></p>
    <p>Spain (RIP)</p>
    <p><a href='./index.php?section=nation&loc=CA'>Canada</a></p>
    <p><a href='./index.php?section=nation&loc=DE'>Deutschland</a></p>
    <p><a href='./index.php?section=nation&loc=IT'>Italia</a></p>
    <p><a href='./index.php?section=nation&loc=FR'>France</a></p>
    <p><a href='./index.php?section=nation&loc=AU'>Australia</a></p>
    <p><a href='./index.php?section=nation&loc=TW'>Taiwan</a></p>
    <p><a href='./index.php?section=nation&loc=NL'>Nederland</a></p>
    <p><a href='./index.php?section=nation&loc=BR'>Brasil</a></p>
    <p><a href='./index.php?section=nation&loc=TR'>Turkey</a></p>
    <p><a href='./index.php?section=nation&loc=BE'>Belgium</a></p>
    <p><a href='./index.php?section=nation&loc=GR'>Greece</a></p>
    <p><a href='./index.php?section=nation&loc=IN'>India</a></p>
    <p><a href='./index.php?section=nation&loc=MX'>Mexico</a></p>
    <p><a href='./index.php?section=nation&loc=DK'>Denmark</a></p>
    <p><a href='./index.php?section=nation&loc=AR'>Argentina</a></p>
    <p><a href='./index.php?section=nation&loc=CH'>Switzerland</a></p>
    <p><a href='./index.php?section=nation&loc=CL'>Chile</a></p>
    <p><a href='./index.php?section=nation&loc=AT'>Austria</a></p>
    <p><a href='./index.php?section=nation&loc=KR'>Korea</a></p>
    <p><a href='./index.php?section=nation&loc=IE'>Ireland</a></p>
    <p><a href='./index.php?section=nation&loc=CO'>Colombia</a></p>
    <p><a href='./index.php?section=nation&loc=PL'>Poland</a></p>
    <p><a href='./index.php?section=nation&loc=PT'>Portugal</a></p>
    <p><a href='./index.php?section=nation&loc=PK'>Pakistan</a></p>
    </center>
    <small><a href="./index.php?loc=<?php echo $loc ?>">< Back to <font color="#9400d3">68k.news</font> <?php echo $loc ?>front page</a></small>
	<p><center><small>Powered by Mozilla Readability (Andres Rey PHP Port) and SimplePie</small></p>
</body>
</html>