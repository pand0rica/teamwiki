<?php

define("IN_MYBB", 1);
define('THIS_SCRIPT', 'teamwiki.php');
require_once "./global.php";

// Load plugin language strings
$lang->load('teamwiki');

add_breadcrumb($lang->teamwiki, "teamwiki.php");

// MENÜ
// Build menu: always include welcome link
$teamwiki_menu = "<a href=\"teamwiki.php\">" . htmlspecialchars_uni(
    $lang->teamwiki_welcome
) . "</a><br /><br />";

// Use helper to get categories and entries visible to current user
$cats = teamwiki_get_visible_tree_for_user();

// If user has no access to any entries, show permission error
if (empty($cats)) {
    error_no_permission();
    exit;
}

foreach ($cats as $c) {
    $cat_name = htmlspecialchars_uni($c['name']);
    eval("\$teamwiki_menu .= \"" . $templates->get("teamwiki_nav_category") . "\";");
    
    // list visible entries for this category
    if (!empty($c['entries'])) {
        foreach ($c['entries'] as $e) {
            $entry_id = (int)$e['id'];
            $entry_title = htmlspecialchars_uni($e['title']);
            eval("\$teamwiki_menu .= \"" . $templates->get("teamwiki_nav_entry") . "\";");
        }
    }
}

$eid = (int)$mybb->get_input('eid');

// STARTSEITE
if(!$eid)
{
    $entry['title'] = $lang->teamwiki_welcome;
    eval("\$teamwiki_content .= \"" . $templates->get("teamwiki_how_to") . "\";");
//TEAMWIKI
} elseif ($eid > 0) {
    // ENTRY VIEW: if ?eid=ID is provided, show that entry's content (if visible)
    $entry = teamwiki_get_entry($eid);
    if (!$entry) {
        $teamwiki_content = '<div class="error">' . htmlspecialchars_uni($lang->teamwiki_entry_not_found) . '</div>';
    } else {
        $allowed = teamwiki_get_entry_allowed_gids($entry['id']);
        if (!empty($allowed) && !teamwiki_user_in_allowed_groups($allowed)) {
            error_no_permission();
            exit;
        }

        // Load parent category for breadcrumb and title area
        $cres = $db->simple_select('teamwiki_categories', '*', 'id=' . (int)$entry['cat_id']);
        $row_teamwiki = $db->fetch_array($cres);

        add_breadcrumb(htmlspecialchars_uni($row_teamwiki['name']), 'teamwiki.php');
        add_breadcrumb(htmlspecialchars_uni($entry['title']), 'teamwiki.php?eid=' . (int)$entry['id']);

        require_once MYBB_ROOT."inc/class_parser.php";
        $parser = new postParser;
        $parser_options = array(
            "allow_html" => 1,
            "allow_mycode" => 1,
            "allow_smilies" => 1,
            "allow_imgcode" => 1
        );

        $teamwiki_content = $parser->parse_message($entry['content'], $parser_options);
    }

    $page = eval($templates->render('teamwiki'));
    output_page($page);
    exit();
}


$page = eval($templates->render("teamwiki"));
output_page($page);
exit();
