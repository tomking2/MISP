<?php
App::uses('AppController', 'Controller');

/**
 * @property News $News
 */
class NewsController extends AppController
{
    public $components = array('Session', 'RequestHandler');

    public $paginate = array(
        'limit' => 5,
        'maxLimit' => 9999, // LATER we will bump here on a problem once we have more than 9999 events <- no we won't, this is the max a user van view/page.
        'order' => [
            'News.id' => 'DESC'
        ],
        'contain' => [
            'User' => ['fields' => ['User.email']],
        ]
    );

    public function index()
    {
        $user = $this->Auth->user();
        $newsItems = $this->paginate();

        $newsread = $user['newsread'];
        $hasUnreadNews = false;
        foreach ($newsItems as &$item) {
            $isNew = $item['News']['date_created'] > $newsread;
            $item['News']['new'] = $isNew;
            if ($isNew) {
                $hasUnreadNews = true;
            }
        }
        $this->set('newsItems', $newsItems);
        $this->set('hasUnreadNews', $hasUnreadNews);

        if ($hasUnreadNews) {
            $homepage = $this->User->UserSetting->getValueForUser($user['id'], 'homepage');
            if (!empty($homepage)) {
                $this->set('homepage', $homepage);
            } else {
                $this->set('homepage', "{$this->baseurl}/events/index");
            }

            $this->User->updateField($user, 'newsread', time());
        }
    }

    public function admin_index()
    {
        $user = $this->Auth->user();
        $this->paginate['limit'] = 25;
        $newsItems = $this->paginate();

        $this->set('newsItems', $newsItems);
        $this->set('user', $user);
    }

    public function add()
    {
        if ($this->request->is('post')) {
            $this->News->create();
            $this->request->data['News']['date_created'] = time();
            if (!isset($this->request->data['News']['anonymise']) || !$this->request->data['News']['anonymise']) {
                $this->request->data['News']['user_id'] = $this->Auth->user('id');
            } else {
                $this->request->data['News']['user_id'] = 0;
            }
            if ($this->News->save($this->request->data)) {
                $this->Flash->success(__('News item added.'));
                $this->redirect(array('action' => 'index'));
            } else {
                $this->Flash->error(__('The news item could not be added.'));
            }
        }
    }

    public function edit($id)
    {
        $this->News->id = $id;
        if (!$this->News->exists()) {
            throw new NotFoundException('Invalid news item.');
        }
        if ($this->request->is('post') || $this->request->is('put')) {
            $this->request->data['News']['id'] = $id;
            if ($this->News->save($this->request->data)) {
                $this->Flash->success(__('News item updated.'));
                $this->redirect(array('action' => 'index'));
            } else {
                $this->Flash->error(__('Could not update news item.'));
            }
        } else {
            $this->request->data = $this->News->read(null, $id);
            $this->set('newsItem', $this->request->data);
        }
        $this->render('add');
    }

    public function delete($id)
    {
        $this->CRUD->delete($id);
        if ($this->IndexFilter->isRest()) {
            return $this->restResponsePayload;
        }
    }
}
