<?php
App::uses('AppController', 'Controller');

/**
 * @property Taxonomy $Taxonomy
 */
class TaxonomiesController extends AppController
{
    public $components = array('Session', 'RequestHandler');

    public $paginate = array(
        'limit' => 60,
        'maxLimit' => 9999, // LATER we will bump here on a problem once we have more than 9999 events <- no we won't, this is the max a user van view/page.
        'contain' => array(
            'TaxonomyPredicate' => array(
                'fields' => array('TaxonomyPredicate.id', 'TaxonomyPredicate.value'),
                'TaxonomyEntry' => array('fields' => array('TaxonomyEntry.id', 'TaxonomyEntry.value'))
            )
        ),
        'order' => array(
                'Taxonomy.id' => 'DESC'
        ),
    );

    public function index()
    {
        $this->paginate['recursive'] = -1;

        if (!empty($this->passedArgs['value'])) {
            $this->paginate['conditions']['id'] = $this->__search($this->passedArgs['value']);
        }

        if (isset($this->passedArgs['enabled'])) {
            $this->paginate['conditions']['enabled'] = $this->passedArgs['enabled'] ? 1 : 0;
        }

        if ($this->_isRest()) {
            $keepFields = array('conditions', 'contain', 'recursive', 'sort');
            $searchParams = array();
            foreach ($keepFields as $field) {
                if (!empty($this->paginate[$field])) {
                    $searchParams[$field] = $this->paginate[$field];
                }
            }
            $taxonomies = $this->Taxonomy->find('all', $searchParams);
        } else {
            $taxonomies = $this->paginate();
        }

        $taxonomies = $this->__tagCount($taxonomies);

        if ($this->_isRest()) {
            return $this->RestResponse->viewData($taxonomies, $this->response->type());
        }

        $this->set('taxonomies', $taxonomies);
        $this->set('passedArgsArray', $this->passedArgs);
    }

    public function view($id)
    {
        $taxonomy = $this->Taxonomy->getTaxonomy($id, ['full' => $this->_isRest()]);
        if (empty($taxonomy)) {
            throw new NotFoundException(__('Taxonomy not found.'));
        }

        if ($this->_isRest()) {
            return $this->RestResponse->viewData($taxonomy, $this->response->type());
        }

        $this->set('taxonomy', $taxonomy['Taxonomy']);
        $this->set('id', $taxonomy['Taxonomy']['id']);
    }

    public function taxonomy_tags($id)
    {
        $urlparams = '';
        App::uses('CustomPaginationTool', 'Tools');
        $filter = isset($this->passedArgs['filter']) ? $this->passedArgs['filter'] : false;
        $options = ['full' => true, 'filter' => $filter];
        if (isset($this->passedArgs['enabled'])) {
            $options['enabled'] = $this->passedArgs['enabled'];
        }
        $taxonomy = $this->Taxonomy->getTaxonomy($id, $options);
        if (empty($taxonomy)) {
            throw new NotFoundException(__('Taxonomy not found.'));
        }
        $this->loadModel('EventTag');
        $this->loadModel('AttributeTag');

        $tagIds = array_column(array_column(array_column($taxonomy['entries'], 'existing_tag'), 'Tag'), 'id');
        $eventCount = $this->EventTag->countForTags($tagIds, $this->Auth->user());
        $attributeTags = $this->AttributeTag->countForTags($tagIds, $this->Auth->user());

        foreach ($taxonomy['entries'] as $key => $value) {
            $count = 0;
            $count_a = 0;
            if (!empty($value['existing_tag'])) {
                $tagId = $value['existing_tag']['Tag']['id'];
                $count = isset($eventCount[$tagId]) ? $eventCount[$tagId] : 0;
                $count_a = isset($attributeTags[$tagId]) ? $attributeTags[$tagId] : 0;
            }
            $taxonomy['entries'][$key]['events'] = $count;
            $taxonomy['entries'][$key]['attributes'] = $count_a;
        }
        $this->set('filter', $filter);
        $customPagination = new CustomPaginationTool();
        $params = $customPagination->createPaginationRules($taxonomy['entries'], $this->passedArgs, 'TaxonomyEntry');
        if ($params['sort'] == 'id') {
            $params['sort'] = 'tag';
        }
        $this->params->params['paging'] = array($this->modelClass => $params);
        $params = $customPagination->applyRulesOnArray($taxonomy['entries'], $params, 'taxonomies');
        if ($this->_isRest()) {
            return $this->RestResponse->viewData($taxonomy, $this->response->type());
        }

        if (isset($this->passedArgs['pages'])) {
            $currentPage = $this->passedArgs['pages'];
        } else {
            $currentPage = 1;
        }
        $this->set('page', $currentPage);

        $this->set('entries', $taxonomy['entries']);
        $this->set('urlparams', $urlparams);
        $this->set('passedArgs', json_encode($this->passedArgs));
        $this->set('passedArgsArray', $this->passedArgs);
        $this->set('taxonomy', $taxonomy['Taxonomy']);
        $this->set('id', $taxonomy['Taxonomy']['id']);
        $this->set('title_for_layout', __('%s Taxonomy Library', h(strtoupper($taxonomy['Taxonomy']['namespace']))));
        $this->render('ajax/taxonomy_tags');
    }

    public function export($id)
    {
        $taxonomy = $this->Taxonomy->find('first', [
            'recursive' => -1,
            'contain' => ['TaxonomyPredicate' => ['TaxonomyEntry']],
            'conditions' => is_numeric($id) ? ['Taxonomy.id' => $id] : ['LOWER(Taxonomy.namespace)' => mb_strtolower($id)],
        ]);
        if (empty($taxonomy)) {
            throw new NotFoundException(__('Taxonomy not found.'));
        }

        $data = [
            'namespace' => $taxonomy['Taxonomy']['namespace'],
            'description' => $taxonomy['Taxonomy']['description'],
            'version' => (int)$taxonomy['Taxonomy']['version'],
            'exclusive' => $taxonomy['Taxonomy']['exclusive'],
            'predicates' => [],
        ];

        foreach ($taxonomy['TaxonomyPredicate'] as $predicate) {
            $predicateOutput = [];
            foreach (['value', 'expanded', 'colour', 'description', 'exclusive', 'numerical_value'] as $field) {
                if (isset($predicate[$field]) && !empty($predicate[$field])) {
                    $predicateOutput[$field] = $predicate[$field];
                }
            }
            $data['predicates'][] = $predicateOutput;

            if (!empty($predicate['TaxonomyEntry'])) {
                $entries = [];
                foreach ($predicate['TaxonomyEntry'] as $entry) {
                    $entryOutput = [];
                    foreach(['value', 'expanded', 'colour', 'description', 'exclusive', 'numerical_value'] as $field) {
                        if (isset($entry[$field]) && !empty($entry[$field])) {
                            $entryOutput[$field] = $entry[$field];
                        }
                    }
                    $entries[] = $entryOutput;
                }
                $data['values'][] = [
                    'predicate' => $predicate['value'],
                    'entry' => $entries,
                ];
            }
        }

        return $this->RestResponse->viewData($data, 'json');
    }

    public function enable($id)
    {
        $this->request->allowMethod(['post']);

        $taxonomy = $this->Taxonomy->find('first', array(
            'recursive' => -1,
            'conditions' => array('Taxonomy.id' => $id),
        ));
        $taxonomy['Taxonomy']['enabled'] = true;
        $this->Taxonomy->save($taxonomy);

        $this->__log('enable', $id, 'Taxonomy enabled', $taxonomy['Taxonomy']['namespace'] . ' - enabled');

        if ($this->_isRest()) {
            return $this->RestResponse->saveSuccessResponse('Taxonomy', 'enable', $id, $this->response->type());
        } else {
            $this->Flash->success(__('Taxonomy enabled.'));
            $this->redirect($this->referer());
        }
    }

    public function disable($id)
    {
        $this->request->allowMethod(['post']);

        $taxonomy = $this->Taxonomy->find('first', array(
            'recursive' => -1,
            'conditions' => array('Taxonomy.id' => $id),
        ));
        $this->Taxonomy->disableTags($id);
        $taxonomy['Taxonomy']['enabled'] = 0;
        $this->Taxonomy->save($taxonomy);

        $this->__log('disable', $id, 'Taxonomy disabled', $taxonomy['Taxonomy']['namespace'] . ' - disabled');

        if ($this->_isRest()) {
            return $this->RestResponse->saveSuccessResponse('Taxonomy', 'disable', $id, $this->response->type());
        } else {
            $this->Flash->success(__('Taxonomy disabled.'));
            $this->redirect($this->referer());
        }
    }

    public function import()
    {
        $this->request->allowMethod(['post']);

        try {
            $id = $this->Taxonomy->import($this->request->data);
            return $this->view($id);
        } catch (Exception $e) {
            return $this->RestResponse->saveFailResponse('Taxonomy', 'import', false, $e->getMessage());
        }
    }

    public function update()
    {
        $result = $this->Taxonomy->update();
        $fails = 0;
        $successes = 0;
        if (!empty($result)) {
            if (isset($result['success'])) {
                foreach ($result['success'] as $id => $success) {
                    if (isset($success['old'])) {
                        $change = $success['namespace'] . ': updated from v' . $success['old'] . ' to v' . $success['new'];
                    } else {
                        $change = $success['namespace'] . ' v' . $success['new'] . ' installed';
                    }
                    $this->__log('update', $id, 'Taxonomy updated', $change);
                    $successes++;
                }
            }
            if (isset($result['fails'])) {
                foreach ($result['fails'] as $id => $fail) {
                    $this->__log('update', $id, 'Taxonomy failed to update', $fail['namespace'] . ' could not be installed/updated. Error: ' . $fail['fail']);
                    $fails++;
                }
            }
        } else {
            $this->__log('update', 0, 'Taxonomy update (nothing to update)', 'Executed an update of the taxonomy library, but there was nothing to update.');
        }
        if ($successes == 0 && $fails == 0) {
            $flashType = 'info';
            $message = __('All taxonomy libraries are up to date already.');
        } elseif ($successes == 0) {
            $flashType = 'error';
            $message = __('Could not update any of the taxonomy libraries');
        } else {
            $flashType = 'success';
            $message = __('Successfully updated %s taxonomy libraries.', $successes);
            if ($fails != 0) {
                $message .= __(' However, could not update %s taxonomy libraries.', $fails);
            }
        }
        if ($this->_isRest()) {
            return $this->RestResponse->saveSuccessResponse('Taxonomy', 'update', false, $this->response->type(), $message);
        } else {
            $this->Flash->{$flashType}($message);
            $this->redirect(array('controller' => 'taxonomies', 'action' => 'index'));
        }
    }

    public function addTag($taxonomy_id = false)
    {
        if ($this->request->is('get')) {
            if (empty($taxonomy_id) && !empty($this->request->params['named']['taxonomy_id'])) {
                $taxonomy_id = $this->request->params['named']['taxonomy_id'];
            }
            if (
                empty($taxonomy_id) ||
                empty($this->request->params['named']['name'])
            ) {
                throw new MethodNotAllowedException(__('Taxonomy ID or tag name must be provided.'));
            } else {
                $this->request->data['Taxonomy']['taxonomy_id'] = $taxonomy_id;
                $this->request->data['Taxonomy']['name'] = $this->request->params['named']['name'];
            }
        } else {
            if ($taxonomy_id) {
                $result = $this->Taxonomy->addTags($taxonomy_id);
            } else {
                if (isset($this->request->data['Taxonomy'])) {
                    $this->request->data['Tag'] = $this->request->data['Taxonomy'];
                    unset($this->request->data['Taxonomy']);
                }
                if (isset($this->request->data['Tag']['request'])) {
                    $this->request->data['Tag'] = $this->request->data['Tag']['request'];
                }
                if (!isset($this->request->data['Tag']['nameList'])) {
                    $this->request->data['Tag']['nameList'] = array($this->request->data['Tag']['name']);
                } else {
                    $this->request->data['Tag']['nameList'] = json_decode($this->request->data['Tag']['nameList'], true);
                }
                $result = $this->Taxonomy->addTags($this->request->data['Tag']['taxonomy_id'], $this->request->data['Tag']['nameList']);
            }
            if ($result) {
                $message = __('The tag(s) has been saved.');
                if ($this->_isRest()) {
                    return $this->RestResponse->saveSuccessResponse('Taxonomy', 'addTag', $taxonomy_id, $this->response->type(), $message);
                }
                $this->Flash->success($message);
            } else {
                $message = __('The tag(s) could not be saved. Please, try again.');
                if ($this->_isRest()) {
                    return $this->RestResponse->saveFailResponse('Taxonomy', 'addTag', $taxonomy_id, $message, $this->response->type());
                }
                $this->Flash->error($message);
            }
            $this->redirect($this->referer());
        }
    }

    public function hideTag($taxonomy_id = false)
    {
        $this->request->allowMethod(['post']);

        if ($taxonomy_id) {
            $result = $this->Taxonomy->hideTags($taxonomy_id);
        } else {
            if (isset($this->request->data['Taxonomy'])) {
                $this->request->data['Tag'] = $this->request->data['Taxonomy'];
                unset($this->request->data['Taxonomy']);
            }
            if (isset($this->request->data['Tag']['request'])) {
                $this->request->data['Tag'] = $this->request->data['Tag']['request'];
            }
            if (!isset($this->request->data['Tag']['nameList'])) {
                $this->request->data['Tag']['nameList'] = array($this->request->data['Tag']['name']);
            } else {
                $this->request->data['Tag']['nameList'] = json_decode($this->request->data['Tag']['nameList'], true);
            }
            $result = $this->Taxonomy->hideTags($this->request->data['Tag']['taxonomy_id'], $this->request->data['Tag']['nameList']);
        }
        if ($result) {
            $this->Flash->success(__('The tag(s) has been saved.'));
        } else {
            $this->Flash->error(__('The tag(s) could not be saved. Please, try again.'));
        }
        $this->redirect($this->referer());
    }

    public function unhideTag($taxonomy_id = false)
    {
        $this->request->allowMethod(['post']);

        if ($taxonomy_id) {
            $result = $this->Taxonomy->unhideTags($taxonomy_id);
        } else {
            if (isset($this->request->data['Taxonomy'])) {
                $this->request->data['Tag'] = $this->request->data['Taxonomy'];
                unset($this->request->data['Taxonomy']);
            }
            if (isset($this->request->data['Tag']['request'])) {
                $this->request->data['Tag'] = $this->request->data['Tag']['request'];
            }
            if (!isset($this->request->data['Tag']['nameList'])) {
                $this->request->data['Tag']['nameList'] = array($this->request->data['Tag']['name']);
            } else {
                $this->request->data['Tag']['nameList'] = json_decode($this->request->data['Tag']['nameList'], true);
            }
            $result = $this->Taxonomy->unhideTags($this->request->data['Tag']['taxonomy_id'], $this->request->data['Tag']['nameList']);
        }
        if ($result) {
            $this->Flash->success(__('The tag(s) has been saved.'));
        } else {
            $this->Flash->error(__('The tag(s) could not be saved. Please, try again.'));
        }
        $this->redirect($this->referer());
    }

    public function disableTag($taxonomy_id = false)
    {
        if ($this->request->is('get')) {
            if (empty($taxonomy_id) && !empty($this->request->params['named']['taxonomy_id'])) {
                $taxonomy_id = $this->request->params['named']['taxonomy_id'];
            }
            if (
                empty($taxonomy_id) ||
                empty($this->request->params['named']['name'])
            ) {
                throw new MethodNotAllowedException(__('Taxonomy ID or tag name must be provided.'));
            } else {
                $this->request->data['Taxonomy']['taxonomy_id'] = $taxonomy_id;
                $this->request->data['Taxonomy']['name'] = $this->request->params['named']['name'];
            }
        } else {
            if ($taxonomy_id) {
                $result = $this->Taxonomy->disableTags($taxonomy_id);
            } else {
                if (isset($this->request->data['Taxonomy'])) {
                    $this->request->data['Tag'] = $this->request->data['Taxonomy'];
                    unset($this->request->data['Taxonomy']);
                }
                if (isset($this->request->data['Tag']['request'])) {
                    $this->request->data['Tag'] = $this->request->data['Tag']['request'];
                }
                if (!isset($this->request->data['Tag']['nameList'])) {
                    $this->request->data['Tag']['nameList'] = array($this->request->data['Tag']['name']);
                } else {
                    $this->request->data['Tag']['nameList'] = json_decode($this->request->data['Tag']['nameList'], true);
                }
                $result = $this->Taxonomy->disableTags($this->request->data['Tag']['taxonomy_id'], $this->request->data['Tag']['nameList']);
            }
            if ($result) {
                $this->Flash->success(__('The tag(s) has been hidden.'));
            } else {
                $this->Flash->error(__('The tag(s) could not be hidden. Please, try again.'));
            }
            $this->redirect($this->referer());
        }
    }

    public function taxonomyMassConfirmation($id)
    {
        $this->set('id', $id);
        $this->render('ajax/taxonomy_mass_confirmation');
    }

    public function taxonomyMassHide($id)
    {
        $this->set('id', $id);
        $this->render('ajax/taxonomy_mass_hide');
    }

    public function taxonomyMassUnhide($id)
    {
        $this->set('id', $id);
        $this->render('ajax/taxonomy_mass_unhide');
    }

    public function delete($id)
    {
        if ($this->request->is('post')) {
            $result = $this->Taxonomy->delete($id, true);
            if ($result) {
                $this->Flash->success(__('Taxonomy successfully deleted.'));
                $this->redirect(array('controller' => 'taxonomies', 'action' => 'index'));
            } else {
                $this->Flash->error(__('Taxonomy could not be deleted.'));
                $this->redirect(array('controller' => 'taxonomies', 'action' => 'index'));
            }
        } else {
            if ($this->request->is('ajax')) {
                $this->set('id', $id);
                $this->render('ajax/taxonomy_delete_confirmation');
            } else {
                throw new MethodNotAllowedException(__('This function can only be reached via AJAX.'));
            }
        }
    }

    public function toggleRequired($id)
    {
        $taxonomy = $this->Taxonomy->find('first', array(
            'recursive' => -1,
            'conditions' => array('Taxonomy.id' => $id)
        ));
        if (empty($taxonomy)) {
            return $this->RestResponse->saveFailResponse('Taxonomy', 'toggleRequired', $id, 'Invalid Taxonomy', $this->response->type());
        }
        if ($this->request->is('post')) {
            $taxonomy['Taxonomy']['required'] = $this->request->data['Taxonomy']['required'];
            $result = $this->Taxonomy->save($taxonomy);
            if ($result) {
                return $this->RestResponse->saveSuccessResponse('Taxonomy', 'toggleRequired', $id, $this->response->type());
            } else {
                return $this->RestResponse->saveFailResponse('Taxonomy', 'toggleRequired', $id, $this->validationError, $this->response->type());
            }
        }

        $this->set('required', !$taxonomy['Taxonomy']['required']);
        $this->set('id', $id);
        $this->autoRender = false;
        $this->layout = false;
        $this->render('ajax/toggle_required');
    }

    /**
     * @param string $action
     * @param int $modelId
     * @param string $title
     * @param string $change
     * @return void
     * @throws Exception
     */
    private function __log($action, $modelId, $title, $change)
    {
        /** @var Log $log */
        $log = ClassRegistry::init('Log');
        $log->createLogEntry($this->Auth->user(), $action, 'Taxonomy', $modelId, $title, $change);
    }

    /**
     * Attach tag counts.
     * @param array $taxonomies
     * @return array
     */
    private function __tagCount(array $taxonomies)
    {
        $tags = [];
        foreach ($taxonomies as $taxonomyPos => $taxonomy) {
            $total = 0;
            foreach ($taxonomy['TaxonomyPredicate'] as $predicate) {
                if (isset($predicate['TaxonomyEntry']) && !empty($predicate['TaxonomyEntry'])) {
                    foreach ($predicate['TaxonomyEntry'] as $entry) {
                        $tag = mb_strtolower($taxonomy['Taxonomy']['namespace'] . ':' . $predicate['value'] . '="' . $entry['value'] . '"');
                        $tags[$tag] = $taxonomyPos;
                        $total++;
                    }
                } else {
                    $tag = mb_strtolower($taxonomy['Taxonomy']['namespace'] . ':' . $predicate['value']);
                    $tags[$tag] = $taxonomyPos;
                    $total++;
                }
            }
            $taxonomies[$taxonomyPos]['total_count'] = $total;
            $taxonomies[$taxonomyPos]['current_count'] = 0;
            unset($taxonomies[$taxonomyPos]['TaxonomyPredicate']);
        }

        $this->loadModel('Tag');
        $existingTags = $this->Tag->find('column', [
            'fields' => ['Tag.name'],
            'conditions' => [
                'lower(Tag.name)' => array_keys($tags),
                'hide_tag' => 0
            ],
        ]);

        foreach ($existingTags as $existingTag) {
            $existingTag = mb_strtolower($existingTag);
            if (isset($tags[$existingTag])) {
                $taxonomies[$tags[$existingTag]]['current_count']++;
            }
        }

        return $taxonomies;
    }

    private function __search($value)
    {
        $value = mb_strtolower(trim($value));
        $searchTerm = "%$value%";
        $taxonomyPredicateIds = $this->Taxonomy->TaxonomyPredicate->TaxonomyEntry->find('column', [
            'fields' => ['TaxonomyEntry.taxonomy_predicate_id'],
            'conditions' => ['OR' => [
                'LOWER(value) LIKE' => $searchTerm,
                'LOWER(expanded) LIKE' => $searchTerm,
            ]],
            'unique' => true,
        ]);

        $taxonomyIds = $this->Taxonomy->TaxonomyPredicate->find('column', [
            'fields' => ['TaxonomyPredicate.taxonomy_id'],
            'conditions' => ['OR' => [
                'id' => $taxonomyPredicateIds,
                'LOWER(value) LIKE' => $searchTerm,
                'LOWER(expanded) LIKE' => $searchTerm,
            ]],
            'unique' => true,
        ]);

        $taxonomyIds = $this->Taxonomy->find('column', [
            'fields' => ['Taxonomy.id'],
            'conditions' => ['OR' => [
                'id' => $taxonomyIds,
                'LOWER(namespace) LIKE' => $searchTerm,
                'LOWER(description) LIKE' => $searchTerm,
            ]],
        ]);

        return $taxonomyIds;
    }
}
