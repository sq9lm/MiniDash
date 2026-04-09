<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
// Get navbar stats for all pages
// This function returns cached stats from the session that were populated by index.php
function get_navbar_stats() {
    // Return cached session data if available
    if (isset($_SESSION['navbar_stats'])) {
        return $_SESSION['navbar_stats'];
    }
    
    // Fallback: return zeros if no session data yet
    // (This happens if user navigates directly to a non-index page)
    return [
        'cpu' => 0,
        'ram' => 0,
        'down' => 0,
        'up' => 0
    ];
}




