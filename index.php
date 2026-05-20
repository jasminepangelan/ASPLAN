<?php
// Preserve legacy links that still target /index.php by sending them to
// the unified login portal document.
header('Location: /index.html');
exit();
