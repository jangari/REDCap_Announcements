<?php

$page = new HtmlPage();
$page->PrintHeaderExt();
include APP_PATH_VIEWS . 'HomeTabs.php';

?>

<div style="text-align: center">
    <h2 style="padding-top: 50px">REDCap Announcements Setup Instructions</h2>
    <p>This module requires an Announcements configuration project to be created and linked, whose records will be used to generate announcements and their categories. This document instructs users on the setup of that project.</p>
    <h5>Please follow the steps below to create the announcements project and link it to the module.</h5>
</div>
<p>
    <ol>
        <li><a href="<?= $module->getUrl("assets/Announcements_project_template.xml") ?>" download>Download Announcements project template</a></li>
        <li>Create a new REDCap project</li>
        <ul>
            <li>Set whichever title and purpose you prefer</li>
            <li>Choose the "Upload a REDCap project XML file" option</li>
            <li>Then upload the template that you downloaded in step 1</li>
        </ul>
        <li><strong>Important:</strong> Go into the newly created project's designer and update the query for the <code>[cat]</code> Dynamic SQL field to correctly display a list of your categories:<br/>
            <pre>SELECT record,value FROM [data-table] WHERE project_id = [project-id] AND field_name = 'category'</pre><br/>
            This must be done manually by an administrator since SQL queries may not be imported by XML.</li>
        <li>Go to the Configure page for the Announcements project in the Control Center</li>
        <li>Select the newly created project from the dropdown labelled 'Announcement Project'</li>
        <li><strong>If you want to display some categories on project pages</strong>, then you must also check the option to <em>Enable module on all projects by default</em>.</li>
        <li>It is a good idea to also check the option to <em>Hide this module from non-admins in the list of enabled modules on each project</em>.</li>
    </ol>
</p>
