<?php

function getUserIP()
{
    // Get real visitor IP behind CloudFlare network
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        $_SERVER['HTTP_CLIENT_IP'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    $client  = @$_SERVER['HTTP_CLIENT_IP'];
    $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
    $remote  = $_SERVER['REMOTE_ADDR'];

    if (filter_var($client, FILTER_VALIDATE_IP)) {
        $ip = $client;
    } elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
        $ip = $forward;
    } else {
        $ip = $remote;
    }

    return $ip;
}

class VoteController extends VoteAppController {

    public function index()
    {
        $this->set('title_for_layout', $this->Lang->get('VOTE__TITLE'));
        $this->set('config', $this->__getConfig());

        $this->loadModel('Vote.Website');
        $this->loadModel('Vote.Reward');
        $this->loadModel('Server');
        $websites = $this->Website->find('all');
        $servers = $this->Server->findSelectableServers();
        $rewards = $this->Reward->find('all');
        $websitesByServers = [];
        foreach ($websites as $website) {
            if (!isset($servers[$website['Website']['server_id']]))
                continue;
            if (!isset($websitesByServers[$servers[$website['Website']['server_id']]]))
                $websitesByServers[$servers[$website['Website']['server_id']]] = [];
            $websitesByServers[$servers[$website['Website']['server_id']]][] = $website;
        }
        $this->loadModel('User');
        $this->loadModel('Vote.VoteConfiguration');
        $get_config = $this->VoteConfiguration->find('first');
        $group = "username";
        if($get_config['VoteConfiguration']['need_register'])
            $group = "user_id";
        $this->set('users', array_map(function ($row) {
            return ['username' => (isset($row['Vote']['user_id'])) ? $this->User->getUsernameByID($row['Vote']['user_id']) : $row['Vote']['username'], 'count' => $row[0]['count']];
        }, $this->Vote->find('all', [
            'fields' => [$group, 'COUNT(id) AS count'],
            'conditions' => [
                'created LIKE' =>  date('Y') . '-' . date('m') . '-%',
                'Vote.deleted_at' => null
            ],
            'order' => 'count DESC',
            'group' => $group,
            'limit' => 15
        ])));
        $user_info = [];
        if ($this->User->isConnected()) {
            $users_info = $this->Vote->find('all', [
                'fields' => ['user_id', 'COUNT(*) AS count'],
                'conditions' => [
                    'created LIKE' => date('Y') . '-' . date('m') . '-%',
                    'Vote.deleted_at' => null,
                ],
                'order' => 'count DESC',
                'group' => 'user_id',
            ]);
            $i = 0;
            foreach ($users_info as $v) {
                $i++;
                if ($this->User->getKey('id') == $v['Vote']['user_id']) {
                    $user_info = $v;
                    $user_info['username'] = $this->User->getUsernameByID($v['Vote']['user_id']);
                    $user_info['place'] = "#" . $i;
                }
            }
            $user_info['days_number'] = $this->Vote->find('count', [
                'conditions' => [
                    'created LIKE' => date('Y') . '-' . date('m') . '-' . date('d') . '%',
                    'Vote.user_id' => $this->User->getKey('id'),
                    'Vote.deleted_at' => null,
                ],
            ]);
        }
        $this->set(compact('websitesByServers', 'user_info', 'rewards'));
    }

    public function setUser()
    {
        $this->Session->delete('voted');
        if (!$this->request->is('post'))
            throw new NotFoundException();
        if ((empty($this->request->data) || !isset($this->request->data['username'])) && !$this->User->isConnected())
            throw new BadRequestException();
        $this->autoRender = false;
        $this->response->type('json');
        if (empty($this->request->data['username']) && !$this->User->isConnected())
            return $this->sendJSON(['statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')]);

        if ($this->User->isConnected()) { // If already logged
            $user = ['username' => $this->User->getKey('pseudo'), 'id' => $this->User->getKey('id')];
        } else if ($this->__getConfig()->need_register) { // If need register, check if username is valid
            $searchUser = $this->User->find('first', ['fields' => ['pseudo', 'id'], 'conditions' => ['pseudo' => $this->request->data['username']]]);
            if (empty($searchUser))
                return $this->sendJSON(['statut' => false, 'msg' => $this->Lang->get('VOTE__SET_USER_ERROR_USER_NOT_FOUND')]);
            $user = ['username' => $searchUser['User']['pseudo'], 'id' => $searchUser['User']['id']];
        } else {
            $searchUser = $this->User->find('first', ['fields' => ['id'], 'conditions' => ['pseudo' => $this->request->data['username']]]);
            if (empty($searchUser))
                $user = ['username' => $this->request->data['username']];
            else
                $user = ['username' => $this->request->data['username'], 'id' => $searchUser['User']['id']];
        }

        // Store it
        $this->Session->write('vote.user', $user);
        $this->sendJSON(['statut' => true, 'msg' => $this->Lang->get('VOTE__SET_USER_SUCCESS')]);
    }

    public function setWebsite()
    {
        $this->Session->delete('voted');
        if (!$this->request->is('post'))
            throw new NotFoundException();
        if (empty($this->request->data) || empty($this->request->data['website_id']))
            throw new BadRequestException();
        $this->autoRender = false;
        $this->response->type('json');

        // Check if user is stored
        if (!$this->Session->check('vote.user.username'))
            throw new ForbiddenException();

        // Find website
        $this->loadModel('Vote.Website');
        $website = $this->Website->find('first', ['conditions' => ['id' => $this->request->data['website_id']]]);
        if (empty($website))
            throw new NotFoundException();

        // Check if has already vote and if time isn't enough
        $this->loadModel('Vote.Vote');
        if (!$this->Vote->can($this->Session->read('vote.user'), getUserIP(), $website['Website']))
            return $this->sendJSON(['status' => false, 'error' => $this->Lang->get('VOTE__SET_WEBSITE_ERROR_NEED_WAIT', ['{TIME}' => $this->Util->generateStringFromTime($this->Vote->getNextVoteTime($this->Session->read('vote.user'), getUserIP(), $website['Website']))])]);

        // Store it
        $this->Session->write('vote.website.id', $this->request->data['website_id']);
        $this->sendJSON(['status' => true, 'success' => $this->Lang->get('VOTE__SET_WEBSITE_SUCCESS'), 'data' => ['website' => ['url' => $website['Website']['url']]]]);
    }

    public function checkVote()
    {
        $this->Session->delete('voted');
        if (!$this->request->is('ajax'))
            throw new NotFoundException();
        $this->autoRender = false;
        $this->response->type('json');

        // Check if user is stored
        if (!$this->Session->check('vote.user.username'))
            throw new ForbiddenException();
        // Check if website is stored
        if (!$this->Session->check('vote.website.id'))
            throw new ForbiddenException();

        // Check if website type need verification
        $this->loadModel('Vote.Website');
        $website = $this->Website->find('first', ['conditions' => ['id' => $this->Session->read('vote.website.id')]]);
        if (empty($website))
            throw new NotFoundException();
        $this->loadModel('Vote.Vote');
        if (!$this->Website->valid($this->Session->read('vote.user'), getUserIP(), $website['Website']))
            return $this->sendJSON(['status' => false]);

        // Store it
        $this->Session->write('vote.check', true);
        $this->sendJSON(['status' => true, 'success' => $this->Lang->get('VOTE__VOTE_SUCCESS'), 'reward_later' => $this->Session->check('vote.user.id')]);
    }

    public function getReward()
    {
        if (!$this->request->is('ajax'))
            throw new NotFoundException();
        if (empty($this->request->data) || empty($this->request->data['reward_time']) || !in_array($this->request->data['reward_time'], ['NOW', 'LATER']))
            throw new BadRequestException();
        if ($this->request->data['reward_time'] === 'LATER' && !$this->Session->check('vote.user.id'))
            throw new BadRequestException();
        $this->autoRender = false;
        $this->response->type('json');

        if ($this->Session->check('voted')) { // Fail
            // Get last vote not collected
            $findVote = $this->Vote->find('first', ['conditions' => ['collected' => 0, 'vote.ip' => getUserIP()], 'recursive' => 1]);
            if (empty($findVote))
                throw new ForbiddenException();
            $this->loadModel('Vote.Reward');
            if (!$this->Reward->collect($findVote['Reward'], $findVote['Website']['server_id'], $this->Session->read('voted'), $this->Server, [$this->__getConfig()->global_command]))
                return $this->sendJSON(['status' => false, 'error' => $this->Lang->get('VOTE__GET_REWARDS_NOW_ERROR_RETRY')]);

            $this->Vote->read(null, $findVote['Vote']['id']);
            $this->Vote->set(['collected' => 1]);
            $this->Vote->save();

            $this->Session->delete('voted');
            return $this->sendJSON(['status' => true, 'success' => $this->Lang->get('VOTE__GET_REWARDS_NOW_SUCCESS')]);
        }

        // Check if user is stored
        if (!$this->Session->check('vote.user.username'))
            throw new ForbiddenException();
        // Check if website is stored
        if (!$this->Session->check('vote.website.id'))
            throw new ForbiddenException();
        // Check if vote is stored
        if (!$this->Session->check('vote.check'))
            throw new ForbiddenException();

        // Get website
        $this->loadModel('Vote.Website');
        $website = $this->Website->find('first', ['conditions' => ['id' => $this->Session->read('vote.website.id')]]);
        if (empty($website))
            throw new NotFoundException();

        // Check if user can vote
        $this->loadModel('Vote.Vote');
        if (!$this->Vote->can($this->Session->read('vote.user'), getUserIP(), $website['Website']))
            return $this->sendJSON(['status' => false, 'error' => $this->Lang->get('VOTE__SET_WEBSITE_ERROR_NEED_WAIT', ['{TIME}' => $this->Util->generateStringFromTime($this->Vote->getNextVoteTime($this->Session->read('vote.user'), getUserIP(), $website['Website']))])]);

        // Generate random reward
        $this->loadModel('Vote.Reward');
        $reward = $this->Reward->getFromWebsite($website['Website']);

        // Store it
        $user = $this->Session->read('vote.user');
        $this->Vote->create();
        $this->Vote->set([
            'username' => $user['username'],
            'user_id' => (isset($user['id'])) ? $user['id'] : null,
            'reward_id' => $reward['id'],
            'collected' => 0,
            'website_id' => $website['Website']['id'],
            'ip' => getUserIP()
        ]);
        $this->Vote->save();

        // Destroy session
        $this->Session->delete('vote');
        $this->Session->delete('voted');

        // If he want reward now, try to give it
        if ($this->request->data['reward_time'] === 'LATER')
            return $this->sendJSON(['status' => true, 'success' => $this->Lang->get('VOTE__GET_REWARDS_LATER_SUCCESS')]);
        // Try to collect
        if (!($collect = $this->Reward->collect($reward, $website['Website']['server_id'], $user['username'], $this->Server, [$this->__getConfig()->global_command])) && isset($user['id']))
            return $this->sendJSON(['status' => true, 'success' => $this->Lang->get('VOTE__GET_REWARDS_NOW_ERROR')]);
        else if (!$collect && !isset($user['id'])) {
            $this->Session->write('voted', $user['username']);
            return $this->sendJSON(['status' => false, 'error' => $this->Lang->get('VOTE__GET_REWARDS_NOW_ERROR_RETRY')]);
        }

        // money
        if ($reward['amount'] > 0 && isset($user['id']))
            $this->User->setToUser('money', (floatval($this->User->getFromUser('money', $user['id'])) + floatval($reward['amount'])), $user['id']);

        // Set as collected
        $this->Vote->read(null, $this->Vote->getLastInsertId());
        $this->Vote->set(['collected' => 1]);
        $this->Vote->save();

        // Success message
        $this->sendJSON(['status' => true, 'success' => $this->Lang->get('VOTE__GET_REWARDS_NOW_SUCCESS')]);
    }

    public function getNotCollectedReward()
    {
        if (!$this->Permissions->can('VOTE__COLLECT_REWARD'))
            throw new ForbiddenException();
        $this->loadModel('Vote.Vote');
        $this->loadModel('Vote.Reward');
        $this->loadModel('Vote.Website');

        $limit = $this->__getConfig();
        if(!isset($limit->limit_get_not_collected) || !$limit->limit_get_not_collected)
            $limit = -1;

        $votesList = $this->Vote->find('all', [
            'conditions' => [
                'user_id' => $this->User->getKey('id'),
                'collected' => 0
            ],
            'recursive' => 1,
            'limit' => $limit
        ]);
        // Set as collected
        $this->Vote->updateAll(
            ['Vote.collected' => 1],
            ['Vote.id' =>
                array_map(function ($vote) {
                    return $vote['Vote']['id'];
                }, $votesList)
            ]
        );
        if (empty($votesList) || $this->Vote->getAffectedRows() == 0)
            throw new NotFoundException();
        // Give it
        $collectedVotesByServer = [];
        $collectedError = [];
        $old_money = $money = floatval($this->User->getKey('money'));
        foreach ($votesList as $vote) {
            $reward = $vote['Reward'];
            if (!$this->Reward->collect($reward, $vote['Website']['server_id'], $this->User->getKey('pseudo'), $this->Server)) {
                array_push($collectedError, $vote['Vote']['id']);
                continue;
            }
            $server_id = $vote['Website']['server_id'];
            if (!$collectedVotesByServer[$server_id])
                $collectedVotesByServer[$server_id] = [];
            $collectedVotesByServer[$server_id][] = $vote;
            // Add money
            // It seams to bugs when a player try to retrieve his reward and there is at least two money reward so we update money later
            if ($reward['amount'] > 0)
                $money += floatval($reward['amount']);
        }
        // Update money here (Also prevent a lot of database edit)
        if (floatval($old_money) != floatval($money))
            $this->User->setKey('money', $money);

        foreach ($collectedVotesByServer as $server_id => $votes) {
            $command = str_replace('{REWARD_NUMBER}', count($votes), $this->__getConfig()->global_command_plural);
            if (count($votes) === 1) {
                $command = str_replace('{REWARD_NAME}', $votes[0]['Reward']['name'], $this->__getConfig()->global_command);
            }
            $this->Server->commands($command, $server_id);

        }

        // Set as no-collected if error
        $this->Vote->updateAll(
            ['Vote.collected' => 0],
            ['Vote.id' => $collectedError]
        );


        // Redirect
        if (count($collectedError) > 0)
            $this->Session->setFlash($this->Lang->get('VOTE__COLLECT_REWARD_ERROR'), 'default.error');
        else
            $this->Session->setFlash($this->Lang->get('VOTE__COLLECT_REWARD_SUCCESS'), 'default.success');
        $this->redirect($this->referer());
    }

    private function __getConfig()
    {
        $this->loadModel('Vote.VoteConfiguration');
        return (object)$this->VoteConfiguration->getConfig();
    }

    public function admin_configuration()
    {
        if (!$this->Permissions->can('VOTE__ADMIN_MANAGE'))
            throw new ForbiddenException();
        $this->set('title_for_layout', $this->Lang->get('VOTE__ADMIN_VOTE_CONFIGURATION_TITLE'));
        $this->loadModel('Vote.VoteConfiguration');
        $this->set('configuration', $this->VoteConfiguration->getConfig());
        $this->layout = 'admin';

        if ($this->request->is('ajax')) {
            $this->autoRender = false;
            $this->response->type('json');
            if (!isset($this->request->data['need_register']) || !isset($this->request->data['global_command']) || !isset($this->request->data['global_command_plural']))
                return $this->sendJSON(['statut' => false, 'msg' => $this->Lang->get('ERROR__FILL_ALL_FIELDS')]);
            $this->VoteConfiguration->read(null, 1);
            $this->VoteConfiguration->set([
                'need_register' => $this->request->data['need_register'],
                'global_command' => $this->request->data['global_command'],
                'global_command_plural' => $this->request->data['global_command_plural'],
                'limit_get_not_collected' => $this->request->data['limit_get_not_collected']
            ]);
            $this->VoteConfiguration->save();

            $this->History->set('EDIT_VOTE_CONFIGURATION', 'vote');
            return $this->sendJSON(['statut' => true, 'msg' => $this->Lang->get('VOTE__ADMIN_EDIT_CONFIG_SUCCESS')]);
        }
    }

    public function apiCheck()
    {
        $this->loadModel('Vote.Vote');
        $this->loadModel('Vote.Website');
        $username = $this->request->params['username'];
        return $this->sendJSON([
            'status' => true,
            'can_vote' => $this->Vote->canInAll(['username' => $username], '0.0.0.0', $this->Website->find('all'))
        ]);
    }
}
