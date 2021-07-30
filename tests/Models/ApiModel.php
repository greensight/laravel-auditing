<?php

namespace Greensight\LaravelAuditing\Tests\Models;

use Greensight\LaravelAuditing\Database\Factories\ApiModelFactory;
use Greensight\LaravelAuditing\SupportsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Greensight\LaravelAuditing\Contracts\Auditable;

class ApiModel extends Model implements Auditable
{
    use SupportsAudit;
    use SoftDeletes;

    /**
     * @var string UUID key
     */
    public $primaryKey = 'api_model_id';

    /**
     * @var bool Set to false for UUID keys
     */
    public $incrementing = false;

    /**
     * @var string Set to string for UUID keys
     */
    protected $keyType = 'string';

    /**
     * {@inheritdoc}
     */
    protected $dates = [
        'published_at',
    ];

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'api_model_id',
        'content',
        'published_at',
    ];

    public static function factory(): ApiModelFactory
    {
        return ApiModelFactory::new();
    }
}
