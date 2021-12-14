<?php

/**
 * @property User $User
 * @property Log $Log
 */
class UserShell extends AppShell
{
    public $uses = ['User', 'Log'];

    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $parser->addSubcommand('list', [
            'help' => __('Get list of user accounts.'),
            'parser' => [
                'options' => [
                    'json' => ['help' => __('Output as JSON.'), 'boolean' => true],
                ],
            ]
        ]);
        $parser->addSubcommand('block', [
            'help' => __('Immediately block user.'),
            'parser' => [
                'arguments' => [
                    'userId' => ['help' => __('User ID or e-mail address.'), 'required' => true],
                ],
            ],
        ]);
        $parser->addSubcommand('unblock', [
            'help' => __('Unblock blocked user.'),
            'parser' => [
                'arguments' => [
                    'userId' => ['help' => __('User ID or e-mail address.'), 'required' => true],
                ],
            ],
        ]);
        $parser->addSubcommand('change_pw', [
            'help' => __('Change user password.'),
            'parser' => [
                'arguments' => [
                    'userId' => ['help' => __('User ID or e-mail address.'), 'required' => true],
                    'password' => ['help' => __('New user password.'), 'required' => true],
                ],
                'options' => [
                    'no_password_change' => ['help' => __('Do not require password change.'), 'boolean' => true],
                ],
            ],
        ]);
        $parser->addSubcommand('change_authkey', [
            'help' => __('Change authkey. When advanced authkeys are enabled, old authkeys will be disabled.'),
            'parser' => [
                'arguments' => [
                    'userId' => ['help' => __('User ID or e-mail address.'), 'required' => true],
                ],
            ],
        ]);
        $parser->addSubcommand('user_ips', [
            'help' => __('Show IP addresses that user uses to access MISP.'),
            'parser' => [
                'arguments' => [
                    'userId' => ['help' => __('User ID or e-mail address.'), 'required' => true],
                ],
                'options' => [
                    'json' => ['help' => __('Output as JSON.'), 'boolean' => true],
                ],
            ],
        ]);
        $parser->addSubcommand('ip_user', [
            'help' => __('Get user ID for user IP. If multiple users use the same IP, only last user ID will be returned.'),
            'parser' => [
                'arguments' => [
                    'ip' => ['help' => __('IPv4 or IPv6 address.'), 'required' => true],
                ],
                'options' => [
                    'json' => ['help' => __('Output as JSON.'), 'boolean' => true],
                ],
            ],
        ]);
        return $parser;
    }

    public function list()
    {
        if ($this->params['json']) {
            // do not fetch sensitive or big values
            $schema = $this->User->schema();
            unset($schema['authkey']);
            unset($schema['password']);
            unset($schema['gpgkey']);
            unset($schema['certif_public']);

            $fields = array_keys($schema);
            $fields[] = 'Role.*';
            $fields[] = 'Organisation.*';

            $users = $this->User->find('all', [
                'recursive' => -1,
                'fields' => $fields,
                'contain' => ['Organisation', 'Role', 'UserSetting'],
            ]);

            $this->out($this->json($users));
        } else {
            $users = $this->User->find('column', [
                'fields' => ['email'],
            ]);
            foreach ($users as $user) {
                $this->out($user);
            }
        }
    }

    public function block()
    {
        list($userId) = $this->args;
        $user = $this->getUser($userId);
        if ($user['disabled']) {
            $this->error("User $userId is already blocked.");
        }
        $this->User->updateField($user, 'disabled', true);
        $this->out("User $userId blocked.");
    }

    public function unblock()
    {
        list($userId) = $this->args;
        $user = $this->getUser($userId);
        if (!$user['disabled']) {
            $this->error("User $userId is not blocked.");
        }
        $this->User->updateField($user, 'disabled', false);
        $this->out("User $userId unblocked.");
    }

    public function change_pw()
    {
        list($userId, $newPassword) = $this->args;
        $user = $this->getUser($userId);

        $user['password'] = $newPassword;
        $user['confirm_password'] = $newPassword;
        $user['change_pw'] = !$this->params['no_password_change'];

        if (!$this->User->save($user)) {
            $this->out("Could not update password for user $userId.");
            $this->out($this->json($this->User->validationErrors));
            $this->_stop(self::CODE_ERROR);
        }

        $this->out("Password for $userId changed.");
    }

    public function change_authkey()
    {
        list($userId) = $this->args;
        $user = $this->getUser($userId);

        if (empty(Configure::read('Security.advanced_authkeys'))) {
            $oldKey = $user['authkey'];
            $newkey = $this->User->generateAuthKey();
            $this->User->updateField($user, 'authkey', $newkey);
            $this->Log->createLogEntry('SYSTEM', 'reset_auth_key', 'User', $user['id'],
                __('Authentication key for user %s (%s) updated.', $user['id'], $user['email']),
                ['authkey' =>  [$oldKey, $newkey]]
            );
            $this->out("Authentication key changed to: $newkey");
        } else {
            $newkey = $this->User->AuthKey->resetAuthKey($user['id']);
            if ($newkey) {
                $this->out("Old authentication keys disabled and new key created: $newkey");
            } else {
                $this->error('There is problem with changing auth key.');
            }
        }
    }

    public function user_ips()
    {
        list($userId) = $this->args;
        $user = $this->getUser($userId);

        if (empty(Configure::read('MISP.log_user_ips'))) {
            $this->out('<warning>Storing user IP addresses is disabled.</warning>');
        }

        $ips = $this->User->setupRedisWithException()->smembers('misp:user_ip:' . $user['id']);

        if ($this->params['json']) {
            $this->out($this->json($ips));
        } else {
            $this->hr();
            $this->out("User #{$user['id']}: {$user['email']}");
            $this->hr();
            $this->out(implode(PHP_EOL, $ips));
        }
    }

    public function ip_user()
    {
        list($ip) = $this->args;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error("IP `$ip` is not valid IPv4 or IPv6 address");
        }

        if (empty(Configure::read('MISP.log_user_ips'))) {
            $this->out('<warning>Storing user IP addresses is disabled.</warning>');
        }

        $userId = $this->User->setupRedisWithException()->get('misp:ip_user:' . $ip);
        if (empty($userId)) {
            $this->out('No hits.');
            $this->_stop();
        }

        $user = $this->User->find('first', array(
            'recursive' => -1,
            'conditions' => array('User.id' => $userId),
            'fields' => ['id', 'email'],
        ));

        if (empty($user)) {
            $this->error("User with ID $userId doesn't exists anymore.");
        }

        if ($this->params['json']) {
            $this->out($this->json([
                'ip' => $ip,
                'id' => $user['User']['id'],
                'email' => $user['User']['email'],
            ]));
        } else {
            $this->out(sprintf(
                '%s==============================%sIP: %s%s==============================%sUser #%s: %s%s==============================%s',
                PHP_EOL, PHP_EOL, $ip, PHP_EOL, PHP_EOL, $user['User']['id'], $user['User']['email'], PHP_EOL, PHP_EOL
            ));
        }
    }

    /**
     * @param string|int $userId
     * @return array
     */
    private function getUser($userId)
    {
        // Do not fetch password from database
        $schema = $this->User->schema();
        unset($schema['password']);

        $conditions = is_numeric($userId) ? ['User.id' => $userId] : ['User.email' => $userId];
        $user = $this->User->find('first', [
            'conditions' => $conditions,
            'recursive' => -1,
            'fields' => array_keys($schema),
        ]);
        if (empty($user)) {
            $this->error("User `$userId` not found.");
        }
        return $user['User'];
    }
}
