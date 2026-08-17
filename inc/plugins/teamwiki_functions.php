<?php
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

/**
 * Prüft, ob ein gegebener User (aktueller $mybb->user wenn null) eine Gruppe hat,
 * die in der Liste $allowed_gids (array) vorkommt.
 */
function teamwiki_user_in_allowed_groups(array $allowed_gids, $user = null)
{
    global $mybb;
    if ($user === null) {
        $user = $mybb->user;
    }
    if (empty($user)) {
        return false;
    }

    $user_main_gid = (int) ($user['usergroup'] ?? 0);
    if (in_array($user_main_gid, $allowed_gids, true)) {
        return true;
    }
    $additional = (string) ($user['additionalgroups'] ?? '');
    if ($additional !== '') {
        $add = array_map('intval', explode(',', $additional));
        foreach ($add as $g) {
            if (in_array($g, $allowed_gids, true)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Liefert allowed group IDs für einen Eintrag als array.
 */
function teamwiki_get_entry_allowed_gids($entry_id)
{
    global $db;
    $gids = [];
    $query = $db->simple_select("teamwiki_entry_groups", "gid", "entry_id=" . (int)$entry_id);
    while ($r = $db->fetch_array($query)) {
        $gids[] = (int)$r['gid'];
    }
    return $gids;
}

/**
 * Liefert Kategorien mit Einträgen, filtert Einträge die der aktuelle User nicht sehen darf.
 * Struktur:
 *  [
 *    ['id'=>..., 'name'=>..., 'displayorder'=>..., 'entries'=> [ [entry], ... ] ],
 *    ...
 *  ]
 */
function teamwiki_get_visible_tree_for_user($user = null)
{
    global $db;
    $cats = [];
    $csel = $db->simple_select("teamwiki_categories", "*", "", ["order_by" => "displayorder, name"]);
    while ($c = $db->fetch_array($csel)) {
        // category-level visibility: allowed_gids is only a default/gate for entries
        // WITHOUT their own group mapping. Entries with an explicit group mapping are
        // filtered on their own below, so the category must NOT be skipped outright –
        // otherwise a per-entry release to a group the category does not list would be
        // silently swallowed. The category is included further down only if at least
        // one of its entries is visible to the user.
        $cat_allowed = [];
        if (!empty($c['allowed_gids'])) {
            $cat_allowed = array_filter(array_map('intval', explode(',', $c['allowed_gids'])));
        }
        $cat_visible = empty($cat_allowed) || teamwiki_user_in_allowed_groups($cat_allowed, $user);

        $c['entries'] = [];
        $esel = $db->simple_select("teamwiki_entries", "*", "cat_id=" . (int)$c['id'] . " AND active=1", ["order_by" => "displayorder, title"]);
        while ($e = $db->fetch_array($esel)) {
            $gids = teamwiki_get_entry_allowed_gids($e['id']);
            if (!empty($gids)) {
                // entry has its own group mapping => it alone decides visibility
                if (teamwiki_user_in_allowed_groups($gids, $user)) {
                    $c['entries'][] = $e;
                }
            } elseif ($cat_visible) {
                // no per-entry mapping => inherit the category gate (empty = visible to all)
                $c['entries'][] = $e;
            }
        }
        if (!empty($c['entries'])) {
            $cats[] = $c;
        }
    }
    return $cats;
}

/**
 * Liefert einen Entry (oder null) falls nicht vorhanden oder nicht aktiv.
 */
function teamwiki_get_entry($entry_id)
{
    global $db;
    $r = $db->simple_select("teamwiki_entries", "*", "id=" . (int)$entry_id . " AND active=1");
    return $db->fetch_array($r);
}