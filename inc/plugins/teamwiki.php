<?php
// TeamWiki plugin for MyBB - basic implementation
if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$plugins->add_hook("admin_config_action_handler", "teamwiki_admin_config_action_handler");
$plugins->add_hook("admin_config_menu", "teamwiki_admin_config_menu");
$plugins->add_hook("admin_load", "teamwiki_manage");

function teamwiki_info()
{
    return [
        "name" => "TeamWiki",
        "description" => "Ein einfaches Team-Wiki mit Kategorien, Einträgen und gruppenbasierter Sichtbarkeit.",
        "website" => "https://github.com/pand0rica/teamwiki",
        "author" => "pand0rica",
        "authorsite" => "https://github.com/pand0rica",
        "version" => "1.0.0",
        "compatibility" => "18*"
    ];
}

function teamwiki_is_installed()
{
    global $db;
    return $db->table_exists("teamwiki_categories");
}

function teamwiki_install()
{
    global $db;

    // Kategorien
    $db->write_query("
    CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX."teamwiki_categories` (
        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(255) NOT NULL,
        `displayorder` INT(10) NOT NULL DEFAULT 0,
        `allowed_gids` TEXT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;
    ");

    // Einträge
    $db->write_query("
    CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX."teamwiki_entries` (
        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `cat_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `title` VARCHAR(255) NOT NULL,
        `displayorder` INT(10) NOT NULL DEFAULT 0,
        `content` MEDIUMTEXT NOT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        INDEX (`cat_id`)
    ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;
    ");

    // Sichtbarkeits-Mapping (pro Eintrag erlaubte GIDs)
    $db->write_query("
    CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX."teamwiki_entry_groups` (
        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `entry_id` INT(10) UNSIGNED NOT NULL,
        `gid` INT(10) UNSIGNED NOT NULL,
        PRIMARY KEY (`id`),
        INDEX (`entry_id`),
        INDEX (`gid`)
    ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;
    ");
}

function teamwiki_uninstall()
{
    global $db;
    $db->write_query("DROP TABLE IF EXISTS `".TABLE_PREFIX."teamwiki_entry_groups`;");
    $db->write_query("DROP TABLE IF EXISTS `".TABLE_PREFIX."teamwiki_entries`;");
    $db->write_query("DROP TABLE IF EXISTS `".TABLE_PREFIX."teamwiki_categories`;");
}

function teamwiki_activate()
{
    global $db;

    $loader = new TeamwikiTemplateLoader();
    $loader->autoLoad($db);

    // Load CSS file and register it as a global stylesheet
    $css_file = MYBB_ROOT . "inc/plugins/teamwiki/teamwiki.css";
    if (file_exists($css_file)) {
        require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

        $css_content = file_get_contents($css_file);
        $css_data = array(
            "name" => "teamwiki.css",
            "tid" => 1,
            "attachedto" => "",
            "stylesheet" => $db->escape_string($css_content),
            "cachefile" => "teamwiki.css",
            "lastmodified" => time()
        );

        // Idempotent: update an existing stylesheet row instead of inserting a duplicate
        $existing = $db->fetch_field(
            $db->simple_select("themestylesheets", "sid", "name='teamwiki.css' AND tid=1"),
            "sid"
        );
        if ($existing) {
            $db->update_query("themestylesheets", $css_data, "sid=" . (int)$existing);
        } else {
            $db->insert_query("themestylesheets", $css_data);
        }

        $tids = $db->simple_select("themes", "tid");
        while ($theme = $db->fetch_array($tids)) {
            update_theme_stylesheet_list((int)$theme['tid']);
        }
    }
}


function teamwiki_deactivate()
{
    global $db;

    $loader = new TeamwikiTemplateLoader();
    $loader->autoUnload($db);

    // Remove CSS file
    $db->delete_query("themestylesheets", "name = 'teamwiki.css'");

    // Rebuild cached stylesheet list for all themes
    require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
    $query = $db->simple_select("themes", "tid");
    while ($theme = $db->fetch_array($query)) {
        update_theme_stylesheet_list((int)$theme['tid']);
    }
}

// expose helper loader (optional)
require_once __DIR__ . "/teamwiki_functions.php";

###########################################################################################
################################### ADMIN CONTROL PANEL ###################################
###########################################################################################


// ACP Action Handler
function teamwiki_admin_config_action_handler(&$actions)
{
    $actions['teamwiki'] = array('active' => 'teamwiki', 'file' => 'teamwiki');
}

// ACP Menü
function teamwiki_admin_config_menu(&$sub_menu)
{
    global $lang;
    $lang->load('teamwiki');

    $sub_menu[] = [
        "id" => "teamwiki",
        "title" => $lang->teamwiki_manage,
        "link" => "index.php?module=config-teamwiki"
    ];
}

// Verwaltung im ACP
function teamwiki_manage()
{
    global $mybb, $db, $lang, $page, $run_module, $action_file;
    $lang->load('teamwiki');

    if ($page->active_action != 'teamwiki') {
        return false;
    }

    $errors = [];
    $action = $mybb->get_input('action');

    // Navigation
    $page->add_breadcrumb_item($lang->teamwiki_manage);

    // Tab-Menü
    $sub_tabs['teamwiki'] = [
        "title" => $lang->teamwiki_manage_view,
        "link" => "index.php?module=config-teamwiki",
        "description" => $lang->teamwiki_manage_view_desc

    ];
    $sub_tabs['teamwiki_add'] = [
        "title" => $lang->teamwiki_manage_add,
        "link" => "index.php?module=config-teamwiki&amp;action=teamwiki_add",
        "description" => $lang->teamwiki_manage_add_desc
    ];
    $sub_tabs['teamwiki_entries'] = [
        "title" => $lang->teamwiki_manage_entries_overview,
        "link" => "index.php?module=config-teamwiki&amp;action=entries_view",
        "description" => $lang->teamwiki_manage_entries_overview_desc
    ];
    $sub_tabs['teamwiki_entries_add'] = [
        "title" => $lang->teamwiki_manage_entries_add,
        "link" => "index.php?module=config-teamwiki&amp;action=entries_add",
        "description" => $lang->teamwiki_manage_entries_add_desc
    ];



    if ($run_module == 'config' && $action_file == 'teamwiki') {
        ############################################################
        ######################## teamwiki ########################
        ############################################################
        if ($action == "") {

            // Build options header
            $page->output_header($lang->teamwiki_manage);
            $page->output_nav_tabs($sub_tabs, 'teamwiki');

            // Build the form
            $form = new Form("index.php?module=config-teamwiki", "post");

            $form_container = new FormContainer($lang->teamwiki_manage_view_edit);
            $form_container->output_row_header($lang->teamwiki_manage_title, array('style' => 'text-align: justify;'));
            $form_container->output_row_header($lang->teamwiki_manage_displayorder, array("class" => "align_center", 'width' => '6%'));
            $form_container->output_row_header($lang->teamwiki_manage_options, array('style' => 'text-align: center; width: 10%;'));

            // Get all categories (use installer table schema)
            $query = $db->simple_select("teamwiki_categories", "*", "",
                ["order_by" => 'name', 'order_dir' => 'ASC']);

            while($teamwiki_categories = $db->fetch_array($query)) {

                $form_container->output_cell('<strong>'.htmlspecialchars_uni($teamwiki_categories['name']).'</strong>');
                $form_container->output_cell($form->generate_numeric_field("sort[{$teamwiki_categories['displayorder']}]", "{$teamwiki_categories['displayorder']}", array('min' => 0, 'class' => 'align_center', 'style' => 'width:80%; font-weight:bold')), array("class" => "align_center"));
                $popup = new PopupMenu("teamwiki_{$teamwiki_categories['id']}", $lang->teamwiki_manage_edit);
                $popup->add_item(
                    $lang->teamwiki_manage_edit,
                    "index.php?module=config-teamwiki&amp;action=teamwiki_edit&amp;rid={$teamwiki_categories['id']}"
                );
                $popup->add_item(
                    $lang->teamwiki_manage_delete,
                    "index.php?module=config-teamwiki&amp;action=teamwiki_delete&amp;rid={$teamwiki_categories['id']}"
                    ."&amp;my_post_key={$mybb->post_code}"
                );
                $form_container->output_cell($popup->fetch(), array("class" => "align_center"));
                $form_container->construct_row();
            }

            $form_container->end();
            $form->end();
            $page->output_footer();

            exit();
        }

        if ($action == "teamwiki_add") {

            if ($mybb->request_method == "post") {

                // CSRF protection
                if (!verify_post_check($mybb->get_input('my_post_key'))) {
                    flash_message($lang->invalid_post_verify_key2, 'error');
                    admin_redirect("index.php?module=config-teamwiki");
                }

                // Check if required fields are not empty
                $title = $mybb->get_input('title');
                if (trim($title) === '') {
                    $errors[] = $lang->teamwiki_manage_error_no_title;
                }

                // No errors - insert the category
                if (empty($errors)) {

                    $displayorder = $mybb->get_input('displayorder', MyBB::INPUT_INT);
                    $allowed = array_map('intval', $mybb->get_input('allowed_gids', MyBB::INPUT_ARRAY));

                    $new_teamwiki = [
                        "name" => $db->escape_string($title),
                        "displayorder" => $displayorder,
                        "allowed_gids" => $db->escape_string(implode(',', $allowed))
                    ];

                    $db->insert_query("teamwiki_categories", $new_teamwiki);

                    log_admin_action(htmlspecialchars_uni($title));

                    flash_message($lang->teamwiki_manage_added, 'success');
                    admin_redirect("index.php?module=config-teamwiki");
                }

            }

            $page->add_breadcrumb_item($lang->teamwiki_manage_add);

            // Build options header
            $page->output_header($lang->teamwiki_manage." - ".$lang->teamwiki_manage_add);
            $page->output_nav_tabs($sub_tabs, 'teamwiki_add');

            // Show errors
            if (!empty($errors)) {
                $page->output_inline_error($errors);
            }

            // Build the form
            $form = new Form("index.php?module=config-teamwiki&amp;action=teamwiki_add", "post", "", 1);

            $form_container = new FormContainer($lang->teamwiki_manage_add);
            $form_container->output_row(
                $lang->teamwiki_manage_title. "<em>*</em>",
                $lang->teamwiki_manage_add_name_desc,
                $form->generate_text_box('title', $mybb->get_input('title'))
            );

            $form_container->output_row(
                $lang->teamwiki_manage_displayorder,
                "",
                $form->generate_text_box('displayorder', $mybb->get_input('displayorder', MyBB::INPUT_INT))
            );

            // Build group list for category visibility
            $selected_gids = array_map('intval', $mybb->get_input('allowed_gids', MyBB::INPUT_ARRAY));
            $gq = $db->simple_select('usergroups', 'gid, title', "", ['order_by' => 'title', 'order_dir' => 'ASC']);
            $group_select_html = "<select name='allowed_gids[]' id='allowed_gids' multiple='multiple' size='8'>";
            while ($g = $db->fetch_array($gq)) {
                $sel = in_array((int)$g['gid'], $selected_gids) ? " selected" : "";
                $group_select_html .= "<option value='".(int)$g['gid']."'".$sel.">".htmlspecialchars_uni($g['title'])."</option>";
            }
            $group_select_html .= "</select>";
            $form_container->output_row(
                $lang->teamwiki_manage_visibility,
                $lang->teamwiki_manage_visibility_desc,
                $group_select_html,
                'allowed_gids'
            );

            $form_container->end();
            $buttons[] = $form->generate_submit_button($lang->teamwiki_manage_submit);
            $form->output_submit_wrapper($buttons);
            $form->end();
            $page->output_footer();

            exit();
        }

        if ($action == "teamwiki_edit") {
            if ($mybb->request_method == "post") {

                // CSRF protection
                if (!verify_post_check($mybb->get_input('my_post_key'))) {
                    flash_message($lang->invalid_post_verify_key2, 'error');
                    admin_redirect("index.php?module=config-teamwiki");
                }

                // Check if required fields are not empty
                $title = $mybb->get_input('title');
                if (trim($title) === '') {
                    $errors[] = $lang->teamwiki_manage_error_no_title;
                }

                // No errors - update the category
                if (empty($errors)) {
                    $rid = $mybb->get_input('rid', MyBB::INPUT_INT);

                    $displayorder = $mybb->get_input('displayorder', MyBB::INPUT_INT);
                    $allowed = array_map('intval', $mybb->get_input('allowed_gids', MyBB::INPUT_ARRAY));

                    $edited_teamwiki = array(
                        "name" => $db->escape_string($title),
                        "displayorder" => $displayorder,
                        "allowed_gids" => $db->escape_string(implode(',', $allowed))
                    );

                    $db->update_query("teamwiki_categories", $edited_teamwiki, "id=" . (int)$rid);

                    log_admin_action(htmlspecialchars_uni($title));

                    flash_message($lang->teamwiki_manage_teamwiki_edited, 'success');
                    admin_redirect("index.php?module=config-teamwiki");
                }
            }

            $page->add_breadcrumb_item($lang->teamwiki_manage_edit);

            // Build options header
            $page->output_header($lang->teamwiki_manage." - ".$lang->teamwiki_manage_edit);
            $page->output_nav_tabs($sub_tabs, 'teamwiki_add');

            // Show errors
            if (!empty($errors)) {
                $page->output_inline_error($errors);
            }

            // Get the data
            $rid = $mybb->get_input('rid', MyBB::INPUT_INT);
            $query = $db->simple_select("teamwiki_categories", "*", "id=" . (int)$rid);
            $edit_values = $db->fetch_array($query);

            // Build the form
            $form = new Form("index.php?module=config-teamwiki&amp;action=teamwiki_edit", "post", "", 1);
            echo $form->generate_hidden_field('rid', $rid);

            $form_container = new FormContainer($lang->teamwiki_manage_edit);
            $form_container->output_row(
                $lang->teamwiki_manage_title.'<em>*</em>',
                $lang->teamwiki_manage_add_name_desc,
                $form->generate_text_box('title', $edit_values['name'])
            );

            $form_container->output_row(
                $lang->teamwiki_manage_displayorder,
                "",
                $form->generate_text_box('displayorder', $edit_values['displayorder'])
            );

            // group select for category visibility (preselect existing)
            $selected_groups = [];
            if (!empty($edit_values['allowed_gids'])) {
                $selected_groups = array_filter(array_map('intval', explode(',', $edit_values['allowed_gids'])));
            }
            $gq = $db->simple_select('usergroups', 'gid, title', "", ['order_by' => 'title', 'order_dir' => 'ASC']);
            $group_select_html = "<select name='allowed_gids[]' id='allowed_gids' multiple='multiple' size='8'>";
            while ($g = $db->fetch_array($gq)) {
                $sel = in_array((int)$g['gid'], $selected_groups) ? " selected" : "";
                $group_select_html .= "<option value='".(int)$g['gid']."'".$sel.">".htmlspecialchars_uni($g['title'])."</option>";
            }
            $group_select_html .= "</select>";
            $form_container->output_row(
                $lang->teamwiki_manage_visibility,
                $lang->teamwiki_manage_visibility_desc,
                $group_select_html,
                'allowed_gids'
            );

            $form_container->end();
            $buttons[] = $form->generate_submit_button($lang->teamwiki_manage_submit);
            $form->output_submit_wrapper($buttons);
            $form->end();
            $page->output_footer();

            exit();
        }

        if ($action == "teamwiki_delete") {
            // Get data
            $rid = $mybb->get_input('rid', MyBB::INPUT_INT);
            $query = $db->simple_select("teamwiki_categories", "*", "id=" . (int)$rid);
            $delete_values = $db->fetch_array($query);

            // Error Handling
            if (empty($rid)) {
                flash_message($lang->teamwiki_manage_error_invalid, 'error');
                admin_redirect("index.php?module=config-teamwiki");
            }

            // Cancel button pressed?
            if ($mybb->get_input('no')) {
                admin_redirect("index.php?module=config-teamwiki");
            }

            if (!verify_post_check($mybb->get_input('my_post_key'))) {
                flash_message($lang->invalid_post_verify_key2, 'error');
                admin_redirect("index.php?module=config-teamwiki");
            }  // all fine
            else {
                if ($mybb->request_method == "post") {

                    // Remove the category, its entries and their group mappings
                    $eq = $db->simple_select("teamwiki_entries", "id", "cat_id=" . (int)$rid);
                    while ($er = $db->fetch_array($eq)) {
                        $db->delete_query("teamwiki_entry_groups", "entry_id=" . (int)$er['id']);
                    }
                    $db->delete_query("teamwiki_entries", "cat_id=" . (int)$rid);
                    $db->delete_query("teamwiki_categories", "id=" . (int)$rid);

                    log_admin_action(htmlspecialchars_uni($delete_values['name']));

                    flash_message($lang->teamwiki_manage_teamwiki_deleted, 'success');
                    admin_redirect("index.php?module=config-teamwiki");
                } else {
                    $page->output_confirm_action(
                        "index.php?module=config-teamwiki&amp;action=teamwiki_delete&amp;rid={$rid}",
                        $lang->teamwiki_manage_delete
                    );
                }
            }
            exit();
        }



        ############################################################
        ######################### EINTRÄGE #########################
        ############################################################
        if ($action == "entries_view") {

            // Add to page navigation
            $page->add_breadcrumb_item($lang->teamwiki_manage_entries_overview);

            // Build options header
            $page->output_header($lang->teamwiki_manage." - ".$lang->teamwiki_manage_entries_overview);
            $page->output_nav_tabs($sub_tabs, 'teamwiki_entries');

            // Build the overview
            $form = new Form("index.php?module=config-teamwiki&amp;action=entries_view", "post");

            $form_container = new FormContainer($lang->teamwiki_manage_entries_edit);
            $form_container->output_row_header($lang->teamwiki_manage_title);
            $form_container->output_row_header($lang->teamwiki_manage_entries_cat);
            $form_container->output_row_header($lang->teamwiki_manage_options, array('style' => 'text-align: center; width: 10%;'));

            // Get all entries
            $query = $db->simple_select("teamwiki_entries", "*", "",
                ["order_by" => 'title', 'order_dir' => 'ASC']);

            while($teamwiki_entries = $db->fetch_array($query)) {

                // Get category name for this entry
                $teamwiki = $db->simple_select("teamwiki_categories", "name", "id=" . (int)$teamwiki_entries['cat_id']);
                $teamwiki_title = $db->fetch_field($teamwiki, "name");

                $form_container->output_cell('<strong>'.htmlspecialchars_uni($teamwiki_entries['title']).'</strong>');
                $form_container->output_cell(htmlspecialchars_uni($teamwiki_title));
                $popup = new PopupMenu("teamwiki_{$teamwiki_entries['id']}", $lang->teamwiki_manage_edit);
                $popup->add_item(
                    $lang->teamwiki_manage_edit,
                    "index.php?module=config-teamwiki&amp;action=entry_edit&amp;eid={$teamwiki_entries['id']}"
                );
                $popup->add_item(
                    $lang->teamwiki_manage_delete,
                    "index.php?module=config-teamwiki&amp;action=entry_delete&amp;eid={$teamwiki_entries['id']}"
                    ."&amp;my_post_key={$mybb->post_code}"
                );
                $form_container->output_cell($popup->fetch(), array("class" => "align_center"));
                $form_container->construct_row();
            }

            $form_container->end();
            $form->end();
            $page->output_footer();

            exit();
        }

        if ($action == "entries_add") {

            if ($mybb->request_method == "post") {

                // CSRF protection
                if (!verify_post_check($mybb->get_input('my_post_key'))) {
                    flash_message($lang->invalid_post_verify_key2, 'error');
                    admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
                }

                // Check if required fields are not empty
                $title = $mybb->get_input('title');
                $description = $mybb->get_input('description');
                $cat_id = $mybb->get_input('rid', MyBB::INPUT_INT);
                if (trim($title) === '') {
                    $errors[] = $lang->teamwiki_manage_error_no_title;
                }
                if (trim($description) === '') {
                    $errors[] = $lang->teamwiki_manage_error_no_description;
                }
                if (empty($cat_id)) {
                    $errors[] = $lang->teamwiki_manage_error_no_rid;
                }

                // No errors - insert
                if (empty($errors)) {

                    $new_entry = array(
                        "cat_id" => $cat_id,
                        "title" => $db->escape_string($title),
                        "content" => $db->escape_string($description),
                        "displayorder" => 0,
                        "active" => 1
                    );

                    $db->insert_query("teamwiki_entries", $new_entry);
                    $eid = (int)$db->insert_id();

                    // handle entry group visibility: either explicit selection or inherit from category
                    $sel_gids = array_map('intval', $mybb->get_input('allowed_gids', MyBB::INPUT_ARRAY));
                    if (empty($sel_gids)) {
                        // inherit from category
                        $cq = $db->simple_select('teamwiki_categories', 'allowed_gids', "id=" . (int)$cat_id);
                        $cat = $db->fetch_array($cq);
                        if (!empty($cat['allowed_gids'])) {
                            $sel_gids = array_filter(array_map('intval', explode(',', $cat['allowed_gids'])));
                        }
                    }
                    foreach ($sel_gids as $gid) {
                        $db->insert_query('teamwiki_entry_groups', ['entry_id' => $eid, 'gid' => (int)$gid]);
                    }

                    log_admin_action(htmlspecialchars_uni($title));

                    flash_message($lang->teamwiki_manage_entry_added, 'success');
                    admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
                }
            }

                $page->add_breadcrumb_item($lang->teamwiki_manage_entries_add);

                // Build options header
                $page->output_header($lang->teamwiki_manage." - ".$lang->teamwiki_manage_entries_overview);
                $page->output_nav_tabs($sub_tabs, 'teamwiki_entries_add');

                // Show errors
                if (!empty($errors)) {
                    $page->output_inline_error($errors);
                }

                // Build the form
                $form = new Form("index.php?module=config-teamwiki&amp;action=entries_add", "post", "", 1);
                $form_container = new FormContainer($lang->teamwiki_manage_entries_add);
                $form_container->output_row(
                    $lang->teamwiki_manage_title."<em>*</em>",
                    $lang->teamwiki_manage_entry_title_desc,
                    $form->generate_text_box('title', $mybb->get_input('title'))
                );

                $query = $db->simple_select("teamwiki_categories", "id, name", "",
                ["order_by" => "name", "order_dir" => "ASC"]);
                $teamwikis = [];
                while($teamwiki = $db->fetch_array($query)) {
                    $teamwikis[$teamwiki['id']] = $teamwiki['name'];
                }

                $form_container->output_row(
                    $lang->teamwiki_manage_entries_cat." <em>*</em>",
                    "",
                    $form->generate_select_box("rid", $teamwikis, $mybb->get_input('rid', MyBB::INPUT_INT), ["id" => "rid"]), 'rid'
                );

                $description_editor = $form->generate_text_area('description', $mybb->get_input('description'), array(
                    'id' => 'description',
                    'rows' => '25',
                    'cols' => '70',
                    'style' => 'height: 250px; width: 75%'
                    )
                );
                $description_editor .= build_mycode_inserter('description');
                $form_container->output_row(
                    $lang->teamwiki_manage_content. " <em>*</em>",
                    "",
                    $description_editor,
                    'description'
                );

                // category visibility preselection for new entry
                $selected_cat_gids = [];
                $preselect_cat = $mybb->get_input('rid', MyBB::INPUT_INT);
                if (!empty($preselect_cat)) {
                    $cq = $db->simple_select('teamwiki_categories', 'allowed_gids', "id=" . (int)$preselect_cat);
                    $cat = $db->fetch_array($cq);
                    if (!empty($cat['allowed_gids'])) {
                        $selected_cat_gids = array_filter(array_map('intval', explode(',', $cat['allowed_gids'])));
                    }
                }

                $gq = $db->simple_select('usergroups', 'gid, title', "", ['order_by' => 'title', 'order_dir' => 'ASC']);
                $group_select_html = "<select name='allowed_gids[]' id='allowed_gids' multiple='multiple' size='8'>";
                while ($g = $db->fetch_array($gq)) {
                    $sel = in_array((int)$g['gid'], $selected_cat_gids) ? " selected" : "";
                    $group_select_html .= "<option value='".(int)$g['gid']."'".$sel.">".htmlspecialchars_uni($g['title'])."</option>";
                }
                $group_select_html .= "</select>";
                $form_container->output_row(
                    $lang->teamwiki_manage_visibility,
                    $lang->teamwiki_manage_visibility_desc,
                    $group_select_html,
                    'allowed_gids'
                );

                $form_container->end();
                $buttons[] = $form->generate_submit_button($lang->teamwiki_manage_submit);
                $form->output_submit_wrapper($buttons);
                $form->end();
                $page->output_footer();

                exit();
        }

        if ($action == "entry_edit") {
            if ($mybb->request_method == "post") {

                // CSRF protection
                if (!verify_post_check($mybb->get_input('my_post_key'))) {
                    flash_message($lang->invalid_post_verify_key2, 'error');
                    admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
                }

                // Check if required fields are not empty
                $title = $mybb->get_input('title');
                if (trim($title) === '') {
                    $errors[] = $lang->teamwiki_manage_error_no_title;
                }

                // No errors - update the entry
                if (empty($errors)) {
                    $eid = $mybb->get_input('eid', MyBB::INPUT_INT);
                    $cat_id = $mybb->get_input('rid', MyBB::INPUT_INT);

                    $edited_entry = array(
                        "cat_id" => $cat_id,
                        "title" => $db->escape_string($title),
                        "content" => $db->escape_string($mybb->get_input('description'))
                    );

                    $db->update_query("teamwiki_entries", $edited_entry, "id=" . (int)$eid);

                    // update entry group mappings: remove existing and insert new
                    $db->delete_query('teamwiki_entry_groups', "entry_id=" . (int)$eid);
                    $sel_gids = array_map('intval', $mybb->get_input('allowed_gids', MyBB::INPUT_ARRAY));
                    if (empty($sel_gids)) {
                        // inherit from category if none selected
                        $cq = $db->simple_select('teamwiki_categories', 'allowed_gids', "id=" . (int)$cat_id);
                        $cat = $db->fetch_array($cq);
                        if (!empty($cat['allowed_gids'])) {
                            $sel_gids = array_filter(array_map('intval', explode(',', $cat['allowed_gids'])));
                        }
                    }
                    foreach ($sel_gids as $gid) {
                        $db->insert_query('teamwiki_entry_groups', ['entry_id' => $eid, 'gid' => (int)$gid]);
                    }

                    log_admin_action(htmlspecialchars_uni($title));

                    flash_message($lang->teamwiki_manage_entry_edited, 'success');
                    admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
                }
            }

            $page->add_breadcrumb_item($lang->teamwiki_manage_edit);

            // Build options header
            $page->output_header($lang->teamwiki_manage." - ".$lang->teamwiki_manage_edit);
            $page->output_nav_tabs($sub_tabs, 'teamwiki_entries_add');

            // Show errors
            if (!empty($errors)) {
                $page->output_inline_error($errors);
            }

            // Get the data
            $eid = $mybb->get_input('eid', MyBB::INPUT_INT);
            $query = $db->simple_select("teamwiki_entries", "*", "id=" . (int)$eid);
            $edit_values = $db->fetch_array($query);

            // Build the form
            $form = new Form("index.php?module=config-teamwiki&amp;action=entry_edit", "post", "", 1);
            echo $form->generate_hidden_field('eid', $eid);

            $form_container = new FormContainer($lang->teamwiki_manage_entries_add);
            $form_container->output_row(
                $lang->teamwiki_manage_title."<em>*</em>",
                $lang->teamwiki_manage_entry_title_desc,
                $form->generate_text_box('title', $edit_values['title'])
            );

            $query = $db->simple_select("teamwiki_categories", "id, name", "",
            ["order_by" => "name", "order_dir" => "ASC"]);
            $teamwikis = [];
            while($teamwiki = $db->fetch_array($query)) {
                $teamwikis[$teamwiki['id']] = $teamwiki['name'];
            }

            $form_container->output_row(
                $lang->teamwiki_manage_entries_cat." <em>*</em>",
                "",
                $form->generate_select_box("rid", $teamwikis, $edit_values['cat_id'], ["id" => "rid"]), 'rid'
            );

            $description_editor = $form->generate_text_area('description', $edit_values['content'], array(
                'id' => 'description',
                'rows' => '25',
                'cols' => '70',
                'style' => 'height: 250px; width: 75%'
                )
            );

            $description_editor .= build_mycode_inserter('description');
            $form_container->output_row(
                $lang->teamwiki_manage_content. "<em>*</em>",
                "",
                $description_editor,
                'description'
            );

            // entry-specific visibility: load existing mappings or fallback to category
            $selected_entry_gids = [];
            $egq = $db->simple_select('teamwiki_entry_groups', 'gid', "entry_id=" . (int)$eid);
            while ($er = $db->fetch_array($egq)) {
                $selected_entry_gids[] = (int)$er['gid'];
            }
            if (empty($selected_entry_gids)) {
                $cq = $db->simple_select('teamwiki_categories', 'allowed_gids', "id=" . (int)$edit_values['cat_id']);
                $cat = $db->fetch_array($cq);
                if (!empty($cat['allowed_gids'])) {
                    $selected_entry_gids = array_filter(array_map('intval', explode(',', $cat['allowed_gids'])));
                }
            }
            $gq = $db->simple_select('usergroups', 'gid, title', "", ['order_by' => 'title', 'order_dir' => 'ASC']);
            $group_select_html = "<select name='allowed_gids[]' id='allowed_gids' multiple='multiple' size='8'>";
            while ($g = $db->fetch_array($gq)) {
                $sel = in_array((int)$g['gid'], $selected_entry_gids) ? " selected" : "";
                $group_select_html .= "<option value='".(int)$g['gid']."'".$sel.">".htmlspecialchars_uni($g['title'])."</option>";
            }
            $group_select_html .= "</select>";
            $form_container->output_row(
                $lang->teamwiki_manage_visibility,
                $lang->teamwiki_manage_visibility_desc,
                $group_select_html,
                'allowed_gids'
            );

            $form_container->end();
            $buttons[] = $form->generate_submit_button($lang->teamwiki_manage_submit);
            $form->output_submit_wrapper($buttons);
            $form->end();
            $page->output_footer();

            exit();
        }

        if ($action == "entry_delete") {
            // Get data
            $eid = $mybb->get_input('eid', MyBB::INPUT_INT);
            $query = $db->simple_select("teamwiki_entries", "*", "id=" . (int)$eid);
            $delete_values = $db->fetch_array($query);

            // Error Handling
            if (empty($eid)) {
                flash_message($lang->teamwiki_manage_error_invalid, 'error');
                admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
            }

            // Cancel button pressed?
            if ($mybb->get_input('no')) {
                admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
            }

            if (!verify_post_check($mybb->get_input('my_post_key'))) {
                flash_message($lang->invalid_post_verify_key2, 'error');
                admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
            }  // all fine
            else {
                if ($mybb->request_method == "post") {

                    $db->delete_query("teamwiki_entry_groups", "entry_id=" . (int)$eid);
                    $db->delete_query("teamwiki_entries", "id=" . (int)$eid);

                    log_admin_action(htmlspecialchars_uni($delete_values['title']));

                    flash_message($lang->teamwiki_manage_entry_deleted, 'success');
                    admin_redirect("index.php?module=config-teamwiki&amp;action=entries_view");
                } else {
                    $page->output_confirm_action(
                        "index.php?module=config-teamwiki&amp;action=entry_delete&amp;eid={$eid}",
                        $lang->teamwiki_manage_delete
                    );
                }
            }
            exit();
        }

    }
}

// ###########################################################################################
/**
 * Class TemplateLoader loads template files
 */
class TeamwikiTemplateLoader
{
    private $extension = ".tpl";
    private $templateDir;

    function __construct()
    {
        $this->templateDir = MYBB_ROOT . "inc/plugins/teamwiki/templates/";
    }

    /**
     * Loads the specified template file into a string from the template directory
     * @param $templatetitle
     * @throws Exception Thrown if $templatetitle is null or specified file is not found
     * @return string The template loaded from file
     */
    function load($templatetitle)
    {
        if (!$templatetitle) {
            throw new Exception("Template title mustn't be null.");
        }
        $file = $this->templateDir . $templatetitle . $this->extension;
        if (file_exists($file)) {
            $template = file_get_contents($file);
            return $template;
        } else {
            throw new Exception("File " . $file . " not found");
        }
    }

    /**
     * Auto loads all templates files into the database. As title the filetitle without extension is used
     * @param $db DB_MySQLi Connection to the database
     * @throws Exception Thrown if db is null
     */
    function autoLoad($db)
    {
        if (!$db) {
            throw new Exception($db);
        }
        $files = $this->getFilesInDirectory($this->templateDir);
        foreach ($files as $file) {
            $title = str_replace(".tpl", "", $file);
            $template = $this->load($title);
            $data = array(
                "title" => $db->escape_string($title),
                "template" => $db->escape_string($template),
                "sid" => -1,
                "version" => "",
                "dateline" => time()
            );
            $db->insert_query("templates", $data);
        }
    }

    function autoUnload($db)
    {
        if (!$db) {
            throw new Exception($db);
        }
        $files = $this->getFilesInDirectory($this->templateDir);
        foreach ($files as $file) {
            $title = str_replace(".tpl", "", $file);
            $db->delete_query("templates", "title = '" . $db->escape_string($title) . "'");
        }
    }

    /**
     * Returns the file titles of the files in the given paths which have the given extension
     * @param $path string The path
     * @param string $extension The file extension
     * @return array The array containing the file titles
     * @throws Exception Thrown if path is null
     */
    private function getFilesInDirectory($path, $extension = ".tpl")
    {
        if (!$path) {
            throw new Exception("path is null");
        }
        $files = array();
        if ($handle = opendir($path)) {
            while ($entry = readdir($handle)) {
                if ($entry != "." and $entry != ".." and $this->endsWith($entry, $extension)) {
                    $files[] = $entry;
                }
            }
            return $files;
        } else {
            return array();
        }
    }

    /**
     * Returns if haystack ends with needle
     * @param $haystack string The string to search in
     * @param $needle string What to search for
     * @return bool true if haystacks ends with needle otherwise false
     */
    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return $length === 0 ||
            (substr($haystack, -$length) === $needle);
    }
}
