<?php
class OrgContributionToplistWidget
{
    public $title = 'Contributor Top List (Orgs)';
    public $render = 'BarChart';
    public $description = 'The top contributors (orgs) in a selected time frame.';
    public $width = 3;
    public $height = 4;
    public $params = [
        'days' => 'How many days back should the list go - for example, setting 7 will only show contributions in the past 7 days. (integer)',
        'month' => 'Who contributed most this month? (boolean)',
        'year' => 'Which contributed most this year? (boolean)',
        'filter' => 'A list of filters by organisation meta information (nationality, sector, type, name, uuid, local (- expects a boolean or a list of boolean values)) to include. (dictionary, prepending values with ! uses them as a negation)',
        'limit' => 'Limits the number of displayed tags. Default: 10'
    ];
    public $cacheLifetime = null;
    public $autoRefreshDelay = false;
    private $validFilterKeys = [
        'nationality',
        'sector',
        'type',
        'name',
        'uuid'
    ];
    public $placeholder =
'{
    "days": "7d",
    "threshold": 15,
    "filter": {
        "sector": "Financial"
    }
}';
    private $Org = null;
    private $Event = null;


    private function timeConditions($options)
    {
        $limit = empty($options['limit']) ? 10 : $options['limit'];
        if (!empty($options['days'])) {
            $condition = strtotime(sprintf("-%s days", $options['days']));
        } else if (!empty($options['month'])) {
            $condition = strtotime('first day of this month 00:00:00', time());
        } else if (!empty($options['year'])) {
            $condition = strtotime('first day of this year 00:00:00', time());
        } else {
            return null;
        }
        return $condition;
    }


    public function handler($user, $options = array())
    {
        $params = ['conditions' => []];
        $timeConditions = $this->timeConditions($options);
        if ($timeConditions) {
            $params['conditions']['AND'][] = ['Event.timestamp >=' => $timeConditions];
        }
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
        if (isset($options['filter']['local'])) {
            $params['conditions']['AND']['local'] = $options['filter']['local'];
        }

        $this->Org = ClassRegistry::init('Organisation');
        $org_ids = $this->Org->find('list', [
            'fields' => ['Organisation.id', 'Organisation.name'],
            'conditions' => $params['conditions']
        ]);
        $conditions = ['Event.orgc_id IN' => array_keys($org_ids)];
        $this->Event = ClassRegistry::init('Event');
        $this->Event->virtualFields['frequency'] = 0;
        $orgs = $this->Event->find('all', [
            'recursive' => -1,
            'fields' => ['orgc_id', 'count(Event.orgc_id) as Event__frequency'],
            'group' => ['orgc_id'],
            'conditions' => $conditions,
            'order' => 'count(Event.orgc_id) desc',
            'limit' => empty($options['limit']) ? 10 : $options['limit']
        ]);
        $results = [];
        foreach($orgs as $org) {
            $results[$org_ids[$org['Event']['orgc_id']]] = $org['Event']['frequency'];
        }
        return ['data' => $results];
    }
}
?>
