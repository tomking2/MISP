<?php
App::uses('AppModel', 'Model');
App::uses('ConnectionManager', 'Model');
App::uses('FileAccessTool', 'Tools');

/**
 * @property Event $Event
 */
class Organisation extends AppModel
{
    public $useTable = 'organisations';

    public $recursive = -1;

    public $actsAs = array(
        'AuditLog',
        'Containable',
        'SysLogLogable.SysLogLogable' => array(	// TODO Audit, logable
                'roleModel' => 'Organisation',
                'roleKey' => 'organisation_id',
                'change' => 'full'
        ),
    );

    private $__orgCache = array();

    public $validate = array(
        'name' => array(
            'unique' => array(
                'rule' => 'isUnique',
                'message' => 'An organisation with this name already exists.'
            ),
            'valueNotEmpty' => array(
                'rule' => array('valueNotEmpty'),
            ),
        ),
        'uuid' => array(
            'unique' => array(
                'rule' => 'isUnique',
                'message' => 'An organisation with this UUID already exists.',
                'on' => 'create',
            ),
            'uuid' => array(
                'rule' => 'uuid',
                'message' => 'Please provide a valid RFC 4122 UUID',
                'allowEmpty' => true
            ),
            'valueNotEmpty' => array(
                'rule' => array('valueNotEmpty'),
            )
        )
    );

    public $hasMany = array(
        'User' => array(
            'className' => 'User',
            'foreignKey' => 'org_id'
        ),
        'SharingGroupOrg' => array(
            'className' => 'SharingGroupOrg',
            'foreignKey' => 'org_id',
            'dependent'=> true,
        ),
        'SharingGroup' => array(
            'className' => 'SharingGroup',
            'foreignKey' => 'org_id',
        ),
        'Event' => array(
            'className' => 'Event',
            'foreignKey' => 'orgc_id',
        ),
        'EventOwned' => array(
            'className' => 'Event',
            'foreignKey' => 'org_id',
        ),
    );

    public $organisationAssociations = array(
            'Correlation' => array('table' => 'correlations', 'fields' => array('org_id')),
            'Event' => array('table' => 'events', 'fields' => array('org_id', 'orgc_id')),
            'Job' => array('table' => 'jobs', 'fields' => array('org_id')),
            'Server' => array('table' => 'servers', 'fields' => array('org_id', 'remote_org_id')),
            'ShadowAttribute' =>array('table' => 'shadow_attributes', 'fields' => array('org_id', 'event_org_id')),
            'SharingGroup' => array('table' => 'sharing_groups', 'fields' => array('org_id')),
            'SharingGroupOrg' => array('table' => 'sharing_group_orgs', 'fields' => array('org_id')),
            'Thread' => array('table' => 'threads', 'fields' => array('org_id')),
            'User' => array('table' => 'users', 'fields' => array('org_id'))
    );

    const GENERIC_MISP_ORGANISATION = [
        'id' => '0',
        'name' => 'MISP',
        'date_created' => '',
        'date_modified' => '',
        'description' => 'Automatically generated MISP organisation',
        'type' => '',
        'nationality' => 'Not specified',
        'sector' => '',
        'created_by' => '0',
        'uuid' => '0',
        'contacts' => '',
        'local' => true,
        'restricted_to_domain' => [],
        'landingpage' => null
    ];

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        $org = &$this->data[$this->alias];
        if (empty($org['uuid'])) {
            $org['uuid'] = CakeText::uuid();
        } else {
            $org['uuid'] = strtolower(trim($org['uuid']));
        }
        $date = date('Y-m-d H:i:s');
        if (array_key_exists('restricted_to_domain', $org)) {
            if (!is_array($org['restricted_to_domain'])) {
                $org['restricted_to_domain'] = str_replace("\r", '', $org['restricted_to_domain']);
                $org['restricted_to_domain'] = explode("\n", $org['restricted_to_domain']);
            }

            $org['restricted_to_domain'] = array_values(
                array_filter(
                    array_map('trim', $org['restricted_to_domain'])
                )
            );

            $org['restricted_to_domain'] = json_encode($org['restricted_to_domain']);
        }
        if (!isset($org['id'])) {
            $org['date_created'] = $date;
        }
        $org['date_modified'] = $date;
        if (empty($org['nationality'])) {
            $org['nationality'] = '';
        }
        return true;
    }

    public function beforeDelete($cascade = false)
    {
        if ($this->User->find('count', array('conditions' => array('User.org_id' => $this->id))) != 0) {
            return false;
        }
        if ($this->Event->find('count', array('conditions' => array('OR' => array('Event.org_id' => $this->id, 'Event.orgc_id' => $this->id)))) != 0) {
            return false;
        }
        return true;
    }

    public function afterSave($created, $options = array())
    {
        if ($this->pubToZmq('organisation')) {
            $pubSubTool = $this->getPubSubTool();
            $pubSubTool->modified($this->data, 'organisation');
        }
        $action = $created ? 'add' : 'edit';
        $this->publishKafkaNotification('organisation', $this->data, $action);
        return true;
    }

    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $organisation) {
            if (!empty($organisation['Organisation']['restricted_to_domain'])) {
                $results[$k]['Organisation']['restricted_to_domain'] = json_decode($organisation['Organisation']['restricted_to_domain'], true);
                foreach ($results[$k]['Organisation']['restricted_to_domain'] as $k2 => $v) {
                    $results[$k]['Organisation']['restricted_to_domain'][$k2] = trim($v);
                }
            } else if (isset($organisation['Organisation']['restricted_to_domain'])){
                $results[$k]['Organisation']['restricted_to_domain'] = array();
            }
        }
        return $results;
    }

    /**
     * @param array|string $org
     * @param array $user
     * @param bool $force
     * @return int Organisation ID
     * @throws Exception
     */
    public function captureOrg($org, array $user, $force = false)
    {
        $fieldsToFetch = $force ?
            ['id', 'uuid', 'type', 'date_created', 'date_modified', 'nationality', 'sector', 'contacts'] :
            ['id', 'uuid'];

        if (is_array($org)) {
            if (!empty($org['uuid'])) {
                $conditions = array('uuid' => $org['uuid']);
                $uuid = $org['uuid'];
            } else {
                $conditions = array('name' => $org['name']);
            }
            $name = $org['name'];
        } else {
            $conditions = array('name' => $org);
            $name = $org;
        }

        $existingOrg = $this->find('first', array(
            'recursive' => -1,
            'conditions' => $conditions,
            'fields' => $fieldsToFetch,
        ));
        if (empty($existingOrg)) {
            $organisation = array(
                'name' => $name,
                'local' => 0,
                'created_by' => $user['id'],
            );
            // If we have the UUID set, then we have only made sure that the org doesn't exist by UUID
            // We want to create a new organisation for pushed data, even if the same org name exists
            // Alter the name if the name is already taken by a random string
            if (isset($uuid)) {
                $existingOrgByName = $this->hasAny(['name' => $name]);
                if ($existingOrgByName) {
                    $organisation['name'] = $organisation['name'] . '_' . mt_rand(0, 9999);
                }
                $organisation['uuid'] = $uuid;
            }
            $this->create();
            $this->save($organisation);
            return $this->id;
        } else {
            $changed = false;
            if (isset($org['uuid']) && empty($existingOrg[$this->alias]['uuid'])) {
                $existingOrg[$this->alias]['uuid'] = $org['uuid'];
                $changed = true;
            }
            if ($force) {
                $fields = array('type', 'date_created', 'date_modified', 'nationality', 'sector', 'contacts');
                foreach ($fields as $field) {
                    if (isset($org[$field])) {
                        if ($existingOrg[$this->alias][$field] != $org[$field]) {
                            $existingOrg[$this->alias][$field] = $org[$field];
                            if ($field !== 'date_modified') {
                                $changed = true;
                            }
                        }
                    }
                }
            }
            if ($changed) {
                $this->save($existingOrg);
            }
        }
        return $existingOrg[$this->alias]['id'];
    }

    /**
     * @param string $name Organisation name
     * @param int $userId Organisation creator
     * @param bool $local True if organisation should be marked as local
     * @return int Existing or newly created organisation ID
     * @throws Exception
     */
    public function createOrgFromName($name, $userId, $local)
    {
        $existingOrg = $this->find('first', [
            'recursive' => -1,
            'conditions' => ['name' => $name],
            'fields' => ['id'],
        ]);
        if (empty($existingOrg)) {
            $this->create();
            $organisation = [
                'name' => $name,
                'local' => $local,
                'created_by' => $userId,
            ];
            $this->save($organisation);
            return $this->id;
        }
        return $existingOrg[$this->alias]['id'];
    }

    public function orgMerge($id, $request, $user)
    {
        $currentOrg = $this->find('first', array('recursive' => -1, 'conditions' => array('Organisation.id' => $id)));
        $currentOrgUserCount = $this->User->find('count', array(
            'conditions' => array('User.org_id' => $id)
        ));
        $targetOrgId = $request['Organisation']['targetType'] == 0 ? $request['Organisation']['orgsLocal'] : $request['Organisation']['orgsExternal'];
        $targetOrg = $this->find(
                'first',
            array(
                        'recursive' => -1,
                        'conditions' => array('Organisation.id' => $targetOrgId)
                )
        );
        if (empty($currentOrg) || empty($targetOrg)) {
            throw new MethodNotAllowedException('Something went wrong with the organisation merge. Organisation not found.');
        }
        $dir = new Folder();
        $this->Log = ClassRegistry::init('Log');
        $dirPath = APP . 'tmp' . DS . 'logs' . DS . 'merges';
        if (!$dir->create($dirPath)) {
            throw new MethodNotAllowedException('Merge halted because the log directory (default: /var/www/MISP/app/tmp/logs/merges) could not be created. This is most likely a permission issue, make sure that MISP can write to the logs directory and try again.');
        }
        $logFile = new File($dirPath . DS . 'merge_' . $currentOrg['Organisation']['id'] . '_' . $targetOrg['Organisation']['id'] . '_' . time() . '.log');
        if (!$logFile->create()) {
            throw new MethodNotAllowedException('Merge halted because the log file (default location: /var/www/MISP/app/tmp/logs/merges/[old_org_id]_[new_org_id]_timestamp.log) could not be created. This is most likely a permission issue, make sure that MISP can write to the logs directory and try again.');
        }
        $backupFile = new File($dirPath . DS . 'merge_' . $currentOrg['Organisation']['id'] . '_' . $targetOrg['Organisation']['id'] . '_' . time() . '.sql');
        if (!$backupFile->create()) {
            throw new MethodNotAllowedException('Merge halted because the backup script file (default location: /var/www/MISP/app/tmp/logs/merges/[old_org_id]_[new_org_id]_timestamp.sql) could not be created. This is most likely a permission issue, make sure that MISP can write to the logs directory and try again.');
        }
        if ($this->isMysql()) {
            $sql = 'INSERT INTO organisations (`' . implode('`, `', array_keys($currentOrg['Organisation'])) . '`) VALUES (\'' . implode('\', \'', array_values($currentOrg['Organisation'])) . '\');';
        } else {
            $sql = 'INSERT INTO organisations ("' . implode('", "', array_keys($currentOrg['Organisation'])) . '") VALUES (\'' . implode('\', \'', array_values($currentOrg['Organisation'])) . '\');';
        }
        $backupFile->append($sql . PHP_EOL);
        $this->Log->create();
        $this->Log->save(array(
                'org' => $user['Organisation']['name'],
                'model' => 'Organisation',
                'model_id' => $currentOrg['Organisation']['id'],
                'email' => $user['email'],
                'action' => 'merge',
                'user_id' => $user['id'],
                'title' => 'Starting merger of ' . $currentOrg['Organisation']['name'] . '(' . $currentOrg['Organisation']['id'] . ') into ' . $targetOrg['Organisation']['name'] . '(' . $targetOrg['Organisation']['name'] . ')',
                'change' => '',
        ));
        $dataMoved = array('removed_org' => $currentOrg);
        $success = true;
        foreach ($this->organisationAssociations as $model => $data) {
            foreach ($data['fields'] as $field) {
                if ($this->isMysql()) {
                    $sql = 'SELECT `id` FROM `' . $data['table'] . '` WHERE `' . $field . '` = "' . $currentOrg['Organisation']['id'] . '"';
                } else {
                    $sql = 'SELECT "id" FROM "' . $data['table'] . '" WHERE "' . $field . '" = "' . $currentOrg['Organisation']['id'] . '"';
                }
                $temp = $this->query($sql);
                if (!empty($temp)) {
                    $dataMoved['values_changed'][$model][$field] = Set::extract('/' . $data['table'] . '/id', $temp);
                    if (!empty($dataMoved['values_changed'][$model][$field])) {
                        $this->Log->create();
                        try {
                            if ($this->isMysql()) {
                                $sql = 'UPDATE `' . $data['table'] . '` SET `' . $field . '` = ' . $targetOrg['Organisation']['id'] . ' WHERE `' . $field . '` = ' . $currentOrg['Organisation']['id'] . ';';
                            } else {
                                $sql = 'UPDATE "' . $data['table'] . '" SET "' . $field . '" = ' . $targetOrg['Organisation']['id'] . ' WHERE "' . $field . '" = ' . $currentOrg['Organisation']['id'] . ';';
                            }
                            $result = $this->query($sql);
                            if ($this->isMysql()) {
                                $sql = 'UPDATE `' . $data['table'] . '` SET `' . $field . '` = ' . $currentOrg['Organisation']['id'] . ' WHERE `id` IN (' . implode(',', $dataMoved['values_changed'][$model][$field]) . ');';
                            } else {
                                $sql = 'UPDATE "' . $data['table'] . '" SET "' . $field . '" = ' . $currentOrg['Organisation']['id'] . ' WHERE "id" IN (' . implode(',', $dataMoved['values_changed'][$model][$field]) . ');';
                            }
                            $backupFile->append($sql . PHP_EOL);
                            $this->Log->save(array(
                                    'org' => $user['Organisation']['name'],
                                    'model' => 'Organisation',
                                    'model_id' => $currentOrg['Organisation']['id'],
                                    'email' => $user['email'],
                                    'action' => 'merge',
                                    'user_id' => $user['id'],
                                    'title' => 'Update for ' . $model . '.' . $field . ' has completed successfully.',
                                    'change' => '',
                            ));
                        } catch (Exception $e) {
                            $this->Log->save(array(
                                    'org' => $user['Organisation']['name'],
                                    'model' => 'Organisation',
                                    'model_id' => $currentOrg['Organisation']['id'],
                                    'email' => $user['email'],
                                    'action' => 'merge',
                                    'user_id' => $user['id'],
                                    'title' => 'Update for ' . $model . '.' . $field . ' has failed.',
                                    'change' => json_encode($e->getMessage()),
                            ));
                        }
                    }
                }
            }
        }
        if ($success) {
            $updateTargetOrg = false;
            if ($currentOrgUserCount > 0 && $currentOrg['Organisation']['local'] && !$targetOrg['Organisation']['local']) {
                $targetOrg['Organisation']['local'] = 1;
                $updateTargetOrg = true;
            }
            if (strlen($targetOrg['Organisation']['name']) > strlen($currentOrg['Organisation']['name']) && strpos($targetOrg['Organisation']['name'], $currentOrg['Organisation']['name']) === 0) {
                $temp = substr($targetOrg['Organisation']['name'], strlen($currentOrg['Organisation']['name']));
                if (preg_match('/^\_[0-9]+$/i', $temp)) {
                    $targetOrg['Organisation']['name'] = $currentOrg['Organisation']['name'];
                    $updateTargetOrg = true;
                }
            }
            if (!file_exists(APP . 'webroot/img/orgs/' . $targetOrgId . '.png') && file_exists(APP . 'webroot/img/orgs/' . $id . '.png')) {
                rename(APP . 'webroot/img/orgs/' . $id . '.png', APP . 'webroot/img/orgs/' . $targetOrgId . '.png');
            }
            $this->delete($currentOrg['Organisation']['id']);
            if ($updateTargetOrg) {
                $this->save($targetOrg);
            }
            $success = $targetOrgId;
        }
        $backupFile->close();
        $logFile->write(json_encode($dataMoved));
        $logFile->close();
        return $success;
    }

    public function fetchOrg($id)
    {
        if (empty($id)) {
            return false;
        }
        $conditions = array('Organisation.id' => $id);
        if (Validation::uuid($id)) {
            $conditions = array('Organisation.uuid' => $id);
        } elseif (!is_numeric($id)) {
            $conditions = array('LOWER(Organisation.name)' => strtolower($id));
        }
        $org = $this->find('first', array(
            'conditions' => $conditions,
            'recursive' => -1
        ));
        return (empty($org)) ? false : $org[$this->alias];
    }

    /**
     * Attach organisations to evnet
     * @param array $data
     * @param array $fields
     * @return array
     */
    public function attachOrgs($data, $fields)
    {
        $event = $data['Event'];
        $toFetch = [];
        if (!isset($this->__orgCache[$event['orgc_id']])) {
            $toFetch[] = $event['orgc_id'];
        }
        if (!isset($this->__orgCache[$event['org_id']]) && $event['org_id'] != $event['orgc_id']) {
            $toFetch[] = $event['org_id'];
        }
        if (!empty($toFetch)) {
            $orgs = $this->find('all', array(
                'conditions' => array('id' => $toFetch),
                'recursive' => -1,
                'fields' => $fields,
            ));
            foreach ($orgs as $org) {
                $this->__orgCache[$org[$this->alias]['id']] = $org[$this->alias];
            }
        }
        $data['Orgc'] = $this->__orgCache[$event['orgc_id']];
        $data['Org'] = $this->__orgCache[$event['org_id']];
        return $data;
    }

    public function getOrgIdsFromMeta($metaConditions)
    {
        $orgIds = $this->find('column', array(
            'conditions' => $metaConditions,
            'fields' => array('id'),
            'recursive' => -1
        ));
        if (empty($orgIds)) {
            return array(-1);
        }
        return $orgIds;
    }

    public function checkDesiredOrg($suggestedOrg, $registration)
    {
        if ($suggestedOrg !== false && $suggestedOrg !== -1) {
            $conditions = array();
            if (!empty($registration['Inbox']['data']['org_uuid'])) {
                $conditions = array('Organisation.uuid' => $registration['Inbox']['data']['org_uuid']);
            } else if (!empty($registration['Inbox']['data']['org_name'])) {
                $conditions = array('Organisation.name' => $registration['Inbox']['data']['org_name']);
            } else {
                $domain = explode('@', $registration['Inbox']['data']['email'])[1];
                $conditions = array('LOWER(Organisation.name)' => strtolower($domain));
            }
            $identifiedOrg = $this->User->Organisation->find('first', array(
                'recursive' => -1,
                'fields' => array('id', 'name', 'local'),
                'conditions' => $conditions
            ));
            if (empty($identifiedOrg)) {
            $suggestedOrg = -1;
            } else if (!empty($suggestedOrg) && $suggestedOrg[0] !== $identifiedOrg['Organisation']['id']) {
                $suggestedOrg = false;
            } else {
                $suggestedOrg = array($identifiedOrg['Organisation']['id'], $identifiedOrg['Organisation']['name'], $identifiedOrg['Organisation']['local']);
            }
        }
        return $suggestedOrg;
    }

    /**
     * Hide organisation view from users if they haven't yet contributed data and Security.hide_organisation_index_from_users is enabled
     *
     * @see Organisation::canSee if you want to check multiple orgs
     * @param array $user
     * @param int $orgId
     * @return bool
     */
    public function canSee(array $user, $orgId)
    {
        if ($user['org_id'] == $orgId) {
            return true; // User can see his own org.
        }
        if (!$user['Role']['perm_sharing_group'] && Configure::read('Security.hide_organisation_index_from_users')) {
            // Check if there is event from given org that can current user see
            $eventConditions = $this->Event->createEventConditions($user);
            $eventConditions['AND']['Event.orgc_id'] = $orgId;
            $event = $this->Event->hasAny($eventConditions);
            if (!$event) {
                $proposalConditions = $this->Event->ShadowAttribute->buildConditions($user);
                $proposalConditions['AND']['ShadowAttribute.org_id'] = $orgId;
                $proposal = $this->Event->ShadowAttribute->find('first', array(
                    'fields' => array('ShadowAttribute.id'),
                    'recursive' => -1,
                    'conditions' => $proposalConditions,
                    'contain' => ['Event', 'Attribute'],
                ));
                if (empty($proposal)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Create conditions for fetching orgs based on user permission.
     * @see Organisation::canSee if you want to check just one org
     * @param array $user
     * @return array|array[]
     */
    public function createConditions(array $user)
    {
        if (!$user['Role']['perm_sharing_group'] && Configure::read('Security.hide_organisation_index_from_users')) {
            $allowedOrgs = [$user['org_id']];

            $eventConditions = $this->Event->createEventConditions($user);
            $orgsWithEvent = $this->Event->find('column', [
                'fields' => ['Event.orgc_id'],
                'conditions' => $eventConditions,
                'unique' => true,
            ]);
            $allowedOrgs = array_merge($allowedOrgs, $orgsWithEvent);

            $proposalConditions = $this->Event->ShadowAttribute->buildConditions($user);
            // Do not check orgs that we already can see
            $proposalConditions['AND'][]['NOT'] = ['ShadowAttribute.org_id' => $allowedOrgs];
            $orgsWithProposal = $this->Event->ShadowAttribute->find('column', [
                'fields' => ['ShadowAttribute.org_id'],
                'conditions' => $proposalConditions,
                'contain' => ['Event', 'Attribute'],
                'unique' => true,
                'order' => false,
            ]);

            $allowedOrgs = array_merge($allowedOrgs, $orgsWithProposal);
            return ['AND' => ['id' => $allowedOrgs]];
        }

        return [];
    }

    /**
     * @return array
     */
    private function getCountryGalaxyCluster()
    {
        static $list;
        if (!$list) {
            try {
                $content = FileAccessTool::readFromFile(APP . '/files/misp-galaxy/clusters/country.json');
                $list = $this->jsonDecode($content)['values'];
            } catch (Exception $e) {
                $this->logException("MISP Galaxy are not updated, countries will not be available.", $e, LOG_WARNING);
                $list = [];
            }
        }
        return $list;
    }

    /**
     * @param string $countryName
     * @return string|null
     */
    public function getCountryCode($countryName)
    {
        foreach ($this->getCountryGalaxyCluster() as $country) {
            if ($country['description'] === $countryName) {
                return $country['meta']['ISO'];
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    public function getCountries()
    {
        $countries = array_column($this->getCountryGalaxyCluster(), 'description');
        sort($countries);
        array_unshift($countries, 'International');
        array_unshift($countries, 'Europe');
        return $countries;
    }
}
