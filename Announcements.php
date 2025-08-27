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

    /**
     * Checks if a given project ID is present in a comma-separated list of IDs and ranges.
     *
     * @param int $current_pid The project ID to check for.
     * @param string $pid_list_string The string containing IDs/ranges (e.g., "12, 45, 100-110").
     * @return bool True if the PID is in the list, false otherwise.
     */
    private function isPidInList($current_pid, $pid_list_string)
    {
        if (empty($pid_list_string) || !is_numeric($current_pid)) {
            return false;
        }

        // Set debug mode
        $debug = $this->getSystemSetting('debug');

        // Remove all whitespace and split the string by commas
        $parts = explode(',', str_replace(' ', '', $pid_list_string));

        foreach ($parts as $part) {
            if (strpos($part, '-') !== false) {
                list($start, $end) = explode('-', $part);
                if (is_numeric($start) && is_numeric($end) && $start <= $end) {
                    if ($current_pid >= $start && $current_pid <= $end) {
                        return true; // The PID is within this range
                    }
                } 
                // ADDED: Debug message for malformed range
                else if ($debug) {
                    echo "<script>console.warn('Announcements DEBUG: Malformed range \"" . $this->escape($part) . "\" in PID list was ignored.');</script>";
                }
            }
            elseif (is_numeric($part)) {
                if ($current_pid == $part) {
                    return true; // The PID is an exact match
                }
            }
            // ADDED: Debug message for non-numeric part
            else if ($debug && !empty($part)) {
                echo "<script>console.warn('Announcements DEBUG: Non-numeric value \"" . $this->escape($part) . "\" in PID list was ignored.');</script>";
            }
        }

        return false; // No match was found
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

            // Get the full path of the currently running script.
            $current_script_path = $_SERVER['SCRIPT_NAME'];

            // Use APP_PATH_WEBROOT_PARENT to get the stable, unversioned path to REDCap.
            $redcap_base_path = APP_PATH_WEBROOT_PARENT;              // e.g., '/redcap/'
            $redcap_index_path = APP_PATH_WEBROOT_PARENT . 'index.php'; // e.g., '/redcap/index.php'

            // Check the server's script path against the stable REDCap base path.
            if (
                ($current_script_path === $redcap_base_path || $current_script_path === $redcap_index_path) &&
                ($action === '' || $action === 'myprojects')
            ) {
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
                echo "<script>console.log('Announcements Module DEBUG: Not in an eligible page context.');</script>";
            }
            return;
        }

        // Retrieve announcement project and exit if not set, otherwise module exception is thrown
        $announcementProject = $this->getSystemSetting('announcement-project');
        if (empty($announcementProject)) {
            if ($debug) {
                echo "<script>console.log('Announcements Module DEBUG: No announcement project specified.');</script>";
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
                'fields'=>array('record_id', 'label', 'cat', 'desc', 'named_filter', 'pid_list', 'active', 'order', 'since', 'until')
            );

        $announcements = json_decode(REDCap::getData($announcementParams), true);
        $categories = json_decode(REDCap::getData($categoryParams), true);
        $this->sort_array_by_key($announcements, 'order', 'record_id');
        $this->sort_array_by_key($categories, 'cat_order', 'record_id');

        // Group announcements by category ID for efficiency
        $announcements_by_category = [];
        foreach ($announcements as $announcement) {
            $category_id_for_ann = $announcement['cat'] ?? null; // 'cat' field links to category record_id
            if ($category_id_for_ann !== null) {
                if (!isset($announcements_by_category[$category_id_for_ann])) {
                    $announcements_by_category[$category_id_for_ann] = [];
                }
                $announcements_by_category[$category_id_for_ann][] = $announcement;
            }
        }

        // If debug mode enabled, report how many categories and how many announcements.
        if ($debug) {
            echo "<script>
                console.log('Announcements Module DEBUG');
                console.log('    - Context: " . $this->escape($page_context) . "');
                console.log('    - Announcement Project: " . $announcementProject . "');
                console.log('    - Found " . count($announcements) . " announcement" . (count($announcements) === 1 ? '' : 's') . " in " . count($categories) . " categor" . (count($categories) === 1 ? 'y' : 'ies') . "');
                </script>";
        }

        $html_output = ""; // Initialize an empty string to build the HTML

        // Lazy-load admin-defined filters just once, outside the main loop
        $all_named_filters = null; 

        foreach ($categories as $category) {
            if ($debug) {
                echo "<script>console.log('    - Category \`" . ($this->escape($category['category']) ?? '') . "\`:');</script>";
            }

            // 1. Get all potential announcements for this category.
            $current_cat_announcements = $announcements_by_category[$category['record_id']] ?? [];

            // 2. Create a temporary array to hold ONLY announcements that pass the named filter check.
            $displayable_announcements = [];

            // 3. Filter the announcements based on their 'named_filter' (if in a project context).
            foreach ($current_cat_announcements as $announcement) {
                $filter_name = $announcement['named_filter'] ?? null;
                $pid_list = $announcement['pid_list'] ?? null;

                // Initialise match flags
                $pidMatched = true;
                $sqlMatched = true;

                // --- Perform PID List Check (only in project context if a list is provided) ---
                if ($page_context === 'project' && !empty($pid_list)) {
                    if (!$this->isPidInList($project_id, $pid_list)) {
                        $pidMatched = false; // The current project is NOT in the list.
                        if ($debug) {
                            $logMessage = "        - PID List Check Failed for announcement " . ($announcement['record_id'] ?? '') . " (" . ($announcement['label'] ?? '') . "):";
                            $logDetails = "          PID " . ($project_id ?? '') . " NOT in " . ($pid_list ?? '');
                            echo "<script>console.log(" . json_encode($logMessage) . ");</script>";
                            echo "<script>console.log(" . json_encode($logDetails) . ");</script>";
                        }
                    }
                }

                if ($page_context === 'project' && !empty($filter_name)) {
                    // If not in a project context, or if this announcement has no filter, it's eligible.
                    $sqlMatched = false;

                    // We ARE in a project context AND a filter is named. Time to check the SQL.
                    if ($all_named_filters === null) {
                        $all_named_filters = $this->getSubSettings('defined-named-filters');
                    }

                    $sql_query = null;
                    foreach ($all_named_filters as $named_filter) {
                        if ($named_filter['filter-name'] === $filter_name) {
                            $sql_query = $named_filter['filter-sql'];
                            break;
                        }
                    }

                    if ($sql_query !== null) {
                        try {
                            $check_sql = "SELECT project_id FROM (" . $sql_query . ") AS query_result WHERE project_id = ?";
                            $q = $this->query($check_sql, [$project_id]);

                            if (db_num_rows($q) > 0) {
                                $sqlMatched = true;
                            } else {
                                if ($debug) {
                                    $logMessage = "        - Named Filter Check Failed for announcement " . ($announcement['record_id'] ?? '') . " (" . ($announcement['label'] ?? '') . "):";
                                    $logDetails = "          PID " . ($project_id ?? '') . " NOT returned by `" . ($filter_name ?? '') . "` query.";
                                    echo "<script>console.log(" . json_encode($logMessage) . ");</script>";
                                    echo "<script>console.log(" . json_encode($logDetails) . ");</script>";
                                }
                            }
                        } catch (\Exception $e) {
                            $this->log( // Log the failed query in the module log in the announcement project
                                "A named filter failed to execute.",
                                [
                                    "filter" => $this->escape($filter_name),
                                    "message" => "Please check the SQL query for errors and see the documentation.",
                                    "sql" => $this->escape($sql_query),
                                    "target_project" => $project_id,
                                    "record" => $announcement['record_id'],
                                    "category" => $category['record_id'],
                                    "context" => $this->escape($page_context),
                                    "project_id" => $announcementProject
                                ]
                            );   
                            $sqlMatched = false;
                            if ($debug) {
                                echo "<script>console.warn('        - Named Filter Check Failed to execute. See EM log in PID " . $announcementProject . " for details.');</script>";
                            }
                        }
                    }
                }

                if ($pidMatched && $sqlMatched) {
                    // This announcement passed all checks, add it to our display list.
                    $displayable_announcements[] = $announcement;
                } 
            }

            // 4. NOW, use the count of the FILTERED list for all decisions.
            $announcement_count = count($displayable_announcements);

            // Condition for displaying the category block, if category has announcements or a fallback configured, and if the context and scope align.
            if ((!empty($category['fallback']) || $announcement_count > 0) &&
                (($page_context === 'system' && ($category['scope___1'] ?? 0) == '1') || // Logged in users on system pages
                ($page_context === 'project' && ($category['scope___2'] ?? 0) == '1') || // Logged in users on project pages
                ($page_context === 'login' && ($category['scope___3'] ?? 0) == '1')) // Non-logged in users on login page
            ) {
                if ($debug) { 
                    echo "<script>console.log('      - Found " . $announcement_count . " filtered announcement" . ($announcement_count === 1 ? '' : 's') . ".');</script>";
                }

                // Prepare variables with RAW data
                $cat_record_id = $category['record_id'] ?? '';
                $cat_title = $category['cat_title'] ?? '';
                $cat_header = $category['header'] ?? '';
                $cat_footer = $category['footer'] ?? '';
                $cat_fallback = $category['fallback'] ?? '';
                $fa_class = $category['fa'] ?? null;

                // Sanitization for CSS classes
                $user_defined_classes_raw = trim($category['custom_classes'] ?? '');
                $user_defined_classes_sanitized = '';
                if (!empty($user_defined_classes_raw)) {
                    $cleaned_classes = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $user_defined_classes_raw);
                    $user_defined_classes_sanitized = trim(preg_replace('/\s+/', ' ', $cleaned_classes));
                }

                // Build the category slug (raw)
                $category_slug = 'rcannounce-cat-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($category['category'] ?: $cat_record_id));

                // Build the class list (raw)
                $category_custom_classes = $this->getSystemSetting('category-custom-classes');
                $class_list = "rcannounce-category " . $category_custom_classes . " " . $category_slug . " alert";
                if (!empty($user_defined_classes_sanitized)) {
                    $class_list .= " " . $user_defined_classes_sanitized;
                }

                // Apply escaping for attributes right at the moment of output
                $html_output .= "<div id=\"" . $this->escape($category_slug) . "\" class=\"" . $this->escape($class_list) . "\">";

                if (!empty($cat_title)) {
                    $category_fa_icon = !empty($fa_class) ? "<i class=\"" . $this->escape($fa_class) . "\"></i> " : "";
                    $html_output .= "<h4 class=\"alert-title rcannounce-title\">" . $category_fa_icon . $this->escape($cat_title) . "</h4>";
                }

                if ($announcement_count == 0) {
                    if (!empty($cat_fallback)) {
                        // Sanitize fallback text for HTML and convert newlines
                        $html_output .= "<p class=\"rcannounce-fallback\">" . nl2br(\REDCap::filterHtml($cat_fallback)) . "</p>";
                    } 
                } else {
                    if (!empty($cat_header)) {
                        // Sanitize header text for HTML and convert newlines
                        $html_output .= "<p class=\"rcannounce-hdr\">" . nl2br(\REDCap::filterHtml($cat_header)) . "</p>";
                    }

                    // Render the list using the FILTERED array
                    foreach ($displayable_announcements as $announcement) {
                        $raw_ann_desc = $announcement['desc'] ?? ''; 
                        // Sanitize the main announcement content, which is expected to be HTML
                        $safe_desc_html = \REDCap::filterHtml($raw_ann_desc);

                        $html_output .= "<p class=\"rcannounce-desc\">" . $safe_desc_html . "</p>";

                        if ($debug) {
                            $log_label = $this->escape($announcement['label'] ?? '');
                            echo "<script>console.log('      - Rendered announcement " . $this->escape($announcement['record_id']) . " (" . $log_label . ")');</script>";
                        }
                    }

                    if (!empty($cat_footer)) {
                        // Sanitize footer text for HTML and convert newlines
                        $html_output .= "<p class=\"rcannounce-ftr\">" . nl2br(\REDCap::filterHtml($cat_footer)) . "</p>";
                    }
                }
                $html_output .= "</div>"; // End .rc-announcement-category
            } else {
                if ($debug) {
                    echo "<script>console.log('        - No announcements found and no fallback message or scope does not align.');</script>";
                }
            }
        } // End main foreach categories loop

        // Wrap all category blocks in a main container.
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
                echo "<style type=\"text/css\">" . strip_tags($custom_css) . "</style>";
            }
            $escaped_js_html_output = json_encode($final_html_output);

            echo "<script type=\"text/javascript\">
                $(document).ready(function() {
                    var announcementHTML = {$escaped_js_html_output};
                    var \$targetContainer;

                    // --- CORRECTED TARGETING LOGIC ---
                    if ($('#left_col').length) {
                        \$targetContainer = $('#left_col').children('div').first(); // Login page
                    } else if ($('#pagecontent').length) {
                        \$targetContainer = $('#pagecontent'); // System pages (my projects, etc)
                    } else if ($('#subheader').length) {
                        \$targetContainer = $('#subheader'); // Project pages
                    } else if ($('#pagecontainer').length) {
                        \$targetContainer = $('#pagecontainer'); // Primary fallback
                    } else {
                        \$targetContainer = $('body'); // Absolute fallback
                    }
                    // --- END CORRECTION ---

                    // Prepend the announcements to the determined target container
                    if (\$targetContainer && \$targetContainer.length) {
                        \$targetContainer.prepend(announcementHTML);
                    } else {
                        // This case should now be impossible since $('body') is the ultimate fallback
                        console.error('Announcements Module: Could not find a suitable container to inject announcements.');
                    }
                    });
            </script>";
        }
    }
}

