<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
session_start();
session_destroy();
header('Location: login.php');
exit; 



