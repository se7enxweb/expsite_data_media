<?php
require_once 'autoload.php';

$script = eZScript::instance( array(
    'description' => 'Install the sevenx site data media pack.',
    'use-session' => false,
    'use-modules' => false,
    'use-extensions' => true,
) );
$script->startup();

require_once 'extension/explayouts/classes/explayoutssiteinstaller.php';
require_once 'extension/expsite_data_media/classes/expsitedatamediainstaller.php';

$installer = new expSiteDataMediaInstaller( 'extension/expsite_data_media/data', 'var/site/storage' );
// Use INSERT OR IGNORE (data.sql) so we do not overwrite core content objects
// such as the Home node that were created by kickstarter.
$installer->replaceConflicts = false;
$output = $installer->installDataPack();

eZCLI::instance()->output( 'Exp site data media install output:' );
foreach ( $output as $line )
{
    eZCLI::instance()->output( '  - ' . $line );
}

$db = eZDB::instance();
mergeMediaSiteRoot( $db );
fixUrlAliasActions( $db );
mergeSitesIntoHome( $db );
mergeImportedMediaIntoCore( $db );
setMediaRootNode( $db );
cleanupUrlAliases( $db );

// The data-replace.sql dump may contain orphan draft versions from the
// source site. Remove any ezcontentobject_version rows (and their related
// attributes/name/node-assignment rows) that are not the object's current
// published version, so the admin editor does not open empty draft content.
$db->query( 'DELETE FROM ezcontentobject_attribute WHERE NOT EXISTS ( SELECT 1 FROM ezcontentobject WHERE ezcontentobject.id = ezcontentobject_attribute.contentobject_id AND ezcontentobject.current_version = ezcontentobject_attribute.version )' );
$db->query( 'DELETE FROM ezcontentobject_name WHERE NOT EXISTS ( SELECT 1 FROM ezcontentobject WHERE ezcontentobject.id = ezcontentobject_name.contentobject_id AND ezcontentobject.current_version = ezcontentobject_name.content_version )' );
$db->query( 'DELETE FROM eznode_assignment WHERE NOT EXISTS ( SELECT 1 FROM ezcontentobject WHERE ezcontentobject.id = eznode_assignment.contentobject_id AND ezcontentobject.current_version = eznode_assignment.contentobject_version )' );
$db->query( 'DELETE FROM ezcontentobject_version WHERE NOT EXISTS ( SELECT 1 FROM ezcontentobject WHERE ezcontentobject.id = ezcontentobject_version.contentobject_id AND ezcontentobject.current_version = ezcontentobject_version.version )' );

eZCLI::instance()->output( 'Cleaned up non-current (orphan draft) versions.' );

// The data-replace.sql dump also ships its own copies of the stock roles and
// content classes (image, file, user, user_group) with different IDs than the
// Class-attribute ID mapping is still required because the data.sql dump
// ships its own copies of stock classes (image, file, user, user_group) with
// different attribute IDs than the base install.
deduplicateContentClasses( $db );
deduplicateObjectAttributes( $db );
// deduplicateRoles( $db );

fixMediaSectionPolicies( $db );
applyFitHealthyLayoutFix( $db );
activateMediaTheme();

eZCLI::instance()->output( 'Clearing all caches.' );
eZCache::clearAll();

eZCLI::instance()->output( 'Media data install complete and cleaned.' );
$script->shutdown();

function applyFitHealthyLayoutFix( $db )
{
    // Imported /Fit-Healthy layout (id 29) has broken two_columns/column marker
    // blocks and misplaced content blocks. Hide the markers and reorder the rest.
    $hideIds = array( 398, 401, 406, 409, 412, 418, 421 );
    $positionUpdates = array(
        397 => 0,
        414 => 1,
        410 => 2,
        411 => 3,
        413 => 4,
        402 => 5,
        416 => 6,
        404 => 7,
        419 => 8,
        423 => 9,
        422 => 10,
        415 => 11,
        403 => 12,
        407 => 13,
        405 => 14,
        420 => 15,
        399 => 20,
        400 => 21,
        408 => 22,
        417 => 23,
    );

    $db->query( 'UPDATE explayouts_block SET status = 0 WHERE id IN (' . implode( ',', $hideIds ) . ')' );
    foreach ( $positionUpdates as $id => $position )
    {
        $db->query( "UPDATE explayouts_block SET position = $position WHERE id = $id" );
    }
    eZCLI::instance()->output( 'Applied Fit-Healthy layout position/status fix.' );
}

function updateNiceUrls()
{
    eZCLI::instance()->output( 'Regenerating nice URLs...' );
    $cmd = 'php bin/php/ezexec.php bin/php/updateniceurls.php --allow-root-user';
    passthru( $cmd, $return );
    if ( $return !== 0 )
    {
        eZCLI::instance()->warning( "updateniceurls exited with code $return." );
    }
}

function q( $db, $sql )
{
    $db->query( $sql );
}

function a( $db, $sql )
{
    $result = $db->arrayQuery( $sql );
    return is_array( $result ) ? $result : array();
}

function deduplicateContentClasses( $db )
{
    $dupes = a( $db,
        "SELECT c1.id AS base_id, c2.id AS dup_id, c1.identifier " .
        "FROM ezcontentclass c1 " .
        "JOIN ezcontentclass c2 ON c1.identifier = c2.identifier AND c1.id < c2.id " .
        "WHERE c1.version = 0 AND c2.version = 0 " .
        "ORDER BY c1.identifier"
    );

    if ( count( $dupes ) === 0 )
    {
        eZCLI::instance()->output( 'No duplicate content classes found.' );
        return;
    }

    foreach ( $dupes as $row )
    {
        $baseId = (int)$row['base_id'];
        $dupId  = (int)$row['dup_id'];
        $identifier = $row['identifier'];

        eZCLI::instance()->output( "Deduplicating class '$identifier': $dupId -> $baseId." );

        q( $db, "UPDATE ezcontentobject SET contentclass_id = $baseId WHERE contentclass_id = $dupId" );
        q( $db, "UPDATE ezsearch_object_word_link SET contentclass_id = $baseId WHERE contentclass_id = $dupId" );

        $attrs = a( $db,
            "SELECT a1.id AS base_attr, a2.id AS dup_attr, a1.identifier AS attr_identifier " .
            "FROM ezcontentclass_attribute a1 " .
            "LEFT JOIN ezcontentclass_attribute a2 ON a2.contentclass_id = $dupId AND a2.identifier = a1.identifier AND a2.version = 0 " .
            "WHERE a1.contentclass_id = $baseId AND a1.version = 0 " .
            "ORDER BY a1.identifier"
        );

        foreach ( $attrs as $attr )
        {
            $dupAttr = isset( $attr['dup_attr'] ) ? (int)$attr['dup_attr'] : 0;
            if ( $dupAttr === 0 ) continue;
            $baseAttr = (int)$attr['base_attr'];
            q( $db, "UPDATE ezcontentobject_attribute SET contentclassattribute_id = $baseAttr WHERE contentclassattribute_id = $dupAttr" );
            eZCLI::instance()->output( "  Attribute '{$attr['attr_identifier']}': $dupAttr -> $baseAttr." );
        }

        q( $db, "DELETE FROM ezcontentclass_classgroup WHERE contentclass_id = $dupId" );
        q( $db, "DELETE FROM ezcontentclass_name WHERE contentclass_id = $dupId" );
        q( $db, "DELETE FROM ezcontentclass_attribute WHERE contentclass_id = $dupId" );
        q( $db, "DELETE FROM ezcontentclass WHERE id = $dupId" );
    }

    eZExpiryHandler::instance()->setTimestamp( 'class-identifier-cache', time() );
    eZCLI::instance()->output( 'Class identifier cache invalidated.' );
}

function deduplicateObjectAttributes( $db )
{
    // The media data dump may contain extra ezcontentobject_attribute rows for
    // core objects (e.g. Home node) with different attribute IDs. After class
    // attribute IDs have been remapped to the base class, keep only the first
    // attribute row per object / version / class-attribute and remove the
    // duplicate rows that otherwise leave the object with empty/corrupted data.
    $dupes = a( $db,
        "SELECT contentobject_id, version, contentclassattribute_id, COUNT(*) AS c, MIN(id) AS keep_id " .
        "FROM ezcontentobject_attribute " .
        "GROUP BY contentobject_id, version, contentclassattribute_id " .
        "HAVING c > 1"
    );

    if ( count( $dupes ) === 0 )
    {
        eZCLI::instance()->output( 'No duplicate object attributes found.' );
        return;
    }

    foreach ( $dupes as $row )
    {
        $coId = (int)$row['contentobject_id'];
        $version = (int)$row['version'];
        $attrId = (int)$row['contentclassattribute_id'];
        $keepId = (int)$row['keep_id'];

        eZCLI::instance()->output( "Removing duplicate attributes for object $coId version $version classattr $attrId, keeping $keepId." );

        q( $db,
            "DELETE FROM ezcontentobject_attribute " .
            "WHERE contentobject_id = $coId " .
            "  AND version = $version " .
            "  AND contentclassattribute_id = $attrId " .
            "  AND id != $keepId"
        );
    }

    eZCLI::instance()->output( 'Duplicate object attributes cleaned.' );
}

function deduplicateRoles( $db )
{
    $dupes = a( $db,
        "SELECT name, GROUP_CONCAT(id) AS ids, COUNT(*) AS c " .
        "FROM ezrole " .
        "GROUP BY name " .
        "HAVING c > 1 " .
        "ORDER BY name"
    );

    if ( count( $dupes ) === 0 )
    {
        eZCLI::instance()->output( 'No duplicate roles found.' );
        return;
    }

    foreach ( $dupes as $row )
    {
        $ids = explode( ',', $row['ids'] );
        sort( $ids, SORT_NUMERIC );
        $keepId = (int)array_shift( $ids );

        foreach ( $ids as $dupId )
        {
            $dupId = (int)$dupId;
            eZCLI::instance()->output( "Deduplicating role '{$row['name']}': $dupId -> $keepId." );

            q( $db, "DELETE FROM ezpolicy_limitation_value WHERE limitation_id IN ( SELECT id FROM ezpolicy_limitation WHERE policy_id IN ( SELECT id FROM ezpolicy WHERE role_id = $dupId ) )" );
            q( $db, "DELETE FROM ezpolicy_limitation WHERE policy_id IN ( SELECT id FROM ezpolicy WHERE role_id = $dupId )" );
            q( $db, "DELETE FROM ezpolicy WHERE role_id = $dupId" );
            q( $db, "DELETE FROM ezuser_role WHERE role_id = $dupId" );
            q( $db, "DELETE FROM ezrole WHERE id = $dupId" );
        }
    }

    eZCLI::instance()->output( 'Role deduplication complete.' );
}

function mergeMediaSiteRoot( $db )
{
    // The imported dump starts at its own self-referencing root node instead
    // of the site root (node_id=1). Move its immediate children under the real
    // root so they appear in the content tree.
    $roots = a( $db,
        "SELECT node_id FROM ezcontentobject_tree " .
        "WHERE parent_node_id = node_id AND contentobject_id = 0 AND node_id != 1"
    );

    if ( count( $roots ) === 0 )
    {
        eZCLI::instance()->output( 'No imported orphan root to merge.' );
        return;
    }

    foreach ( $roots as $root )
    {
        $rootId = (int)$root['node_id'];

        $aliases = a( $db, "SELECT text AS url_alias FROM ezurlalias_ml WHERE id = $rootId" );
        $aliasList = array();
        foreach ( $aliases as $alias )
        {
            $aliasList[] = $alias['url_alias'];
        }
        eZCLI::instance()->output( "Removing orphan root node $rootId (aliases: '" . implode( "', '", $aliasList ) . "')." );

        q( $db, "UPDATE ezcontentobject_tree SET parent_node_id = 1 WHERE parent_node_id = $rootId" );
        q( $db, "UPDATE ezcontentobject_tree SET path_string = '/1' || SUBSTR( path_string, LENGTH( '/$rootId/' ) ) WHERE path_string LIKE '/$rootId/%' AND node_id != $rootId" );
        q( $db, "UPDATE eznode_assignment SET parent_node = 1 WHERE parent_node = $rootId" );
        q( $db, "UPDATE ezurlalias_ml SET parent = 0 WHERE parent = $rootId" );
        q( $db, "DELETE FROM ezcontentobject_tree WHERE node_id = $rootId" );
        q( $db, "DELETE FROM ezurlalias_ml WHERE id = $rootId" );

        eZCLI::instance()->output( "Merged imported orphan root $rootId into the site root." );
    }
}

function mergeSitesIntoHome( $db )
{
    // The imported media pack has a top-level "Sites" container that we want
    // flattened into the core Home node (node_id=2) so the demo pages sit
    // alongside the regular site content.
    $sites = a( $db,
        "SELECT t.node_id FROM ezcontentobject_tree t " .
        "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
        "WHERE t.parent_node_id = 1 AND o.name = 'Sites'"
    );

    if ( count( $sites ) === 0 )
    {
        eZCLI::instance()->output( 'No imported Sites container to merge into Home.' );
        return;
    }

    $homeId = 2;
    foreach ( $sites as $site )
    {
        $siteId = (int)$site['node_id'];

        $details = a( $db,
            "SELECT o.name, ua.text AS url_alias " .
            "FROM ezcontentobject_tree t " .
            "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
            "LEFT JOIN ezurlalias_ml ua ON ua.id = t.node_id " .
            "WHERE t.node_id = $siteId"
        );
        foreach ( $details as $d )
        {
            $name = $d['name'];
            $alias = isset( $d['url_alias'] ) ? $d['url_alias'] : '';
            eZCLI::instance()->output( "Removing content object '$name' (node $siteId, alias '$alias')." );
        }

        q( $db, "UPDATE ezcontentobject_tree SET parent_node_id = $homeId WHERE parent_node_id = $siteId" );
        q( $db, "UPDATE ezcontentobject_tree SET path_string = '/1/$homeId' || SUBSTR( path_string, LENGTH( '/1/$siteId/' ) ) WHERE path_string LIKE '/1/$siteId/%' AND node_id != $siteId" );
        q( $db, "UPDATE eznode_assignment SET parent_node = $homeId WHERE parent_node = $siteId" );
        q( $db, "UPDATE ezurlalias_ml SET parent = $homeId WHERE parent = $siteId" );
        q( $db, "DELETE FROM ezcontentobject_tree WHERE node_id = $siteId" );
        q( $db, "DELETE FROM ezurlalias_ml WHERE id = $siteId" );

        eZCLI::instance()->output( "Merged imported Sites container $siteId into Home ($homeId)." );
    }
}

function mergeImportedMediaIntoCore( $db )
{
    // The core Media folder (node 43) is empty; fold the imported Media
    // container into it so the admin Media library tab shows all media files.
    $coreRows = a( $db,
        "SELECT t.node_id, t.contentobject_id FROM ezcontentobject_tree t " .
        "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
        "WHERE o.name = 'Media' AND t.parent_node_id = 1 " .
        "ORDER BY t.node_id ASC LIMIT 1"
    );
    $importedRows = a( $db,
        "SELECT t.node_id, t.contentobject_id FROM ezcontentobject_tree t " .
        "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
        "WHERE o.name = 'Media' AND t.parent_node_id = 1 " .
        "ORDER BY t.node_id DESC LIMIT 1"
    );

    if ( count( $coreRows ) === 0 || count( $importedRows ) === 0 )
    {
        eZCLI::instance()->output( 'Could not find both core and imported Media nodes.' );
        return;
    }

    $coreId = (int)$coreRows[0]['node_id'];
    $importedId = (int)$importedRows[0]['node_id'];
    $importedContentObjectId = (int)$importedRows[0]['contentobject_id'];
    if ( $coreId === $importedId )
    {
        eZCLI::instance()->output( 'Imported Media already merged into core Media.' );
        return;
    }

    $details = a( $db,
        "SELECT o.name, ua.text AS url_alias " .
        "FROM ezcontentobject o " .
        "LEFT JOIN ezurlalias_ml ua ON ua.id = $importedId " .
        "WHERE o.id = $importedContentObjectId"
    );
    foreach ( $details as $d )
    {
        $name = $d['name'];
        $alias = isset( $d['url_alias'] ) ? $d['url_alias'] : '';
        eZCLI::instance()->output( "Removing content object '$name' (node $importedId, alias '$alias')." );
    }

    q( $db, "UPDATE ezcontentobject_tree SET parent_node_id = $coreId WHERE parent_node_id = $importedId" );
    q( $db, "UPDATE ezcontentobject_tree SET path_string = '/1/$coreId' || SUBSTR( path_string, LENGTH( '/1/$importedId/' ) ) WHERE path_string LIKE '/1/$importedId/%' AND node_id != $importedId" );
    q( $db, "UPDATE eznode_assignment SET parent_node = $coreId WHERE parent_node = $importedId" );
    q( $db, "UPDATE ezurlalias_ml SET parent = $coreId WHERE parent = $importedId" );
    q( $db, "DELETE FROM ezcontentobject_tree WHERE node_id = $importedId" );
    q( $db, "DELETE FROM ezurlalias_ml WHERE id = $importedId" );
    q( $db, "DELETE FROM ezcontentobject_name WHERE contentobject_id = $importedContentObjectId" );
    q( $db, "DELETE FROM ezcontentobject WHERE id = $importedContentObjectId" );

    eZCLI::instance()->output( "Merged imported Media container $importedId into core Media ($coreId) and removed imported Media object $importedContentObjectId." );
}

function setMediaRootNode( $db )
{
    // Make the admin "Media library" tab point at the imported Media node
    // instead of the empty core Media folder.
    $rows = a( $db,
        "SELECT t.node_id FROM ezcontentobject_tree t " .
        "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
        "WHERE o.name = 'Media' AND t.parent_node_id = 1 " .
        "ORDER BY t.node_id DESC LIMIT 1"
    );

    if ( count( $rows ) === 0 )
    {
        eZCLI::instance()->output( 'No imported Media node found; MediaRootNode unchanged.' );
        return;
    }

    $mediaNodeId = (int)$rows[0]['node_id'];
    $overrideFile = 'settings/override/content.ini.append.php';
    $contents = file_exists( $overrideFile ) ? file_get_contents( $overrideFile ) : '';
    $newLine = "MediaRootNode=$mediaNodeId";

    if ( preg_match( '/^MediaRootNode=\d+$/m', $contents ) )
    {
        $contents = preg_replace( '/^MediaRootNode=\d+$/m', $newLine, $contents );
    }
    else
    {
        $contents = preg_replace( '/\*\//', "[NodeSettings]\n$newLine\n\n*/", $contents, 1 );
    }

    file_put_contents( $overrideFile, $contents );
    eZCLI::instance()->output( "Set MediaRootNode to imported Media node $mediaNodeId in $overrideFile." );
}

function fixUrlAliasActions( $db )
{
    // data.sql ships URL aliases with source node IDs in the form
    // action = 'eznode:<source_node_id>'. After the tree is shifted by the
    // node ID offset, the numeric node reference must be bumped to match the
    // actual node_id in ezcontentobject_tree.
    // The Media folder exists in both the core install and the import with
    // the same source node id, so imported_media - core_media = the offset.

    $coreRows = a( $db,
        "SELECT t.node_id FROM ezcontentobject_tree t " .
        "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
        "WHERE o.name = 'Media' AND t.parent_node_id = 1 " .
        "ORDER BY t.node_id ASC LIMIT 1"
    );
    $importedRows = a( $db,
        "SELECT t.node_id FROM ezcontentobject_tree t " .
        "JOIN ezcontentobject o ON o.id = t.contentobject_id " .
        "WHERE o.name = 'Media' AND t.parent_node_id = 1 " .
        "ORDER BY t.node_id DESC LIMIT 1"
    );

    if ( count( $coreRows ) === 0 || count( $importedRows ) === 0 )
    {
        eZCLI::instance()->output( 'Could not find both core and imported Media nodes to derive node offset; skipping URL alias action fix.' );
        return;
    }

    $coreId = (int)$coreRows[0]['node_id'];
    $importedId = (int)$importedRows[0]['node_id'];

    if ( $coreId === $importedId )
    {
        eZCLI::instance()->output( 'Imported Media already merged into core Media; skipping URL alias action fix.' );
        return;
    }

    $nodeOffset = $importedId - $coreId;

    if ( $nodeOffset <= 0 )
    {
        eZCLI::instance()->output( 'Node offset is zero; skipping URL alias action fix.' );
        return;
    }

    eZCLI::instance()->output( "Fixing eznode URL alias actions with node offset $nodeOffset (core Media $coreId -> imported Media $importedId)." );

    q( $db,
        "UPDATE ezurlalias_ml " .
        "SET action = CASE " .
        "WHEN id > $nodeOffset AND CAST(SUBSTR(action, 8) AS int) <= $nodeOffset THEN 'eznode:' || (CAST(SUBSTR(action, 8) AS int) + $nodeOffset) " .
        "WHEN id <= $nodeOffset AND CAST(SUBSTR(action, 8) AS int) > $nodeOffset THEN 'eznode:' || (CAST(SUBSTR(action, 8) AS int) - $nodeOffset) " .
        "ELSE action " .
        "END " .
        "WHERE action LIKE 'eznode:%'"
    );
}

function cleanupUrlAliases( $db )
{
    // Merging imported Sites/Media roots removes some parent aliases, leaving children orphaned.
    $orphans = a( $db,
        "SELECT id, text AS url_alias, action FROM ezurlalias_ml " .
        "WHERE parent != 0 AND parent NOT IN ( SELECT id FROM ezurlalias_ml )"
    );
    foreach ( $orphans as $o )
    {
        eZCLI::instance()->output( "Removing orphaned URL alias '{$o['url_alias']}' (id {$o['id']}, action {$o['action']})." );
    }
    $db->query( "DELETE FROM ezurlalias_ml WHERE parent != 0 AND parent NOT IN ( SELECT id FROM ezurlalias_ml )" );
    eZCLI::instance()->output( 'Removed ' . count( $orphans ) . ' orphaned URL aliases.' );

    // Some aliases may still reference nodes that no longer exist after the merge/cleanup.
    $missing = a( $db,
        "SELECT id, text AS url_alias, action FROM ezurlalias_ml " .
        "WHERE action LIKE 'eznode:%' AND CAST( SUBSTR( action, 8 ) AS int ) NOT IN ( SELECT node_id FROM ezcontentobject_tree )"
    );
    foreach ( $missing as $m )
    {
        eZCLI::instance()->output( "Removing URL alias '{$m['url_alias']}' (id {$m['id']}, action {$m['action']}) pointing at deleted node." );
    }
    $db->query( "DELETE FROM ezurlalias_ml WHERE action LIKE 'eznode:%' AND CAST( SUBSTR( action, 8 ) AS int ) NOT IN ( SELECT node_id FROM ezcontentobject_tree )" );
    eZCLI::instance()->output( 'Removed ' . count( $missing ) . ' URL aliases pointing at deleted nodes.' );
}

function activateMediaTheme()
{
    $siteaccess = 'sevenx_site_user';
    $siteIniPath = "settings/siteaccess/$siteaccess/site.ini.append.php";
    if ( !file_exists( $siteIniPath ) )
    {
        eZCLI::instance()->output( "Siteaccess INI not found: $siteIniPath" );
        return;
    }

    $content = file_get_contents( $siteIniPath );
    if ( strpos( $content, 'ActiveAccessExtensions[]=sevenx_themes_simple' ) !== false )
    {
        eZCLI::instance()->output( 'Simple media theme already activated in site.ini.' );
        return;
    }

    $append = "\n[ExtensionSettings]\nActiveAccessExtensions[]=sevenx_themes_simple\nActiveAccessExtensions[]=sevenx_themes_media\nActiveAccessExtensions[]=explayouts\n\n[DesignSettings]\nSiteDesign=simple\nAdditionalSiteDesignList[]\nAdditionalSiteDesignList[]=standard\n";
    $content = preg_replace( '/\s*\*\/\s*\?>\s*$/s', $append . "\n*/ ?>", $content );
    file_put_contents( $siteIniPath, $content );
    eZCLI::instance()->output( "Activated sevenx_themes_simple, sevenx_themes_media and explayouts for $siteaccess." );
}

function fixMediaSectionPolicies( $db )
{
    $roles = array( 'Anonymous', 'Partner', 'Member' );
    $roleIds = array();
    foreach ( $roles as $roleName )
    {
        $rows = $db->arrayQuery( "SELECT MIN(id) AS id FROM ezrole WHERE name = '" . $db->escapeString( $roleName ) . "'" );
        if ( isset( $rows[0]['id'] ) && $rows[0]['id'] )
        {
            $roleIds[$roleName] = (int)$rows[0]['id'];
        }
    }
    if ( count( $roleIds ) === 0 )
    {
        eZCLI::instance()->output( 'No core roles found; skipping media section policy fix.' );
        return;
    }

    $sectionRows = $db->arrayQuery( "SELECT id FROM ezsection WHERE name IN ('Standard','Media') AND id > 3" );
    $sectionIds = array();
    foreach ( $sectionRows as $row )
    {
        $sectionIds[] = (int)$row['id'];
    }
    if ( count( $sectionIds ) === 0 )
    {
        eZCLI::instance()->output( 'No imported Standard/Media sections found; skipping media section policy fix.' );
        return;
    }

    $policyMax = $db->arrayQuery( 'SELECT COALESCE(MAX(id),0) AS m FROM ezpolicy' );
    $limitMax = $db->arrayQuery( 'SELECT COALESCE(MAX(id),0) AS m FROM ezpolicy_limitation' );
    $valueMax = $db->arrayQuery( 'SELECT COALESCE(MAX(id),0) AS m FROM ezpolicy_limitation_value' );
    $policyId = (int)$policyMax[0]['m'];
    $limitId = (int)$limitMax[0]['m'];
    $valueId = (int)$valueMax[0]['m'];

    foreach ( $roleIds as $roleName => $roleId )
    {
        foreach ( $sectionIds as $sectionId )
        {
            $policyId++;
            $limitId++;
            $valueId++;

            $db->query( "INSERT INTO ezpolicy (id, role_id, module_name, function_name, original_id) VALUES ($policyId, $roleId, 'content', 'read', 0)" );
            $db->query( "INSERT INTO ezpolicy_limitation (id, policy_id, identifier) VALUES ($limitId, $policyId, 'Section')" );
            $db->query( "INSERT INTO ezpolicy_limitation_value (id, limitation_id, value) VALUES ($valueId, $limitId, '$sectionId')" );
        }
    }

    eZCLI::instance()->output( 'Added content/read policies for imported sections ' . implode( ', ', $sectionIds ) . ' to roles: ' . implode( ', ', array_keys( $roleIds ) ) . '.' );
}
