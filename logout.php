<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
session_start();
session_destroy();
header('Location: login.php');
exit; 



