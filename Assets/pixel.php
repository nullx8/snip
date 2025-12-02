<?php
// this sends the pixel image but also re-loads the current session

/*
 * include it like this 

<img src="/pixel.php?ts=<?=time()?>" style="display:none" id="ka">
<script>setInterval(() => {document.getElementById("ka").src = "/keepalive.php?ts=" + Date.now();}, 150000); // 2,5 minutes
</script>

*/
session_start();
header("Content-Type: image/gif");
die(base64_decode("R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=="));
