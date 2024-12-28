<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Helpers\Constant;

class Event extends Model
{
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime:'.Constant::DATETIME_FORMAT,
        'updated_at' => 'datetime:'.Constant::DATETIME_FORMAT,
    ];

    protected $with = [
        'jury', 'winner', 'finalists'
    ];

    public $appends = ['amount', 'is_open', 'is_announced'];

    public function getAmountAttribute()
    {
        return $this->price ? $this->currency.' '.$this->price : 'Free';
    }

    public function getIsOpenAttribute()
    {
        return ($this->start_date <= date("Y-m-d") && $this->end_date >= date("Y-m-d")) ? true : false;
    }

    public function jury()
    {
        return $this->belongsToMany(User::class, 'event_jury')->withTimestamps()->setEagerLoads([]);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function winner()
    {
        return $this->belongsTo(Submission::class, 'winner_submission_id');
    }

    public function finalists()
    {
        return $this->belongsToMany(Submission::class, 'event_finalist')->withTimestamps();
    }

    public function getIsAnnouncedAttribute()
    {
        return (date("Y-m-d") >= $this->anouncement_date);
    }
}
