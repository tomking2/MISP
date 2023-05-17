<?php

class NewOrgsWidget
{
    public $title = 'New organisations';
    public $render = 'Index';
    public $width = 7;
    public $height = 6;
    public $description = 'A list of the latest new member organisations.';
    private $tableDescription = null;
    public $cacheLifetime = null;
    public $autoRefreshDelay = false;
    public $params = [
        'limit' => 'Maximum number of joining organisations shown. (integer, defaults to 10 if not set)',
        'filter' => 'A list of filters by organisation meta information (nationality, sector, type, name, uuid) to include. (dictionary, prepending values with ! uses them as a negation)',
        'days' => 'How many days back should the list go - for example, setting 7 will only show the organisations that were added in the past 7 days. (integer)',
        'month' => 'Which organisations have been added this month? (boolean)',
        'year' => 'Which organisations have been added this year? (boolean)',
        'local' => 'Should the list only show local organisations? (boolean or list of booleans, defaults to 1. To get both sets, use [0,1])',
        'fields' => 'Which fields should be displayed, by default all are selected. Pass a list with the following options: [id, uuid, name, sector, type, nationality, creation_date]'
    ];
    private $validFilterKeys = [
        'nationality',
        'sector',
        'type',
        'name',
        'uuid'
    ];

    public $placeholder =
        '{
    "limit": 5,
    "filter": {
       "nationality": [
            "Hungary",
            "Russia",
            "North Korea"
       ]
    },
    "month": true
}';

    private $Organisation = null;

    private function timeConditions($options)
    {
        $limit = empty($options['limit']) ? 10 : $options['limit'];
        if (!empty($options['days'])) {
            $condition = strtotime(sprintf("-%s days", $options['days']));
            $this->tableDescription = __('The %d newest organisations created in the past %d days', $limit, (int)$options['days']);
        } else if (!empty($options['month'])) {
            $condition = strtotime('first day of this month 00:00:00', time());
            $this->tableDescription = __('The %d newest organisations created during the current month', $limit);
        } else if (!empty($options['year'])) {
            $condition = strtotime('first day of this year 00:00:00', time());
            $this->tableDescription = __('The %d newest organisations created during the current year', $limit);
        } else {
            $this->tableDescription = __('The %d newest organisations created', $limit);
            return null;
        }
        $datetime = new DateTime();
        $datetime->setTimestamp($condition);
        return $datetime->format('Y-m-d H:i:s');
    }

    public function handler($user, $options = array())
    {
        $this->Organisation = ClassRegistry::init('Organisation');
        $field_options = [
            'id' => [
                'name' => '#',
                'url' => Configure::read('MISP.baseurl') . '/organisations/view',
                'element' => 'links',
                'data_path' => 'Organisation.id',
                'url_params_data_paths' => 'Organisation.id'
            ],
            'date_created' => [
                'name' => 'Creation date',
                'data_path' => 'Organisation.date_created'
            ],
            'name' => [
                'name' => 'Name',
                'data_path' => 'Organisation.name',
            ],
            'uuid' => [
                'name' => 'UUID',
                'data_path' => 'Organisation.uuid',
            ],
            'sector' => [
                'name' => 'Sector',
                'data_path' => 'Organisation.sector',
            ],
            'nationality' => [
                'name' => 'Nationality',
                'data_path' => 'Organisation.nationality',
            ],
            'type' => [
                'name' => 'Type',
                'data_path' => 'Organisation.type',
            ]
        ];
        $params = [
            'conditions' => [
                'AND' => ['Organisation.local' => !isset($options['local']) ? 1 : $options['local']]
            ],
            'limit' => 10,
            'recursive' => -1
        ];
        if (!empty($options['filter']) && is_array($options['filter'])) {
            foreach ($this->validFilterKeys as $filterKey) {
                if (!empty($options['filter'][$filterKey])) {
                    if (!is_array($options['filter'][$filterKey])) {
                        $options['filter'][$filterKey] = [$options['filter'][$filterKey]];
                    }
                    $tempConditionBucket = [];
                    foreach ($options['filter'][$filterKey] as $value) {
                        if ($value[0] === '!') {
                            $tempConditionBucket['Organisation.' . $filterKey . ' NOT IN'][] = mb_substr($value, 1);
                        } else {
                            $tempConditionBucket['Organisation.' . $filterKey . ' IN'][] = $value;
                        }
                    }
                    if (!empty($tempConditionBucket)) {
                        $params['conditions']['AND'][] = $tempConditionBucket;
                    }
                }
            }
        }
        $timeConditions = $this->timeConditions($options);
        if ($timeConditions) {
            $params['conditions']['AND'][] = ['Organisation.date_created >=' => $timeConditions];
        }
        if (isset($options['fields'])) {
            $fields = [];
            foreach ($options['fields'] as $field) {
                if (isset($field_options[$field])) {
                    $fields[$field] = $field_options[$field];
                }
            }
        } else {
            $fields = $field_options;
        }
        $data = $this->Organisation->find('all', [
            'recursive' => -1,
            'conditions' => $params['conditions'],
            'limit' => isset($options['limit']) ? (int)$options['limit'] : 10,
            'fields' => array_keys($fields),
            'order' => 'Organisation.date_created DESC'
        ]);

        return [
            'data' => $data,
            'fields' => $fields,
            'description' => $this->tableDescription
        ];
    }
}
