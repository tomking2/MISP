<?php

/**
 * Common functions for the 3 analyst objects
 */
class AnalystDataBehavior extends ModelBehavior
{
    public $SharingGroup;

    private $__current_type = null;

    public function setup(Model $Model, $settings = array()) {
        // We want to know whether we're a Note, Opinion or Relationship
        $this->__current_type = $Model->alias;
    }

    // Return the analystData of the current type for a given UUID (this only checks the ACL of the analystData, NOT of the parent.)
    public function fetchForUuid(Model $Model, $uuid, $user = null)
    {
        $conditions = [
            'object_uuid' => $uuid
        ];
        $type = $Model->current_type;
        if (empty($user['Role']['perm_site_admin'])) {
            $validSharingGroups = $Model->SharingGroup->authorizedIds($user, true);
            $conditions['AND'][] = [
                'OR' => [
                    $type . '.orgc_uuid' => $user['Organisation']['uuid'],
                    $type . '.org_uuid' => $user['Organisation']['uuid'],
                    $type . '.distribution IN' => [1, 2, 3],
                    'AND' => [
                        $type . '.distribution' => 4,
                        $type . '.sharing_group_id IN' => $validSharingGroups
                    ]
                ]
            ];
        }
        return $Model->find('all', [
            'recursive' => -1,
            'conditions' => $conditions,
            'contain' => ['Org', 'Orgc', 'SharingGroup'],
        ]);
    }

    public function checkACL()
    {

    }
}
