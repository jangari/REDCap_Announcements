<?php

namespace INTERSECT\Announcements;

use ExternalModules\AbstractExternalModule;
use REDCap;

class Announcements extends \ExternalModules\AbstractExternalModule {

    /**
     * Sorts an array of associative arrays in place.
     *
     * @param array &$array The array to be sorted (passed by reference).
     * @param string $primary_sort_key The key to use for the primary sort.
     * @param string $secondary_sort_key The key to use for secondary sort / tie-breaking.
     * @return bool True on success, false if the input $array is not a non-empty array.
     */
    private function sort_array_by_key(&$array, $primary_sort_key, $secondary_sort_key) {
        // Ensure the input is a sortable array
        if (!is_array($array) || empty($array)) {
            return false; // Or trigger an error, or return the array as is
        }

        usort($array, function ($a, $b) use ($primary_sort_key, $secondary_sort_key) {
            // Get and normalize primary key values
            // Treat empty strings or non-existent keys for primary sort as 'null' (no defined order)
            $primary_val_a = (isset($a[$primary_sort_key]) && $a[$primary_sort_key] !== '') ? (int)$a[$primary_sort_key] : null;
            $primary_val_b = (isset($b[$primary_sort_key]) && $b[$primary_sort_key] !== '') ? (int)$b[$primary_sort_key] : null;

            // Get and normalize secondary key values (assume they should exist and be numeric)
            // Default to 0 if not set or not numeric, though ideally data should be clean.
            $secondary_val_a = isset($a[$secondary_sort_key]) ? (int)$a[$secondary_sort_key] : 0;
            $secondary_val_b = isset($b[$secondary_sort_key]) ? (int)$b[$secondary_sort_key] : 0;

            // Case 1: Both items have a defined primary sort value
            if ($primary_val_a !== null && $primary_val_b !== null) {
                if ($primary_val_a < $primary_val_b) {
                    return -1;
                }
                if ($primary_val_a > $primary_val_b) {
                    return 1;
                }
                // If primary keys are the same, sort by secondary key
                return $secondary_val_a < $secondary_val_b ? -1 : ($secondary_val_a > $secondary_val_b ? 1 : 0);
            }
            // Case 2: Only item 'a' has a defined primary sort value ('a' comes before 'b')
            elseif ($primary_val_a !== null) {
                return -1; // Items with a primary value come before those without
            }
            // Case 3: Only item 'b' has a defined primary sort value ('b' comes before 'a')
            elseif ($primary_val_b !== null) {
                return 1;  // Items with a primary value come before those without
            }
            // Case 4: Neither item has a defined primary sort value, so sort by secondary key
            else {
                return $secondary_val_a < $secondary_val_b ? -1 : ($secondary_val_a > $secondary_val_b ? 1 : 0);
            }
        });

        return true; // `usort` sorts in place and returns true on success
    }

    function redcap_every_page_top($project_id = null)
    {
        // Set debug mode
        $debug = $this->getSystemSetting('debug');

        // 1. Determine the page context
        $page_context = '';
        if (!defined('USERID')) {
            // If not in a project and no user is logged in, it's the 'login' page context.
            $page_context = 'login';
        } elseif ($project_id !== null) {
            // If a project_id exists, we are in a 'project' context.
            $page_context = 'project';
        } else {
            // If not in a project but a user IS logged in, it's a 'system' context.
            $page_context = 'system';
        }

        // 2. Decide if the module should run based on context and specific page restrictions
        $run_module_on_this_page = false;
        switch ($page_context) {
        case 'login':
            // Always run on the login page when the context is matched.
            $run_module_on_this_page = true;
            break;

        case 'system':
            // For system context, only run on the home page or "My Projects" page.
            $action = $_GET['action'] ?? '';
            if (PAGE === 'index.php' && ($action === '' || $action === 'myprojects')) {
                $run_module_on_this_page = true;
            }
            break;

        case 'project':
            // For project context, only run on the project home page or Project Setup page.
            if ($this->isREDCapPage('index.php') || $this->isREDCapPage('ProjectSetup/index.php')) {
                $run_module_on_this_page = true;
            }
            break;
        }

        // 3. Final check to exit the module if we are not in a context in which it ought to run
        if (!$run_module_on_this_page) {
            if ($debug) {
                echo "<script>console.log('Announcements Module: Not in a context in which it should run.');</script>";
            }
            return;
        }

        // Retrieve announcement project and exit if not set, otherwise module exception is thrown
        $announcementProject = $this->getSystemSetting('announcement-project');
        if (empty($announcementProject)) {
            if ($debug) {
                echo "<script>console.log('Announcements Module: No announcement project specified.');</script>";
            }
            return;
        }

        // Get active categories
        $categoryParams = array
            (
                'project_id'=>$announcementProject,
                'return_format'=>'json',
                'event'=>'categories_arm_2',
                'filterLogic'=>'[categories_arm_2][cat_active] = "1"',
                'fields'=>array( 'record_id', 'category', 'fa', 'scope', 'cat_active', 'cat_title', 'cat_order', 'header', 'fallback', 'footer', 'custom_classes')
            );

        // Get active announcements that are within their date range
        $announcementParams = array
            (
                'project_id'=>$announcementProject,
                'return_format'=>'json',
                'event'=>'announcements_arm_1',
                'filterLogic'=>'
                ( datediff([announcements_arm_1][since],"now","s","true") > 0 or [announcements_arm_1][since] = "" )
                and
                ( datediff("now",[announcements_arm_1][until],"s","true") > 0 or [announcements_arm_1][until] = "" )
                and [announcements_arm_1][active] = "1"
                ',
                'fields'=>array('record_id', 'cat', 'desc', 'active', 'order', 'since', 'until')
            );

        $announcements = json_decode(REDCap::getData($announcementParams), true);
        $categories = json_decode(REDCap::getData($categoryParams), true);
        $this->sort_array_by_key($announcements, 'order', 'record_id');
        $this->sort_array_by_key($categories, 'cat_order', 'record_id');

        // Group announcements by category ID for efficiency
        $announcements_by_category = [];
        foreach ($announcements as $ann) {
            $category_id_for_ann = $ann['cat'] ?? null; // 'cat' field links to category record_id
            if ($category_id_for_ann !== null) {
                if (!isset($announcements_by_category[$category_id_for_ann])) {
                    $announcements_by_category[$category_id_for_ann] = [];
                }
                $announcements_by_category[$category_id_for_ann][] = $ann;
            }
        }

        // If debug mode enabled, report how many categories and how many announcements.
        if ($debug) {
            echo "<script>
                console.log('Announcements Module Debug');
                console.log('Context: " . $page_context . "');
                console.log('Announcement Project: " . $announcementProject . "');
                console.log('Found " . count($announcements) . " announcements in " . count($categories) . " categories');
                </script>";
        }

        $html_output = ""; // Initialize an empty string to build the HTML

        // Step 2: Loop through each category
        foreach ($categories as $category) {
            if ($debug) {
                echo "<script>console.log('Processing category: " . $category['category'] . "');</script>";
            }
            $cat_record_id = htmlspecialchars($category['record_id'] ?? '');
            $cat_title = htmlentities($category['cat_title'] ?? '');
            $cat_header = !empty($category['header']) ? nl2br(htmlentities($category['header'])) : '';
            $cat_footer = !empty($category['footer']) ? nl2br(htmlentities($category['footer'])) : '';
            $cat_fallback = !empty($category['fallback']) ? nl2br(htmlentities($category['fallback'])) : '';

            $user_defined_classes_raw = trim($category['custom_classes'] ?? '');
            $user_defined_classes_sanitized = '';
            if (!empty($user_defined_classes_raw)) {
                // Sanitize the class string:
                // 1. Allow only valid characters for CSS class names and spaces.
                //    (alphanumeric, hyphens, underscores. Spaces are delimiters).
                // 2. Normalize multiple spaces to single spaces.
                $cleaned_classes = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $user_defined_classes_raw);
                $user_defined_classes_sanitized = trim(preg_replace('/\s+/', ' ', $cleaned_classes));
            }

            // Get announcements for the current category
            $current_cat_announcements = $announcements_by_category[$category['record_id']] ?? [];
            $announcement_count = count($current_cat_announcements);

            // Condition for displaying the category block, if category has announcements or a fallback configured, and if the context and scope align.
            if ((!empty($category['fallback']) || $announcement_count > 0) &&
                (($page_context === 'system' && ($category['scope___1'] ?? 0) == '1') || // Logged in users on system pages
                ($page_context === 'project' && ($category['scope___2'] ?? 0) == '1') || // Logged in users on project pages
                ($page_context === 'login' && ($category['scope___3'] ?? 0) == '1')) // Non-logged in users on login page
            ) {
                if ($debug) { 
                    echo "<script>console.log('Found " . $announcement_count . " announcements for category: " . $category['category'] . "');</script>";
                }
                // Create a slug from category title for more specific CSS targeting if desired
                $category_slug = 'rcannounce-cat-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($category['category'] ?: $cat_record_id));
                // Build the class list
                $category_custom_classes = $this->getSystemSetting('category-custom-classes');
                $class_list = "rcannounce-category " . htmlspecialchars($category_custom_classes) . " " . htmlspecialchars($category_slug) . " alert"; // Base classes
                if (!empty($user_defined_classes_sanitized)) {
                    $class_list .= " " . htmlspecialchars($user_defined_classes_sanitized); // Add user's classes (htmlspecialchars for attribute safety, though sanitized classes should be safe)
                }

                $html_output .= "<div id=\"" . htmlspecialchars($category_slug) . "\" class=\"" . $class_list . "\">";

                if (!empty($cat_title)) {
                    // Font awesome icon
                    $category_fa_icon = isset($category['fa']) && !empty($category['fa']) ? "<i class=\"" . htmlspecialchars($category['fa'], ENT_QUOTES) . "\"></i> " : "";
                    $html_output .= "<h4 class=\"alert-title rcannounce-title\">" . $category_fa_icon . $cat_title . "</h4>";
                }

                if ($announcement_count == 0) {
                    // No announcements, display fallback (only if fallback message itself is not empty)
                    if (!empty($cat_fallback)) { // This check is a bit redundant due to the outer IF, but safe.
                        $cat_fallback = \REDCap::filterHtml($cat_fallback);
                        $html_output .= "<p class=\"rcannounce-fallback\">" . $cat_fallback . "</p>";
                    }
                } else {
                    // There are announcements, display them in a list
                    if (!empty($cat_header)) {
                        $cat_header = \REDCap::filterHtml($cat_header);
                        $html_output .= "<p class=\"rcannounce-hdr\">" . $cat_header . "</p>";
                    }
                    foreach ($current_cat_announcements as $ann) {
                        // Get the raw description field
                        $raw_ann_desc = $ann['desc'] ?? ''; 
                        $safe_desc_html = \REDCap::filterHtml($raw_ann_desc);

                        // Output the sanitized HTML. We'll wrap it in a div for consistent structure and styling.
                        $html_output .= "<p class=\"rcannounce-desc\">" . $safe_desc_html . "</p>";
                    }
                    if (!empty($cat_footer)) {
                        $cat_footer = \REDCap::filterHtml($cat_footer);
                        $html_output .= "<p class=\"rcannounce-ftr\">" . $cat_footer . "</p>";
                    }
                }
                $html_output .= "</div>"; // End .rc-announcement-category
            } else {
                if ($debug) {
                    echo "<script>console.log('Category " . $category['category'] . " either empty and no fallback message or scope does not align. Skipping.');</script>";
                }
            }
        }

        // Wrap all category blocks in a main container. Set left-align since the login page left_col div sets centre alignment which then breaks any classes set by the module. Should only ever affect this module's div.
        if (!empty($html_output)) {
            // 1. Get user-defined classes from module settings
            $wrapper_custom_classes = trim($this->getSystemSetting('wrapper-custom-classes') ?? '');

            // 2. Start building the class list with base classes
            $class_list = [
                'rcannounce-wrapper',                      // Base class for wrapper
                'rcannounce-context-' . $page_context      // Dynamic class for context
            ];

            // 3. Add the user's custom classes only if they exist
            if (!empty($wrapper_custom_classes)) {
                // Sanitize and add the user's classes
                $cleaned_classes = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $wrapper_custom_classes);
                $class_list[] = trim(preg_replace('/\s+/', ' ', $cleaned_classes));
            }

            // 4. Set left-alignment for login context only, since the login page left_col div sets centre alignment which then breaks any classes set by the module. Should only ever affect this module's div. This left alignment it then overridden by the announcement HTML anyway.
            $style_attr = $page_context === 'login' ? ' style="text-align: left;"' : '';

            if ($this->getSystemSetting('fix-project-width') && $page_context === 'project') {
               $style_attr = ' style="max-width: 800px;" ';
            }

            // 5. Implode the array into a final, clean class string and build the div
            $final_html_output = "<div id=\"rcannounce-wrapper\" " . $style_attr . " class=\"" . htmlspecialchars(implode(' ', $class_list)) . "\">" . $html_output . "</div>";
        }

        // Output
        if (!empty($final_html_output)) {
            // Insert style now
            $custom_css = $this->getSystemSetting('custom-css');
            if (!empty($custom_css)) {
                echo "<style type=\"text/css\">" . $custom_css . "</style>";
            }
            $escaped_js_html_output = json_encode($final_html_output);

            echo "<script type=\"text/javascript\">
                $(document).ready(function() {
                    var announcementHTML = {$escaped_js_html_output};
            var \$targetContainer;

            if ($('#left_col').length) {
                \$targetContainer = $('#left_col').children('div').first(); // Login page
            } else if ($('#pagecontent').length) {
                \$targetContainer = $('#pagecontent'); // System pages (my projects, etc)
            } else if ($('#subheader').length) {
                \$targetContainer = $('#subheader'); // Project pages
            } else if (!\$targetContainer.length) {
                \$targetContainer = $('#pagecontainer');
            } else {
                \$targetContainer = $('body'); // Absolute fallback
            }

            // Prepend the announcements to the determined target container
            if (\$targetContainer && \$targetContainer.length) {
                \$targetContainer.prepend(announcementHTML);
            } else {
                // This case should be rare if 'body' is the ultimate fallback
                console.error('Announcements Module: Could not find a suitable container to inject announcements.');
            }
        });
    </script>";
        }
    }
}

