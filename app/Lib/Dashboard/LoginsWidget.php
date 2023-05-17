<?php

class LoginsWidget
{
    public $title = 'Logins';
    public $render = 'SimpleList';
    public $width = 2;
    public $height = 2;
    public $params = [
        'filter' => 'A list of filters by organisation meta information (sector, type, nationality, id, uuid) to include. (dictionary, prepending values with ! uses them as a negation)',
        'limit' => 'Limits the number of displayed APIkeys. (-1 will list all) Default: -1',
        'days' => 'How many days back should the list go - for example, setting 7 will only show contributions in the past 7 days. (integer)',
        'month' => 'Who contributed most this month? (boolean)',
        'year' => 'Which contributed most this year? (boolean)',
    ];
    public $description = 'Basic widget showing some server statistics in regards to MISP.';
    public $cacheLifetime = 10;
    public $autoRefreshDelay = null;
    private $User = null;
    private $Log = null;


    private function getDates($options)
    {
        if (!empty($options['days'])) {
            $begin = date('Y-m-d H:i:s', strtotime(sprintf("-%s days", $options['days'])));
        } else if (!empty($options['month'])) {
            $begin = date('Y-m-d H:i:s', strtotime('first day of this month 00:00:00', time()));
        } else if (!empty($options['year'])) {
            $begin = date('Y-m-d', strtotime('first day of this year 00:00:00', time()));
        } else {
            $begin = date('Y-m-d H:i:s', strtotime('-7 days', time()));
        }
        return $begin ? ['Log.created >=' => $begin] : [];
    }

	public function handler($user, $options = array())
	{
        $this->User = ClassRegistry::init('User');
        $this->Log = ClassRegistry::init('Log');
        $conditions = $this->getDates($options);
        $conditions['Log.action'] = 'login';
        $this->Log->Behaviors->load('Containable');
        $this->Log->bindModel([
            'belongsTo' => [
                'User'
            ]
        ]);
        $this->Log->virtualFields['count'] = 0;
        $this->Log->virtualFields['email'] = '';
        $logs = $this->Log->find('all', [
            'recursive' => -1,
            'conditions' => $conditions,
            'fields' => ['Log.user_id', 'COUNT(Log.id) AS Log__count', 'User.email AS Log__email'],
            'contain' => ['User'],
            'group' => ['Log.user_id']
        ]);
        $counts = [];
        $emails = [];
        foreach ($logs as $log) {
            $counts[$log['Log']['user_id']] = $log['Log']['count'];
            $emails[$log['Log']['user_id']] = $log['Log']['email'];
        }
        $results = [];
        arsort($counts);
        $baseurl = empty(Configure::read('MISP.external_baseurl')) ? h(Configure::read('MISP.baseurl')) : Configure::read('MISP.external_baseurl');
        foreach ($counts as $user_id => $count) {
            $results[] = [
                'html_title' => sprintf(
                    '<a href="%s/admin/users/view/%s">%s</a>',
                    h($baseurl),
                    h($user_id),
                    h($emails[$user_id])
                ),
                'value' => $count
            ];
        }
        return $results;
	}

    public function checkPermissions($user)
    {
        if (empty($user['Role']['perm_site_admin'])) {
            return false;
        }
        return true;
    }
}
