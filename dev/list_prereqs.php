<?php
require_once '../config/config.php';
$c = getDBConnection();
$r = $c->query("SELECT DISTINCT pre_requisite FROM cvsucarmona_courses WHERE pre_requisite IS NOT NULL AND pre_requisite != '' AND pre_requisite != 'NONE' ORDER BY pre_requisite");
while ($row = $r->fetch_assoc()) echo $row['pre_requisite'] . "\n";
