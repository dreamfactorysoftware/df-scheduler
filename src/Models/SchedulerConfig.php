<?php

namespace DreamFactory\Core\Scheduler\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;
use Illuminate\Support\Arr;

/**
 * Scheduled jobs
 */
class SchedulerConfig extends BaseSystemModel
{
    /** @var string */
    protected $table = 'scheduler_config';


    protected $rules = [
        'component' => 'required'
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'is_active' => 'boolean',
        'verb_mask'      => 'integer',
        'service_id'     => 'integer'
    ];

    /**
     * @param array $schema
     */
    /*protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'service_id':
                $schema['label'] = 'Simple label';
                $schema['type'] = 'text';
                $schema['required'] = false;
                $schema['description'] = 'This is just a simple label';
                break;

            case 'description':
                $schema['label'] = 'Description';
                $schema['type'] = 'text';
                $schema['description'] =
                    'This is just a description';
                break;
            case 'is_example':
                $schema['label'] = 'Is this an example?';
                $schema['type'] = 'boolean';
                $schema['description'] =
                    'It must be an example';
                break;
        }
    }*/


}