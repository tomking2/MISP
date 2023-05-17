<?php
include_once APP . 'Model/WorkflowModules/WorkflowBaseModule.php';

class Module_generic_filter_data extends WorkflowFilteringLogicModule
{
    public $id = 'generic-filter-data';
    public $name = 'Filter :: Generic';
    public $description = 'Generic data filtering block. The module filters incoming data and forward the matching data to its output.';
    public $icon = 'filter';
    public $inputs = 1;
    public $outputs = 1;
    public $params = [];

    private $operators = [
        'in' => 'In',
        'not_in' => 'Not in',
        'equals' => 'Equals',
        'not_equals' => 'Not equals',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->params = [
            [
                'id' => 'filtering-label',
                'label' => __('Filtering Label'),
                'type' => 'select',
                'options' => $this->_genFilteringLabels(),
            ],
            [
                'id' => 'selector',
                'label' => __('Data selector'),
                'type' => 'input',
                'placeholder' => 'Event._AttributeFlattened.{n}',
            ],
            [
                'id' => 'value',
                'label' => __('Value'),
                'type' => 'input',
                'placeholder' => 'tlp:red',
            ],
            [
                'id' => 'operator',
                'label' => __('Operator'),
                'type' => 'select',
                'default' => 'in',
                'options' => $this->operators,
            ],
            [
                'id' => 'hash_path',
                'label' => __('Hash path'),
                'type' => 'input',
                'placeholder' => 'Tag.name',
            ],
        ];
    }

    public function exec(array $node, WorkflowRoamingData $roamingData, array &$errors=[]): bool
    {
        parent::exec($node, $roamingData, $errors);
        $params = $this->getParamsWithValues($node);
        $selector = $params['selector']['value'];
        $path = $params['hash_path']['value'];
        $operator = $params['operator']['value'];
        $value = $params['value']['value'];
        $filteringLabel = $params['filtering-label']['value'];
        $rData = $roamingData->getData();

        $newRData = $rData;
        if (empty($newRData['_unfilteredData'])) {
            $newRData['_unfilteredData'] = $rData;
        }
        $newRData['enabledFilters'][$filteringLabel] = [
            'selector' => $selector,
            'path' => $path,
            'operator' => $operator,
            'value' => $value,
        ];

        $roamingData->setData($newRData);
        return true;
    }
}
