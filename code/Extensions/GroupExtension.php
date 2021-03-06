<?php

namespace AirNZ\SimpleSubsites\Extensions;

use AirNZ\SimpleSubsites\Model\Subsite;
use SilverStripe\Control\Cookie;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Group;
use SilverStripe\Security\PermissionProvider;

/**
 * Extension for the Group object to add subsites support
 */
class GroupExtension extends DataExtension implements PermissionProvider
{
    private static $db = [
        'AccessAllSubsites' => 'Boolean',
    ];

    private static $many_many = [
        'Subsites' => Subsite::class,
    ];

    private static $defaults = [
        'AccessAllSubsites' => true,
    ];

    /**
     * Migrations for GroupSubsites data.
     */
    public function requireDefaultRecords()
    {
        // Migration for Group.SubsiteID data from when Groups only had a single subsite
        /** @skipUpgrade */
        $groupFields = DB::field_list('Group');

        // Detection of SubsiteID field is the trigger for old-style-subsiteID migration
        if (isset($groupFields['SubsiteID'])) {
            // Migrate subsite-specific data
            DB::query('INSERT INTO "Group_Subsites" ("GroupID", "SubsiteID")
                SELECT "ID", "SubsiteID" FROM "Group" WHERE "SubsiteID" > 0');

            // Migrate global-access data
            DB::query('UPDATE "Group" SET "AccessAllSubsites" = 1 WHERE "SubsiteID" = 0');

            // Move the field out of the way so that this migration doesn't get executed again
            DB::get_schema()->renameField('SilverStripe\\Security\\Group', 'SubsiteID', '_obsolete_SubsiteID');

        // No subsite access on anything means that we've just installed the subsites module.
        // Make all previous groups global-access groups
        } elseif (!DB::query('SELECT "Group"."ID" FROM "Group"
            LEFT JOIN "Group_Subsites" ON "Group_Subsites"."GroupID" = "Group"."ID" AND "Group_Subsites"."SubsiteID" > 0
            WHERE "AccessAllSubsites" = 1
            OR "Group_Subsites"."GroupID" IS NOT null ')->value()) {
            DB::query('UPDATE "Group" SET "AccessAllSubsites" = 1');
        }
    }

    public function updateCMSFields(FieldList $fields)
    {
        if (!$this->owner->canEdit()) {
            return;
        }

        // i18n tab
        $fields->findOrMakeTab('Root.Subsites', _t('GroupSubsites.SECURITYTABTITLE', 'Subsites'));

        $subsites = Subsite::accessible_sites(array('ADMIN', 'SECURITY_SUBSITE_GROUP'));
        $subsiteMap = $subsites->map()->toArray();

        // Prevent XSS injection
        $subsiteMap = Convert::raw2xml($subsiteMap);
        // Interface is different if you have the rights to modify subsite group values on
        // all subsites
        $fields->addFieldToTab(
            "Root.Subsites",
            new OptionsetField(
                "AccessAllSubsites",
                _t('GroupSubsites.ACCESSRADIOTITLE', 'Give this group access to'),
                [
                    1 => _t('GroupSubsites.ACCESSALL', "All subsites"),
                    0 => _t('GroupSubsites.ACCESSONLY', "Only these subsites"),
                ]
            )
        );

        $fields->addFieldToTab("Root.Subsites", new CheckboxSetField("Subsites", "", $subsiteMap));
    }

    /**
     * If this group belongs to a subsite,
     * append the subsites title to the group title
     * to make it easy to distinguish in the tree-view
     * of the security admin interface.
     */
    public function updateTreeTitle(&$title)
    {
        if ($this->owner->AccessAllSubsites) {
            $title .= ' <i>(' . _t('GroupSubsites.GlobalGroup', 'global group') . ')</i>';
            return;
        }

        $subsites = Convert::raw2xml(implode(", ", $this->owner->Subsites()->column('Title')));
        $title .= " <i>($subsites)</i>";
    }

    /**
     * Update any requests to limit the results to the current site
     */
    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        if (Subsite::$disable_subsite_filter) {
            return;
        }

        // Don't break ManyMany relationship
        /** @skipUpgrade */
        if (count($query->getFrom()) == 2 &&
            (
                (isset($query->getFrom()['Group']) && isset($query->getFrom()['Group_Members'])) ||
                (isset($query->getFrom()['Group']) && isset($query->getFrom()['WorkflowDefinition_Groups'])) ||
                (isset($query->getFrom()['Group']) && isset($query->getFrom()['AssignUsersToWorkflowAction_Groups'])) ||
                (isset($query->getFrom()['Group']) && isset($query->getFrom()['File_ViewerGroups'])) ||
                (isset($query->getFrom()['Group']) && isset($query->getFrom()['File_EditorGroups']))
            )) {
            return;
        }

        // If you're querying by ID, ignore the sub-site - this is a bit ugly...
        if (!$query->filtersOnID()) {
            $subsiteID = (int)Subsite::currentSubsiteID(true);
            // Don't filter by Group_Subsites if we've already done that
            $hasGroupSubsites = false;
            foreach ($query->getFrom() as $item) {
                if ((is_array($item) && strpos($item['table'], 'Group_Subsites')!==false) || (!is_array($item) && strpos($item, 'Group_Subsites')!==false)) {
                    $hasGroupSubsites = true;
                    break;
                }
            }

            if (!$hasGroupSubsites) {
                if ($subsiteID) {
                    $query->addLeftJoin("Group_Subsites", "\"Group_Subsites\".\"GroupID\"
                        = \"Group\".\"ID\" AND \"Group_Subsites\".\"SubsiteID\" = $subsiteID");
                    $query->addWhere("(\"Group_Subsites\".\"SubsiteID\" IS NOT null OR
                        \"Group\".\"AccessAllSubsites\" = 1)");
                } else {
                    $query->addWhere("\"Group\".\"AccessAllSubsites\" = 1");
                }
            }

            // WORKAROUND for databases that complain about an ORDER BY when the column wasn't selected (e.g. SQL Server)
            $select=$query->getSelect();
            if (isset($select[0]) && !$select[0] == 'COUNT(*)') {
                $query->orderby = "\"AccessAllSubsites\" DESC" . ($query->orderby ? ', ' : '') . $query->orderby;
            }
        }
    }

    public function onBeforeWrite()
    {
        // New record test approximated by checking whether the ID has changed.
        // Note also that the after write test is only used when we're *not* on a subsite
        if ($this->owner->isChanged('ID') && !Subsite::currentSubsiteID()) {
            $this->owner->AccessAllSubsites = 1;
        }
    }

    public function onAfterWrite()
    {
        // New record test approximated by checking whether the ID has changed.
        // Note also that the after write test is only used when we're on a subsite
        if ($this->owner->isChanged('ID') && $currentSubsiteID = Subsite::currentSubsiteID(true)) {
            $subsites = $this->owner->Subsites();
            $subsites->add($currentSubsiteID);
        }
    }

    public function alternateCanEdit()
    {
        // Find the sites that this group belongs to and the sites where we have appropriate perm.
        $accessibleSites = Subsite::accessible_sites('CMS_ACCESS_SecurityAdmin')->column('ID');
        $linkedSites = $this->owner->Subsites()->column('ID');

        // We are allowed to access this site if at we have CMS_ACCESS_SecurityAdmin permission on
        // at least one of the sites
        return (bool)array_intersect($accessibleSites, $linkedSites);
    }

    public function providePermissions()
    {
        return [
            'SECURITY_SUBSITE_GROUP' => [
                'name' => _t('GroupSubsites.MANAGE_SUBSITES', 'Manage subsites for groups'),
                'category' => _t('Permissions.PERMISSIONS_CATEGORY', 'Roles and access permissions'),
                'help' => _t('GroupSubsites.MANAGE_SUBSITES_HELP', 'Ability to limit the permissions for a group to one or more subsites.'),
                'sort' => 200
            ]
        ];
    }
}
